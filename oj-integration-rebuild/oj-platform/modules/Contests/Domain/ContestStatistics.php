<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Contests\Domain;

final class ContestStatistics
{
    /** @param list<ContestProblemStatistics> $problems */
    public function __construct(
        private readonly int $participantCount,
        private readonly int $submissionCount,
        private readonly float $averageScore,
        private readonly float $averageSolved,
        private readonly float $maximumScore,
        private readonly int $noSubmissionCount,
        private readonly array $problems
    ) {
    }

    public function participantCount(): int { return $this->participantCount; }
    public function submissionCount(): int { return $this->submissionCount; }
    public function averageScore(): float { return $this->averageScore; }
    public function averageSolved(): float { return $this->averageSolved; }
    public function maximumScore(): float { return $this->maximumScore; }
    public function noSubmissionCount(): int { return $this->noSubmissionCount; }

    /** @return list<ContestProblemStatistics> */
    public function problems(): array { return $this->problems; }
}
