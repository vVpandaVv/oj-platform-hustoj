<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Migration\Application;

use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobDispatcher;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobEnqueueResult;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobTarget;
use CodingPanda\OjPlatform\Modules\Migration\Contract\LegacyMigrationGateway;
use CodingPanda\OjPlatform\Modules\Migration\Domain\MigrationPlan;
use CodingPanda\OjPlatform\Modules\Migration\Domain\MigrationScan;
use CodingPanda\OjPlatform\Modules\Migration\Domain\MigrationStage;
use CodingPanda\OjPlatform\Modules\Migration\Domain\MigrationState;
use CodingPanda\OjPlatform\Modules\Migration\Domain\MigrationWorkResult;
use CodingPanda\OjPlatform\Modules\Settings\Contract\ModuleWriteFreezeManager;
use CodingPanda\OjPlatform\Shared\Clock\Clock;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use JsonException;
use Throwable;

final class MigrationCommandService
{
    public function __construct(
        private readonly MigrationStateService $states,
        private readonly MigrationPlanService $plans,
        private readonly MigrationCatalog $catalog,
        private readonly LegacyMigrationGateway $gateway,
        private readonly JobDispatcher $jobs,
        private readonly ModuleWriteFreezeManager $freezes,
        private readonly Clock $clock,
        private readonly ?Clock $siteClock = null
    ) {
    }

    public function scan(int $expectedRevision): MigrationScan
    {
        $scan = $this->gateway->scan($this->catalog);
        $this->states->update(
            fn (MigrationState $state): MigrationState => $state->withScan(
                $scan,
                $this->clock->now()
            ),
            $expectedRevision
        );

        return $scan;
    }

    /**
     * @param array<string, mixed> $input
     */
    public function savePreparation(array $input, int $expectedRevision): MigrationState
    {
        $evidence = $this->evidence($input);
        $owners = $this->ownerAssignments((string) ($input['owner_assignments'] ?? ''));

        return $this->states->update(
            fn (MigrationState $state): MigrationState => $state->withPreparation(
                $evidence,
                $owners,
                $this->clock->now()
            ),
            $expectedRevision
        );
    }

    public function dryRun(
        int $expectedRevision,
        ?MigrationStage $requestedStage = null
    ): MigrationPlan {
        $plan = null;
        $this->states->update(
            function (MigrationState $state) use (&$plan, $requestedStage): MigrationState {
                if ($state->status() === 'ready_for_next') {
                    $state = $state->beginNextWave($this->clock->now());
                }
                $plan = $this->plans->create($state, $requestedStage);

                return $state->withPlan($plan, $this->clock->now());
            },
            $expectedRevision
        );
        if (! $plan instanceof MigrationPlan) {
            throw new DomainException('이전 계획을 만들지 못했습니다.');
        }

        return $plan;
    }

    public function enqueue(
        int $actorUserId,
        string $planHash,
        string $confirmation,
        int $expectedRevision
    ): JobEnqueueResult {
        if ($actorUserId < 1 || trim($confirmation) !== '이전 실행') {
            throw new DomainException('확인란에 이전 실행을 정확히 입력해 주세요.');
        }

        $plan = null;
        $approved = $this->states->update(
            function (MigrationState $state) use (&$plan, $planHash, $actorUserId): MigrationState {
                $plan = $this->plans->create($state, $state->stage());
                if (! hash_equals($plan->hash(), strtolower(trim($planHash)))) {
                    throw new DomainException('현재 계획 해시와 승인한 계획 해시가 다릅니다.');
                }

                return $state->withApproval($plan, $actorUserId, $this->clock->now());
            },
            $expectedRevision
        );
        if (! $plan instanceof MigrationPlan || ! $approved->isApprovedFor($plan)) {
            throw new DomainException('이전 계획 승인을 확인하지 못했습니다.');
        }

        try {
            if ($plan->stage() === MigrationStage::FinalCopy) {
                $this->freezes->acquireMigrationFreeze(
                    $plan->wave()->ownerModule(),
                    $plan->freezeKey()
                );
            }

            $result = $this->jobs->enqueue(
                'platform_migration',
                'migration',
                $actorUserId,
                [
                    new JobTarget(
                        'migration_wave',
                        $plan->wave()->value . ':' . $plan->stage()->value,
                        ['plan' => $plan->canonical()],
                        $plan->hash()
                    ),
                ],
                [
                    'plan_hash' => $plan->hash(),
                    'stage' => $plan->stage()->value,
                ],
                [
                    'wave' => $plan->wave()->value,
                    'stage' => $plan->stage()->value,
                    'plan_hash' => $plan->hash(),
                ],
                'migration.execute'
            );

            $this->states->update(
                fn (MigrationState $state): MigrationState => $state->withQueuedJob(
                    $plan,
                    $result->jobId(),
                    $this->clock->now()
                )
            );
        } catch (Throwable $error) {
            $failure = new MigrationWorkResult(
                'migration.dispatch_failed',
                '이전 Jobs 등록 또는 쓰기 동결을 완료하지 못했습니다.',
                ['plan_hash' => $plan->hash()],
                ['retryable' => true],
                true
            );
            $this->states->update(
                function (MigrationState $state) use ($plan, $failure): MigrationState {
                    if (! $state->isApprovedFor($plan)) {
                        return $state;
                    }
                    if ($state->lastResultCode() !== 'migration.plan_approved') {
                        return $state;
                    }

                    return $state->withWorkResult(
                        $plan->stage(),
                        $failure,
                        $this->clock->now()
                    );
                }
            );
            throw $error;
        }

        return $result;
    }

    public function recordResult(
        MigrationPlan $plan,
        MigrationWorkResult $result
    ): MigrationState {
        if (! $result->needsAttention() && $plan->stage() === MigrationStage::Resume) {
            $this->states->update(function (MigrationState $state) use ($plan): MigrationState {
                if (! $state->isApprovedFor($plan)) {
                    throw new DomainException('작업 중 현재 승인 계획이 변경되었습니다.');
                }

                return $state;
            });
            try {
                $this->freezes->releaseMigrationFreeze(
                    $plan->wave()->ownerModule(),
                    $plan->freezeKey()
                );
            } catch (Throwable $error) {
                $failure = new MigrationWorkResult(
                    'migration.freeze_release_failed',
                    '검증 뒤 모듈 쓰기 동결을 해제하지 못했습니다.',
                    [],
                    ['module' => $plan->wave()->ownerModule()],
                    true
                );
                $this->states->update(
                    fn (MigrationState $state): MigrationState => $state->withWorkResult(
                        $plan->stage(),
                        $failure,
                        $this->clock->now()
                    )
                );
                throw new DomainException($failure->message(), 0, $error);
            }
        }

        return $this->states->update(
            function (MigrationState $state) use ($plan, $result): MigrationState {
                if (! $state->isApprovedFor($plan)) {
                    throw new DomainException('작업 중 현재 승인 계획이 변경되었습니다.');
                }

                return $state->withWorkResult(
                    $plan->stage(),
                    $result,
                    $this->clock->now()
                );
            }
        );
    }

    public function deactivateLegacyPlugins(
        int $actorUserId,
        string $confirmation,
        int $expectedRevision
    ): MigrationWorkResult {
        if ($actorUserId < 1 || trim($confirmation) !== '기존 플러그인 정리') {
            throw new DomainException('확인란에 기존 플러그인 정리를 정확히 입력해 주세요.');
        }
        $claimed = $this->states->update(
            fn (MigrationState $state): MigrationState =>
                $state->beginLegacyPluginDeactivation($this->clock->now()),
            $expectedRevision
        );

        try {
            $result = $this->gateway->deactivateLegacyPlugins($claimed->legacyPlugins());
        } catch (Throwable $error) {
            $failure = new MigrationWorkResult(
                'migration.legacy_plugin_deactivation_failed',
                '기존 플러그인 비활성화를 완료하지 못했습니다.',
                [],
                ['completed' => false],
                true
            );
            try {
                $this->states->update(
                    fn (MigrationState $state): MigrationState =>
                        $state->withLegacyPluginDeactivationResult(
                            $failure,
                            $this->clock->now()
                        )
                );
            } catch (Throwable $stateError) {
                unset($stateError);
                throw new DomainException(
                    '플러그인 비활성화 실패 상태도 기록하지 못했습니다.',
                    0,
                    $error
                );
            }
            throw $error;
        }

        $this->states->update(
            fn (MigrationState $state): MigrationState =>
                $state->withLegacyPluginDeactivationResult(
                    $result,
                    $this->clock->now()
                )
        );

        return $result;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, scalar|null>
     */
    private function evidence(array $input): array
    {
        $evidence = [
            'backup_ref' => $this->reference($input['backup_ref'] ?? null),
            'backup_hash' => $this->hash($input['backup_hash'] ?? null),
            'restore_ref' => $this->reference($input['restore_ref'] ?? null),
            'restore_hash' => $this->hash($input['restore_hash'] ?? null),
            'restore_duration_seconds' => max(0, (int) ($input['restore_duration_seconds'] ?? 0)),
            'verified_at' => trim((string) ($input['verified_at'] ?? '')),
        ];
        if ($evidence['restore_duration_seconds'] < 1) {
            throw new DomainException('분리 환경의 실제 복구 소요 시간을 초 단위로 입력해 주세요.');
        }

        try {
            $verifiedAt = new DateTimeImmutable(
                (string) $evidence['verified_at'],
                ($this->siteClock ?? $this->clock)->now()->getTimezone()
            );
        } catch (\Throwable $error) {
            throw new DomainException('복구 검증 시각이 올바르지 않습니다.', 0, $error);
        }
        $now = $this->clock->now();
        if ($verifiedAt > $now || $verifiedAt < $now->modify('-30 days')) {
            throw new DomainException('최근 30일 안에 완료한 복구 리허설 증거가 필요합니다.');
        }
        $evidence['verified_at'] = $verifiedAt
            ->setTimezone(new DateTimeZone('UTC'))
            ->format(DATE_ATOM);

        return $evidence;
    }

    private function reference(mixed $value): string
    {
        $value = is_string($value) ? trim($value) : '';
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/-]{2,190}$/', $value) !== 1) {
            throw new DomainException('백업과 복구 증거 참조는 안전한 식별자로 입력해 주세요.');
        }

        return $value;
    }

    private function hash(mixed $value): string
    {
        $value = is_string($value) ? strtolower(trim($value)) : '';
        if (preg_match('/^[a-f0-9]{64}$/', $value) !== 1) {
            throw new DomainException('증거 해시는 SHA-256 형식이어야 합니다.');
        }

        return $value;
    }

    /** @return array<string, int> */
    private function ownerAssignments(string $json): array
    {
        if (trim($json) === '') {
            return [];
        }
        try {
            $value = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new DomainException('담당자 배정 JSON이 올바르지 않습니다.', 0, $error);
        }
        if (! is_array($value)) {
            throw new DomainException('담당자 배정은 JSON 객체여야 합니다.');
        }

        $owners = [];
        foreach ($value as $key => $userId) {
            $number = is_int($userId) || (is_string($userId) && ctype_digit($userId))
                ? (int) $userId
                : 0;
            if (! is_string($key)
                || preg_match('/^(problem|class|contest|group):[1-9][0-9]*$/', $key) !== 1
                || $number < 1) {
                throw new DomainException('담당자 배정은 problem:번호, class:번호, contest:번호, group:번호 형식을 사용합니다.');
            }
            $owners[$key] = $number;
        }
        ksort($owners, SORT_STRING);

        return $owners;
    }
}
