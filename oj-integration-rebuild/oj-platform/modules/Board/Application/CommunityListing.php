<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Board\Application;

final class CommunityListing
{
    /**
     * @param list<CommunityListItem> $items
     */
    public function __construct(
        private readonly array $items,
        private readonly int $total,
        private readonly int $page,
        private readonly int $pageSize
    ) {
    }

    /** @return list<CommunityListItem> */
    public function items(): array { return $this->items; }
    public function total(): int { return $this->total; }
    public function page(): int { return $this->page; }
    public function pageSize(): int { return $this->pageSize; }
    public function pages(): int
    {
        return max(1, (int) ceil($this->total / max(1, $this->pageSize)));
    }
}
