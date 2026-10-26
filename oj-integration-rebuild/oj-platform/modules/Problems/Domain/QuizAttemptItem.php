<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Domain;

use InvalidArgumentException;

final class QuizAttemptItem
{
    /**
     * @param list<int> $selectedChoiceIds
     */
    public function __construct(
        private readonly int $questionId,
        private readonly ?int $choiceId,
        private readonly ?string $answerText,
        private readonly bool $correct,
        private readonly float $pointsAwarded,
        private readonly array $selectedChoiceIds = []
    ) {
        if (
            $this->questionId < 1
            || ($this->choiceId !== null && $this->choiceId < 1)
            || (
                $this->answerText !== null
                && (
                    strlen($this->answerText) > 1048576
                    || preg_match('//u', $this->answerText) !== 1
                )
            )
            || $this->pointsAwarded < 0
            || $this->pointsAwarded > 10000
        ) {
            throw new InvalidArgumentException('퀴즈 응시 답안 형식이 올바르지 않습니다.');
        }
        foreach ($this->selectedChoiceIds as $selectedChoiceId) {
            if (! is_int($selectedChoiceId) || $selectedChoiceId < 1) {
                throw new InvalidArgumentException('퀴즈 응시 답안 형식이 올바르지 않습니다.');
            }
        }
    }

    public function questionId(): int
    {
        return $this->questionId;
    }

    public function choiceId(): ?int
    {
        return $this->choiceId;
    }

    public function answerText(): ?string
    {
        return $this->answerText;
    }

    public function isCorrect(): bool
    {
        return $this->correct;
    }

    public function pointsAwarded(): float
    {
        return $this->pointsAwarded;
    }

    /**
     * @return list<int>
     */
    public function selectedChoiceIds(): array
    {
        return $this->selectedChoiceIds;
    }
}
