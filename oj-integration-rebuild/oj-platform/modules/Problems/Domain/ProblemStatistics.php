<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Domain;

final class ProblemStatistics
{
    /**
     * @param array<int, int> $resultCounts
     */
    public function __construct(
        private readonly ProblemId $problemId,
        private readonly int $submitted,
        private readonly int $accepted,
        private readonly array $resultCounts
    ) {
    }

    public function problemId(): ProblemId
    {
        return $this->problemId;
    }

    public function submitted(): int
    {
        return $this->submitted;
    }

    public function accepted(): int
    {
        return $this->accepted;
    }

    /**
     * @return array<int, int>
     */
    public function resultCounts(): array
    {
        return $this->resultCounts;
    }
}
