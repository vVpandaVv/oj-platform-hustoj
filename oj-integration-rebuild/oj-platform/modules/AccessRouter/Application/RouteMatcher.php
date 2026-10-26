<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AccessRouter\Application;

use CodingPanda\OjPlatform\Kernel\Registry\DefinitionRegistry;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteDefinition;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;

final class RouteMatcher
{
    /**
     * @var list<RouteDefinition>|null
     */
    private ?array $orderedRoutes = null;

    private readonly RoutePatternCompiler $patterns;

    /**
     * @param DefinitionRegistry<RouteDefinition> $routes
     */
    public function __construct(
        private readonly DefinitionRegistry $routes,
        ?RoutePatternCompiler $patterns = null
    ) {
        $this->patterns = $patterns ?? new RoutePatternCompiler();
    }

    public function match(string $method, string $requestPath): ?RouteMatch
    {
        $method = strtoupper($method);
        $path = parse_url($requestPath, PHP_URL_PATH);
        if (! is_string($path) || $path === '') {
            return null;
        }

        $path = '/' . ltrim($path, '/');
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        foreach ($this->orderedRoutes() as $route) {
            if (! in_array($method, $route->methods(), true)) {
                continue;
            }

            $parameterNames = $route->parameters();
            if (preg_match($this->patterns->matchPattern($route), $path, $matches) !== 1) {
                continue;
            }

            $parameters = [];
            foreach ($parameterNames as $parameterName) {
                $parameters[$parameterName] = rawurldecode((string) $matches[$parameterName]);
            }

            return new RouteMatch($route, $parameters);
        }

        return null;
    }

    /**
     * @return list<RouteDefinition>
     */
    private function orderedRoutes(): array
    {
        if ($this->orderedRoutes !== null) {
            return $this->orderedRoutes;
        }

        $this->orderedRoutes = $this->patterns->ordered(
            array_values($this->routes->all())
        );

        return $this->orderedRoutes;
    }
}
