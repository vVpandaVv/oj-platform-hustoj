<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonEditorGatewayV1;

/** @internal */
interface AddonEditorRuntimeServiceFactory extends AddonRuntimeServiceFactory
{
    public function editor(): AddonEditorGatewayV1;
}
