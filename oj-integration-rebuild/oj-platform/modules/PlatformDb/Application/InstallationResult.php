<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\PlatformDb\Application;

final class InstallationResult
{
    /**
     * @param list<string> $createdTables
     * @param list<string> $updatedTables
     * @param list<string> $skippedTables
     * @param list<string> $pendingTables
     */
    public function __construct(
        private readonly string $planHash,
        private readonly array $createdTables,
        private readonly array $updatedTables,
        private readonly array $skippedTables,
        private readonly array $pendingTables,
        private readonly bool $complete
    ) {
    }

    public function planHash(): string
    {
        return $this->planHash;
    }

    /**
     * @return list<string>
     */
    public function createdTables(): array
    {
        return $this->createdTables;
    }

    /**
     * @return list<string>
     */
    public function updatedTables(): array
    {
        return $this->updatedTables;
    }

    /**
     * @return list<string>
     */
    public function skippedTables(): array
    {
        return $this->skippedTables;
    }

    /**
     * @return list<string>
     */
    public function pendingTables(): array
    {
        return $this->pendingTables;
    }

    public function isComplete(): bool
    {
        return $this->complete;
    }
}
