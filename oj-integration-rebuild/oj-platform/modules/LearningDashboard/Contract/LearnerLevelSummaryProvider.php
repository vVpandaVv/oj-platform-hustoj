<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\LearningDashboard\Contract;

interface LearnerLevelSummaryProvider
{
    public function isAvailable(): bool;

    public function summaryFor(
        int $userId,
        int $lastSubmissionId
    ): ?LearnerLevelSummary;
}
