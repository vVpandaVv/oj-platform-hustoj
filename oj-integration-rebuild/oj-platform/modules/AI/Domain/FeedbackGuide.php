<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AI\Domain;

use InvalidArgumentException;

final class FeedbackGuide
{
    public function __construct(
        private readonly string $key,
        private readonly string $name,
        private readonly string $level,
        private readonly string $purpose,
        private readonly string $instructions,
        private readonly int $revision,
        private readonly bool $active,
        private readonly bool $builtIn,
        private readonly string $updatedAt
    ) {
        if (
            preg_match('/^[a-z0-9][a-z0-9-]{1,99}$/', $this->key) !== 1
            || ! in_array(
                $this->level,
                ['insufficient', 'beginner', 'intermediate', 'advanced'],
                true
            )
            || trim($this->name) === ''
            || trim($this->instructions) === ''
            || $this->revision < 1
        ) {
            throw new InvalidArgumentException('AI 답변 지침이 올바르지 않습니다.');
        }
    }

    public function key(): string { return $this->key; }
    public function name(): string { return $this->name; }
    public function level(): string { return $this->level; }
    public function purpose(): string { return $this->purpose; }
    public function instructions(): string { return $this->instructions; }
    public function revision(): int { return $this->revision; }
    public function isActive(): bool { return $this->active; }
    public function isBuiltIn(): bool { return $this->builtIn; }
    public function updatedAt(): string { return $this->updatedAt; }
}
