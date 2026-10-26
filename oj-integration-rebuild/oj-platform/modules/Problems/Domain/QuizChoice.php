<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Domain;

use InvalidArgumentException;

final class QuizChoice
{
    public function __construct(
        private readonly int $id,
        private readonly string $key,
        private readonly string $label,
        private readonly string $body,
        private readonly bool $correct,
        private readonly int $sortOrder,
        private readonly bool $enabled = true
    ) {
        if (
            $this->id < 0
            || preg_match(
                '/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/',
                $this->key
            ) !== 1
            || trim($this->label) === ''
            || strlen($this->label) > 50
            || trim($this->body) === ''
            || strlen($this->body) > 1048576
            || preg_match('//u', $this->body) !== 1
            || $this->sortOrder < 1
        ) {
            throw new InvalidArgumentException('퀴즈 선택지 형식이 올바르지 않습니다.');
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

    public function label(): string
    {
        return $this->label;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function isCorrect(): bool
    {
        return $this->correct;
    }

    public function sortOrder(): int
    {
        return $this->sortOrder;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
