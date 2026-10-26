<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Domain;

use InvalidArgumentException;

final class ProblemArchiveResult
{
    public function __construct(
        private readonly int $fileCount,
        private readonly int $totalBytes,
        private readonly string $resultSha256
    ) {
        if (
            $this->fileCount < 1
            || $this->totalBytes < 0
            || preg_match('/^[a-f0-9]{64}$/', $this->resultSha256) !== 1
        ) {
            throw new InvalidArgumentException('ZIP 작업 결과가 올바르지 않습니다.');
        }
    }

    public function fileCount(): int
    {
        return $this->fileCount;
    }

    public function totalBytes(): int
    {
        return $this->totalBytes;
    }

    public function resultSha256(): string
    {
        return $this->resultSha256;
    }
}
