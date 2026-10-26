<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\AddonApi\V1;

interface AddonLearningContextGatewayV1 extends AddonContextGatewayV1
{
    public function listLearningContexts(
        AddonLearningContextQueryV1 $query
    ): AddonContextPageV1;
}
