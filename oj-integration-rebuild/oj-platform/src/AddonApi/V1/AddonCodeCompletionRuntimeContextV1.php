<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\AddonApi\V1;

/** Optional extension; the base runtime contract remains unchanged. */
interface AddonCodeCompletionRuntimeContextV1 extends AddonRuntimeContextV1
{
    public function codeCompletion(): AddonCodeCompletionGatewayV1;
}
