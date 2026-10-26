<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AccessRouter\Contract;

use InvalidArgumentException;

final class UploadedFile
{
    public function __construct(
        private readonly string $clientName,
        private readonly string $temporaryPath,
        private readonly int $size,
        private readonly int $errorCode
    ) {
        if ($this->size < 0 || $this->errorCode < 0) {
            throw new InvalidArgumentException('업로드 파일 정보가 올바르지 않습니다.');
        }
    }

    public function clientName(): string
    {
        return $this->clientName;
    }

    public function temporaryPath(): string
    {
        return $this->temporaryPath;
    }

    public function size(): int
    {
        return $this->size;
    }

    public function errorCode(): int
    {
        return $this->errorCode;
    }

    public function isSuccessful(): bool
    {
        return $this->errorCode === UPLOAD_ERR_OK
            && $this->temporaryPath !== ''
            && $this->size > 0;
    }
}
