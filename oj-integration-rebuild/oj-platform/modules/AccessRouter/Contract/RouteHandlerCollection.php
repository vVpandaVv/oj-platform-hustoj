<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AccessRouter\Contract;

interface RouteHandlerCollection
{
    public function register(string $routeName, RouteHandler $handler): void;

    public function find(string $routeName): ?RouteHandler;

    /**
     * @return list<string>
     */
    public function keys(): array;
}
