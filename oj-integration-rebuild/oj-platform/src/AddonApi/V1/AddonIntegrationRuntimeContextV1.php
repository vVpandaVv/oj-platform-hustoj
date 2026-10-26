<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\AddonApi\V1;

/** Optional Addon API 1.4 runtime extension. */
interface AddonIntegrationRuntimeContextV1 extends AddonRuntimeContextV1
{
    public function integrations(): AddonIntegrationGatewayV1;
}
