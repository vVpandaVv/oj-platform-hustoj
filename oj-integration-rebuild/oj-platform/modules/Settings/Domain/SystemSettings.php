<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Settings\Domain;

use InvalidArgumentException;

final class SystemSettings
{
    /**
     * @param list<string> $frozenModules
     */
    public function __construct(
        private readonly bool $maintenanceEnabled,
        private readonly string $maintenanceMessage,
        private readonly ?string $maintenanceEndsAt,
        private readonly array $frozenModules,
        private readonly ?string $uninstallApprovalHash = null,
        private readonly ?int $uninstallApprovalExpiresAt = null,
        private readonly ?int $uninstallApprovalUserId = null,
        private readonly ?string $uninstallBackupProof = null,
        private readonly ?string $uninstallRestoreProof = null,
        private readonly array $migrationFreezes = []
    ) {
        if (
            preg_match('//u', $this->maintenanceMessage) !== 1
            || strlen($this->maintenanceMessage) > 1000
            || (
                $this->maintenanceEnabled
                && trim($this->maintenanceMessage) === ''
            )
        ) {
            throw new InvalidArgumentException('점검 안내 문구가 올바르지 않습니다.');
        }
        if (
            $this->maintenanceEndsAt !== null
            && strtotime($this->maintenanceEndsAt) === false
        ) {
            throw new InvalidArgumentException('점검 종료 예정 시각이 올바르지 않습니다.');
        }
        $normalized = array_values(array_unique(array_map('strval', $this->frozenModules)));
        foreach ($normalized as $moduleKey) {
            if (preg_match('/^[a-z][a-z0-9_]*$/', $moduleKey) !== 1) {
                throw new InvalidArgumentException('쓰기 동결 모듈 키가 올바르지 않습니다.');
            }
        }
        if ($normalized !== $this->frozenModules) {
            throw new InvalidArgumentException('쓰기 동결 모듈 키가 중복되거나 정렬되지 않았습니다.');
        }
        if (self::migrationFreezeMap($this->migrationFreezes) !== $this->migrationFreezes) {
            throw new InvalidArgumentException('데이터 이전 쓰기 동결 값이 올바르지 않습니다.');
        }
        if (
            ($this->uninstallApprovalHash === null)
            !== ($this->uninstallApprovalExpiresAt === null)
            || ($this->uninstallApprovalHash === null)
            !== ($this->uninstallApprovalUserId === null)
            || ($this->uninstallApprovalHash === null)
                !== ($this->uninstallBackupProof === null)
            || ($this->uninstallApprovalHash === null)
                !== ($this->uninstallRestoreProof === null)
            || (
                $this->uninstallApprovalHash !== null
                && (
                    preg_match('/^[a-f0-9]{64}$/', $this->uninstallApprovalHash) !== 1
                    || ($this->uninstallApprovalExpiresAt ?? 0) < 1
                    || ($this->uninstallApprovalUserId ?? 0) < 1
                    || trim((string) $this->uninstallBackupProof) === ''
                    || strlen((string) $this->uninstallBackupProof) > 191
                    || trim((string) $this->uninstallRestoreProof) === ''
                    || strlen((string) $this->uninstallRestoreProof) > 191
                )
            )
        ) {
            throw new InvalidArgumentException('플러그인 제거 승인이 올바르지 않습니다.');
        }
    }

    /**
     * @param array<string, mixed> $value
     */
    public static function fromArray(array $value): self
    {
        $endsAt = trim((string) ($value['maintenance_ends_at'] ?? ''));
        $hash = trim((string) ($value['uninstall_approval_hash'] ?? ''));

        return new self(
            filter_var($value['maintenance_enabled'] ?? false, FILTER_VALIDATE_BOOL),
            trim((string) ($value['maintenance_message'] ?? '현재 점검 중입니다. 잠시 뒤 다시 이용해 주세요.')),
            $endsAt !== '' ? $endsAt : null,
            self::moduleList($value['frozen_modules'] ?? []),
            $hash !== '' ? $hash : null,
            $hash !== '' ? (int) ($value['uninstall_approval_expires_at'] ?? 0) : null,
            $hash !== '' ? (int) ($value['uninstall_approval_user_id'] ?? 0) : null,
            $hash !== '' ? trim((string) ($value['uninstall_backup_proof'] ?? '')) : null,
            $hash !== '' ? trim((string) ($value['uninstall_restore_proof'] ?? '')) : null,
            self::migrationFreezeMap($value['migration_freezes'] ?? [])
        );
    }

    public static function defaults(): self
    {
        return self::fromArray([]);
    }

    public function maintenanceEnabled(): bool
    {
        return $this->maintenanceEnabled;
    }

    public function maintenanceMessage(): string
    {
        return $this->maintenanceMessage;
    }

    public function maintenanceEndsAt(): ?string
    {
        return $this->maintenanceEndsAt;
    }

    /**
     * @return list<string>
     */
    public function frozenModules(): array
    {
        return $this->frozenModules;
    }

    /** @return list<string> */
    public function effectiveFrozenModules(): array
    {
        $modules = array_values(array_unique(array_merge(
            $this->frozenModules,
            array_keys($this->migrationFreezes)
        )));
        sort($modules, SORT_STRING);

        return $modules;
    }

    /** @return array<string, string> */
    public function migrationFreezes(): array
    {
        return $this->migrationFreezes;
    }

    public function isModuleWriteFrozen(string $moduleKey): bool
    {
        return in_array($moduleKey, $this->effectiveFrozenModules(), true);
    }

    public function withMigrationFreeze(string $moduleKey, string $freezeKey): self
    {
        $freezes = $this->migrationFreezes;
        $freezes[trim($moduleKey)] = strtolower(trim($freezeKey));
        ksort($freezes, SORT_STRING);

        return new self(
            $this->maintenanceEnabled,
            $this->maintenanceMessage,
            $this->maintenanceEndsAt,
            $this->frozenModules,
            $this->uninstallApprovalHash,
            $this->uninstallApprovalExpiresAt,
            $this->uninstallApprovalUserId,
            $this->uninstallBackupProof,
            $this->uninstallRestoreProof,
            $freezes
        );
    }

    public function withoutMigrationFreeze(string $moduleKey, string $freezeKey): self
    {
        $moduleKey = trim($moduleKey);
        $freezeKey = strtolower(trim($freezeKey));
        $current = $this->migrationFreezes[$moduleKey] ?? null;
        if ($current === null) {
            return $this;
        }
        if (! hash_equals($current, $freezeKey)) {
            throw new InvalidArgumentException('현재 데이터 이전 동결 소유자가 다릅니다.');
        }
        $freezes = $this->migrationFreezes;
        unset($freezes[$moduleKey]);

        return new self(
            $this->maintenanceEnabled,
            $this->maintenanceMessage,
            $this->maintenanceEndsAt,
            $this->frozenModules,
            $this->uninstallApprovalHash,
            $this->uninstallApprovalExpiresAt,
            $this->uninstallApprovalUserId,
            $this->uninstallBackupProof,
            $this->uninstallRestoreProof,
            $freezes
        );
    }

    public function uninstallApprovalExpiresAt(): ?int
    {
        return $this->uninstallApprovalExpiresAt;
    }

    public function withUninstallApproval(
        string $token,
        int $userId,
        int $expiresAt,
        string $backupProof,
        string $restoreProof
    ): self {
        return new self(
            $this->maintenanceEnabled,
            $this->maintenanceMessage,
            $this->maintenanceEndsAt,
            $this->frozenModules,
            hash('sha256', $token),
            $expiresAt,
            $userId,
            trim($backupProof),
            trim($restoreProof),
            $this->migrationFreezes
        );
    }

    public function withoutUninstallApproval(): self
    {
        return new self(
            $this->maintenanceEnabled,
            $this->maintenanceMessage,
            $this->maintenanceEndsAt,
            $this->frozenModules,
            null,
            null,
            null,
            null,
            null,
            $this->migrationFreezes
        );
    }

    public function verifiesUninstallApproval(
        string $token,
        int $userId,
        int $now
    ): bool {
        return $this->uninstallApprovalHash !== null
            && $this->uninstallApprovalUserId === $userId
            && ($this->uninstallApprovalExpiresAt ?? 0) > $now
            && hash_equals($this->uninstallApprovalHash, hash('sha256', $token));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'maintenance_enabled' => $this->maintenanceEnabled,
            'maintenance_message' => $this->maintenanceMessage,
            'maintenance_ends_at' => $this->maintenanceEndsAt,
            'frozen_modules' => $this->frozenModules,
            'uninstall_approval_hash' => $this->uninstallApprovalHash,
            'uninstall_approval_expires_at' => $this->uninstallApprovalExpiresAt,
            'uninstall_approval_user_id' => $this->uninstallApprovalUserId,
            'uninstall_backup_proof' => $this->uninstallBackupProof,
            'uninstall_restore_proof' => $this->uninstallRestoreProof,
            'migration_freezes' => $this->migrationFreezes,
        ];
    }

    /**
     * @return list<string>
     */
    private static function moduleList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }
        $modules = [];
        foreach ($value as $moduleKey) {
            if (is_string($moduleKey) && trim($moduleKey) !== '') {
                $modules[] = trim($moduleKey);
            }
        }
        $modules = array_values(array_unique($modules));
        sort($modules);

        return $modules;
    }

    /** @return array<string, string> */
    private static function migrationFreezeMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }
        $freezes = [];
        foreach ($value as $moduleKey => $freezeKey) {
            if (
                is_string($moduleKey)
                && is_string($freezeKey)
                && preg_match('/^[a-z][a-z0-9_]*$/', $moduleKey) === 1
                && preg_match('/^[a-f0-9]{64}$/', $freezeKey) === 1
            ) {
                $freezes[$moduleKey] = $freezeKey;
            }
        }
        ksort($freezes, SORT_STRING);

        return $freezes;
    }
}
