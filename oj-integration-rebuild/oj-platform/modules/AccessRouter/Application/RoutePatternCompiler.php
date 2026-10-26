<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AccessRouter\Application;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteDefinition;

final class RoutePatternCompiler
{
    public function matchPattern(RouteDefinition $route): string
    {
        $pattern = preg_quote($route->path(), '#');
        foreach ($route->parameters() as $parameterName) {
            $pattern = str_replace(
                preg_quote('{' . $parameterName . '}', '#'),
                '(?P<' . $parameterName . '>[^/]+)',
                $pattern
            );
        }

        return '#^' . $pattern . '$#u';
    }

    /**
     * @return array{pattern: string, query: string}
     */
    public function rewriteRule(RouteDefinition $route): array
    {
        $path = ltrim($route->path(), '/');
        $pattern = preg_quote($path, '#');
        $queryPath = $route->path();
        $position = 1;

        foreach ($route->parameters() as $parameterName) {
            $placeholder = '{' . $parameterName . '}';
            $pattern = str_replace(
                preg_quote($placeholder, '#'),
                '([^/]+)',
                $pattern
            );
            $queryPath = str_replace(
                $placeholder,
                '$matches[' . $position . ']',
                $queryPath
            );
            ++$position;
        }

        return [
            'pattern' => $path === '' ? '^$' : '^' . $pattern . '/?$',
            'query' => 'index.php?oj_route=' . $queryPath,
        ];
    }

    /**
     * @param list<RouteDefinition> $routes
     * @return list<RouteDefinition>
     */
    public function ordered(array $routes): array
    {
        usort(
            $routes,
            static function (RouteDefinition $left, RouteDefinition $right): int {
                return [
                    count($left->parameters()),
                    -strlen($left->path()),
                    $left->key(),
                ] <=> [
                    count($right->parameters()),
                    -strlen($right->path()),
                    $right->key(),
                ];
            }
        );

        return $routes;
    }
}
