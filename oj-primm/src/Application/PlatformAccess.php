<?php
declare(strict_types=1);
namespace OjPrimm\Application;

interface PlatformAccess
{
    public function actorId(): int;
    public function assertRole(string $role): void;
    public function contexts(bool $teaching): array;
    public function authorize(string $type, string $id, string $operation, ?int $learnerId = null): array;
    public function searchProblems(string $search): array;
    public function assertSupport(int $problemId, string $kind, string $contextType, string $contextId): void;
    /** Public problem and optionally the current actor's own verified submission. */
    public function problem(int $problemId, string $contextType, string $contextId, ?int $submissionId = null): array;
    public function problemUrl(int $problemId, string $contextType, string $contextId): string;
}
