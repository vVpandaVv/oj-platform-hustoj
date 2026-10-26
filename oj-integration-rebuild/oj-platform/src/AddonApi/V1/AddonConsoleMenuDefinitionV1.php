<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\AddonApi\V1;

use CodingPanda\OjPlatform\AddonApi\V1\Internal\ValueValidator;

final class AddonConsoleMenuDefinitionV1
{
    public function __construct(
        private readonly string $key,
        private readonly string $label,
        private readonly string $anchor,
        private readonly string $route,
        private readonly string $capability,
        private readonly int $order,
        private readonly string $icon = ''
    ) {
        ValueValidator::contributionKey($this->key);
        ValueValidator::label($this->label);
        ValueValidator::simpleKey($this->anchor, 'anchor');
        ValueValidator::contributionKey($this->route, 'route');
        ValueValidator::capabilityKey($this->capability);
        if ($this->icon !== '') {
            ValueValidator::simpleKey($this->icon, 'icon');
        }
    }

    public function key(): string { return $this->key; }
    public function label(): string { return $this->label; }
    public function anchor(): string { return $this->anchor; }
    public function route(): string { return $this->route; }
    public function capability(): string { return $this->capability; }
    public function order(): int { return $this->order; }
    public function icon(): string { return $this->icon; }
}
