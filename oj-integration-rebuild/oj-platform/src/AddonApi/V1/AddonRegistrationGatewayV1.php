<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\AddonApi\V1;

interface AddonRegistrationGatewayV1
{
    public function apiVersion(): string;

    /** @param callable(AddonManifestBuilderV1): void $build */
    public function register(string $addonId, callable $build): void;
}
