<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\AddonApi\V1;

interface AddonEditorGatewayV1
{
    /** Read-only appearance and assets for the current account only. */
    public function current(): AddonEditorSnapshotV1;
}
