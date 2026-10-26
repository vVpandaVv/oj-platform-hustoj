<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Composition;

use CodingPanda\OjPlatform\Kernel\Configuration\PhpConfigLoader;
use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Kernel\Registry\DefinitionRegistry;
use CodingPanda\OjPlatform\Modules\AI\Application\AiFeedbackJobAccessRule;
use CodingPanda\OjPlatform\Modules\AI\Application\AiFeedbackAccountErasureParticipant;
use CodingPanda\OjPlatform\Modules\AI\Application\AiFeedbackSettingsService;
use CodingPanda\OjPlatform\Modules\AI\Application\FeedbackInputFactory;
use CodingPanda\OjPlatform\Modules\AI\Application\FeedbackOutputValidator;
use CodingPanda\OjPlatform\Modules\AI\Application\LearnerLevelCalculator;
use CodingPanda\OjPlatform\Modules\AI\Application\LearnerLevelService;
use CodingPanda\OjPlatform\Modules\AI\Application\SubmissionAiFeedbackJobHandler;
use CodingPanda\OjPlatform\Modules\AI\Application\SubmissionFeedbackJobService;
use CodingPanda\OjPlatform\Modules\AI\Application\SubmissionFeedbackReusePolicy;
use CodingPanda\OjPlatform\Modules\AI\Contract\FeedbackGuideRepository;
use CodingPanda\OjPlatform\Modules\AI\Contract\FeedbackResearchPolicy;
use CodingPanda\OjPlatform\Modules\AI\Contract\LearnerLevelRepository;
use CodingPanda\OjPlatform\Modules\AI\Contract\SubmissionFeedbackRepository;
use CodingPanda\OjPlatform\Modules\AI\Infrastructure\Filesystem\PrivateFeedbackGuideRepository;
use CodingPanda\OjPlatform\Modules\AI\Infrastructure\WordPress\WordPressLearnerLevelRepository;
use CodingPanda\OjPlatform\Modules\AI\Infrastructure\WordPress\WordPressSubmissionFeedbackRepository;
use CodingPanda\OjPlatform\Modules\AI\Module;
use CodingPanda\OjPlatform\Modules\AI\Presentation\Action\AiFeedbackActionHandler;
use CodingPanda\OjPlatform\Modules\AI\Presentation\Action\FeedbackGuideOperationActionHandler;
use CodingPanda\OjPlatform\Modules\AI\Presentation\Action\SaveAiFeedbackSettingsActionHandler;
use CodingPanda\OjPlatform\Modules\AI\Presentation\Action\SaveFeedbackGuideActionHandler;
use CodingPanda\OjPlatform\Modules\AI\Presentation\AiHtml;
use CodingPanda\OjPlatform\Modules\AI\Presentation\Console\AiFeedbackSettingsPageHandler;
use CodingPanda\OjPlatform\Modules\AI\Presentation\Console\FeedbackGuideEditorPageHandler;
use CodingPanda\OjPlatform\Modules\AI\Presentation\Console\FeedbackGuideListPageHandler;
use CodingPanda\OjPlatform\Modules\AI\Presentation\Console\FeedbackGuidePreviewPageHandler;
use CodingPanda\OjPlatform\Modules\AI\Presentation\Console\LearnerLevelPageHandler;
use CodingPanda\OjPlatform\Modules\AI\Presentation\SubmissionFeedbackExtension;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobController;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobDispatcher;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobQuery;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\PrivateDataRootProvider;
use CodingPanda\OjPlatform\Modules\Jobs\Registry\JobHandlerRegistry;
use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;
use CodingPanda\OjPlatform\Modules\PlatformDb\Contract\ApplicationDatabase;
use CodingPanda\OjPlatform\Modules\PlatformDb\Contract\SchemaTableDefinition;
use CodingPanda\OjPlatform\Modules\Settings\Contract\FeatureFlags;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsManager;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\LearningHistoryEligibility;
use CodingPanda\OjPlatform\Shared\Ai\AiTaskRouter;
use CodingPanda\OjPlatform\Shared\Clock\Clock;

final class AiFeedbackComposition
{
    /**
     * @param DefinitionRegistry<SchemaTableDefinition> $tableSchemas
     */
    public function assemble(
        ErrorReporter $errors,
        PhpConfigLoader $loader,
        DefinitionRegistry $tableSchemas,
        ApplicationDatabase $database,
        PrivateDataRootProvider $privateDataRoots,
        SettingsManager $settings,
        FeatureFlags $features,
        Authorization $permissions,
        AiTaskRouter $ai,
        JobDispatcher $jobs,
        JobQuery $jobQuery,
        JobController $jobControl,
        AiFeedbackJobAccessRule $jobAccess,
        JobHandlerRegistry $jobHandlers,
        AccountShellBundle $accountShell,
        ProblemsBundle $problems,
        SubmissionsBundle $submissions,
        LearningHistoryEligibility $historyEligibility,
        Clock $clock,
        ?FeedbackResearchPolicy $research = null
    ): AiFeedbackBundle {
        $levelRepository = new WordPressLearnerLevelRepository($database);
        $accountErasure = new AiFeedbackAccountErasureParticipant($database);
        $accountShell->accountErasureParticipants()->register($accountErasure);
        $feedbackRepository = new WordPressSubmissionFeedbackRepository(
            $database
        );
        $guides = new PrivateFeedbackGuideRepository($privateDataRoots);
        $calculator = new LearnerLevelCalculator();
        $levels = new LearnerLevelService(
            $levelRepository,
            $accountShell->users(),
            $submissions->projections(),
            $problems->learningProblems(),
            $historyEligibility,
            $calculator,
            $clock
        );
        $feedbackSettings = new AiFeedbackSettingsService($settings);
        $inputs = new FeedbackInputFactory();
        $outputs = new FeedbackOutputValidator();
        $reuse = new SubmissionFeedbackReusePolicy();
        $feedback = new SubmissionFeedbackJobService(
            $submissions->aiFeedbackSubmissions(),
            $problems->learningProblems(),
            $feedbackRepository,
            $guides,
            $feedbackSettings,
            $inputs,
            $levels,
            $features,
            $jobs,
            $ai,
            $research
        );
        $jobHandler = new SubmissionAiFeedbackJobHandler(
            $accountShell->users(),
            $submissions->aiFeedbackSubmissions(),
            $problems->learningProblems(),
            $levels,
            $guides,
            $feedbackSettings,
            $inputs,
            $outputs,
            $feedbackRepository,
            $reuse,
            $feedback,
            $ai,
            $clock
        );
        $jobHandlers->register($jobHandler);
        $html = new AiHtml();
        $validation = new \CodingPanda\OjPlatform\Modules\AI\Application\FeedbackValidationService(
            $guides, new \CodingPanda\OjPlatform\Modules\AI\Infrastructure\Filesystem\PrivateFeedbackValidationStore($privateDataRoots), $ai, $inputs, $outputs
        );

        $submissions->detailExtensions()->register(
            new SubmissionFeedbackExtension(
                $feedback,
                $problems->learningProblems(),
                $accountShell->actionUrls(),
                $accountShell->actionSecurity(),
                $html
            )
        );

        $routes = $accountShell->routeHandlers();
        $reviewService = new \CodingPanda\OjPlatform\Modules\AI\Application\FeedbackReviewService(
            $feedbackRepository, $feedbackRepository, new \CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionReviewService($submissions->queries()), $permissions
        );
        $reviewPage = new \CodingPanda\OjPlatform\Modules\AI\Presentation\Console\FeedbackReviewPageHandler(
            $accountShell->consolePages(), $accountShell->currentAccounts(), $reviewService,
            $accountShell->users(), $html
        );
        $submissions->detailExtensions()->registerAdmin($reviewPage);
        $routes->register('console.submissions.ai_feedback.records', $reviewPage);
        $routes->register(
            'console.submissions.ai_feedback',
            new AiFeedbackSettingsPageHandler(
                $accountShell->consolePages(),
                $feedbackSettings,
                $guides,
                $feedback,
                $ai,
                $accountShell->actionUrls(),
                $accountShell->actionSecurity(),
                $html
            )
        );
        $routes->register(
            'console.submissions.ai_guides',
            new FeedbackGuideListPageHandler(
                $accountShell->consolePages(),
                $guides,
                $accountShell->actionUrls(),
                $accountShell->actionSecurity(),
                $html
            )
        );
        $editor = new FeedbackGuideEditorPageHandler(
            $accountShell->consolePages(),
            $guides,
            $accountShell->actionUrls(),
            $accountShell->actionSecurity(),
            $html
        );
        $routes->register('console.submissions.ai_guides.new', $editor);
        $routes->register('console.submissions.ai_guides.edit', $editor);
        $preview = new FeedbackGuidePreviewPageHandler(
            $accountShell->consolePages(),
            $guides,
            $html,
            new \CodingPanda\OjPlatform\Modules\AI\Presentation\Console\FeedbackValidationPanel($validation, $accountShell->actionUrls(), $accountShell->actionSecurity(), $html)
        );
        $routes->register('console.submissions.ai_guides.preview', $preview);
        $routes->register('console.submissions.ai_guides.revisions', $preview);
        $routes->register(
            'console.submissions.learner_levels',
            new LearnerLevelPageHandler(
                $accountShell->consolePages(),
                $levels,
                $accountShell->users(),
                $html
            )
        );

        $actions = $accountShell->actionHandlers();
        $actions->register(
            \CodingPanda\OjPlatform\Modules\AI\Presentation\Action\FeedbackValidationActionHandler::ACTION,
            new \CodingPanda\OjPlatform\Modules\AI\Presentation\Action\FeedbackValidationActionHandler($validation, $accountShell->actionGuard(), $errors)
        );
        $actions->register(
            'oj_ai_feedback_generate',
            new AiFeedbackActionHandler(
                $feedback,
                $jobQuery,
                $jobControl,
                $permissions,
                $accountShell->actionGuard(),
                $errors
            )
        );
        $actions->register(
            'oj_save_ai_feedback_settings',
            new SaveAiFeedbackSettingsActionHandler(
                $feedbackSettings,
                $guides,
                $accountShell->actionGuard(),
                $accountShell->actionResponder(),
                $errors
            )
        );
        $actions->register(
            'oj_ai_persona_save',
            new SaveFeedbackGuideActionHandler(
                $guides,
                $accountShell->actionGuard(),
                $accountShell->actionResponder(),
                $errors
            )
        );
        foreach ([
            'oj_ai_persona_duplicate' => 'duplicate',
            'oj_ai_persona_delete' => 'archive',
            'oj_ai_persona_restore' => 'restore',
        ] as $action => $operation) {
            $actions->register(
                $action,
                new FeedbackGuideOperationActionHandler(
                    $action,
                    $operation,
                    $guides,
                    $feedbackSettings,
                    $accountShell->actionGuard(),
                    $accountShell->actionResponder(),
                    $errors
                )
            );
        }

        return new AiFeedbackBundle(
            new Module(
                $tableSchemas,
                $loader->load('ai-feedback-schema')
            ),
            [
                LearnerLevelRepository::class => $levelRepository,
                AiFeedbackAccountErasureParticipant::class => $accountErasure,
                SubmissionFeedbackRepository::class => $feedbackRepository,
                FeedbackGuideRepository::class => $guides,
                LearnerLevelCalculator::class => $calculator,
                LearnerLevelService::class => $levels,
                AiFeedbackSettingsService::class => $feedbackSettings,
                FeedbackInputFactory::class => $inputs,
                FeedbackOutputValidator::class => $outputs,
                SubmissionFeedbackReusePolicy::class => $reuse,
                SubmissionFeedbackJobService::class => $feedback,
                SubmissionAiFeedbackJobHandler::class => $jobHandler,
                AiFeedbackJobAccessRule::class => $jobAccess,
                AiHtml::class => $html,
            ]
        );
    }
}
