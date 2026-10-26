<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Contract;

use InvalidArgumentException;

final class AccountErasureBlocker
{
    public function __construct(
        private readonly string $moduleKey,
        private readonly string $code,
        private readonly string $label,
        private readonly int $count
    ) {
        if (
            preg_match('/^[a-z][a-z0-9_]*$/', $this->moduleKey) !== 1
            || preg_match('/^[a-z][a-z0-9_.-]*$/', $this->code) !== 1
            || trim($this->label) === ''
            || $this->count < 1
        ) {
            throw new InvalidArgumentException('계정 익명화 차단 항목이 올바르지 않습니다.');
        }
    }

    public function moduleKey(): string
    {
        return $this->moduleKey;
    }

    public function code(): string
    {
        return $this->code;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function count(): int
    {
        return $this->count;
    }
}
