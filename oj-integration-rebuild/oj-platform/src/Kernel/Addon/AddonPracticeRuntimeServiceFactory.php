<?php
declare(strict_types=1);
namespace CodingPanda\OjPlatform\Kernel\Addon;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPracticeGatewayV1;
interface AddonPracticeRuntimeServiceFactory extends AddonRuntimeServiceFactory
{
    public function practice(string $addonId,?AddonCatalogEntry $entry):AddonPracticeGatewayV1;
}
