<?php
declare(strict_types=1);
namespace OjPrimm\Storage;

interface ManagementStore
{
    public function page(string $type, string $id, int $before, int $limit): array;
    public function update(int $id, int $revision, array $changes): array;
    public function version(?array $parent, array $data, string $key, bool $inTransaction = false): array;
    public function versions(int $family): array;
    public function attempts(int $activityId): array;
}
