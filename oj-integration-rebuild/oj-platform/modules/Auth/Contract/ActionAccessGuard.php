<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Contract;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;

interface ActionAccessGuard
{
    public function verifyNonce(string $actionKey, HttpRequest $request): void;

    public function actor(
        string $actionKey,
        HttpRequest $request,
        string $capability
    ): UserIdentity;

    public function authenticatedActor(
        string $actionKey,
        HttpRequest $request
    ): UserIdentity;

    public function throttle(
        string $scope,
        string $identifier,
        int $limit,
        int $windowSeconds
    ): void;
}
