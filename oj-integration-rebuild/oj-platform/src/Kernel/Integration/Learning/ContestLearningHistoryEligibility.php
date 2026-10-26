<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Integration\Learning;

use CodingPanda\OjPlatform\Modules\Contests\Application\ContestService;
use CodingPanda\OjPlatform\Modules\Settings\Contract\FeatureFlags;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\LearningHistoryEligibility;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionProjection;
use Throwable;

final class ContestLearningHistoryEligibility implements LearningHistoryEligibility
{
    public function __construct(
        private readonly ContestService $contests,
        private readonly FeatureFlags $features
    ) {
    }

    public function includes(
        int $userId,
        SubmissionProjection $submission
    ): bool {
        if ($submission->contestId() === 0) {
            return true;
        }
        try {
            if (! $this->features->isEnabled('contests')) {
                return true;
            }
            $contest = $this->contests->findVisible(
                $submission->contestId(),
                $userId
            );

            return $this->contests->currentStage($contest) === 'ended';
        } catch (Throwable) {
            // The contest no longer resolves (deleted, archived, or the
            // caller can no longer see it) — there is nothing left to
            // shield the submission from, so it must count toward the
            // learner's own history instead of being silently dropped
            // forever.
            return true;
        }
    }
}
