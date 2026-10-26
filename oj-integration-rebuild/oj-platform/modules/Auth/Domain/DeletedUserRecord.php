<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Domain;

use InvalidArgumentException;

final class DeletedUserRecord
{
    public function __construct(
        private readonly int $recordId,
        private readonly int $userId,
        private readonly string $anonymousLabel,
        private readonly int $deletedByUserId,
        private readonly string $deletedAt,
        private readonly ?string $reason,
        private readonly ?int $jobId
    ) {
        if (
            $this->recordId < 1
            || $this->userId < 1
            || $this->deletedByUserId < 1
            || trim($this->anonymousLabel) === ''
            || trim($this->deletedAt) === ''
        ) {
            throw new InvalidArgumentException('삭제 사용자 기록이 올바르지 않습니다.');
        }
    }

    public function recordId(): int
    {
        return $this->recordId;
    }

    public function userId(): int
    {
        return $this->userId;
    }

    public function anonymousLabel(): string
    {
        return $this->anonymousLabel;
    }

    public function deletedByUserId(): int
    {
        return $this->deletedByUserId;
    }

    public function deletedAt(): string
    {
        return $this->deletedAt;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }

    public function jobId(): ?int
    {
        return $this->jobId;
    }
}
