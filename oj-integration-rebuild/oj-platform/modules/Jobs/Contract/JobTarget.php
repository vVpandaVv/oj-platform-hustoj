<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Jobs\Contract;

use InvalidArgumentException;

final class JobTarget
{
    public function __construct(
        private readonly string $targetType,
        private readonly string $targetKey,
        private readonly array $payload,
        private readonly ?string $targetVersion = null,
        private readonly ?string $targetUpdatedAt = null
    ) {
        if (
            preg_match('/^[a-z][a-z0-9_]*$/', $this->targetType) !== 1
            || trim($this->targetKey) === ''
            || strlen($this->targetKey) > 191
        ) {
            throw new InvalidArgumentException('Jobs 대상이 올바르지 않습니다.');
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

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->payload;
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
