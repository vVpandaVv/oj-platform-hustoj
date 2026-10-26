<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Shared\Pagination;

use InvalidArgumentException;

final class PageRequest
{
    public function __construct(
        private readonly int $page,
        private readonly int $perPage
    ) {
        if ($this->page < 1) {
            throw new InvalidArgumentException('Page must be greater than or equal to 1.');
        }

        if ($this->perPage < 1 || $this->perPage > 100) {
            throw new InvalidArgumentException('Items per page must be between 1 and 100.');
        }
    }

    public function page(): int
    {
        return $this->page;
    }

    public function perPage(): int
    {
        return $this->perPage;
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }
}
