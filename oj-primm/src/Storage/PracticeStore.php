<?php
declare(strict_types=1);
namespace OjPrimm\Storage;

interface PracticeStore
{
    public function createOrFind(int $activity, int $actor, string $fingerprint, array $data): array;
    public function find(int $id): ?array;
    public function claim(int $id, string $lease): bool;
    public function save(int $id, string $lease, array $data, string $status): array;
    public function latest(int $activity, int $actor): ?array;
}
