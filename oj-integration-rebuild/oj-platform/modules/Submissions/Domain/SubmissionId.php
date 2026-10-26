<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Domain;

use InvalidArgumentException;

final class SubmissionId
{
    public function __construct(private readonly int $value)
    {
        if ($this->value < 1) {
            throw new InvalidArgumentException('제출 번호는 1 이상이어야 합니다.');
        }
    }

    public function value(): int
    {
        return $this->value;
    }
}
