<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\AddonApi\V1;

use CodingPanda\OjPlatform\AddonApi\V1\Internal\ValueValidator;

/**
 * One segment of an addon's in-page navigation (Addon API 1.3).
 *
 * The sidebar keeps a single entry per addon menu; the console pages that
 * belong to that menu are listed here instead. The platform draws them as a
 * segmented control under the page title, keeps the parent menu highlighted
 * and hides segments the user has no capability for.
 */
final class AddonConsoleLocalNavItemV1
{
    public function __construct(
        private readonly string $key,
        private readonly string $label,
        private readonly string $menu,
        private readonly string $route,
        private readonly int $order
    ) {
        ValueValidator::contributionKey($this->key);
        ValueValidator::label($this->label);
        ValueValidator::contributionKey($this->menu, 'menu');
        ValueValidator::contributionKey($this->route, 'route');
    }

    public function key(): string { return $this->key; }
    public function label(): string { return $this->label; }
    public function menu(): string { return $this->menu; }
    public function route(): string { return $this->route; }
    public function order(): int { return $this->order; }
}
