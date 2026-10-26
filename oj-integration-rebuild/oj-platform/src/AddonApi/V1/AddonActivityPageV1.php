<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\AddonApi\V1;

use InvalidArgumentException;

final class AddonActivityPageV1
{
    /** @param list<AddonActivityV1> $items */
    public function __construct(
        private readonly bool $successful,
        private readonly array $items,
        private readonly ?AddonApiProblemV1 $problem
    ) {
        foreach ($this->items as $item) {
            if (! $item instanceof AddonActivityV1) {
                throw new InvalidArgumentException('Addon API 값이 올바르지 않습니다: items');
            }
        }
        if ($this->successful === ($this->problem instanceof AddonApiProblemV1)) {
            throw new InvalidArgumentException('Addon activity page invariant failed.');
        }
    }

    public function isSuccessful(): bool { return $this->successful; }
    /** @return list<AddonActivityV1> */
    public function items(): array { return $this->items; }
    public function problem(): ?AddonApiProblemV1 { return $this->problem; }
}
