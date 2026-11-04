<?php
declare(strict_types=1);
namespace OjMicrobit\Application;
interface LessonRepository
{
    public function publicExercises(int $beforeId = 0, int $limit = 21): array;
    public function assignments(array $classIds, int $beforeId = 0, int $limit = 21, bool $publicOnly = true): array;
    public function assignment(int $id): ?array;
    public function saveAssignment(int $classId, int $exerciseId, int $ownerId): array;
    public function archiveAssignment(int $id, int $revision): void;
    public function lessonProject(int $assignmentId, int $userId): ?array;
    public function saveLessonProject(array $assignment, int $userId, array $project, int $revision): array;
    public function saveLessonAttempt(array $assignment, int $exerciseRevision, int $userId, array $project, array $result): int;
    public function lessonAttempts(int $assignmentId, ?int $userId, int $beforeId = 0, int $limit = 21): array;
    public function lessonAttempt(int $assignmentId, int $attemptId, ?int $userId): ?array;
    public function lessonResults(int $assignmentId, array $userIds): array;
}
