<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AccessRouter\Contract;

interface RouteUrlProvider
{
    /**
     * @param array<string, int|string> $parameters
     */
    public function path(string $routeName, array $parameters = []): string;
}
