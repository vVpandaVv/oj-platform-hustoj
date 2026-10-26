<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Integration\Learning;

use CodingPanda\OjPlatform\Modules\AI\Application\LearnerLevelService;
use CodingPanda\OjPlatform\Modules\AI\Domain\LearnerLevelProfile;
use CodingPanda\OjPlatform\Modules\LearningDashboard\Contract\LearnerLevelSummary;
use CodingPanda\OjPlatform\Modules\LearningDashboard\Contract\LearnerLevelSummaryProvider;
use CodingPanda\OjPlatform\Modules\Settings\Contract\FeatureFlags;
use Throwable;

final class AiLearnerLevelSummaryProvider implements LearnerLevelSummaryProvider
{
    public function __construct(
        private readonly LearnerLevelService $levels,
        private readonly FeatureFlags $features
    ) {
    }

    public function summaryFor(
        int $userId,
        int $lastSubmissionId
    ): ?LearnerLevelSummary {
        if (! $this->isAvailable()) {
            return null;
        }
        $profile = $this->levels->find($userId);
        if (
            ! $profile instanceof LearnerLevelProfile
            || $profile->lastSubmissionId() !== $lastSubmissionId
            || $profile->policyVersion() !== LearnerLevelProfile::POLICY_VERSION
        ) {
            try {
                $profile = $this->levels->refresh($userId);
            } catch (Throwable) {
                return null;
            }
        }

        return new LearnerLevelSummary(
            $profile->analysisStatus(),
            $profile->level(),
            $profile->provenTier(),
            $profile->metric('skill_score'),
            $profile->analyzedAt()
        );
    }

    public function isAvailable(): bool
    {
        try {
            return $this->features->isEnabled('ai_feedback');
        } catch (\Throwable) {
            return false;
        }
    }
}
