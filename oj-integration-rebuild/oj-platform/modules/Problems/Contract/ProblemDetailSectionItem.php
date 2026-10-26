<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Contract;

final class ProblemDetailSectionItem
{
    public function __construct(
        private readonly string $title,
        private readonly string $url,
        private readonly string $meta,
        private readonly string $status
    ) {
    }

    public function title(): string { return $this->title; }
    public function url(): string { return $this->url; }
    public function meta(): string { return $this->meta; }
    public function status(): string { return $this->status; }
}
