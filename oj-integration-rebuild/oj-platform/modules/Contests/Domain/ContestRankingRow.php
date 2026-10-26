<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Contests\Domain;

final class ContestRankingRow
{
    /**
     * @param array<int, float> $problemScores
     * @param array<int, ContestProblemResult> $problemResults
     */
    public function __construct(
        private readonly int $rank,
        private readonly string $userLogin,
        private readonly string $displayName,
        private readonly int $solved,
        private readonly float $score,
        private readonly int $penalty,
        private readonly int $completionSeconds,
        private readonly array $problemScores,
        private readonly array $problemResults = [],
        private readonly ?string $latestSubmittedAt = null
    ) {
    }

    public function rank(): int { return $this->rank; }
    public function userLogin(): string { return $this->userLogin; }
    public function displayName(): string { return $this->displayName; }
    public function solved(): int { return $this->solved; }
    public function score(): float { return $this->score; }
    public function penalty(): int { return $this->penalty; }
    public function completionSeconds(): int { return $this->completionSeconds; }

    /** @return array<int, float> */
    public function problemScores(): array { return $this->problemScores; }

    /** @return array<int, ContestProblemResult> */
    public function problemResults(): array { return $this->problemResults; }
    public function latestSubmittedAt(): ?string
    {
        return $this->latestSubmittedAt;
    }
}
