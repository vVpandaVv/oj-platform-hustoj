<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Contests\Domain;

final class ContestProblemStatistics
{
    public function __construct(
        private readonly int $problemId,
        private readonly int $submissionCount,
        private readonly int $attemptedParticipantCount,
        private readonly int $acceptedParticipantCount,
        private readonly float $acceptedRate,
        private readonly float $averageAttempts
    ) {
    }

    public function problemId(): int { return $this->problemId; }
    public function submissionCount(): int { return $this->submissionCount; }
    public function attemptedParticipantCount(): int
    {
        return $this->attemptedParticipantCount;
    }
    public function acceptedParticipantCount(): int
    {
        return $this->acceptedParticipantCount;
    }
    public function acceptedRate(): float { return $this->acceptedRate; }
    public function averageAttempts(): float { return $this->averageAttempts; }
}
