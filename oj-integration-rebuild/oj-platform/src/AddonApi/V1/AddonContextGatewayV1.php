<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\AddonApi\V1;

interface AddonContextGatewayV1
{
    public function listTeachingContexts(AddonTeachingContextQueryV1 $query): AddonContextPageV1;

    public function authorize(AddonContextAccessRequestV1 $request): AddonContextAccessDecisionV1;

    public function snapshot(AddonContextSnapshotRequestV1 $request): AddonContextSnapshotResultV1;
}
