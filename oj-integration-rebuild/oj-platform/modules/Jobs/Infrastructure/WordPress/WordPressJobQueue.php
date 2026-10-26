<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Jobs\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobControl;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobQueue;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobQueueReadiness;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\ClaimedJobItem;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\FormDraftReference;
use CodingPanda\OjPlatform\Modules\Jobs\Domain\JobControlResult;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobEnqueueResult;
use CodingPanda\OjPlatform\Modules\Jobs\Domain\JobExecutionPlan;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobHandlerResult;
use CodingPanda\OjPlatform\Modules\Jobs\Domain\JobItemPlan;
use CodingPanda\OjPlatform\Modules\Jobs\Domain\JobStatus;
use DomainException;
use RuntimeException;
use Throwable;

final class WordPressJobQueue implements JobQueue, JobControl, JobQueueReadiness
{
    public function __construct(private readonly object $database)
    {
    }

    public static function fromGlobals(): self
    {
        $database = $GLOBALS['wpdb'] ?? null;

        return new self(is_object($database) ? $database : new \stdClass());
    }

    public function enqueue(
        JobExecutionPlan $plan,
        array $items
    ): JobEnqueueResult {
        $this->assertDatabase();
        if (count($items) !== $plan->totalCount() || $items === []) {
            throw new RuntimeException('Jobs 실행 계획과 항목 수가 일치하지 않습니다.');
        }
        $existing = $this->existingJobId($plan->idempotencyKey());
        if ($existing > 0) {
            return new JobEnqueueResult($existing, false);
        }

        $this->begin();
        try {
            $now = gmdate('Y-m-d H:i:s');
            $inserted = $this->database->insert('background_jobs', [
                'job_type' => $plan->jobType(),
                'owner_module' => $plan->ownerModule(),
                'handler_version' => $plan->handlerVersion(),
                'input_version' => $plan->inputVersion(),
                'access_policy_key' => $plan->accessPolicyKey(),
                'access_policy_version' => $plan->accessPolicyVersion(),
                'execution_mode' => $plan->executionMode(),
                'idempotency_key' => $plan->idempotencyKey(),
                'input_contract_summary' => $this->json($plan->inputSummary()),
                'created_by_user_id' => $plan->createdByUserId(),
                'status' => JobStatus::Queued->value,
                'target_summary' => $this->json($plan->targetSummary()),
                'total_count' => $plan->totalCount(),
                'succeeded_count' => 0,
                'failed_count' => 0,
                'cancelled_count' => 0,
                'available_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $jobId = (int) ($this->database->insert_id ?? 0);
            if ($inserted === false || $jobId < 1) {
                throw new RuntimeException('Jobs 작업을 등록하지 못했습니다.');
            }
            foreach ($items as $item) {
                if (! $item instanceof JobItemPlan) {
                    throw new RuntimeException('Jobs 항목 형식이 올바르지 않습니다.');
                }
                $itemInserted = $this->database->insert('background_job_items', [
                    'job_id' => $jobId,
                    'target_type' => $item->targetType(),
                    'target_key' => $item->targetKey(),
                    'target_version' => $item->targetVersion(),
                    'target_updated_at' => $item->targetUpdatedAt(),
                    'input_hash' => $item->inputHash(),
                    'status' => JobStatus::Queued->value,
                    'attempt_count' => 0,
                    'max_attempts' => $item->maxAttempts(),
                    'payload_ref' => $item->payloadReference()->value(),
                    'available_at' => $now,
                    'updated_at' => $now,
                ]);
                if ($itemInserted === false) {
                    throw new RuntimeException('Jobs 작업 항목을 등록하지 못했습니다.');
                }
            }
            $this->event(
                $jobId,
                null,
                'job_registered',
                null,
                JobStatus::Queued->value,
                'user',
                $plan->createdByUserId(),
                null,
                null,
                ['total_count' => $plan->totalCount()]
            );
            $this->commit();

            return new JobEnqueueResult($jobId, true);
        } catch (Throwable $error) {
            $this->rollback();
            $existing = $this->existingJobId($plan->idempotencyKey());
            if ($existing > 0) {
                return new JobEnqueueResult($existing, false);
            }
            throw $error;
        }
    }

    public function hasReadyItems(): bool
    {
        $this->assertDatabase();
        $id = $this->database->get_var("SELECT i.id
            FROM background_job_items i
            INNER JOIN background_jobs j ON j.id = i.job_id
            WHERE ((i.status = 'queued' AND i.available_at <= UTC_TIMESTAMP())
                OR (i.status = 'running' AND i.lease_expires_at IS NOT NULL
                    AND i.lease_expires_at < UTC_TIMESTAMP()))
            AND j.status IN ('queued', 'running') AND j.cancel_requested_at IS NULL
            AND i.attempt_count < i.max_attempts
            ORDER BY i.available_at ASC, i.id ASC LIMIT 1");
        if (($this->database->last_error ?? '') !== '') {
            throw new RuntimeException('대기 작업을 확인하지 못했습니다.');
        }
        return $id !== null;
    }

    public function requestCancellation(
        int $jobId,
        int $actorUserId
    ): JobControlResult {
        $this->assertDatabase();
        $this->assertControlInput($jobId, $actorUserId);
        $this->begin();
        try {
            $job = $this->lockedJob($jobId);
            $status = (string) ($job->status ?? '');
            if ($status === JobStatus::Cancelled->value) {
                $this->commit();

                return new JobControlResult(0);
            }
            if ($this->isTerminalStatus($status)) {
                throw new DomainException('완료된 작업은 취소할 수 없습니다.');
            }

            $rows = $this->database->get_results($this->database->prepare(
                "SELECT id, payload_ref
                FROM background_job_items
                WHERE job_id = %d AND status = 'queued'
                FOR UPDATE",
                $jobId
            ));
            $items = is_array($rows)
                ? array_values(array_filter($rows, 'is_object'))
                : [];
            $updated = $this->database->query($this->database->prepare(
                "UPDATE background_jobs
                SET cancel_requested_at = COALESCE(cancel_requested_at, UTC_TIMESTAMP()),
                    cancel_requested_by_user_id = COALESCE(cancel_requested_by_user_id, %d),
                    updated_at = UTC_TIMESTAMP()
                WHERE id = %d AND status IN ('queued', 'running')",
                $actorUserId,
                $jobId
            ));
            if ((int) $updated !== 1) {
                throw new DomainException('작업 상태가 변경되어 취소 요청을 적용하지 못했습니다.');
            }
            $cancelled = $this->database->query($this->database->prepare(
                "UPDATE background_job_items
                SET status = 'cancelled',
                    result_code = 'cancelled_by_user',
                    result_message = %s,
                    safe_result = %s,
                    completed_at = UTC_TIMESTAMP(),
                    lease_token = NULL,
                    lease_expires_at = NULL,
                    claimed_by = NULL,
                    updated_at = UTC_TIMESTAMP()
                WHERE job_id = %d AND status = 'queued'",
                '사용자가 작업을 취소했습니다.',
                $this->json(['cancelled_by_user_id' => $actorUserId]),
                $jobId
            ));
            if ($cancelled === false) {
                throw new RuntimeException('대기 중인 Jobs 항목을 취소하지 못했습니다.');
            }
            foreach ($items as $item) {
                $this->event(
                    $jobId,
                    (int) ($item->id ?? 0),
                    'item_cancelled',
                    JobStatus::Queued->value,
                    JobStatus::Cancelled->value,
                    'user',
                    $actorUserId,
                    null,
                    'cancelled_by_user',
                    []
                );
            }
            $this->event(
                $jobId,
                null,
                'job_cancel_requested',
                $status,
                $status,
                'user',
                $actorUserId,
                null,
                'cancelled_by_user',
                ['cancelled_count' => (int) $cancelled]
            );
            $this->aggregate($jobId);
            $this->commit();

            $references = [];
            foreach ($items as $item) {
                $reference = $this->nullableString($item->payload_ref ?? null);
                if ($reference !== null) {
                    $references[] = $reference;
                }
            }

            return new JobControlResult((int) $cancelled, $references);
        } catch (Throwable $error) {
            $this->rollback();
            throw $error;
        }
    }

    public function retryFailed(
        int $jobId,
        int $actorUserId
    ): JobControlResult {
        $this->assertDatabase();
        $this->assertControlInput($jobId, $actorUserId);
        $this->begin();
        try {
            $job = $this->lockedJob($jobId);
            $status = (string) ($job->status ?? '');
            if (! in_array($status, [
                JobStatus::Failed->value,
                JobStatus::Partial->value,
                JobStatus::NeedsAttention->value,
            ], true)) {
                throw new DomainException('실패하거나 확인이 필요한 완료 작업만 다시 시도할 수 있습니다.');
            }
            $rows = $this->database->get_results($this->database->prepare(
                "SELECT id, status
                FROM background_job_items
                WHERE job_id = %d AND status IN ('failed', 'needs_attention')
                    AND (result_code IS NULL OR result_code != 'payload_unavailable')
                FOR UPDATE",
                $jobId
            ));
            $items = is_array($rows)
                ? array_values(array_filter($rows, 'is_object'))
                : [];
            if ($items === []) {
                $expiredCount = (int) $this->database->get_var($this->database->prepare(
                    "SELECT COUNT(*) FROM background_job_items
                    WHERE job_id = %d AND status IN ('failed', 'needs_attention')
                        AND result_code = 'payload_unavailable'",
                    $jobId
                ));
                throw new DomainException($expiredCount > 0
                    ? '입력이 만료되어 다시 시도할 수 없는 항목만 남아 있습니다. 새 작업으로 다시 등록해 주세요.'
                    : '다시 시도할 실패 항목이 없습니다.');
            }
            $retried = $this->database->query($this->database->prepare(
                "UPDATE background_job_items
                SET status = 'queued',
                    attempt_count = 0,
                    result_code = NULL,
                    result_message = NULL,
                    safe_result = NULL,
                    available_at = UTC_TIMESTAMP(),
                    completed_at = NULL,
                    lease_token = NULL,
                    lease_expires_at = NULL,
                    claimed_by = NULL,
                    updated_at = UTC_TIMESTAMP()
                WHERE job_id = %d AND status IN ('failed', 'needs_attention')
                    AND (result_code IS NULL OR result_code != 'payload_unavailable')",
                $jobId
            ));
            if ($retried === false || (int) $retried !== count($items)) {
                throw new RuntimeException('실패한 Jobs 항목을 다시 대기 상태로 전환하지 못했습니다.');
            }
            $updated = $this->database->query($this->database->prepare(
                "UPDATE background_jobs
                SET status = 'queued',
                    cancel_requested_at = NULL,
                    cancel_requested_by_user_id = NULL,
                    completed_at = NULL,
                    updated_at = UTC_TIMESTAMP()
                WHERE id = %d",
                $jobId
            ));
            if ((int) $updated !== 1) {
                throw new RuntimeException('Jobs 작업 재시도 상태를 저장하지 못했습니다.');
            }
            foreach ($items as $item) {
                $this->event(
                    $jobId,
                    (int) ($item->id ?? 0),
                    'item_manual_retry',
                    (string) ($item->status ?? ''),
                    JobStatus::Queued->value,
                    'user',
                    $actorUserId,
                    null,
                    'manual_retry',
                    []
                );
            }
            $this->event(
                $jobId,
                null,
                'job_manual_retry',
                $status,
                JobStatus::Queued->value,
                'user',
                $actorUserId,
                null,
                'manual_retry',
                ['retried_count' => (int) $retried]
            );
            $this->aggregate($jobId);
            $this->commit();

            return new JobControlResult((int) $retried);
        } catch (Throwable $error) {
            $this->rollback();
            throw $error;
        }
    }

    public function claim(
        string $workerId,
        int $leaseSeconds
    ): ?ClaimedJobItem {
        $this->assertDatabase();
        if (
            preg_match('/^[a-z0-9-]{3,191}$/', $workerId) !== 1
            || $leaseSeconds < 30
            || $leaseSeconds > 600
        ) {
            throw new RuntimeException('Jobs Worker 임대 값이 올바르지 않습니다.');
        }
        $this->reconcileExpiredMaxAttemptLeases();
        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $candidate = $this->database->get_var(
                "SELECT i.id
                FROM background_job_items i
                INNER JOIN background_jobs j ON j.id = i.job_id
                WHERE (
                    (i.status = 'queued' AND i.available_at <= UTC_TIMESTAMP())
                    OR (
                        i.status = 'running'
                        AND i.lease_expires_at IS NOT NULL
                        AND i.lease_expires_at < UTC_TIMESTAMP()
                    )
                )
                AND j.status IN ('queued', 'running')
                AND j.cancel_requested_at IS NULL
                AND i.attempt_count < i.max_attempts
                ORDER BY i.available_at ASC, i.id ASC
                LIMIT 1"
            );
            $itemId = is_numeric($candidate) ? (int) $candidate : 0;
            if ($itemId < 1) {
                return null;
            }
            $leaseToken = hash('sha256', random_bytes(32));
            $expiresAt = gmdate('Y-m-d H:i:s', time() + $leaseSeconds);
            $sql = $this->database->prepare(
                "UPDATE background_job_items
                SET status = 'running',
                    attempt_count = attempt_count + 1,
                    lease_token = %s,
                    lease_expires_at = %s,
                    claimed_by = %s,
                    started_at = COALESCE(started_at, UTC_TIMESTAMP()),
                    updated_at = UTC_TIMESTAMP()
                WHERE id = %d
                AND attempt_count < max_attempts
                AND (
                    (status = 'queued' AND available_at <= UTC_TIMESTAMP())
                    OR (
                        status = 'running'
                        AND lease_expires_at IS NOT NULL
                        AND lease_expires_at < UTC_TIMESTAMP()
                    )
                )",
                $leaseToken,
                $expiresAt,
                $workerId,
                $itemId
            );
            $updated = $this->database->query($sql);
            if ((int) $updated !== 1) {
                continue;
            }
            $row = $this->database->get_row($this->database->prepare(
                "SELECT i.*, j.job_type, j.created_by_user_id
                FROM background_job_items i
                INNER JOIN background_jobs j ON j.id = i.job_id
                WHERE i.id = %d AND i.lease_token = %s",
                $itemId,
                $leaseToken
            ));
            if (! is_object($row)) {
                throw new RuntimeException('임대한 Jobs 항목을 읽지 못했습니다.');
            }
            $this->database->query($this->database->prepare(
                "UPDATE background_jobs
                SET status = 'running',
                    started_at = COALESCE(started_at, UTC_TIMESTAMP()),
                    updated_at = UTC_TIMESTAMP()
                WHERE id = %d AND status IN ('queued', 'running')",
                (int) ($row->job_id ?? 0)
            ));
            $this->event(
                (int) ($row->job_id ?? 0),
                $itemId,
                'item_claimed',
                JobStatus::Queued->value,
                JobStatus::Running->value,
                'worker',
                null,
                $workerId,
                null,
                ['attempt' => (int) ($row->attempt_count ?? 0)]
            );

            return new ClaimedJobItem(
                (int) ($row->job_id ?? 0),
                $itemId,
                (string) ($row->job_type ?? ''),
                (string) ($row->target_type ?? ''),
                (string) ($row->target_key ?? ''),
                $this->nullableString($row->target_version ?? null),
                $this->nullableString($row->target_updated_at ?? null),
                (string) ($row->input_hash ?? ''),
                new FormDraftReference((string) ($row->payload_ref ?? '')),
                (int) ($row->attempt_count ?? 0),
                (int) ($row->max_attempts ?? 0),
                (int) ($row->created_by_user_id ?? 0),
                $leaseToken
            );
        }

        return null;
    }

    public function finish(
        ClaimedJobItem $claim,
        JobHandlerResult $result
    ): void {
        $this->assertDatabase();
        if (
            $result->outcome() === 'retry'
            && $claim->attempt() >= $claim->maxAttempts()
        ) {
            $result = JobHandlerResult::fromArray(array_replace($result->toArray(), [
                'outcome' => 'failed',
                'retry_delay_seconds' => 0,
                'safe_result' => array_replace($result->safeResult(), [
                    'retry_exhausted' => true,
                    'attempt_count' => $claim->attempt(),
                ]),
            ]));
        }
        $status = match ($result->outcome()) {
            'succeeded' => JobStatus::Completed,
            'retry' => JobStatus::Queued,
            'failed' => JobStatus::Failed,
            'needs_attention' => JobStatus::NeedsAttention,
        };
        $availableAt = $result->outcome() === 'retry'
            ? gmdate('Y-m-d H:i:s', time() + $result->retryDelaySeconds())
            : gmdate('Y-m-d H:i:s');
        $completedAssignment = $result->outcome() === 'retry'
            ? 'completed_at = NULL,'
            : 'completed_at = UTC_TIMESTAMP(),';
        $sql = $this->database->prepare(
            "UPDATE background_job_items
            SET status = %s,
                result_code = %s,
                result_message = %s,
                safe_result = %s,
                available_at = %s,
                {$completedAssignment}
                lease_token = NULL,
                lease_expires_at = NULL,
                claimed_by = NULL,
                updated_at = UTC_TIMESTAMP()
            WHERE id = %d
            AND job_id = %d
            AND status = 'running'
            AND lease_token = %s",
            $status->value,
            $result->resultCode(),
            $result->message(),
            $this->json($result->safeResult()),
            $availableAt,
            $claim->itemId(),
            $claim->jobId(),
            $claim->leaseToken()
        );
        $updated = $this->database->query($sql);
        if ((int) $updated !== 1) {
            throw new RuntimeException('Jobs 항목 임대가 만료되어 결과를 확정할 수 없습니다.');
        }
        $this->event(
            $claim->jobId(),
            $claim->itemId(),
            $result->outcome() === 'retry' ? 'item_retry_scheduled' : 'item_finished',
            JobStatus::Running->value,
            $status->value,
            'worker',
            null,
            null,
            $result->resultCode(),
            $result->safeResult()
        );
        $this->aggregate($claim->jobId());
    }

    private function reconcileExpiredMaxAttemptLeases(): void
    {
        $rows = $this->database->get_results(
            "SELECT i.id, i.job_id
            FROM background_job_items i
            INNER JOIN background_jobs j ON j.id = i.job_id
            WHERE i.status = 'running'
            AND i.lease_expires_at IS NOT NULL
            AND i.lease_expires_at < UTC_TIMESTAMP()
            AND i.attempt_count >= i.max_attempts
            AND j.status IN ('queued', 'running')
            ORDER BY i.lease_expires_at ASC, i.id ASC
            LIMIT 100"
        );
        if (! is_array($rows) || $rows === []) {
            return;
        }
        $affectedJobs = [];
        foreach ($rows as $row) {
            if (! is_object($row)) {
                continue;
            }
            $itemId = (int) ($row->id ?? 0);
            $jobId = (int) ($row->job_id ?? 0);
            if ($itemId < 1 || $jobId < 1) {
                continue;
            }
            $updated = $this->database->query($this->database->prepare(
                "UPDATE background_job_items
                SET status = 'needs_attention',
                    result_code = 'lease_expired_after_max_attempt',
                    result_message = %s,
                    safe_result = %s,
                    completed_at = UTC_TIMESTAMP(),
                    lease_token = NULL,
                    lease_expires_at = NULL,
                    claimed_by = NULL,
                    updated_at = UTC_TIMESTAMP()
                WHERE id = %d
                AND job_id = %d
                AND status = 'running'
                AND lease_expires_at IS NOT NULL
                AND lease_expires_at < UTC_TIMESTAMP()
                AND attempt_count >= max_attempts",
                '실행 도중 임대가 만료되었습니다. 단계 기록을 확인해 주세요.',
                $this->json(['reason_code' => 'lease_expired_after_max_attempt']),
                $itemId,
                $jobId
            ));
            if ((int) $updated !== 1) {
                continue;
            }
            $this->event(
                $jobId,
                $itemId,
                'item_lease_expired',
                JobStatus::Running->value,
                JobStatus::NeedsAttention->value,
                'system',
                null,
                null,
                'lease_expired_after_max_attempt',
                ['attempts_exhausted' => true]
            );
            $affectedJobs[$jobId] = true;
        }
        foreach (array_keys($affectedJobs) as $jobId) {
            $this->aggregate((int) $jobId);
        }
    }

    private function aggregate(int $jobId): void
    {
        $previousStatus = $this->database->get_var(
            $this->database->prepare(
                'SELECT status FROM background_jobs WHERE id = %d',
                $jobId
            )
        );
        $previousStatus = is_string($previousStatus)
            ? $previousStatus
            : null;
        $counts = [];
        $rows = $this->database->get_results($this->database->prepare(
            'SELECT status, COUNT(*) AS total
            FROM background_job_items
            WHERE job_id = %d
            GROUP BY status',
            $jobId
        ));
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (is_object($row)) {
                    $counts[(string) ($row->status ?? '')] = (int) ($row->total ?? 0);
                }
            }
        }
        $queued = ($counts[JobStatus::Queued->value] ?? 0)
            + ($counts[JobStatus::Running->value] ?? 0);
        $completed = $counts[JobStatus::Completed->value] ?? 0;
        $failed = $counts[JobStatus::Failed->value] ?? 0;
        $needsAttention = $counts[JobStatus::NeedsAttention->value] ?? 0;
        $cancelled = $counts[JobStatus::Cancelled->value] ?? 0;
        $total = array_sum($counts);
        $status = match (true) {
            $queued > 0 => JobStatus::Running,
            $needsAttention > 0 => JobStatus::NeedsAttention,
            $failed > 0 && ($completed > 0 || $cancelled > 0) => JobStatus::Partial,
            $failed > 0 => JobStatus::Failed,
            $completed > 0 && $cancelled > 0 => JobStatus::Partial,
            $completed === $total => JobStatus::Completed,
            $cancelled === $total => JobStatus::Cancelled,
            default => JobStatus::Failed,
        };
        $completedAt = $status === JobStatus::Running
            ? null
            : gmdate('Y-m-d H:i:s');
        $updated = $this->database->update(
            'background_jobs',
            [
                'status' => $status->value,
                'succeeded_count' => $completed,
                'failed_count' => $failed + $needsAttention,
                'cancelled_count' => $cancelled,
                'result_summary' => $this->json([
                    'succeeded_count' => $completed,
                    'failed_count' => $failed,
                    'needs_attention_count' => $needsAttention,
                    'cancelled_count' => $cancelled,
                ]),
                'completed_at' => $completedAt,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ],
            ['id' => $jobId]
        );
        if ($updated === false) {
            throw new RuntimeException('Jobs 작업 집계를 갱신하지 못했습니다.');
        }
        if (
            $status !== JobStatus::Running
            && $previousStatus !== $status->value
        ) {
            $this->event(
                $jobId,
                null,
                'job_finished',
                $previousStatus,
                $status->value,
                'system',
                null,
                null,
                null,
                [
                    'succeeded_count' => $completed,
                    'failed_count' => $failed,
                    'needs_attention_count' => $needsAttention,
                    'cancelled_count' => $cancelled,
                ]
            );
        }
    }

    private function lockedJob(int $jobId): object
    {
        $job = $this->database->get_row($this->database->prepare(
            'SELECT id, status FROM background_jobs WHERE id = %d FOR UPDATE',
            $jobId
        ));
        if (! is_object($job)) {
            throw new DomainException('작업을 찾을 수 없습니다.');
        }

        return $job;
    }

    private function assertControlInput(int $jobId, int $actorUserId): void
    {
        if ($jobId < 1 || $actorUserId < 1) {
            throw new DomainException('작업 변경 요청이 올바르지 않습니다.');
        }
    }

    private function isTerminalStatus(string $status): bool
    {
        return in_array($status, [
            JobStatus::NeedsAttention->value,
            JobStatus::Partial->value,
            JobStatus::Completed->value,
            JobStatus::Failed->value,
            JobStatus::Cancelled->value,
        ], true);
    }

    /**
     * @param array<string, mixed> $safeMetadata
     */
    private function event(
        int $jobId,
        ?int $itemId,
        string $eventType,
        ?string $fromStatus,
        string $toStatus,
        string $actorType,
        ?int $actorUserId,
        ?string $actorRef,
        ?string $reasonCode,
        array $safeMetadata
    ): void {
        $inserted = $this->database->insert('background_job_events', [
            'job_id' => $jobId,
            'item_id' => $itemId,
            'event_type' => $eventType,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'actor_type' => $actorType,
            'actor_user_id' => $actorUserId,
            'actor_ref' => $actorRef,
            'reason_code' => $reasonCode,
            'safe_metadata' => $this->json($safeMetadata),
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        if ($inserted === false) {
            throw new RuntimeException('Jobs 감사 사건을 기록하지 못했습니다.');
        }
    }

    private function existingJobId(string $idempotencyKey): int
    {
        $row = $this->database->get_row($this->database->prepare(
            'SELECT id, status FROM background_jobs WHERE idempotency_key = %s LIMIT 1',
            $idempotencyKey
        ));
        if (! is_object($row)) {
            return 0;
        }
        if (! $this->isTerminalStatus((string) ($row->status ?? ''))) {
            return (int) $row->id;
        }
        /*
         * A terminal job (completed/failed/cancelled/...) already holds this
         * idempotency key. Retire its key so the exact same target+input can
         * be resubmitted as a brand-new job, instead of every future request
         * silently bouncing back to the same finished job forever.
         */
        $this->database->query($this->database->prepare(
            "UPDATE background_jobs
            SET idempotency_key = SHA2(CONCAT(idempotency_key, ':retired:', id), 256)
            WHERE id = %d AND idempotency_key = %s",
            (int) $row->id,
            $idempotencyKey
        ));

        return 0;
    }

    private function begin(): void
    {
        if ($this->database->query('START TRANSACTION') === false) {
            throw new RuntimeException('Jobs 등록 트랜잭션을 시작하지 못했습니다.');
        }
    }

    private function commit(): void
    {
        if ($this->database->query('COMMIT') === false) {
            throw new RuntimeException('Jobs 등록 트랜잭션을 완료하지 못했습니다.');
        }
    }

    private function rollback(): void
    {
        $this->database->query('ROLLBACK');
    }

    /**
     * @param array<string, mixed> $value
     */
    private function json(array $value): string
    {
        return json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }

    private function nullableString(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }

    private function assertDatabase(): void
    {
        foreach (
            ['insert', 'update', 'query', 'prepare', 'get_var', 'get_row', 'get_results']
            as $method
        ) {
            if (! method_exists($this->database, $method)) {
                throw new RuntimeException('WordPress Jobs 데이터베이스를 사용할 수 없습니다.');
            }
        }
    }
}
