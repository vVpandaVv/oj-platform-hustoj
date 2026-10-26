<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\AddonApi\V1;

/** Read-only, current-learner context for verified learning checks. */
interface AddonLearningCheckGatewayV1
{
    public function context(AddonLearningCheckContextQueryV1 $query): AddonLearningCheckContextResultV1;
}
