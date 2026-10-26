<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Domain;

use InvalidArgumentException;

final class PersonalProblemBookItem
{
    public function __construct(
        private readonly int $id,
        private readonly int $userId,
        private readonly ProblemId $problemId,
        private readonly string $note,
        private readonly int $sortOrder,
        private readonly string $createdAt,
        private readonly string $updatedAt
    ) {
        if (
            $this->id < 1
            || $this->userId < 1
            || strlen($this->note) > 65535
            || preg_match('//u', $this->note) !== 1
            || $this->sortOrder < 0
        ) {
            throw new InvalidArgumentException('개인 문제집 항목 형식이 올바르지 않습니다.');
        }
    }

    public function id(): int
    {
        return $this->id;
    }

    public function userId(): int
    {
        return $this->userId;
    }

    public function problemId(): ProblemId
    {
        return $this->problemId;
    }

    public function note(): string
    {
        return $this->note;
    }

    public function sortOrder(): int
    {
        return $this->sortOrder;
    }

    public function createdAt(): string
    {
        return $this->createdAt;
    }

    public function updatedAt(): string
    {
        return $this->updatedAt;
    }
}
