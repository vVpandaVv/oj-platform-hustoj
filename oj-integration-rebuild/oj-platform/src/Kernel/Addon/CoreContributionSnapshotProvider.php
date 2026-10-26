<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Addon;

interface CoreContributionSnapshotProvider
{
    public function snapshot(): CoreContributionSnapshot;
}
