<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Domain;

use InvalidArgumentException;

final class QuizRevision
{
    public function __construct(
        private readonly int $id,
        private readonly ProblemId $problemId,
        private readonly int $revisionNumber,
        private readonly string $status,
        private readonly bool $currentPublished,
        private readonly QuizDefinition $definition,
        private readonly string $contentHash,
        private readonly int $createdByUserId,
        private readonly ?int $publishedByUserId,
        private readonly ?string $publishedAt
    ) {
        if (
            $this->id < 1
            || $this->revisionNumber < 0
            || ! in_array($this->status, ['draft', 'published'], true)
            || (
                $this->status === 'draft'
                && ($this->revisionNumber !== 0 || $this->currentPublished)
            )
            || (
                $this->status === 'published'
                && $this->revisionNumber < 1
            )
            || ! hash_equals($this->definition->contentHash(), $this->contentHash)
            || $this->createdByUserId < 1
            || (
                $this->status === 'published'
                && ($this->publishedByUserId === null || $this->publishedAt === null)
            )
        ) {
            throw new InvalidArgumentException('퀴즈 버전 형식이 올바르지 않습니다.');
        }
    }

    public function id(): int
    {
        return $this->id;
    }

    public function problemId(): ProblemId
    {
        return $this->problemId;
    }

    public function revisionNumber(): int
    {
        return $this->revisionNumber;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function isCurrentPublished(): bool
    {
        return $this->currentPublished;
    }

    public function definition(): QuizDefinition
    {
        return $this->definition;
    }

    public function contentHash(): string
    {
        return $this->contentHash;
    }

    public function createdByUserId(): int
    {
        return $this->createdByUserId;
    }

    public function publishedByUserId(): ?int
    {
        return $this->publishedByUserId;
    }

    public function publishedAt(): ?string
    {
        return $this->publishedAt;
    }
}
