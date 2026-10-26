<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonAiTaskGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextGatewayV1;

/** @internal */
final class UnavailableAddonRuntimeServiceFactory implements AddonRuntimeServiceFactory
{
    public function __construct(private readonly AddonCorrelationIdFactory $correlations)
    {
    }

    public function contexts(string $addonId, ?AddonCatalogEntry $entry): AddonContextGatewayV1
    {
        return new UnavailableAddonContextGateway($addonId, $this->correlations);
    }

    public function aiTasks(string $addonId, ?AddonCatalogEntry $entry): AddonAiTaskGatewayV1
    {
        return new UnavailableAddonAiTaskGateway($addonId, $this->correlations);
    }
}
