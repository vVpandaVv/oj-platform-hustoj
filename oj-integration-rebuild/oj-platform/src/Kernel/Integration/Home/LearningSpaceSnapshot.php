<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Integration\Home;

final class LearningSpaceSnapshot
{
    /** @param list<LearningSpaceSummary> $items */
    public function __construct(
        private readonly array $items,
        private readonly bool $partial
    ) {
    }

    /** @return list<LearningSpaceSummary> */
    public function items(): array { return $this->items; }
    public function partial(): bool { return $this->partial; }
}
