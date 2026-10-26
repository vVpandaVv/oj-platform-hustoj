<?php
declare(strict_types=1);
namespace OjPrimm\Storage;

interface GenerationStore
{
    /** Reuses the same actor/content fingerprint, including failed work for explicit retry. */
    public function createOrFind(array $data): array;
    public function find(int $id): ?array;
    public function claim(int $id, string $lease, string $now, string $expires): bool;
    /** Save private pipeline state under the current lease, then release it for the next bounded step. */
    public function checkpoint(int $id, string $lease, array $pipeline): array;
    /** The activity insert and job completion must commit together under the lease. */
    public function complete(int $id, string $lease, callable $createActivity): array;
    public function fail(int $id, string $lease, string $message): void;
    public function retry(int $id): array;
    public function cancelPending(int $id, string $message): void;
    public function pending(int $limit = 3): array;
}
