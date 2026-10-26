<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\AddonApi\V1;

use CodingPanda\OjPlatform\AddonApi\V1\Internal\ValueValidator;
use InvalidArgumentException;

final class AddonProblemQueryV1
{
    public function __construct(
        private readonly int $actorUserId,
        private readonly string $search = '',
        private readonly int $limit = 20
    ) {
        ValueValidator::positiveUserId($this->actorUserId, 'actorUserId');
        ValueValidator::text($this->search, 'search', 120, true);
        if ($this->limit < 1 || $this->limit > 100) {
            throw new InvalidArgumentException('Addon API 값이 올바르지 않습니다: limit');
        }
    }

    public function actorUserId(): int { return $this->actorUserId; }
    public function search(): string { return trim($this->search); }
    public function limit(): int { return $this->limit; }
}
