<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonAiTaskGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextGatewayV1;

interface AddonRuntimeServiceFactory
{
    public function contexts(string $addonId, ?AddonCatalogEntry $entry): AddonContextGatewayV1;

    public function aiTasks(string $addonId, ?AddonCatalogEntry $entry): AddonAiTaskGatewayV1;
}
