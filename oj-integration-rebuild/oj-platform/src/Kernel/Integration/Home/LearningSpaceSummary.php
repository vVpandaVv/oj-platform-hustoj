<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Integration\Home;

use CodingPanda\OjPlatform\Ui\View\RouteTarget;

final class LearningSpaceSummary
{
    public function __construct(
        private readonly string $category,
        private readonly string $title,
        private readonly string $description,
        private readonly string $meta,
        private readonly string $tone,
        private readonly RouteTarget $target
    ) {
    }

    public function category(): string { return $this->category; }
    public function title(): string { return $this->title; }
    public function description(): string { return $this->description; }
    public function meta(): string { return $this->meta; }
    public function tone(): string { return $this->tone; }
    public function target(): RouteTarget { return $this->target; }

    public function homeItem(): \CodingPanda\OjPlatform\Modules\PublicUi\Domain\HomeBlockItem
    {
        return new \CodingPanda\OjPlatform\Modules\PublicUi\Domain\HomeBlockItem(
            $this->title, $this->description, $this->target, $this->category,
            $this->tone, $this->meta, '열기'
        );
    }
}
