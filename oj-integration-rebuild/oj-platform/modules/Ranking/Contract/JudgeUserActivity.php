<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Ranking\Contract;

/** Public, non-contest, completed and valid submission activity. */
final class JudgeUserActivity
{
    public function __construct(
        private readonly string $userLogin,
        private readonly int $submissionCount,
        private readonly int $acceptedSubmissionCount,
        private readonly int $solvedProblemCount
    ) {
    }

    public function userLogin(): string
    {
        return $this->userLogin;
    }

    public function submissionCount(): int
    {
        return $this->submissionCount;
    }

    public function acceptedSubmissionCount(): int
    {
        return $this->acceptedSubmissionCount;
    }

    public function solvedProblemCount(): int
    {
        return $this->solvedProblemCount;
    }
}
