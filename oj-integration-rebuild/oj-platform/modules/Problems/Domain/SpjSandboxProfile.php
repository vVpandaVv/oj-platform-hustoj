<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Domain;

use InvalidArgumentException;

final class SpjSandboxProfile
{
    public function __construct(private readonly string $executable)
    {
        if (! str_starts_with($this->executable, '/')) {
            throw new InvalidArgumentException(
                'SPJ 격리 실행기 경로는 절대 경로여야 합니다.'
            );
        }
    }

    public function executable(): string
    {
        return $this->executable;
    }
}
