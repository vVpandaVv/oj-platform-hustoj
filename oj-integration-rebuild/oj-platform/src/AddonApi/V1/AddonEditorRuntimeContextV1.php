<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\AddonApi\V1;

/** Optional extension; older runtime implementations remain compatible. */
interface AddonEditorRuntimeContextV1 extends AddonRuntimeContextV1
{
    public function editor(): AddonEditorGatewayV1;
}
