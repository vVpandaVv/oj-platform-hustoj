<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Shared\Pagination;

use InvalidArgumentException;

/**
 * @template TItem
 */
final class Page
{
    /**
     * @param list<TItem> $items
     */
    public function __construct(
        private readonly array $items,
        private readonly int $totalItems,
        private readonly PageRequest $request
    ) {
        if ($this->totalItems < 0) {
            throw new InvalidArgumentException('Total items cannot be negative.');
        }

        if (count($this->items) > $this->request->perPage()) {
            throw new InvalidArgumentException('Page items exceed the requested page size.');
        }

        if (count($this->items) > $this->totalItems) {
            throw new InvalidArgumentException('Page items cannot exceed the total item count.');
        }
    }

    /**
     * @return list<TItem>
     */
    public function items(): array
    {
        return array_values($this->items);
    }

    public function totalItems(): int
    {
        return $this->totalItems;
    }

    public function request(): PageRequest
    {
        return $this->request;
    }

    public function totalPages(): int
    {
        if ($this->totalItems === 0) {
            return 0;
        }

        return (int) ceil($this->totalItems / $this->request->perPage());
    }

    public function hasPreviousPage(): bool
    {
        return $this->request->page() > 1;
    }

    public function hasNextPage(): bool
    {
        return $this->request->page() < $this->totalPages();
    }
}
