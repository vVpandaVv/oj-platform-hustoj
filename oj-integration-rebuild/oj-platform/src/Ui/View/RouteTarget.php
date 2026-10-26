<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Ui\View;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteUrlProvider;
use InvalidArgumentException;

final class RouteTarget
{
    /**
     * @param array<string, int|string> $parameters
     */
    private function __construct(
        private readonly ?string $routeName,
        private readonly array $parameters,
        private readonly ?string $internalPath
    ) {
        if (($this->routeName === null) === ($this->internalPath === null)) {
            throw new InvalidArgumentException('화면 이동 대상은 Route 또는 내부 경로 하나만 지정해야 합니다.');
        }
        if (
            $this->routeName !== null
            && preg_match('/^[a-z][a-z0-9_.]*$/', $this->routeName) !== 1
        ) {
            throw new InvalidArgumentException('화면 이동 Route 이름이 올바르지 않습니다.');
        }
        if (
            $this->internalPath !== null
            && (
                ! str_starts_with($this->internalPath, '/')
                || str_starts_with($this->internalPath, '//')
                || preg_match('/[\x00-\x1f\x7f]/', $this->internalPath) === 1
            )
        ) {
            throw new InvalidArgumentException('화면 이동 내부 경로가 올바르지 않습니다.');
        }
    }

    /** @param array<string, int|string> $parameters */
    public static function route(string $routeName, array $parameters = []): self
    {
        return new self($routeName, $parameters, null);
    }

    public static function internalPath(string $path): self
    {
        return new self(null, [], $path);
    }

    public function resolve(RouteUrlProvider $urls): string
    {
        return $this->routeName === null
            ? (string) $this->internalPath
            : $urls->path($this->routeName, $this->parameters);
    }
}
