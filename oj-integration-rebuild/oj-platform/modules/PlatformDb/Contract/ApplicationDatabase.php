<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\PlatformDb\Contract;

interface ApplicationDatabase
{
    /**
     * @param list<mixed> $values
     * @return array<string, mixed>|null
     */
    public function row(string $sql, array $values = []): ?array;

    /**
     * @param list<mixed> $values
     * @return list<array<string, mixed>>
     */
    public function rows(string $sql, array $values = []): array;

    /**
     * @param list<mixed> $values
     */
    public function value(string $sql, array $values = []): mixed;

    /**
     * @param array<string, mixed> $data
     * @param list<string> $formats
     */
    public function insert(
        string $table,
        array $data,
        array $formats = []
    ): int;

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $where
     * @param list<string> $formats
     * @param list<string> $whereFormats
     */
    public function update(
        string $table,
        array $data,
        array $where,
        array $formats = [],
        array $whereFormats = []
    ): int;

    /**
     * @param array<string, mixed> $where
     * @param list<string> $whereFormats
     */
    public function delete(
        string $table,
        array $where,
        array $whereFormats = []
    ): int;

    public function beginTransaction(): void;

    public function commit(): void;

    public function rollBack(): void;
}
