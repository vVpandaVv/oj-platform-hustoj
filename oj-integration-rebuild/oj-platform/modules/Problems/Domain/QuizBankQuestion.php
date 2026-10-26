<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Domain;

use InvalidArgumentException;

final class QuizBankQuestion
{
    public function __construct(
        private readonly int $id,
        private readonly QuizQuestion $question,
        private readonly int $createdByUserId,
        private readonly string $createdAt
    ) {
        if ($this->id < 1 || $this->createdByUserId < 1) {
            throw new InvalidArgumentException('문항 은행 항목 형식이 올바르지 않습니다.');
        }
    }

    public function id(): int
    {
        return $this->id;
    }

    public function question(): QuizQuestion
    {
        return $this->question;
    }

    public function createdByUserId(): int
    {
        return $this->createdByUserId;
    }

    public function createdAt(): string
    {
        return $this->createdAt;
    }
}
