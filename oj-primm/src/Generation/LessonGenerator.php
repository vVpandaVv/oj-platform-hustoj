<?php
declare(strict_types=1);
namespace OjPrimm\Generation;

interface LessonGenerator
{
    /** @return array{available:bool,message:string} */
    public function availability(): array;

    /** Returns a server-normalized lesson; it has not been executed or graded. */
    public function generate(int $actor, array $publicProblem, string $language, string $supportKind, string $idempotencyKey): array;

    /** Repairs the whole draft from bounded judge feedback; callers must execute every case again. */
    public function repair(int $actor, array $publicProblem, array $lesson, array $verificationReport, string $idempotencyKey): array;
}
