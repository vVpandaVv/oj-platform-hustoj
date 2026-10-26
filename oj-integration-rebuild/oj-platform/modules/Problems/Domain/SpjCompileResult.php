<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Domain;

use InvalidArgumentException;

final class SpjCompileResult
{
    public function __construct(
        private readonly bool $succeeded,
        private readonly string $profileKey,
        private readonly ?string $binarySha256,
        private readonly int $binaryBytes,
        private readonly string $logSha256,
        private readonly string $resultCode
    ) {
        if (
            preg_match('/^[a-z][a-z0-9_]*$/', $this->profileKey) !== 1
            || preg_match('/^[a-z][a-z0-9_.-]*$/', $this->resultCode) !== 1
            || preg_match('/^[a-f0-9]{64}$/', $this->logSha256) !== 1
            || (
                $this->succeeded
                && (
                    $this->binarySha256 === null
                    || preg_match('/^[a-f0-9]{64}$/', $this->binarySha256) !== 1
                    || $this->binaryBytes < 1
                )
            )
        ) {
            throw new InvalidArgumentException('SPJ 컴파일 결과가 올바르지 않습니다.');
        }
    }

    public function succeeded(): bool
    {
        return $this->succeeded;
    }

    public function profileKey(): string
    {
        return $this->profileKey;
    }

    public function binarySha256(): ?string
    {
        return $this->binarySha256;
    }

    public function binaryBytes(): int
    {
        return $this->binaryBytes;
    }

    public function logSha256(): string
    {
        return $this->logSha256;
    }

    public function resultCode(): string
    {
        return $this->resultCode;
    }
}
