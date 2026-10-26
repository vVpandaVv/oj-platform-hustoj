<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AccessRouter\Application;

use CodingPanda\OjPlatform\Kernel\Registry\DefinitionRegistry;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteDefinition;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteUrlProvider;
use InvalidArgumentException;

final class RouteUrlGenerator implements RouteUrlProvider
{
    /**
     * @param DefinitionRegistry<RouteDefinition> $routes
     */
    public function __construct(private readonly DefinitionRegistry $routes)
    {
    }

    /**
     * @param array<string, int|string> $parameters
     */
    public function path(string $routeName, array $parameters = []): string
    {
        $route = $this->routes->find($routeName);
        if (! $route instanceof RouteDefinition) {
            throw new InvalidArgumentException(sprintf('등록되지 않은 Route입니다: %s', $routeName));
        }

        $expected = $route->parameters();
        $provided = array_keys($parameters);
        sort($expected);
        sort($provided);

        if ($expected !== $provided) {
            throw new InvalidArgumentException('Route 매개변수가 경로 계약과 일치하지 않습니다.');
        }

        $path = $route->path();
        foreach ($parameters as $name => $value) {
            $encoded = rawurlencode((string) $value);
            if ($encoded === '') {
                throw new InvalidArgumentException('Route 매개변수는 비어 있을 수 없습니다.');
            }

            $path = str_replace('{' . $name . '}', $encoded, $path);
        }

        return $path;
    }
}
