<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Integration\Learning;

use CodingPanda\OjPlatform\Modules\Contests\Contract\ContestSubmissionAccess;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionStaffScope;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionSummary;

final class ContestSubmissionStaffScope implements SubmissionStaffScope
{
    public function __construct(
        private readonly ContestSubmissionAccess $contests
    ) {
    }

    public function canView(
        int $actorUserId,
        SubmissionSummary $submission
    ): bool {
        return $submission->contestId() > 0
            && $this->contests->canViewSubmission(
                $actorUserId,
                $submission->contestId()
            );
    }
}
