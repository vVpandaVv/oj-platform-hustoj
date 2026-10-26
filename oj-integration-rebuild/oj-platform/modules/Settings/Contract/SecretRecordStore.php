<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Settings\Contract;

interface SecretRecordStore
{
    /**
     * @param array<string, mixed> $record
     */
    public function put(string $recordId, array $record): void;

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $recordId): ?array;

    public function remove(string $recordId): void;

    /**
     * Overwrites an existing record in place (same recordId, new encrypted
     * payload) — used by key rotation. Unlike put(), does not reject an
     * existing recordId.
     *
     * @param array<string, mixed> $record
     */
    public function replace(string $recordId, array $record): void;

    /**
     * @return list<string>
     */
    public function recordIds(): array;
}
