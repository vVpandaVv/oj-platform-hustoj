<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Domain;

use InvalidArgumentException;
use JsonException;

final class QuizDefinition
{
    /**
     * @param list<QuizQuestion> $questions
     */
    public function __construct(
        private readonly string $title,
        private readonly string $description,
        private readonly int $attemptLimit,
        private readonly QuizResultPolicy $resultPolicy,
        private readonly ?string $resultsAvailableAt,
        private readonly bool $publicStatistics,
        private readonly bool $protectPublished,
        private readonly bool $enabled,
        private readonly array $questions
    ) {
        if (
            trim($this->title) === ''
            || strlen($this->title) > 191
            || strlen($this->description) > 1048576
            || preg_match('//u', $this->description) !== 1
            || $this->attemptLimit < 1
            || $this->attemptLimit > 20
            || count($this->questions) < 1
            || count($this->questions) > 50
            || (
                $this->resultPolicy === QuizResultPolicy::AfterEnd
                && ! $this->validDateTime($this->resultsAvailableAt)
            )
        ) {
            throw new InvalidArgumentException('퀴즈 기본 설정이 올바르지 않습니다.');
        }
        $keys = [];
        $orders = [];
        $total = 0.0;
        foreach ($this->questions as $question) {
            if (! $question instanceof QuizQuestion) {
                throw new InvalidArgumentException('퀴즈 문항 형식이 올바르지 않습니다.');
            }
            $keys[] = $question->key();
            $orders[] = $question->sortOrder();
            if ($question->isEnabled()) {
                $total += $question->points();
            }
        }
        if (
            count(array_unique($keys)) !== count($keys)
            || count(array_unique($orders)) !== count($orders)
            || $total <= 0
        ) {
            throw new InvalidArgumentException('퀴즈 문항 키·순서·총점이 올바르지 않습니다.');
        }
    }

    public function title(): string
    {
        return trim($this->title);
    }

    public function description(): string
    {
        return $this->description;
    }

    public function attemptLimit(): int
    {
        return $this->attemptLimit;
    }

    public function resultPolicy(): QuizResultPolicy
    {
        return $this->resultPolicy;
    }

    public function resultsAvailableAt(): ?string
    {
        return $this->resultsAvailableAt;
    }

    public function publicStatistics(): bool
    {
        return $this->publicStatistics;
    }

    public function protectPublished(): bool
    {
        return $this->protectPublished;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * @return list<QuizQuestion>
     */
    public function questions(): array
    {
        return $this->questions;
    }

    public function questionCount(): int
    {
        return count(array_filter(
            $this->questions,
            static fn (QuizQuestion $question): bool => $question->isEnabled()
        ));
    }

    public function maxScore(): float
    {
        return array_reduce(
            $this->questions,
            static fn (float $score, QuizQuestion $question): float =>
                $score + ($question->isEnabled() ? $question->points() : 0.0),
            0.0
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function config(): array
    {
        return [
            'title' => $this->title(),
            'description' => $this->description,
            'attempt_limit' => $this->attemptLimit,
            'result_policy' => $this->resultPolicy->value,
            'results_available_at' => $this->resultsAvailableAt,
            'public_statistics' => $this->publicStatistics,
            'protect_published' => $this->protectPublished,
            'enabled' => $this->enabled,
        ];
    }

    public function contentHash(): string
    {
        $questions = [];
        foreach ($this->questions as $question) {
            $choices = [];
            foreach ($question->choices() as $choice) {
                $choices[] = [
                    'key' => $choice->key(),
                    'label' => $choice->label(),
                    'body' => $choice->body(),
                    'correct' => $choice->isCorrect(),
                    'sort_order' => $choice->sortOrder(),
                    'enabled' => $choice->isEnabled(),
                ];
            }
            $questions[] = [
                'key' => $question->key(),
                'title' => $question->title(),
                'body' => $question->body(),
                'type' => $question->type(),
                'explanation' => $question->explanation(),
                'points' => $question->points(),
                'sort_order' => $question->sortOrder(),
                'enabled' => $question->isEnabled(),
                'choices' => $choices,
            ];
        }
        try {
            $encoded = json_encode(
                ['config' => $this->config(), 'questions' => $questions],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        } catch (JsonException $error) {
            throw new InvalidArgumentException('퀴즈 내용을 직렬화하지 못했습니다.', 0, $error);
        }

        return hash('sha256', $encoded);
    }

    private function validDateTime(?string $value): bool
    {
        if ($value === null) {
            return false;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);

        return $date !== false && $date->format('Y-m-d H:i:s') === $value;
    }
}
