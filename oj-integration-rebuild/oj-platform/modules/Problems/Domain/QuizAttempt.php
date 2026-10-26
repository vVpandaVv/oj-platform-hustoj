<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Domain;

use InvalidArgumentException;

final class QuizAttempt
{
    /**
     * @param list<QuizAttemptItem> $items
     */
    public function __construct(
        private readonly int $id,
        private readonly string $attemptKey,
        private readonly ProblemId $problemId,
        private readonly int $revisionId,
        private readonly int $userId,
        private readonly string $status,
        private readonly float $score,
        private readonly float $maxScore,
        private readonly string $startedAt,
        private readonly ?string $submittedAt,
        private readonly array $items = [],
        private readonly string $attemptKind = 'learner',
        private readonly ?int $parentAttemptId = null
    ) {
        if (
            $this->id < 1
            || preg_match('/^[a-f0-9]{64}$/', $this->attemptKey) !== 1
            || $this->revisionId < 1
            || $this->userId < 1
            || ! in_array(
                $this->status,
                ['in_progress', 'graded', 'superseded'],
                true
            )
            || ! in_array(
                $this->attemptKind,
                ['learner', 'replacement', 'regrade'],
                true
            )
            || ($this->parentAttemptId !== null && $this->parentAttemptId < 1)
            || $this->score < 0
            || $this->maxScore < 0
        ) {
            throw new InvalidArgumentException('퀴즈 응시 기록 형식이 올바르지 않습니다.');
        }
        foreach ($this->items as $item) {
            if (! $item instanceof QuizAttemptItem) {
                throw new InvalidArgumentException('퀴즈 응시 답안 형식이 올바르지 않습니다.');
            }
        }
    }

    public function id(): int
    {
        return $this->id;
    }

    public function attemptKey(): string
    {
        return $this->attemptKey;
    }

    public function problemId(): ProblemId
    {
        return $this->problemId;
    }

    public function revisionId(): int
    {
        return $this->revisionId;
    }

    public function userId(): int
    {
        return $this->userId;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function score(): float
    {
        return $this->score;
    }

    public function maxScore(): float
    {
        return $this->maxScore;
    }

    public function startedAt(): string
    {
        return $this->startedAt;
    }

    public function submittedAt(): ?string
    {
        return $this->submittedAt;
    }

    /**
     * @return list<QuizAttemptItem>
     */
    public function items(): array
    {
        return $this->items;
    }

    public function attemptKind(): string
    {
        return $this->attemptKind;
    }

    public function parentAttemptId(): ?int
    {
        return $this->parentAttemptId;
    }
}
