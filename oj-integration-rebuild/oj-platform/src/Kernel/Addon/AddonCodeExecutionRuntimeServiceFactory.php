<?php
declare(strict_types=1);
namespace CodingPanda\OjPlatform\Kernel\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonCodeExecutionGatewayV1;

/** @internal */
interface AddonCodeExecutionRuntimeServiceFactory extends AddonRuntimeServiceFactory
{
    public function codeExecution(string $addonId, ?AddonCatalogEntry $entry): AddonCodeExecutionGatewayV1;
}
