<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\AddonApi\V1;

interface AddonAssignmentRosterGatewayV1 extends AddonContextGatewayV1
{
    public function listAssignmentLearners(
        AddonAssignmentRosterQueryV1 $query
    ): AddonAssignmentLearnerPageV1;
}
