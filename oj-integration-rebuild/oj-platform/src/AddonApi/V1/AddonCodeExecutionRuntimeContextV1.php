<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\AddonApi\V1;

/** Optional staff-only execution extension, available since Addon API 1.5. */
interface AddonCodeExecutionRuntimeContextV1 extends AddonRuntimeContextV1
{
    public function codeExecution(): AddonCodeExecutionGatewayV1;
}
