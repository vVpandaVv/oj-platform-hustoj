<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Classes\Contract;

interface ClassSubmissionAccess
{
    public function canViewProblem(
        int $userId,
        int $classId,
        int $problemId
    ): bool;

    public function validateSubmission(
        int $userId,
        int $classId,
        int $problemId
    ): void;

    public function submissionBlocklyProfile(int $classId): ?string;

    public function canViewSubmission(int $actorUserId, int $classId): bool;
}
