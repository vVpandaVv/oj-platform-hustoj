<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AccessRouter\Application;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandlerCollection;
use InvalidArgumentException;

final class RouteHandlerRegistry implements RouteHandlerCollection
{
    /**
     * @var array<string, RouteHandler>
     */
    private array $handlers = [];

    public function register(string $routeName, RouteHandler $handler): void
    {
        if (preg_match('/^[a-z][a-z0-9_.-]*$/', $routeName) !== 1) {
            throw new InvalidArgumentException('Route Handler 키 형식이 올바르지 않습니다.');
        }

        if (isset($this->handlers[$routeName])) {
            throw new InvalidArgumentException(sprintf(
                'Route Handler가 중복 등록되었습니다: %s',
                $routeName
            ));
        }

        $this->handlers[$routeName] = $handler;
    }

    public function find(string $routeName): ?RouteHandler
    {
        return $this->handlers[$routeName] ?? null;
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        $keys = array_keys($this->handlers);
        sort($keys);

        return $keys;
    }
}
