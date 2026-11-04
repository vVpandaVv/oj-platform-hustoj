<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AccessRouter\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteUrlProvider;

/** Registry URLs ready for browser JSON as well as ordinary HTML attributes. */
final class WordPressBrowserRouteUrlProvider implements RouteUrlProvider
{
    public function __construct(
        private readonly RouteUrlProvider $routes,
        private readonly WordPressRouteTransport $transport
    ) {
    }

    public function path(string $routeName, array $parameters = []): string
    {
        return $this->transport->routeUrl($this->routes->path($routeName, $parameters));
    }
}
