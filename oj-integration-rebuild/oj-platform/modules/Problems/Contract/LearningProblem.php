<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Contract;

final class LearningProblem
{
    /**
     * @param list<string> $topicKeys
     */
    public function __construct(
        private readonly int $problemId,
        private readonly string $title,
        private readonly string $description,
        private readonly string $input,
        private readonly string $output,
        private readonly string $hint,
        private readonly ?int $difficulty,
        private readonly array $topicKeys,
        private readonly int $accepted,
        private readonly int $submitted,
        private readonly bool $published,
        private readonly string $contentHash
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

    public function description(): string
    {
        return $this->description;
    }

    public function input(): string
    {
        return $this->input;
    }

    public function output(): string
    {
        return $this->output;
    }

    public function hint(): string
    {
        return $this->hint;
    }

    public function difficulty(): ?int
    {
        return $this->difficulty;
    }

    /**
     * @return list<string>
     */
    public function topicKeys(): array
    {
        return $this->topicKeys;
    }

    public function accepted(): int
    {
        return $this->accepted;
    }

    public function submitted(): int
    {
        return $this->submitted;
    }

    public function isPublished(): bool
    {
        return $this->published;
    }

    public function contentHash(): string
    {
        return $this->contentHash;
    }

    public function effectiveDifficulty(): ?int
    {
        if ($this->difficulty !== null) {
            return $this->difficulty;
        }
        if ($this->submitted < 10) {
            return null;
        }

        $rate = ($this->accepted / max(1, $this->submitted)) * 100;

        return match (true) {
            $rate >= 70 => 1,
            $rate >= 50 => 2,
            $rate >= 30 => 3,
            $rate >= 15 => 4,
            default => 5,
        };
    }

    public function difficultySource(): ?string
    {
        if ($this->difficulty !== null) {
            return 'metadata';
        }

        return $this->submitted >= 10 ? 'accepted_rate' : null;
    }
}
