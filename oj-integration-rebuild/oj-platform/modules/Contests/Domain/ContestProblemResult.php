<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Contests\Domain;

final class ContestProblemResult
{
    public function __construct(
        private readonly int $problemId,
        private readonly float $score,
        private readonly bool $accepted,
        private readonly float $bestPassRate,
        private readonly int $attemptCount,
        private readonly int $penaltyAttemptCount,
        private readonly ?int $firstAcceptedElapsedSeconds,
        private readonly float $correctnessScore,
        private readonly float $timeScore,
        private readonly int $penaltyPoints,
        private readonly int $penaltySeconds,
        private readonly ?string $latestSubmittedAt
    ) {
    }

    public function problemId(): int { return $this->problemId; }
    public function score(): float { return $this->score; }
    public function isAccepted(): bool { return $this->accepted; }
    public function bestPassRate(): float { return $this->bestPassRate; }
    public function attemptCount(): int { return $this->attemptCount; }
    public function penaltyAttemptCount(): int
    {
        return $this->penaltyAttemptCount;
    }
    public function firstAcceptedElapsedSeconds(): ?int
    {
        return $this->firstAcceptedElapsedSeconds;
    }
    public function correctnessScore(): float { return $this->correctnessScore; }
    public function timeScore(): float { return $this->timeScore; }
    public function penaltyPoints(): int { return $this->penaltyPoints; }
    public function penaltySeconds(): int { return $this->penaltySeconds; }
    public function latestSubmittedAt(): ?string
    {
        return $this->latestSubmittedAt;
    }
}
