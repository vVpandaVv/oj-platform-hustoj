<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Contests\Contract;

interface ContestSubmissionAccess
{
    public function canViewProblem(
        int $userId,
        int $contestId,
        int $problemId,
        ?int $problemNumber
    ): bool;

    public function validateSubmission(
        int $userId,
        int $contestId,
        int $problemId,
        int $problemNumber,
        string $ipAddress
    ): int;


    public function allowsAiFeedback(int $contestId): bool;
    public function submissionBlocklyProfile(int $contestId): ?string;

    public function canViewSubmission(int $actorUserId, int $contestId): bool;
}
