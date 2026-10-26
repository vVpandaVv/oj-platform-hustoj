<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Migration\Domain;

use InvalidArgumentException;

final class MigrationWorkResult
{
    /**
     * @param array<string, scalar|null> $checkpoint
     * @param array<string, scalar|null> $validation
     */
    public function __construct(
        private readonly string $resultCode,
        private readonly string $message,
        private readonly array $checkpoint = [],
        private readonly array $validation = [],
        private readonly bool $needsAttention = false
    ) {
        if (
            preg_match('/^[a-z][a-z0-9_.-]*$/', $this->resultCode) !== 1
            || trim($this->message) === ''
        ) {
            throw new InvalidArgumentException('이전 작업 결과가 올바르지 않습니다.');
        }
    }

    public function resultCode(): string
    {
        return $this->resultCode;
    }

    public function message(): string
    {
        return $this->message;
    }

    /** @return array<string, scalar|null> */
    public function checkpoint(): array
    {
        return $this->checkpoint;
    }

    /** @return array<string, scalar|null> */
    public function validation(): array
    {
        return $this->validation;
    }

    public function needsAttention(): bool
    {
        return $this->needsAttention;
    }
}
