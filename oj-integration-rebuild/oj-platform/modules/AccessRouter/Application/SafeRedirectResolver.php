<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AccessRouter\Application;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\AuthenticationRequirement;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandlerCollection;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\SafeRedirect;

final class SafeRedirectResolver implements SafeRedirect
{
    public function __construct(
        private readonly RouteMatcher $matcher,
        private readonly RouteHandlerCollection $handlers
    ) {
    }

    public function afterLogin(string $candidate): string
    {
        if (
            $candidate === ''
            || ! str_starts_with($candidate, '/')
            || str_starts_with($candidate, '//')
        ) {
            return '/';
        }

        $path = parse_url($candidate, PHP_URL_PATH);
        if (! is_string($path)) {
            return '/';
        }

        $match = $this->matcher->match('GET', $path);
        if (
            $match === null
            || $match->route()->authentication() !== AuthenticationRequirement::Authenticated
            || $this->handlers->find($match->route()->key()) === null
        ) {
            return '/';
        }

        return $path;
    }
}
