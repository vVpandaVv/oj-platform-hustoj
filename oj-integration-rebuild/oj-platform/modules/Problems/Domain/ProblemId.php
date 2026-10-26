<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Domain;

use InvalidArgumentException;

final class ProblemId
{
    public function __construct(private readonly int $value)
    {
        if ($this->value < 1) {
            throw new InvalidArgumentException('문제 번호는 1 이상의 정수여야 합니다.');
        }
    }

    public function value(): int
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
