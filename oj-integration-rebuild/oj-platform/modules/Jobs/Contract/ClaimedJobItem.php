<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Jobs\Contract;

use InvalidArgumentException;

final class ClaimedJobItem
{
    public function __construct(
        private readonly int $jobId,
        private readonly int $itemId,
        private readonly string $jobType,
        private readonly string $targetType,
        private readonly string $targetKey,
        private readonly ?string $targetVersion,
        private readonly ?string $targetUpdatedAt,
        private readonly string $inputHash,
        private readonly FormDraftReference $payloadReference,
        private readonly int $attempt,
        private readonly int $maxAttempts,
        private readonly int $createdByUserId,
        private readonly string $leaseToken
    ) {
        if (
            $this->jobId < 1
            || $this->itemId < 1
            || preg_match('/^[a-z][a-z0-9_]*$/', $this->jobType) !== 1
            || preg_match('/^[a-f0-9]{64}$/', $this->leaseToken) !== 1
            || $this->attempt < 1
            || $this->maxAttempts < 1
            || $this->createdByUserId < 1
        ) {
            throw new InvalidArgumentException('임대한 Jobs 항목이 올바르지 않습니다.');
        }
    }

    public function jobId(): int
    {
        return $this->jobId;
    }

    public function itemId(): int
    {
        return $this->itemId;
    }

    public function jobType(): string
    {
        return $this->jobType;
    }

    public function targetType(): string
    {
        return $this->targetType;
    }

    public function targetKey(): string
    {
        return $this->targetKey;
    }

    public function targetVersion(): ?string
    {
        return $this->targetVersion;
    }

    public function targetUpdatedAt(): ?string
    {
        return $this->targetUpdatedAt;
    }

    public function inputHash(): string
    {
        return $this->inputHash;
    }

    public function payloadReference(): FormDraftReference
    {
        return $this->payloadReference;
    }

    public function attempt(): int
    {
        return $this->attempt;
    }

    public function maxAttempts(): int
    {
        return $this->maxAttempts;
    }

    public function createdByUserId(): int
    {
        return $this->createdByUserId;
    }

    public function leaseToken(): string
    {
        return $this->leaseToken;
    }
}
