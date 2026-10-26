<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Domain;

use InvalidArgumentException;

final class ProblemArchiveEntry
{
    public function __construct(
        private readonly string $name,
        private readonly int $uncompressedBytes,
        private readonly int $compressedBytes,
        private readonly ?string $collisionSha256
    ) {
        if (
            trim($this->name) === ''
            || $this->uncompressedBytes < 0
            || $this->compressedBytes < 0
            || (
                $this->collisionSha256 !== null
                && preg_match('/^[a-f0-9]{64}$/', $this->collisionSha256) !== 1
            )
        ) {
            throw new InvalidArgumentException('ZIP 항목 정보가 올바르지 않습니다.');
        }
    }

    public function name(): string
    {
        return $this->name;
    }

    public function uncompressedBytes(): int
    {
        return $this->uncompressedBytes;
    }

    public function compressedBytes(): int
    {
        return $this->compressedBytes;
    }

    public function hasCollision(): bool
    {
        return $this->collisionSha256 !== null;
    }

    public function collisionSha256(): ?string
    {
        return $this->collisionSha256;
    }
}
