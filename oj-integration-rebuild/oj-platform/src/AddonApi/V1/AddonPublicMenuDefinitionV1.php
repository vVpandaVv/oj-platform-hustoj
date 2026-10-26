<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\AddonApi\V1;

use CodingPanda\OjPlatform\AddonApi\V1\Internal\ValueValidator;

final class AddonPublicMenuDefinitionV1
{
    public function __construct(
        private readonly string $key,
        private readonly string $label,
        private readonly string $parent,
        private readonly string $route,
        private readonly string $capability,
        private readonly int $order,
        private readonly string $scope,
        private readonly bool $mobile = true,
        private readonly bool $defaultEnabled = true
    ) {
        ValueValidator::contributionKey($this->key);
        ValueValidator::label($this->label);
        ValueValidator::simpleKey($this->parent, 'parent');
        ValueValidator::contributionKey($this->route, 'route');
        ValueValidator::capabilityKey($this->capability);
        ValueValidator::oneOf($this->scope, ['learner'], 'scope');
    }

    public function key(): string { return $this->key; }
    public function label(): string { return $this->label; }
    public function parent(): string { return $this->parent; }
    public function route(): string { return $this->route; }
    public function capability(): string { return $this->capability; }
    public function order(): int { return $this->order; }
    public function scope(): string { return $this->scope; }
    public function mobile(): bool { return $this->mobile; }
    public function defaultEnabled(): bool { return $this->defaultEnabled; }
}
