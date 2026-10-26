<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Integration\Learning;

use CodingPanda\OjPlatform\Modules\Groups\Contract\GroupSubmissionAccess;
use CodingPanda\OjPlatform\Modules\Settings\Contract\FeatureFlags;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\PerformanceModePolicy;

final class GroupPerformanceModePolicy implements PerformanceModePolicy
{
    public function __construct(
        private readonly GroupSubmissionAccess $groups,
        private readonly FeatureFlags $features
    ) {
    }

    public function restrictsOwnSubmissionDetails(int $userId): bool
    {
        return $this->features->isEnabled('groups')
            && $this->groups->restrictsOwnSubmissionDetails($userId);
    }
}
