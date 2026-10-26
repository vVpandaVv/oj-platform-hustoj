<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Composition;

use CodingPanda\OjPlatform\Modules\LearningDashboard\Application\LearningDashboardService;
use CodingPanda\OjPlatform\Modules\LearningDashboard\Contract\LearnerLevelSummaryProvider;
use CodingPanda\OjPlatform\Modules\LearningDashboard\Infrastructure\WordPress\WordPressLearningDashboardCache;
use CodingPanda\OjPlatform\Modules\LearningDashboard\Module;
use CodingPanda\OjPlatform\Modules\LearningDashboard\Presentation\LearningDashboardPageHandler;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\LearningHistoryEligibility;
use CodingPanda\OjPlatform\Shared\Clock\Clock;

final class LearningDashboardComposition
{
    public function assemble(
        AccountShellBundle $accountShell,
        ProblemsBundle $problems,
        SubmissionsBundle $submissions,
        LearningHistoryEligibility $historyEligibility,
        LearnerLevelSummaryProvider $levels,
        Clock $clock,
        ?\CodingPanda\OjPlatform\Modules\LearningDashboard\Contract\RecommendationPolicy $research = null
    ): LearningDashboardBundle {
        $service = new LearningDashboardService(
            $submissions->projections(),
            $problems->learningProblems(),
            $historyEligibility,
            $levels,
            $clock,
            new WordPressLearningDashboardCache(),
            $research
        );
        $handler = new LearningDashboardPageHandler(
            $accountShell->publicPages(),
            $accountShell->currentAccounts(),
            $service
        );
        $accountShell->routeHandlers()->register(
            'learning_dashboard.index',
            $handler
        );

        return new LearningDashboardBundle(
            new Module(),
            [
                LearnerLevelSummaryProvider::class => $levels,
                LearningDashboardService::class => $service,
                LearningDashboardPageHandler::class => $handler,
            ]
        );
    }
}
