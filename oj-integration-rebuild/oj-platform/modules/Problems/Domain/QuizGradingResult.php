<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Domain;

use InvalidArgumentException;

final class QuizGradingResult
{
    /**
     * @param list<QuizAttemptItem> $items
     */
    public function __construct(
        private readonly array $items,
        private readonly float $score,
        private readonly float $maxScore
    ) {
        if ($this->score < 0 || $this->maxScore <= 0 || $this->score > $this->maxScore) {
            throw new InvalidArgumentException('퀴즈 채점 결과가 올바르지 않습니다.');
        }
        foreach ($this->items as $item) {
            if (! $item instanceof QuizAttemptItem) {
                throw new InvalidArgumentException('퀴즈 채점 문항이 올바르지 않습니다.');
            }
        }
    }

    /**
     * @return list<QuizAttemptItem>
     */
    public function items(): array
    {
        return $this->items;
    }

    public function score(): float
    {
        return $this->score;
    }

    public function maxScore(): float
    {
        return $this->maxScore;
    }
}
