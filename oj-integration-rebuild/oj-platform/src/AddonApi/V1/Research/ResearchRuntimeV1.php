<?php
declare(strict_types=1);
namespace CodingPanda\OjPlatform\AddonApi\V1\Research;

final class ResearchRuntimeV1
{
    public function __construct(
        public readonly \CodingPanda\OjPlatform\Kernel\Module\Module $module,
        public readonly \CodingPanda\OjPlatform\Modules\AI\Contract\FeedbackResearchPolicy $feedback,
        public readonly \CodingPanda\OjPlatform\Shared\Ai\ActorAiAccessPolicy $actorAiAccess,
        public readonly \CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionResearchRecorder $submissions,
        public readonly \CodingPanda\OjPlatform\Modules\LearningDashboard\Contract\RecommendationPolicy $recommendations,
        public readonly \CodingPanda\OjPlatform\Modules\Settings\Contract\FeatureDisableGuard $disableGuard,
        public readonly \CodingPanda\OjPlatform\Modules\Auth\Contract\AccountErasureParticipant $erasure,
        public readonly \CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemDetailSectionProvider $problemSection,
        public readonly array $routes,
        public readonly array $actions,
        public readonly array $publicMenus = []
    ) {}
}
