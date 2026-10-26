<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Jobs\Domain;

use CodingPanda\OjPlatform\Modules\Jobs\Contract\FormDraftReference;
use InvalidArgumentException;

final class JobItemPlan
{
    public function __construct(
        private readonly string $targetType,
        private readonly string $targetKey,
        private readonly string $inputHash,
        private readonly FormDraftReference $payloadReference,
        private readonly int $maxAttempts,
        private readonly ?string $targetVersion = null,
        private readonly ?string $targetUpdatedAt = null
    ) {
        if (
            preg_match('/^[a-z][a-z0-9_]*$/', $this->targetType) !== 1
            || trim($this->targetKey) === ''
            || strlen($this->targetKey) > 191
            || preg_match('/^[a-f0-9]{64}$/', $this->inputHash) !== 1
            || $this->maxAttempts < 1
            || $this->maxAttempts > 10
        ) {
            throw new InvalidArgumentException('Jobs 항목 계획이 올바르지 않습니다.');
        }
    }

    public function targetType(): string
    {
        return $this->targetType;
    }

    public function targetKey(): string
    {
        return $this->targetKey;
    }

    public function inputHash(): string
    {
        return $this->inputHash;
    }

    public function payloadReference(): FormDraftReference
    {
        return $this->payloadReference;
    }

    public function maxAttempts(): int
    {
        return $this->maxAttempts;
    }

    public function targetVersion(): ?string
    {
        return $this->targetVersion;
    }

    public function targetUpdatedAt(): ?string
    {
        return $this->targetUpdatedAt;
    }
}
