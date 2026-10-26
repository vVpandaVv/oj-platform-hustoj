<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\AddonApi\V1;

use CodingPanda\OjPlatform\AddonApi\V1\Internal\ValueValidator;
use InvalidArgumentException;

final class AddonContextPageV1
{
    /** @param list<AddonContextSummaryV1> $items */
    public function __construct(
        private readonly bool $successful,
        private readonly array $items,
        private readonly string $nextCursor,
        private readonly ?AddonApiProblemV1 $problem
    ) {
        if (! array_is_list($this->items) || count($this->items) > 100) {
            throw new InvalidArgumentException('Addon API Context 목록이 올바르지 않습니다.');
        }
        foreach ($this->items as $item) {
            if (! $item instanceof AddonContextSummaryV1) {
                throw new InvalidArgumentException('Addon API Context 목록이 올바르지 않습니다.');
            }
        }
        ValueValidator::text($this->nextCursor, 'nextCursor', 512, true, false);
        if (
            ($this->successful && $this->problem !== null)
            || (! $this->successful && ($this->problem === null || $this->items !== []))
        ) {
            throw new InvalidArgumentException('Addon API Context 결과 상태가 올바르지 않습니다.');
        }
    }

    public function isSuccessful(): bool { return $this->successful; }
    /** @return list<AddonContextSummaryV1> */
    public function items(): array { return $this->items; }
    public function nextCursor(): string { return $this->nextCursor; }
    public function problem(): ?AddonApiProblemV1 { return $this->problem; }
}
