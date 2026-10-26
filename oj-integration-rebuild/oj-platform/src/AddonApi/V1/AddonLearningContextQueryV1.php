<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\AddonApi\V1;

use CodingPanda\OjPlatform\AddonApi\V1\Internal\ValueValidator;
use InvalidArgumentException;

final class AddonLearningContextQueryV1
{
    /** @param list<string> $types */
    public function __construct(
        private readonly int $actorUserId,
        private readonly array $types,
        private readonly string $search = '',
        private readonly string $cursor = '',
        private readonly int $limit = 30
    ) {
        ValueValidator::positiveUserId($this->actorUserId, 'actorUserId');
        ValueValidator::stringList($this->types, 'types', 2);
        if ($this->types === [] || count(array_unique($this->types)) !== count($this->types)) {
            throw new InvalidArgumentException('Addon API 학습 Context 종류가 올바르지 않습니다.');
        }
        foreach ($this->types as $type) {
            ValueValidator::oneOf($type, ['class', 'group'], 'types');
        }
        ValueValidator::text($this->search, 'search', 200, true, false);
        ValueValidator::text($this->cursor, 'cursor', 512, true, false);
        if ($this->limit < 1 || $this->limit > 100) {
            throw new InvalidArgumentException('Addon API 학습 Context 조회 수량이 올바르지 않습니다.');
        }
    }

    public function actorUserId(): int { return $this->actorUserId; }
    /** @return list<string> */
    public function types(): array { return $this->types; }
    public function search(): string { return $this->search; }
    public function cursor(): string { return $this->cursor; }
    public function limit(): int { return $this->limit; }
}
