<?php
declare(strict_types=1);
namespace OjPrimm\Application;

/** Optional projection of the platform's registered ordering/completion exercises. */
interface ExerciseAccess
{
    public function support(int $problemId, string $kind, string $contextType, string $contextId): array;
    public function assembleSupport(int $problemId, string $kind, string $contextType, string $contextId, array $answer): array;
}
