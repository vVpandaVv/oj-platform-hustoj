<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Contract;

use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionSummary;

interface SubmissionStaffScope
{
    public function canView(
        int $actorUserId,
        SubmissionSummary $submission
    ): bool;
}
