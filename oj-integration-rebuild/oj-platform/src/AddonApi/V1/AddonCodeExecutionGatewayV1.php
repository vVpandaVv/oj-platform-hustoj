<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\AddonApi\V1;

interface AddonCodeExecutionGatewayV1
{
    public function availability(): AddonCodeExecutionAvailabilityV1;
    public function submit(AddonCodeExecutionRequestV1 $request): AddonCodeExecutionResultV1;
    public function poll(AddonCodeExecutionQueryV1 $query): AddonCodeExecutionResultV1;
}
