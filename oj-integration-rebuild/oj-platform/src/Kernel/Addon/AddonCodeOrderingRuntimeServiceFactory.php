<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeOrderingGatewayV1;

/** @internal Optional factory extension; the base factory contract stays unchanged. */
interface AddonCodeOrderingRuntimeServiceFactory extends AddonRuntimeServiceFactory
{
    public function codeOrdering(string $addonId, ?AddonCatalogEntry $entry): AddonCodeOrderingGatewayV1;
}
