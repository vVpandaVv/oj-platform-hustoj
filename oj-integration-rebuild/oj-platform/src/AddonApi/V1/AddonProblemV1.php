<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\AddonApi\V1;

use CodingPanda\OjPlatform\AddonApi\V1\Internal\ValueValidator;
use InvalidArgumentException;

final class AddonProblemV1
{
    public function __construct(
        private readonly int $problemId,
        private readonly string $title,
        private readonly int $submitted,
        private readonly int $accepted
    ) {
        if ($this->problemId < 1 || $this->submitted < 0 || $this->accepted < 0 || $this->accepted > $this->submitted) {
            throw new InvalidArgumentException('Addon API 값이 올바르지 않습니다: problem');
        }
        ValueValidator::text($this->title, 'title', 200);
    }

    public function problemId(): int { return $this->problemId; }
    public function title(): string { return $this->title; }
    public function submitted(): int { return $this->submitted; }
    public function accepted(): int { return $this->accepted; }
}
