<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonLearningCheckGatewayV1;

/** @internal */
interface AddonLearningCheckRuntimeServiceFactory extends AddonRuntimeServiceFactory
{
    public function learningChecks(string $addonId, ?AddonCatalogEntry $entry): AddonLearningCheckGatewayV1;
}
