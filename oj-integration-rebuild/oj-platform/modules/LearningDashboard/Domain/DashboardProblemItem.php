<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\LearningDashboard\Domain;

final class DashboardProblemItem
{
    /**
     * @param list<string> $topics
     */
    public function __construct(
        private readonly int $problemId,
        private readonly string $title,
        private readonly ?int $difficulty,
        private readonly array $topics,
        private readonly string $reason
    ) {
    }

    public function problemId(): int
    {
        return $this->problemId;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function difficulty(): ?int
    {
        return $this->difficulty;
    }

    /**
     * @return list<string>
     */
    public function topics(): array
    {
        return $this->topics;
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
