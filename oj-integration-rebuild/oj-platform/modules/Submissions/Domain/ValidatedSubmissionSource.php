<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Domain;

final class ValidatedSubmissionSource
{
    /**
     * @param array<string, scalar|null> $metadata
     */
    public function __construct(
        private readonly SubmissionMode $mode,
        private readonly int $languageId,
        private readonly string $userSource,
        private readonly string $storedUserSource,
        private readonly string $judgeSource,
        private readonly array $metadata
    ) {
    }

    public function mode(): SubmissionMode
    {
        return $this->mode;
    }

    public function languageId(): int
    {
        return $this->languageId;
    }

    public function userSource(): string
    {
        return $this->userSource;
    }

    public function storedUserSource(): string
    {
        return $this->storedUserSource;
    }

    public function judgeSource(): string
    {
        return $this->judgeSource;
    }

    /**
     * @return array<string, scalar|null>
     */
    public function metadata(): array
    {
        return $this->metadata;
    }
}
