<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\PlatformDb\Contract;

interface PlatformConnection
{
    public function isAvailable(): bool;

    public function databaseName(): string;

    public function serverVersion(): string;

    /**
     * @param list<string> $tableNames
     *
     * @return array<string, PlatformTableState>
     */
    public function inspectTables(array $tableNames): array;

    public function execute(string $statement): void;

    public function synchronize(string $createStatement): void;
}
