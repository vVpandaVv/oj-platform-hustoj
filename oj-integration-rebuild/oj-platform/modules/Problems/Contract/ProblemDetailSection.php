<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Contract;

final class ProblemDetailSection
{
    /**
     * @param list<ProblemDetailSectionItem> $items
     */
    public function __construct(
        private readonly string $key,
        private readonly string $title,
        private readonly array $items,
        private readonly string $primaryLabel,
        private readonly string $primaryUrl,
        private readonly string $secondaryLabel,
        private readonly string $secondaryUrl
    ) {
    }

    public function key(): string { return $this->key; }
    public function title(): string { return $this->title; }
    /** @return list<ProblemDetailSectionItem> */
    public function items(): array { return $this->items; }
    public function primaryLabel(): string { return $this->primaryLabel; }
    public function primaryUrl(): string { return $this->primaryUrl; }
    public function secondaryLabel(): string { return $this->secondaryLabel; }
    public function secondaryUrl(): string { return $this->secondaryUrl; }
}
