<?php
declare(strict_types=1);
namespace OjMicrobit\Application;
interface Repository
{
    public function exercises(?int $ownerId, bool $published, int $beforeId = 0, int $limit = 21): array;
    public function learnerExercises(array $classIds, int $beforeId = 0, int $limit = 21, array $groupIds = []): array;
    public function exercise(int $id): ?array;
    public function exerciseSummary(int $id): ?array;
    public function saveExercise(array $data, int $ownerId, ?int $id, int $revision): array;
    public function archiveExercise(int $id, int $revision): void;
    public function project(int $exerciseId, int $userId): ?array;
    public function saveProject(int $exerciseId, int $userId, array $project, int $revision): array;
    public function saveAttempt(int $exerciseId, int $revision, int $userId, array $project, array $result): int;
    public function attempts(int $exerciseId, ?int $userId, int $beforeId = 0, int $limit = 21): array;
    public function attempt(int $exerciseId, int $attemptId, ?int $userId): ?array;
    public function withRunLease(int $userId, callable $operation): mixed;
}
