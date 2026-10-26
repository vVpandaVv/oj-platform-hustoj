<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Migration\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

final class MigrationState
{
    /**
     * @param array<string, scalar|null> $baseline
     * @param array<string, scalar|null> $recoveryEvidence
     * @param array<string, int> $ownerAssignments
     * @param list<string> $completedWaves
     * @param array<string, string> $observationDays
     * @param list<string> $legacyPlugins
     */
    private function __construct(
        private readonly string $status,
        private readonly MigrationWave $wave,
        private readonly MigrationStage $stage,
        private readonly ?string $planHash,
        private readonly ?string $approvedPlanHash,
        private readonly ?int $approvedByUserId,
        private readonly ?string $approvedAt,
        private readonly array $baseline,
        private readonly array $recoveryEvidence,
        private readonly array $ownerAssignments,
        private readonly array $completedWaves,
        private readonly array $observationDays,
        private readonly array $legacyPlugins,
        private readonly ?int $lastJobId,
        private readonly ?string $lastResultCode,
        private readonly ?string $updatedAt
    ) {
        if (! in_array($this->status, [
            'not_started',
            'scanned',
            'planned',
            'queued',
            'running',
            'needs_attention',
            'observing',
            'ready_for_next',
            'completed',
        ], true)) {
            throw new InvalidArgumentException('이전 상태가 올바르지 않습니다.');
        }
        foreach ([$this->planHash, $this->approvedPlanHash] as $hash) {
            if ($hash !== null && preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
                throw new InvalidArgumentException('이전 계획 해시가 올바르지 않습니다.');
            }
        }
        if (($this->approvedByUserId === null) !== ($this->approvedAt === null)) {
            throw new InvalidArgumentException('이전 승인 기록이 완전하지 않습니다.');
        }
    }

    public static function initial(): self
    {
        return new self(
            'not_started',
            MigrationWave::Auth,
            MigrationStage::Baseline,
            null,
            null,
            null,
            null,
            [],
            [],
            [],
            [],
            [],
            [],
            null,
            null,
            null
        );
    }

    /**
     * @param array<string, mixed> $value
     */
    public static function fromArray(array $value): self
    {
        $wave = MigrationWave::tryFrom((string) ($value['wave'] ?? 'auth'))
            ?? MigrationWave::Auth;
        $stage = MigrationStage::tryFrom((int) ($value['stage'] ?? 0))
            ?? MigrationStage::Baseline;

        return new self(
            (string) ($value['status'] ?? 'not_started'),
            $wave,
            $stage,
            self::nullableHash($value['plan_hash'] ?? null),
            self::nullableHash($value['approved_plan_hash'] ?? null),
            self::nullablePositiveInt($value['approved_by_user_id'] ?? null),
            self::nullableText($value['approved_at'] ?? null),
            self::scalarMap($value['baseline'] ?? []),
            self::scalarMap($value['recovery_evidence'] ?? []),
            self::positiveIntMap($value['owner_assignments'] ?? []),
            self::stringList($value['completed_waves'] ?? []),
            self::stringMap($value['observation_days'] ?? []),
            self::stringList($value['legacy_plugins'] ?? []),
            self::nullablePositiveInt($value['last_job_id'] ?? null),
            self::nullableText($value['last_result_code'] ?? null),
            self::nullableText($value['updated_at'] ?? null)
        );
    }

    public function status(): string
    {
        return $this->status;
    }

    public function wave(): MigrationWave
    {
        return $this->wave;
    }

    public function stage(): MigrationStage
    {
        return $this->stage;
    }

    public function planHash(): ?string
    {
        return $this->planHash;
    }

    public function approvedPlanHash(): ?string
    {
        return $this->approvedPlanHash;
    }

    /** @return array<string, scalar|null> */
    public function baseline(): array
    {
        return $this->baseline;
    }

    /** @return array<string, scalar|null> */
    public function recoveryEvidence(): array
    {
        return $this->recoveryEvidence;
    }

    /** @return array<string, int> */
    public function ownerAssignments(): array
    {
        return $this->ownerAssignments;
    }

    /** @return list<string> */
    public function completedWaves(): array
    {
        return $this->completedWaves;
    }

    /** @return array<string, string> */
    public function observationDays(): array
    {
        return $this->observationDays;
    }

    /** @return list<string> */
    public function legacyPlugins(): array
    {
        return $this->legacyPlugins;
    }

    public function lastJobId(): ?int
    {
        return $this->lastJobId;
    }

    public function lastResultCode(): ?string
    {
        return $this->lastResultCode;
    }

    public function hasRecoveryEvidence(): bool
    {
        foreach ([
            'backup_ref',
            'backup_hash',
            'restore_ref',
            'restore_hash',
            'verified_at',
        ] as $key) {
            if (! is_string($this->recoveryEvidence[$key] ?? null)
                || trim((string) $this->recoveryEvidence[$key]) === '') {
                return false;
            }
        }

        return (int) ($this->recoveryEvidence['restore_duration_seconds'] ?? 0) > 0;
    }

    public function isApprovedFor(MigrationPlan $plan): bool
    {
        return $this->approvedPlanHash !== null
            && hash_equals($this->approvedPlanHash, $plan->hash());
    }

    public function observationDayCount(): int
    {
        return count($this->observationDays);
    }

    public function withScan(MigrationScan $scan, DateTimeImmutable $now): self
    {
        if (in_array($this->status, ['queued', 'running', 'completed'], true)) {
            throw new InvalidArgumentException('실행 중이거나 완료된 이전은 다시 검사할 수 없습니다.');
        }
        $summary = $scan->safeSummary();
        $baseline = [
            'database_fingerprint' => $scan->databaseFingerprint(),
            'report_hash' => hash(
                'sha256',
                (string) json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            ),
            'table_count' => count($scan->tables()),
            'blocker_count' => count($scan->blockers()),
            'warning_count' => count($scan->warnings()),
            'blockers_json' => (string) json_encode(
                $scan->blockers(),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ),
            'warnings_json' => (string) json_encode(
                $scan->warnings(),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ),
            'scanned_at' => $now->format(DATE_ATOM),
        ];
        $started = $this->stage->value > MigrationStage::RecoveryRehearsal->value;

        return $this->copy(
            $scan->canPlan()
                ? ($started ? $this->status : 'scanned')
                : 'needs_attention',
            $started ? $this->stage : MigrationStage::Baseline,
            null,
            null,
            null,
            null,
            $baseline,
            $this->recoveryEvidence,
            $this->ownerAssignments,
            $this->completedWaves,
            $started ? $this->observationDays : [],
            $scan->legacyPlugins(),
            null,
            $scan->canPlan() && $started
                ? $this->lastResultCode
                : ($scan->canPlan() ? 'migration.scan_ready' : 'migration.scan_blocked'),
            $now->format(DATE_ATOM)
        );
    }

    /**
     * @param array<string, scalar|null> $evidence
     * @param array<string, int> $ownerAssignments
     */
    public function withPreparation(
        array $evidence,
        array $ownerAssignments,
        DateTimeImmutable $now
    ): self {
        if ($this->stage->value > MigrationStage::RecoveryRehearsal->value
            || in_array($this->status, ['queued', 'running', 'completed'], true)) {
            throw new InvalidArgumentException('데이터 파동을 시작한 뒤에는 준비 증거를 변경할 수 없습니다.');
        }

        return $this->copy(
            'planned',
            MigrationStage::RecoveryRehearsal,
            null,
            null,
            null,
            null,
            $this->baseline,
            $evidence,
            $ownerAssignments,
            $this->completedWaves,
            [],
            $this->legacyPlugins,
            null,
            'migration.evidence_recorded',
            $now->format(DATE_ATOM)
        );
    }

    public function withPlan(MigrationPlan $plan, DateTimeImmutable $now): self
    {
        return $this->copy(
            'planned',
            $plan->stage(),
            $plan->hash(),
            null,
            null,
            null,
            $this->baseline,
            $this->recoveryEvidence,
            $this->ownerAssignments,
            $this->completedWaves,
            $this->observationDays,
            $this->legacyPlugins,
            null,
            'migration.plan_created',
            $now->format(DATE_ATOM),
            $plan->wave()
        );
    }

    public function withApproval(MigrationPlan $plan, int $userId, DateTimeImmutable $now): self
    {
        if ($this->status !== 'planned'
            || $this->planHash === null
            || ! hash_equals($this->planHash, $plan->hash())) {
            throw new InvalidArgumentException('현재 화면에서 만든 이전 계획만 승인할 수 있습니다.');
        }

        return $this->copy(
            'running',
            $plan->stage(),
            $plan->hash(),
            $plan->hash(),
            $userId,
            $now->format(DATE_ATOM),
            $this->baseline,
            $this->recoveryEvidence,
            $this->ownerAssignments,
            $this->completedWaves,
            $this->observationDays,
            $this->legacyPlugins,
            null,
            'migration.plan_approved',
            $now->format(DATE_ATOM),
            $plan->wave()
        );
    }

    public function withQueuedJob(
        MigrationPlan $plan,
        int $jobId,
        DateTimeImmutable $now
    ): self {
        if (! $this->isApprovedFor($plan)) {
            throw new InvalidArgumentException('승인한 이전 계획과 Jobs 계획이 다릅니다.');
        }
        if ($this->lastResultCode !== 'migration.plan_approved') {
            return $this->copy(
                $this->status,
                $this->stage,
                $this->planHash,
                $this->approvedPlanHash,
                $this->approvedByUserId,
                $this->approvedAt,
                $this->baseline,
                $this->recoveryEvidence,
                $this->ownerAssignments,
                $this->completedWaves,
                $this->observationDays,
                $this->legacyPlugins,
                $jobId,
                $this->lastResultCode,
                $now->format(DATE_ATOM),
                $this->wave
            );
        }

        return $this->copy(
            'queued',
            $plan->stage(),
            $plan->hash(),
            $this->approvedPlanHash,
            $this->approvedByUserId,
            $this->approvedAt,
            $this->baseline,
            $this->recoveryEvidence,
            $this->ownerAssignments,
            $this->completedWaves,
            $this->observationDays,
            $this->legacyPlugins,
            $jobId,
            'migration.job_queued',
            $now->format(DATE_ATOM),
            $plan->wave()
        );
    }

    public function withWorkResult(
        MigrationStage $stage,
        MigrationWorkResult $result,
        DateTimeImmutable $now
    ): self {
        $status = $result->needsAttention() ? 'needs_attention' : 'planned';
        $observation = $this->observationDays;
        $completed = $this->completedWaves;

        if (! $result->needsAttention() && $stage === MigrationStage::Observation) {
            $day = $now->format('Y-m-d');
            $observation[$day] = $result->resultCode();
            ksort($observation, SORT_STRING);
            $status = count($observation) >= 7 ? 'ready_for_next' : 'observing';
            if ($status === 'ready_for_next'
                && ! in_array($this->wave->value, $completed, true)) {
                $completed[] = $this->wave->value;
            }
        }

        return $this->copy(
            $status,
            $stage,
            $this->planHash,

            $this->approvedPlanHash,
            $this->approvedByUserId,
            $this->approvedAt,
            $this->baseline,
            $this->recoveryEvidence,
            $this->ownerAssignments,
            $completed,
            $observation,
            $this->legacyPlugins,
            $this->lastJobId,
            $result->resultCode(),
            $now->format(DATE_ATOM)
        );
    }

    public function beginNextWave(DateTimeImmutable $now): self
    {
        if ($this->status !== 'ready_for_next') {
            throw new InvalidArgumentException('현재 파동의 7일 관찰을 먼저 완료해야 합니다.');
        }
        $next = $this->wave->next();
        if ($next === null) {
            return $this->copy(
                'planned',
                MigrationStage::JudgeCompatibility,
                null,
                null,
                null,
                null,
                $this->baseline,
                $this->recoveryEvidence,
                $this->ownerAssignments,
                $this->completedWaves,
                [],
                $this->legacyPlugins,
                null,
                'migration.all_waves_observed',
                $now->format(DATE_ATOM)
            );
        }

        return $this->copy(
            'planned',
            MigrationStage::TargetSchema,
            null,
            null,
            null,
            null,
            $this->baseline,
            $this->recoveryEvidence,
            $this->ownerAssignments,
            $this->completedWaves,
            [],
            $this->legacyPlugins,
            null,
            'migration.next_wave_ready',
            $now->format(DATE_ATOM),
            $next
        );
    }

    public function markCompleted(
        MigrationWorkResult $result,
        DateTimeImmutable $now
    ): self {
        if ($result->needsAttention()) {
            throw new InvalidArgumentException('Legacy plugin cleanup is not complete.');
        }

        return $this->copy(
            'completed',
            MigrationStage::Cleanup,
            $this->planHash,
            $this->approvedPlanHash,
            $this->approvedByUserId,
            $this->approvedAt,
            $this->baseline,
            $this->recoveryEvidence,
            $this->ownerAssignments,
            $this->completedWaves,
            $this->observationDays,
            [],
            $this->lastJobId,
            $result->resultCode(),
            $now->format(DATE_ATOM)
        );
    }

    public function beginLegacyPluginDeactivation(DateTimeImmutable $now): self
    {
        if ($this->stage !== MigrationStage::Cleanup
            || count($this->completedWaves) !== 7
            || ! in_array($this->status, ['planned', 'needs_attention'], true)
            || ! in_array($this->lastResultCode, [
                'migration.cleanup_report_ready',
                'migration.legacy_plugin_deactivation_failed',
                'migration.legacy_plugins_still_active',
            ], true)) {
            throw new InvalidArgumentException('관찰, Judge 호환 검증과 정리 보고서를 먼저 완료해 주세요.');
        }

        return $this->copy(
            'running',
            MigrationStage::Cleanup,
            $this->planHash,
            $this->approvedPlanHash,
            $this->approvedByUserId,
            $this->approvedAt,
            $this->baseline,
            $this->recoveryEvidence,
            $this->ownerAssignments,
            $this->completedWaves,
            $this->observationDays,
            $this->legacyPlugins,
            $this->lastJobId,
            'migration.legacy_plugin_deactivation_started',
            $now->format(DATE_ATOM)
        );
    }

    public function withLegacyPluginDeactivationResult(
        MigrationWorkResult $result,
        DateTimeImmutable $now
    ): self {
        if ($this->stage !== MigrationStage::Cleanup
            || $this->lastResultCode !== 'migration.legacy_plugin_deactivation_started') {
            throw new InvalidArgumentException('기존 플러그인 비활성화 실행 상태가 아닙니다.');
        }
        if (! $result->needsAttention()) {
            return $this->markCompleted($result, $now);
        }

        return $this->copy(
            'needs_attention',
            MigrationStage::Cleanup,
            $this->planHash,
            $this->approvedPlanHash,
            $this->approvedByUserId,
            $this->approvedAt,
            $this->baseline,
            $this->recoveryEvidence,
            $this->ownerAssignments,
            $this->completedWaves,
            $this->observationDays,
            $this->legacyPlugins,
            $this->lastJobId,
            $result->resultCode(),
            $now->format(DATE_ATOM)
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'contract_version' => '1',
            'status' => $this->status,
            'wave' => $this->wave->value,
            'stage' => $this->stage->value,
            'plan_hash' => $this->planHash,
            'approved_plan_hash' => $this->approvedPlanHash,
            'approved_by_user_id' => $this->approvedByUserId,
            'approved_at' => $this->approvedAt,
            'baseline' => $this->baseline,
            'recovery_evidence' => $this->recoveryEvidence,
            'owner_assignments' => $this->ownerAssignments,
            'completed_waves' => $this->completedWaves,
            'observation_days' => $this->observationDays,
            'legacy_plugins' => $this->legacyPlugins,
            'last_job_id' => $this->lastJobId,
            'last_result_code' => $this->lastResultCode,
            'updated_at' => $this->updatedAt,
        ];
    }

    /**
     * @param array<string, scalar|null> $baseline
     * @param array<string, scalar|null> $recoveryEvidence
     * @param array<string, int> $ownerAssignments
     * @param list<string> $completedWaves
     * @param array<string, string> $observationDays
     * @param list<string> $legacyPlugins
     */
    private function copy(
        string $status,
        MigrationStage $stage,
        ?string $planHash,
        ?string $approvedPlanHash,
        ?int $approvedByUserId,
        ?string $approvedAt,
        array $baseline,
        array $recoveryEvidence,
        array $ownerAssignments,
        array $completedWaves,
        array $observationDays,
        array $legacyPlugins,
        ?int $lastJobId,
        ?string $lastResultCode,
        ?string $updatedAt,
        ?MigrationWave $wave = null
    ): self {
        return new self(
            $status,
            $wave ?? $this->wave,
            $stage,
            $planHash,
            $approvedPlanHash,
            $approvedByUserId,
            $approvedAt,
            $baseline,
            $recoveryEvidence,
            $ownerAssignments,
            array_values(array_unique($completedWaves)),
            $observationDays,
            array_values(array_unique($legacyPlugins)),
            $lastJobId,
            $lastResultCode,
            $updatedAt
        );
    }

    private static function nullableHash(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/', $value) === 1
            ? $value
            : null;
    }

    private static function nullablePositiveInt(mixed $value): ?int
    {
        $number = is_int($value) || (is_string($value) && ctype_digit($value))
            ? (int) $value
            : 0;

        return $number > 0 ? $number : null;
    }

    private static function nullableText(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** @return array<string, scalar|null> */
    private static function scalarMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && (is_scalar($item) || $item === null)) {
                $result[$key] = $item;
            }
        }

        return $result;
    }

    /** @return array<string, int> */
    private static function positiveIntMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }
        $result = [];
        foreach ($value as $key => $item) {
            $number = is_int($item) || (is_string($item) && ctype_digit($item))
                ? (int) $item
                : 0;
            if (is_string($key) && $number > 0) {
                $result[$key] = $number;
            }
        }

        return $result;
    }

    /** @return list<string> */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $item): string => is_string($item) ? trim($item) : '', $value),
            static fn (string $item): bool => $item !== ''
        )));
    }

    /** @return array<string, string> */
    private static function stringMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && is_string($item) && trim($item) !== '') {
                $result[$key] = trim($item);
            }
        }

        return $result;
    }
}
