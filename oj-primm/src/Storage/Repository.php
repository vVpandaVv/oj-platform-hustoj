<?php
declare(strict_types=1);
namespace OjPrimm\Storage;

interface Repository
{
    public function createActivity(array $data): array;
    public function findActivity(int $id): ?array;
    public function activitiesForContext(string $type, string $id): array;
    public function findAttempt(int $activityId, int $userId): ?array;
    public function saveAttempt(int $activityId, int $userId, int $expectedVersion, array $state, array $event, string $requestHash): array;
    public function attemptsForActivities(array $activityIds): array;
    public function exportUser(int $userId, int $page = 1): array;
    public function eraseUser(int $userId): bool;
    public function purgeBefore(string $cutoff): int;
}
