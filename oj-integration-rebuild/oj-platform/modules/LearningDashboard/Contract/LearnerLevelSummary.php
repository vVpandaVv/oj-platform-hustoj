<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\LearningDashboard\Contract;

final class LearnerLevelSummary
{
    public function __construct(
        private readonly string $analysisStatus,
        private readonly ?string $level,
        private readonly ?int $provenTier,
        private readonly ?float $skillScore,
        private readonly string $analyzedAt
    ) {
    }

    public function analysisStatus(): string
    {
        return $this->analysisStatus;
    }

    public function level(): ?string
    {
        return $this->level;
    }

    public function provenTier(): ?int
    {
        return $this->provenTier;
    }

    public function skillScore(): ?float
    {
        return $this->skillScore;
    }

    public function analyzedAt(): string
    {
        return $this->analyzedAt;
    }
}
