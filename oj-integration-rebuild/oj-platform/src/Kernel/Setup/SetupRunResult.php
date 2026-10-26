<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Setup;

final class SetupRunResult
{
    /**
     * @param list<string> $createdTables
     * @param list<string> $updatedTables
     * @param list<string> $pendingTables
     */
    public function __construct(
        private readonly SetupSnapshot $snapshot,
        private readonly array $createdTables,
        private readonly array $updatedTables,
        private readonly array $pendingTables
    ) {
    }

    public function snapshot(): SetupSnapshot
    {
        return $this->snapshot;
    }

    /**
     * @return list<string>
     */
    public function createdTables(): array
    {
        return $this->createdTables;
    }

    /** @return list<string> */
    public function updatedTables(): array
    {
        return $this->updatedTables;
    }

    /**
     * @return list<string>
     */
    public function pendingTables(): array
    {
        return $this->pendingTables;
    }
}
