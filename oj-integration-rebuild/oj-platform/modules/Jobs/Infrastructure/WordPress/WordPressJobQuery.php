<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Jobs\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobQuery;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\RecentJobQuery;
use CodingPanda\OjPlatform\Modules\Jobs\Domain\JobDetails;
use CodingPanda\OjPlatform\Modules\Jobs\Domain\JobItemDetails;
use CodingPanda\OjPlatform\Modules\Jobs\Domain\JobStepDetails;
use RuntimeException;

final class WordPressJobQuery implements JobQuery, RecentJobQuery
{
    public function __construct(private readonly object $database)
    {
    }

    public static function fromGlobals(): self
    {
        $database = $GLOBALS['wpdb'] ?? null;

        return new self(is_object($database) ? $database : new \stdClass());
    }

    public function find(int $jobId): ?JobDetails
    {
        $this->assertDatabase();
        if ($jobId < 1) {
            return null;
        }
        $job = $this->database->get_row($this->database->prepare(
            'SELECT * FROM background_jobs WHERE id = %d',
            $jobId
        ));
        if (! is_object($job)) {
            return null;
        }
        $items = [];
        $itemRows = $this->database->get_results($this->database->prepare(
            'SELECT id, target_type, target_key, status, attempt_count,'
                . ' max_attempts, result_code, result_message, safe_result'
                . ' FROM background_job_items WHERE job_id = %d ORDER BY id ASC',
            $jobId
        ));
        if (is_array($itemRows)) {
            foreach ($itemRows as $row) {
                if (! is_object($row)) {
                    continue;
                }
                $items[] = new JobItemDetails(
                    (int) ($row->id ?? 0),
                    (string) ($row->target_type ?? ''),
                    (string) ($row->target_key ?? ''),
                    (string) ($row->status ?? ''),
                    (int) ($row->attempt_count ?? 0),
                    (int) ($row->max_attempts ?? 0),
                    $this->nullableString($row->result_code ?? null),
                    $this->nullableString($row->result_message ?? null),
                    $this->jsonObject($row->safe_result ?? null)
                );
            }
        }
        $steps = [];
        $stepRows = $this->database->get_results($this->database->prepare(
            'SELECT item_id, step_key, step_order, status, started_at,'
                . ' completed_at'
                . " FROM background_job_steps WHERE job_id = %d"
                . " AND step_key <> 'worker.result'"
                . ' ORDER BY item_id ASC, step_order ASC',
            $jobId
        ));
        if (is_array($stepRows)) {
            foreach ($stepRows as $row) {
                if (! is_object($row)) {
                    continue;
                }
                $status = (string) ($row->status ?? '');
                $steps[] = new JobStepDetails(
                    (int) ($row->item_id ?? 0),
                    (string) ($row->step_key ?? ''),
                    (int) ($row->step_order ?? 0),
                    $status,
                    $this->nullableString($row->started_at ?? null),
                    $this->nullableString($row->completed_at ?? null),
                    in_array($status, ['compensated', 'compensation_failed'], true)
                        ? $status
                        : null
                );
            }
        }

        return new JobDetails(
            (int) ($job->id ?? 0),
            (string) ($job->job_type ?? ''),
            (string) ($job->owner_module ?? ''),
            (string) ($job->access_policy_key ?? ''),
            (string) ($job->access_policy_version ?? ''),
            (int) ($job->created_by_user_id ?? 0),
            (string) ($job->status ?? ''),
            (int) ($job->total_count ?? 0),
            (int) ($job->succeeded_count ?? 0),
            (int) ($job->failed_count ?? 0),
            (int) ($job->cancelled_count ?? 0),
            (string) ($job->created_at ?? ''),
            $this->nullableString($job->completed_at ?? null),
            $this->jsonObject($job->target_summary ?? null),
            $this->jsonObject($job->result_summary ?? null),
            $items,
            $steps
        );
    }

    public function recent(int $limit = 32): array
    {
        $this->assertDatabase();
        $limit = max(1, min(50, $limit));
        $rows = $this->database->get_results($this->database->prepare(
            'SELECT * FROM background_jobs ORDER BY id DESC LIMIT %d',
            $limit
        ));
        if (! is_array($rows)) {
            return [];
        }
        $jobs = [];
        foreach ($rows as $job) {
            if (! is_object($job)) {
                continue;
            }
            $jobs[] = new JobDetails(
                (int) ($job->id ?? 0),
                (string) ($job->job_type ?? ''),
                (string) ($job->owner_module ?? ''),
                (string) ($job->access_policy_key ?? ''),
                (string) ($job->access_policy_version ?? ''),
                (int) ($job->created_by_user_id ?? 0),
                (string) ($job->status ?? ''),
                (int) ($job->total_count ?? 0),
                (int) ($job->succeeded_count ?? 0),
                (int) ($job->failed_count ?? 0),
                (int) ($job->cancelled_count ?? 0),
                (string) ($job->created_at ?? ''),
                $this->nullableString($job->completed_at ?? null),
                $this->jsonObject($job->target_summary ?? null),
                $this->jsonObject($job->result_summary ?? null),
                [],
                []
            );
        }

        return $jobs;
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonObject(mixed $value): array
    {
        if (! is_string($value) || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function nullableString(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }

    private function assertDatabase(): void
    {
        foreach (['prepare', 'get_row', 'get_results'] as $method) {
            if (! method_exists($this->database, $method)) {
                throw new RuntimeException('WordPress Jobs 조회 DB를 사용할 수 없습니다.');
            }
        }
    }
}
