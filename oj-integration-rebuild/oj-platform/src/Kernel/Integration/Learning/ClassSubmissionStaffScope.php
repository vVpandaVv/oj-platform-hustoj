<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Integration\Learning;

use CodingPanda\OjPlatform\Modules\Classes\Contract\ClassSubmissionAccess;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionStaffScope;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionSummary;

final class ClassSubmissionStaffScope implements SubmissionStaffScope
{
    public function __construct(
        private readonly ClassSubmissionAccess $classes
    ) {
    }

    public function canView(
        int $actorUserId,
        SubmissionSummary $submission
    ): bool {
        $classId = $submission->classId();

        return $classId !== null
            && $this->classes->canViewSubmission($actorUserId, $classId);
    }
}
