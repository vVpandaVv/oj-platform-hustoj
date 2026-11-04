<?php
declare(strict_types=1);
namespace OjMicrobit\Application;
interface LessonAccess
{
    /** Complete active lesson metadata, capped at 100; null is unavailable. */
    public function learningLessons(): ?array;
    public function lessonTitle(int $classId, bool $teacher): string;
    public function lessonRoster(int $classId, string $cursor, int $limit, ?string $contextRevision = null, ?string $rosterRevision = null): array;
}
