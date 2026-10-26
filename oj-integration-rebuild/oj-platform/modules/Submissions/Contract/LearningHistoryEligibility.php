<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Contract;

interface LearningHistoryEligibility
{
    public function includes(
        int $userId,
        SubmissionProjection $submission
    ): bool;
}
