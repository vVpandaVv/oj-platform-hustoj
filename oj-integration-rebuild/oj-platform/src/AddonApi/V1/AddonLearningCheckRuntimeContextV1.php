<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\AddonApi\V1;

/** Optional runtime extension; existing addon runtime implementations remain compatible. */
interface AddonLearningCheckRuntimeContextV1 extends AddonRuntimeContextV1
{
    public function learningChecks(): AddonLearningCheckGatewayV1;
}
