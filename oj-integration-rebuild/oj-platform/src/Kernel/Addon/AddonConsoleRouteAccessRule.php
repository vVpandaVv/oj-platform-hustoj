<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Addon;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;
use CodingPanda\OjPlatform\Modules\PublicUi\Contract\ConsoleRouteAccessRule;

/**
 * Grants access to an accepted addon's console routes based on the
 * capability its manifest declared for that page. PlatformRouteAccessPolicy
 * consults this only when a route key has no entry in the static core
 * console-capability map, which is always true for addon routes.
 *
 * @internal
 */
final class AddonConsoleRouteAccessRule implements ConsoleRouteAccessRule
{
    /**
     * @param array<string, string> $routeKeyToCapability
     */
    public function __construct(
        private readonly array $routeKeyToCapability,
        private readonly Authorization $permissions
    ) {
    }

    public function supports(RouteMatch $route): bool
    {
        return isset($this->routeKeyToCapability[$route->route()->key()]);
    }

    public function allows(int $userId, RouteMatch $route): bool
    {
        $capability = $this->routeKeyToCapability[$route->route()->key()] ?? null;

        return $capability !== null
            && $this->permissions->can($userId, 'oj_access_console')
            && $this->permissions->can($userId, $capability);
    }
}
