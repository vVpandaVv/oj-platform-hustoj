<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\AddonApi\V1;

use CodingPanda\OjPlatform\AddonApi\V1\Internal\ValueValidator;
use InvalidArgumentException;

final class AddonActivityQueryV1
{
    /** @param list<string> $kinds */
    public function __construct(
        private readonly int $actorUserId,
        private readonly string $search = '',
        private readonly array $kinds = [],
        private readonly int $limit = 50
    ) {
        ValueValidator::positiveUserId($this->actorUserId, 'actorUserId');
        ValueValidator::text($this->search, 'search', 120, true);
        ValueValidator::stringList($this->kinds, 'kinds', 8);
        foreach ($this->kinds as $kind) {
            ValueValidator::oneOf($kind, self::allowedKinds(), 'kinds');
        }
        if ($this->limit < 1 || $this->limit > 100) {
            throw new InvalidArgumentException('Addon API 값이 올바르지 않습니다: limit');
        }
    }

    public function actorUserId(): int { return $this->actorUserId; }
    public function search(): string { return trim($this->search); }
    /** @return list<string> */
    public function kinds(): array { return array_values(array_unique($this->kinds)); }
    public function limit(): int { return $this->limit; }

    /** @return list<string> */
    private static function allowedKinds(): array
    {
        return ['page', 'material', 'game', 'worksheet', 'practice', 'other'];
    }
}
