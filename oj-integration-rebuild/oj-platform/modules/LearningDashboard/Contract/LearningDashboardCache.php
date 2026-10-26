<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\LearningDashboard\Contract;

use CodingPanda\OjPlatform\Modules\LearningDashboard\Domain\LearningDashboardSnapshot;

interface LearningDashboardCache
{
    public function get(string $key): ?LearningDashboardSnapshot;

    public function put(
        string $key,
        LearningDashboardSnapshot $snapshot,
        int $ttlSeconds
    ): void;
}
