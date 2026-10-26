<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\AddonApi\V1;

interface AddonAiTaskGatewayV1
{
    public function canRoute(string $taskKey): AddonAiTaskRouteDecisionV1;

    public function snapshot(string $taskKey): AddonAiTaskRoutingSnapshotV1;

    public function execute(AddonAiTaskRequestV1 $request): AddonAiTaskResultV1;
}
