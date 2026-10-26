<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Domain;

use InvalidArgumentException;

final class DifficultyScore
{
    public const MIN = 1;
    public const MAX = 5;
    public const RUBRIC_VERSION = 'difficulty-rubric-v1';

    public function __construct(private readonly int $value)
    {
        if ($this->value < self::MIN || $this->value > self::MAX) {
            throw new InvalidArgumentException('문제 난이도는 1부터 5까지의 정수여야 합니다.');
        }
    }

    public function value(): int
    {
        return $this->value;
    }
}
