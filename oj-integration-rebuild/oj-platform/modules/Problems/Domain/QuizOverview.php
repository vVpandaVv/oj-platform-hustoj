<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Domain;

final class QuizOverview
{
    public function __construct(
        private readonly ProblemId $problemId,
        private readonly ?QuizRevision $draft,
        private readonly ?QuizRevision $published,
        private readonly int $gradedAttempts,
        private readonly float $averageScore
    ) {
    }

    public function problemId(): ProblemId
    {
        return $this->problemId;
    }

    public function draft(): ?QuizRevision
    {
        return $this->draft;
    }

    public function published(): ?QuizRevision
    {
        return $this->published;
    }

    public function gradedAttempts(): int
    {
        return $this->gradedAttempts;
    }

    public function averageScore(): float
    {
        return $this->averageScore;
    }
}
