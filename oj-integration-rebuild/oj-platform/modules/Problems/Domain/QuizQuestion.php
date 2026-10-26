<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Domain;

use InvalidArgumentException;

final class QuizQuestion
{
    private const TYPES = ['single_choice', 'multi_choice', 'short_answer'];

    /**
     * @param list<QuizChoice> $choices
     * @param list<string> $acceptedAnswers
     */
    public function __construct(
        private readonly int $id,
        private readonly string $key,
        private readonly string $title,
        private readonly string $body,
        private readonly string $type,
        private readonly ?string $explanation,
        private readonly float $points,
        private readonly int $sortOrder,
        private readonly bool $enabled,
        private readonly array $choices,
        private readonly array $acceptedAnswers = []
    ) {
        if (
            $this->id < 0
            || preg_match(
                '/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/',
                $this->key
            ) !== 1
            || trim($this->title) === ''
            || strlen($this->title) > 191
            || trim($this->body) === ''
            || strlen($this->body) > 1048576
            || preg_match('//u', $this->body) !== 1
            || ! in_array($this->type, self::TYPES, true)
            || (
                $this->explanation !== null
                && (
                    strlen($this->explanation) > 1048576
                    || preg_match('//u', $this->explanation) !== 1
                )
            )
            || $this->points <= 0
            || $this->points > 10000
            || $this->sortOrder < 1
        ) {
            throw new InvalidArgumentException('퀴즈 문항 형식이 올바르지 않습니다.');
        }
        if ($this->type === 'short_answer') {
            if ($this->choices !== []) {
                throw new InvalidArgumentException('단답형 문항에는 선택지를 등록할 수 없습니다.');
            }
            $answers = [];
            foreach ($this->acceptedAnswers as $answer) {
                if (
                    ! is_string($answer)
                    || trim($answer) === ''
                    || strlen($answer) > 191
                    || preg_match('//u', $answer) !== 1
                ) {
                    throw new InvalidArgumentException('단답형 정답 형식이 올바르지 않습니다.');
                }
                $answers[] = $answer;
            }
            if (count($answers) < 1 || count($answers) > 20) {
                throw new InvalidArgumentException(
                    '단답형 문항에는 정답을 1개 이상 20개 이하로 등록해야 합니다.'
                );
            }

            return;
        }
        if ($this->acceptedAnswers !== []) {
            throw new InvalidArgumentException('선택형 문항에는 정답 텍스트를 등록할 수 없습니다.');
        }
        if (count($this->choices) < 2 || count($this->choices) > 8) {
            throw new InvalidArgumentException('퀴즈 문항 형식이 올바르지 않습니다.');
        }
        $keys = [];
        $orders = [];
        $correct = 0;
        foreach ($this->choices as $choice) {
            if (! $choice instanceof QuizChoice) {
                throw new InvalidArgumentException('퀴즈 선택지 형식이 올바르지 않습니다.');
            }
            $keys[] = $choice->key();
            $orders[] = $choice->sortOrder();
            $correct += $choice->isCorrect() && $choice->isEnabled() ? 1 : 0;
        }
        if (
            count(array_unique($keys)) !== count($keys)
            || count(array_unique($orders)) !== count($orders)
            || ($this->type === 'single_choice' && $correct !== 1)
            || ($this->type === 'multi_choice' && $correct < 1)
        ) {
            throw new InvalidArgumentException(
                '퀴즈 문항에는 중복 없는 선택지와 정답이 필요합니다.'
            );
        }
    }

    public function id(): int
    {
        return $this->id;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function explanation(): ?string
    {
        return $this->explanation;
    }

    public function points(): float
    {
        return $this->points;
    }

    public function sortOrder(): int
    {
        return $this->sortOrder;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * @return list<QuizChoice>
     */
    public function choices(): array
    {
        return $this->choices;
    }

    public function correctChoice(): QuizChoice
    {
        foreach ($this->choices as $choice) {
            if ($choice->isCorrect() && $choice->isEnabled()) {
                return $choice;
            }
        }

        throw new \LogicException('퀴즈 정답 선택지가 없습니다.');
    }

    /**
     * @return list<QuizChoice>
     */
    public function correctChoices(): array
    {
        return array_values(array_filter(
            $this->choices,
            static fn (QuizChoice $choice): bool =>
                $choice->isCorrect() && $choice->isEnabled()
        ));
    }

    /**
     * @return list<string>
     */
    public function acceptedAnswers(): array
    {
        return $this->acceptedAnswers;
    }
}
