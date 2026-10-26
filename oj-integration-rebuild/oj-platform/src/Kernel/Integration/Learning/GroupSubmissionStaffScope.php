<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Integration\Learning;

use CodingPanda\OjPlatform\Modules\Groups\Contract\GroupSubmissionAccess;
use CodingPanda\OjPlatform\Modules\Settings\Contract\FeatureFlags;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionStaffScope;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionSummary;

final class GroupSubmissionStaffScope implements SubmissionStaffScope
{
    public function __construct(
        private readonly GroupSubmissionAccess $groups,
        private readonly FeatureFlags $features
    ) {
    }

    public function canView(
        int $actorUserId,
        SubmissionSummary $submission
    ): bool {
        return $this->features->isEnabled('groups')
            && $this->groups->canViewMemberSubmission(
            $actorUserId,
            $submission->userLogin()
        );
    }
}
