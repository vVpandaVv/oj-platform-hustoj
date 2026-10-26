<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeCompletionGatewayV1;

/** @internal Optional extension; the base factory contract stays unchanged. */
interface AddonCodeCompletionRuntimeServiceFactory extends AddonRuntimeServiceFactory
{
    public function codeCompletion(string $addonId, ?AddonCatalogEntry $entry): AddonCodeCompletionGatewayV1;
}
