<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\AddonApi\V1;

interface AddonRuntimeContextV1
{
    public function snapshot(): AddonRuntimeSnapshotV1;

    public function contexts(): AddonContextGatewayV1;

    public function aiTasks(): AddonAiTaskGatewayV1;
}
