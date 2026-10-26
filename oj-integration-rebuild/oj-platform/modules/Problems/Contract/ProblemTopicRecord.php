<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Contract;

interface ProblemTopicRecord
{
    public function key(): string;

    public function label(): string;

    public function categoryKey(): ?string;

    /** @return list<string> */
    public function aliases(): array;

    public function isCategory(): bool;

    public function isActive(): bool;
}
