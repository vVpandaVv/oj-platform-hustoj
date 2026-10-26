<?php
declare(strict_types=1);
namespace OjPrimm\Generation;

/** Each invocation is a separate, caller-budgeted AI request before any OJ execution. */
interface ValidationRepairGenerator extends LessonGenerator
{
    public function repairValidation(int $actor, array $publicProblem, string $language, string $kind,
        array $candidate, string $message, array $profile, string $key): array;
}
