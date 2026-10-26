<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Settings\Application;

use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsManager;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsSnapshot;
use CodingPanda\OjPlatform\Modules\Settings\Contract\ModuleWriteFreezeManager;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SystemOperationPolicy;
use CodingPanda\OjPlatform\Modules\Settings\Domain\SystemSettings;
use DomainException;
use RuntimeException;

final class SystemSettingsService implements SystemOperationPolicy, ModuleWriteFreezeManager
{
    private const SAFETY_ACTIONS = [
        'oj_save_system_settings',
        'oj_platform_clear_last_fatal',
        'oj_save_migration_settings',
        'oj_migration_scan',
        'oj_migration_dry_run',
        'oj_migration_validate',
        'oj_migration_run',
        'oj_migration_cleanup_report',
        'oj_migration_deactivate_legacy_plugins',
        'oj_job_status',
        'oj_job_cancel',
    ];

    public function __construct(private readonly SettingsManager $settings)
    {
    }

    public function snapshot(): SettingsSnapshot
    {
        return $this->settings->read('system', SystemSettings::defaults()->toArray());
    }

    public function current(): SystemSettings
    {
        $value = $this->snapshot()->value();

        return SystemSettings::fromArray(is_array($value) ? $value : []);
    }

    /**
     * @param list<string> $frozenModules
     */
    public function save(
        bool $maintenanceEnabled,
        string $maintenanceMessage,
        ?string $maintenanceEndsAt,
        array $frozenModules,
        int $expectedRevision
    ): SettingsSnapshot {
        $current = $this->current();
        $value = $current->toArray();
        $value['maintenance_enabled'] = $maintenanceEnabled;
        $value['maintenance_message'] = trim($maintenanceMessage);
        $value['maintenance_ends_at'] = $maintenanceEndsAt !== null
            && trim($maintenanceEndsAt) !== ''
                ? trim($maintenanceEndsAt)
                : null;
        $value['frozen_modules'] = array_values(array_unique($frozenModules));
        sort($value['frozen_modules']);
        $next = SystemSettings::fromArray($value);

        return $this->settings->save('system', $next->toArray(), $expectedRevision);
    }

    /**
     * @return array{token:string,expires_at:int,snapshot:SettingsSnapshot}
     */
    public function issueUninstallApproval(
        string $confirmedServiceName,
        string $actualServiceName,
        string $backupProof,
        string $restoreProof,
        int $userId,
        int $expectedRevision
    ): array {
        if (
            trim($confirmedServiceName) === ''
            || ! hash_equals($actualServiceName, trim($confirmedServiceName))
        ) {
            throw new DomainException('서비스 이름을 정확히 입력해 주세요.');
        }
        if (trim($backupProof) === '' || trim($restoreProof) === '') {
            throw new DomainException('백업과 분리 환경 복구 증거를 모두 입력해 주세요.');
        }
        $token = bin2hex(random_bytes(24));
        $expiresAt = time() + 1800;
        $next = $this->current()->withUninstallApproval(
            $token,
            $userId,
            $expiresAt,
            trim($backupProof),
            trim($restoreProof)
        );

        return [
            'token' => $token,
            'expires_at' => $expiresAt,
            'snapshot' => $this->settings->save(
                'system',
                $next->toArray(),
                $expectedRevision
            ),
        ];
    }

    public function maintenanceEnabled(): bool
    {
        return $this->current()->maintenanceEnabled();
    }

    public function maintenanceMessage(): string
    {
        return $this->current()->maintenanceMessage();
    }

    public function maintenanceEndsAt(): ?string
    {
        return $this->current()->maintenanceEndsAt();
    }

    public function isModuleWriteFrozen(string $moduleKey): bool
    {
        return $this->current()->isModuleWriteFrozen($moduleKey);
    }

    public function acquireMigrationFreeze(
        string $moduleKey,
        string $freezeKey
    ): void {
        $this->changeMigrationFreeze(
            static fn (SystemSettings $current): SystemSettings =>
                $current->withMigrationFreeze($moduleKey, $freezeKey)
        );
    }

    public function releaseMigrationFreeze(
        string $moduleKey,
        string $freezeKey
    ): void {
        $this->changeMigrationFreeze(
            static fn (SystemSettings $current): SystemSettings =>
                $current->withoutMigrationFreeze($moduleKey, $freezeKey)
        );
    }

    public function allowsAction(string $moduleKey, string $actionKey): bool
    {
        return in_array($actionKey, self::SAFETY_ACTIONS, true)
            || ! $this->isModuleWriteFrozen($moduleKey);
    }

    public function allowsJob(string $moduleKey, string $jobKey): bool
    {
        return $moduleKey === 'migration'
            || $jobKey === 'platform_migration'
            || ! $this->isModuleWriteFrozen($moduleKey);
    }

    /** @param callable(SystemSettings): SystemSettings $change */
    private function changeMigrationFreeze(callable $change): void
    {
        $lastError = null;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $snapshot = $this->snapshot();
            $value = $snapshot->value();
            $current = SystemSettings::fromArray(is_array($value) ? $value : []);
            $next = $change($current);
            if ($next->toArray() === $current->toArray()) {
                return;
            }
            try {
                $this->settings->save(
                    'system',
                    $next->toArray(),
                    $snapshot->revision()
                );

                return;
            } catch (RuntimeException $error) {
                $lastError = $error;
            }
        }

        throw new RuntimeException(
            '데이터 이전 쓰기 동결 상태를 저장하지 못했습니다.',
            0,
            $lastError
        );
    }
}
