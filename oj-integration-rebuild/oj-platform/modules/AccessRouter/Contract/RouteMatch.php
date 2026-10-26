<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AccessRouter\Contract;

final class RouteMatch
{
    /**
     * @param array<string, string> $parameters
     */
    public function __construct(
        private readonly RouteDefinition $route,
        private readonly array $parameters
    ) {
    }

    public function route(): RouteDefinition
    {
        return $this->route;
    }

    /**
     * @return array<string, string>
     */
    public function parameters(): array
    {
        return $this->parameters;
    }
}
