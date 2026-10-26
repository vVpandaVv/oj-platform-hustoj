<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Setup;

use CodingPanda\OjPlatform\Kernel\Registry\DefinitionRegistry;
use CodingPanda\OjPlatform\Modules\Migration\Contract\LegacyInstallationPresence;
use CodingPanda\OjPlatform\Modules\PlatformDb\Application\InstallationPlanner;
use CodingPanda\OjPlatform\Modules\PlatformDb\Application\SchemaInstaller;
use CodingPanda\OjPlatform\Modules\PlatformDb\Contract\PlatformConnection;
use CodingPanda\OjPlatform\Modules\PlatformDb\Contract\SchemaTableDefinition;
use CodingPanda\OjPlatform\Modules\PlatformDb\Contract\TargetTableDefinition;
use CodingPanda\OjPlatform\Modules\Permission\Application\PermissionInstaller;
use CodingPanda\OjPlatform\Modules\Settings\Application\SettingsService;
use RuntimeException;
use Throwable;

final class SetupCoordinator
{
    /**
     * @param DefinitionRegistry<TargetTableDefinition> $targets
     * @param DefinitionRegistry<SchemaTableDefinition> $schemas
     */
    public function __construct(
        private readonly DefinitionRegistry $targets,
        private readonly DefinitionRegistry $schemas,
        private readonly PlatformConnection $connection,
        private readonly InstallationPlanner $planner,
        private readonly SchemaInstaller $installer,
        private readonly PermissionInstaller $permissionInstaller,
        private readonly SettingsService $settings,
        private readonly InstallationIdFactory $installationIdFactory,
        private readonly SetupLock $lock,
        private readonly LegacyInstallationPresence $legacyPresence,
        private readonly SetupReadinessProbe $readiness,
        private readonly array $additiveModules = [],
        private readonly ?SetupInspectionCache $inspectionCache = null
    ) {
    }

    /** Only successful runtime inspections are cached; setup operations always inspect live metadata. */
    public function inspectRuntime(): SetupSnapshot
    {
        if ($this->inspectionCache === null || ! $this->connection->isAvailable()) {
            return $this->inspect();
        }
        $id = $this->installationId();
        $state = $this->state();
        $migration = $this->migrationStatus();
        if ($id === null || (($state['status'] ?? '') !== 'complete'
            && !(($state['status'] ?? '') === 'migration_ready' && $migration === 'completed'))) {
            return $this->inspect();
        }
        $fingerprint = hash('sha256', serialize([
            $id, $state, $migration, $this->connection->databaseName(), $this->targets->all(),
            array_map(static fn (SchemaTableDefinition $schema): string => $schema->fingerprint(), $this->schemas->all()),
            $this->additiveModules,
        ]));
        // Environment readiness remains live even when the structural inspection is cached.
        if ($this->readiness->blockers() === []) {
            $cached = $this->inspectionCache->read($fingerprint);
            if ($cached !== null && $cached->installationId() === $id
                && $cached->mode() === SetupMode::AlreadyInstalled && $cached->isRuntimeReady()) {
                return $cached;
            }
        }
        $snapshot = $this->inspect();
        $this->inspectionCache->write($fingerprint, $snapshot);
        return $snapshot;
    }

    public function inspect(): SetupSnapshot
    {
        $this->inspectionCache?->clear();
        $legacyReport = $this->legacyPresence->inspect();
        $installationId = $this->installationId();
        $state = $this->state();
        $targetNames = array_keys($this->targets->all());
        $tableStates = $this->connection->inspectTables($targetNames);
        $existing = array_keys($tableStates);
        sort($existing);

        $allCollisions = [];
        $upgrades = [];
        $pending = array_values(array_diff($targetNames, array_keys($this->schemas->all())));
        sort($pending);

        if ($installationId !== null) {
            $plan = $this->planner->plan($installationId, null, $tableStates);
            $allCollisions = $plan->collisions();
            $pending = $plan->missingSchemas();
            $upgrades = $plan->upgradeTables();
        } elseif ($existing !== []) {
            $allCollisions = $existing;
        }

        $collisions = array_values(array_diff(
            $allCollisions,
            $legacyReport->recognizedTargetTables()
        ));
        sort($collisions);

        $status = (string) ($state['status'] ?? 'not_started');
        $legacyCandidate = (bool) ($state['legacy_candidate'] ?? false)
            || $legacyReport->isCandidate();
        $installationComplete = $collisions === []
            && $pending === []
            && $upgrades === []
            && count($existing) === count($targetNames);
        // Legacy source tables (solution/problem/users, ...) are a permanent
        // part of every real deployment — they never "go away" once adopted,
        // so $legacyCandidate stays true forever. If every target table is
        // already physically present (nothing missing) and this site had
        // already reached a completed install/migration once, a later
        // routine schema upgrade (new column, changed default, ...) on an
        // existing table must not re-trigger the full backup-evidence/
        // observation migration ceremony — it only needs the plain Resume
        // flow that re-runs pending schema steps. A migration that's still
        // missing most of its target tables (a genuinely incomplete or
        // interrupted migration) must still go through MigrationTarget.
        $allTargetTablesPresent = $pending === []
            && count($existing) === count($targetNames);
        $missingPhysical = array_values(array_diff($targetNames, $existing));
        $onlyAdditiveMissing = $missingPhysical !== [];
        foreach ($missingPhysical as $name) {
            $target = $this->targets->find($name);
            if (! $target instanceof TargetTableDefinition || ! in_array($target->owner()->value(), $this->additiveModules, true)) {
                $onlyAdditiveMissing = false; break;
            }
        }
        $onlyNeedsAdditiveInstall = $onlyAdditiveMissing && $pending === [] && $collisions === []
            && ($status === 'complete' || ($status === 'migration_ready' && $this->migrationStatus() === 'completed'));
        $onlyNeedsRoutineUpgrade = $allTargetTablesPresent
            && $collisions === []
            && $upgrades !== []
            && (
                $status === 'complete'
                || ($status === 'migration_ready' && $this->migrationStatus() === 'completed')
            );
        // markComplete() is only ever called from the plain (non-legacy)
        // install path, so completed_at surviving in the stored state proves
        // this site genuinely finished a full, ordinary installation at some
        // point — independent of whatever $status says right now. A stray
        // later re-run of the legacy branch (e.g. an admin clicking the only
        // action offered while incorrectly parked in MigrationTarget mode)
        // can overwrite status back to 'migration_ready' without touching
        // completed_at, since saveState() merges rather than replaces. Once
        // every target table is present and correct again, that must resolve
        // back to normal operation — not loop back into the legacy ceremony.
        $hasCompletedInstallBefore = isset($state['completed_at']);
        $mode = match (true) {
            $status === 'complete' && $installationComplete => SetupMode::AlreadyInstalled,
            $status === 'migration_ready'
                && $this->migrationStatus() === 'completed'
                && $installationComplete =>
                SetupMode::AlreadyInstalled,
            $collisions !== [] => SetupMode::NameCollision,
            $hasCompletedInstallBefore && $installationComplete => SetupMode::AlreadyInstalled,
            $onlyNeedsRoutineUpgrade || $onlyNeedsAdditiveInstall || $hasCompletedInstallBefore => SetupMode::Resume,
            $legacyCandidate || $status === 'migration_ready' => SetupMode::MigrationTarget,
            $installationId !== null || $status !== 'not_started' => SetupMode::Resume,
            default => SetupMode::FreshInstall,
        };

        return new SetupSnapshot(
            $mode,
            $installationId,
            $status,
            $existing,
            $collisions,
            $pending,
            $upgrades,
            $this->targets->count(),
            $this->schemas->count(),
            isset($state['plan_hash']) ? (string) $state['plan_hash'] : null,
            $legacyReport->legacyTables(),
            $this->readiness->blockers(),
            $status === 'interrupted' && is_string($state['error_message'] ?? null)
                ? trim((string) $state['error_message'])
                : null
        );
    }

    public function run(): SetupRunResult
    {
        $before = $this->inspect();
        if (! $before->allowsSetupExecution()) {
            throw new RuntimeException('현재 설치 상태에서는 실행할 수 없습니다.');
        }
        if (! $before->isRuntimeReady()) {
            throw new RuntimeException(
                '설치 환경을 먼저 준비해 주세요: '
                . implode(' ', $before->readinessBlockers())
            );
        }

        $lockToken = bin2hex(random_bytes(16));
        if (! $this->lock->acquire($lockToken, 300)) {
            throw new RuntimeException('다른 설치 작업이 진행 중입니다.');
        }

        try {
            $installationId = $before->installationId() ?? $this->createInstallationId();
            if ($before->mode() === SetupMode::MigrationTarget) {
                return $this->prepareMigration($installationId);
            }

            $plan = $this->planner->plan($installationId);
            if (! $plan->canExecute()) {
                throw new RuntimeException(sprintf(
                    '동명 테이블 충돌을 먼저 해결해야 합니다: %s',
                    implode(', ', $plan->collisions())
                ));
            }

            $this->saveState([
                'status' => 'running',
                'plan_hash' => $plan->hash(),
                'pending_tables' => $plan->missingSchemas(),
                'pending_upgrades' => $plan->upgradeTables(),
                'error_code' => null,
                'error_message' => null,
                'updated_at' => gmdate('c'),
            ]);

            $result = $this->installer->execute($plan);
            $roleReport = $this->permissionInstaller->synchronizeRoles();
            $status = $result->isComplete() ? 'waiting_for_runtime' : 'waiting_for_modules';

            $this->saveState([
                'status' => $status,
                'plan_hash' => $result->planHash(),
                'created_tables' => $result->createdTables(),
                'updated_tables' => $result->updatedTables(),
                'pending_tables' => $result->pendingTables(),
                'pending_upgrades' => [],
                'roles_synchronized' => true,
                'created_roles' => $roleReport->createdRoles(),
                'updated_roles' => $roleReport->updatedRoles(),
                'updated_at' => gmdate('c'),
            ]);
            if ($result->isComplete()) {
                $this->markComplete();
            }

            return new SetupRunResult(
                $this->inspect(),
                $result->createdTables(),
                $result->updatedTables(),
                $result->pendingTables()
            );
        } catch (Throwable $error) {
            $this->saveFailureState($error);

            throw $error;
        } finally {
            $this->lock->release($lockToken);
        }
    }

    /**
     * 역할·경로·최초 관리자 검증을 통과한 마지막 설치 단계에서 호출한다.
     */
    public function markComplete(): void
    {
        $snapshot = $this->inspect();
        if (
            $snapshot->collisions() !== []
            || $snapshot->pendingSchemas() !== []
            || $snapshot->pendingUpgrades() !== []
            || count($snapshot->existingTables()) !== $snapshot->targetTableCount()
            || ($this->state()['roles_synchronized'] ?? false) !== true
            || ! $snapshot->isRuntimeReady()
        ) {
            throw new RuntimeException('테이블 준비가 끝나기 전에는 설치를 완료할 수 없습니다.');
        }

        $this->saveState([
            'status' => 'complete',
            'plan_hash' => $snapshot->planHash(),
            'completed_at' => gmdate('c'),
            'error_code' => null,
            'error_message' => null,
            'updated_at' => gmdate('c'),
        ]);
    }

    private function prepareMigration(string $installationId): SetupRunResult
    {
        $plan = $this->planner->plan($installationId, ['jobs', 'auth']);
        if (! $plan->canExecute()) {
            throw new RuntimeException(sprintf(
                '이전 기반 테이블 충돌을 먼저 해결해야 합니다: %s',
                implode(', ', $plan->collisions())
            ));
        }

        $this->saveState([
            'status' => 'running',
            'legacy_candidate' => true,
            'plan_hash' => $plan->hash(),
            'pending_tables' => $plan->missingSchemas(),
            'pending_upgrades' => $plan->upgradeTables(),
            'error_code' => null,
            'error_message' => null,
            'updated_at' => gmdate('c'),
        ]);

        $result = $this->installer->execute($plan);
        if (! $result->isComplete()) {
            throw new RuntimeException('이전 화면 실행에 필요한 기반 스키마가 준비되지 않았습니다.');
        }

        $roleReport = $this->permissionInstaller->synchronizeRoles();
        $targetNames = array_keys($this->targets->all());
        $existing = array_keys($this->connection->inspectTables($targetNames));
        $pending = array_values(array_diff($targetNames, $existing));
        sort($pending);

        $this->saveState([
            'status' => 'migration_ready',
            'legacy_candidate' => true,
            'plan_hash' => $result->planHash(),
            'created_tables' => $result->createdTables(),
            'updated_tables' => $result->updatedTables(),
            'pending_tables' => $pending,
            'pending_upgrades' => [],
            'roles_synchronized' => true,
            'created_roles' => $roleReport->createdRoles(),
            'updated_roles' => $roleReport->updatedRoles(),
            'updated_at' => gmdate('c'),
        ]);

        return new SetupRunResult(
            $this->inspect(),
            $result->createdTables(),
            $result->updatedTables(),
            $pending
        );
    }

    private function migrationStatus(): string
    {
        $value = $this->settings->read('migration', [])->value();

        return is_array($value)
            ? (string) ($value['status'] ?? 'not_started')
            : 'not_started';
    }

    private function installationId(): ?string
    {
        $value = $this->settings->read('installation_id')->value();

        return is_string($value) && preg_match('/^[a-f0-9]{32}$/', $value) === 1
            ? $value
            : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function state(): array
    {
        $value = $this->settings->read('install_state', [])->value();

        return is_array($value) ? $value : [];
    }

    private function createInstallationId(): string
    {
        $snapshot = $this->settings->read('installation_id');
        $installationId = $this->installationIdFactory->create();
        $this->settings->save(
            'installation_id',
            $installationId,
            $snapshot->revision()
        );

        return $installationId;
    }

    /**
     * @param array<string, mixed> $state
     */
    private function saveState(array $state): void
    {
        $this->inspectionCache?->clear();
        $snapshot = $this->settings->read('install_state', []);
        $current = is_array($snapshot->value()) ? $snapshot->value() : [];
        $this->settings->save(
            'install_state',
            array_merge($current, $state),
            $snapshot->revision()
        );
    }

    private function failureMessage(Throwable $error): string
    {
        $message = trim($error->getMessage());
        if ($message === '') {
            return '설치 단계를 완료하지 못했습니다.';
        }

        $message = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', ' ', $message)
            ?? '설치 단계를 완료하지 못했습니다.';

        return strlen($message) > 2000
            ? substr($message, 0, 2000) . '...'
            : $message;
    }

    private function saveFailureState(Throwable $error): void
    {
        try {
            $this->saveState([
                'status' => 'interrupted',
                'error_code' => 'setup_execution_failed',
                'error_message' => $this->failureMessage($error),
                'updated_at' => gmdate('c'),
            ]);
        } catch (Throwable) {
            // 상태 저장도 실패하면 최초 설치 오류를 그대로 유지한다.
        }
    }
}
