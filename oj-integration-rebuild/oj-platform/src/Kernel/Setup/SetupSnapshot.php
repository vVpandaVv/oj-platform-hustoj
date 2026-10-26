<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Setup;

final class SetupSnapshot
{
    /**
     * @param list<string> $existingTables
     * @param list<string> $collisionTables
     * @param list<string> $pendingSchemas
     * @param list<string> $pendingUpgrades
     * @param list<string> $legacyTables
     * @param list<string> $readinessBlockers
     */
    public function __construct(
        private readonly SetupMode $setupMode,
        private readonly ?string $installationId,
        private readonly string $status,
        private readonly array $existingTables,
        private readonly array $collisionTables,
        private readonly array $pendingSchemas,
        private readonly array $pendingUpgrades,
        private readonly int $targetTableCount,
        private readonly int $registeredSchemaCount,
        private readonly ?string $planHash,
        private readonly array $legacyTables,
        private readonly array $readinessBlockers,
        private readonly ?string $lastErrorMessage = null
    ) {
    }

    public function mode(): SetupMode
    {
        return $this->setupMode;
    }

    public function installationId(): ?string
    {
        return $this->installationId;
    }

    public function status(): string
    {
        return $this->status;
    }

    /**
     * @return list<string>
     */
    public function existingTables(): array
    {
        return $this->existingTables;
    }

    /**
     * @return list<string>
     */
    public function collisions(): array
    {
        return $this->collisionTables;
    }

    /**
     * @return list<string>
     */
    public function pendingSchemas(): array
    {
        return $this->pendingSchemas;
    }

    /** @return list<string> */
    public function pendingUpgrades(): array
    {
        return $this->pendingUpgrades;
    }

    /** @return list<string> */
    public function legacyTables(): array
    {
        return $this->legacyTables;
    }

    public function targetTableCount(): int
    {
        return $this->targetTableCount;
    }

    public function registeredSchemaCount(): int
    {
        return $this->registeredSchemaCount;
    }

    public function planHash(): ?string
    {
        return $this->planHash;
    }

    /**
     * @return list<string>
     */
    public function readinessBlockers(): array
    {
        return $this->readinessBlockers;
    }

    public function isRuntimeReady(): bool
    {
        return $this->readinessBlockers === [];
    }

    public function lastErrorMessage(): ?string
    {
        return $this->lastErrorMessage;
    }

    public function allowsSetupExecution(): bool
    {
        return $this->setupMode->allowsExecution() && ! $this->isMigrationRuntime();
    }

    public function allowsRuntime(): bool
    {
        return $this->setupMode === SetupMode::AlreadyInstalled || $this->isMigrationRuntime();
    }

    public function isMigrationRuntime(): bool
    {
        return $this->setupMode === SetupMode::MigrationTarget
            && $this->status === 'migration_ready';
    }
}
