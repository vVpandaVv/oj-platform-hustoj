<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonIntegrationGatewayV1;

interface AddonIntegrationRuntimeServiceFactory extends AddonRuntimeServiceFactory
{
    public function integrations(string $addonId, ?AddonCatalogEntry $entry): AddonIntegrationGatewayV1;
}
