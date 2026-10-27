<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Composition;

use CodingPanda\OjPlatform\Kernel\Configuration\PhpConfigLoader;
use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Kernel\Registry\DefinitionRegistry;
use CodingPanda\OjPlatform\Modules\Jobs\Application\JobManager;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\FormDraftStore;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobStepJournal;
use CodingPanda\OjPlatform\Modules\Jobs\Registry\JobHandlerRegistry;
use CodingPanda\OjPlatform\Modules\JudgeDb\Application\JudgeResultCatalog;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeResults;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeConnectionSettingsProvider;
use CodingPanda\OjPlatform\Modules\JudgeDb\Infrastructure\Mysqli\MysqliJudgeConnector;
use CodingPanda\OjPlatform\Modules\Permission\Application\PermissionService;
use CodingPanda\OjPlatform\Modules\PlatformDb\Contract\ApplicationDatabase;
use CodingPanda\OjPlatform\Modules\PlatformDb\Contract\SchemaTableDefinition;
use CodingPanda\OjPlatform\Modules\Problems\Application\CodeCompletionTemplatePolicy;
use CodingPanda\OjPlatform\Modules\Problems\Application\CodeOrderingAuthoringPolicy;
use CodingPanda\OjPlatform\Modules\Problems\Application\FeatureRoutedProblemAiAvailability;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemAccessPolicy;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemAccountErasureParticipant;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemJobAccessRule;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemDetailSectionRegistry;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemSubmissionPanelRegistry;
use CodingPanda\OjPlatform\Modules\Problems\Application\VisualGradingService;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemImageService;
use CodingPanda\OjPlatform\Modules\Problems\Infrastructure\Filesystem\FilesystemVisualCaptureStore;
use CodingPanda\OjPlatform\Modules\Problems\Infrastructure\WordPress\WordPressProblemImageStore;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\Action\UploadProblemImageActionHandler;
use CodingPanda\OjPlatform\Modules\Problems\Infrastructure\Filesystem\PluginVisualGradingTemplates;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemMetadataBulkApplyService;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemLearningProvider;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemAiDraftService;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemAiInputFactory;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemAiJobService;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemAiJobSupport;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemAiOutputValidator;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemAssignmentService;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemAuthoringGenerateJobHandler;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemBookService;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemCatalogService;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemContextAccessRegistry;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemCodePolicyService;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemEditorService;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemMetadataGenerateJobHandler;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemNumberChangeCoordinator;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemNumberChangeJobHandler;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemNumberChangeService;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemSubmissionProvider;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemSpjCompileJobHandler;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemTestcaseApplyJobHandler;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemTestcaseGenerateJobHandler;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemTestDataJobService;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemTestDataSettingsService;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemTestDataService;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemTopicService;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemXmlCodec;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemXmlExportService;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemXmlImportService;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemXmlMaterializer;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemXmlPreviewService;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemXmlWriter;
use CodingPanda\OjPlatform\Modules\Problems\Application\QuizAccessPolicy;
use CodingPanda\OjPlatform\Modules\Problems\Application\QuizBankService;
use CodingPanda\OjPlatform\Modules\Problems\Application\QuizGrader;
use CodingPanda\OjPlatform\Modules\Problems\Application\QuizKeyFactory;
use CodingPanda\OjPlatform\Modules\Problems\Application\QuizReapplyService;
use CodingPanda\OjPlatform\Modules\Problems\Application\QuizRevisionReapplyJobHandler;
use CodingPanda\OjPlatform\Modules\Problems\Application\QuizService;
use CodingPanda\OjPlatform\Modules\Problems\Contract\BoundedProcessRunner;
use CodingPanda\OjPlatform\Modules\Problems\Contract\LearningProblemProvider;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemArchiveManager;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemAiAvailability;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemAssignmentRepository;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemBookRepository;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemDataRootProvider;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemFileManager;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemGateway;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemMetadataRepository;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemNumberChangeLock;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemSpjCompiler;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemTopicDictionary;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemTopicLinkRepository;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemTransaction;
use CodingPanda\OjPlatform\Modules\Problems\Contract\QuizBankRepository;
use CodingPanda\OjPlatform\Modules\Problems\Contract\QuizReapplyRepository;
use CodingPanda\OjPlatform\Modules\Problems\Contract\QuizRepository;
use CodingPanda\OjPlatform\Modules\Problems\Contract\SubmissionProblemProvider;
use CodingPanda\OjPlatform\Modules\Problems\Contract\SpjCompilerProfileProvider;
use CodingPanda\OjPlatform\Modules\Problems\Contract\SpjSandboxProfileProvider;
use CodingPanda\OjPlatform\Modules\Problems\Infrastructure\Filesystem\ProblemDataWorkspace;
use CodingPanda\OjPlatform\Modules\Problems\Infrastructure\Filesystem\FilesystemProblemNumberChangeLock;
use CodingPanda\OjPlatform\Modules\Problems\Infrastructure\Filesystem\ProblemDataDirectoryNumberChangeParticipant;
use CodingPanda\OjPlatform\Modules\Problems\Infrastructure\Filesystem\RootOnlyProblemFileManager;
use CodingPanda\OjPlatform\Modules\Problems\Infrastructure\Filesystem\ZipProblemArchiveManager;
use CodingPanda\OjPlatform\Modules\Problems\Infrastructure\Judge\MysqliProblemGateway;
use CodingPanda\OjPlatform\Modules\Problems\Infrastructure\Judge\MysqliProblemNumberChangeParticipant;
use CodingPanda\OjPlatform\Modules\Problems\Infrastructure\Process\BubblewrapBoundedProcessRunner;
use CodingPanda\OjPlatform\Modules\Problems\Infrastructure\Process\NativeBoundedProcessRunner;
use CodingPanda\OjPlatform\Modules\Problems\Infrastructure\Process\NativeProblemSpjCompiler;
use CodingPanda\OjPlatform\Modules\Problems\Infrastructure\Settings\SettingsProblemDataRootProvider;
use CodingPanda\OjPlatform\Modules\Problems\Infrastructure\Settings\SettingsSpjCompilerProfileProvider;
use CodingPanda\OjPlatform\Modules\Problems\Infrastructure\Settings\SettingsSpjSandboxProfileProvider;
use CodingPanda\OjPlatform\Modules\Problems\Infrastructure\WordPress\ProblemPlatformDatabase;
use CodingPanda\OjPlatform\Modules\Problems\Infrastructure\WordPress\ProblemTopicBootstrap;
use CodingPanda\OjPlatform\Modules\Problems\Infrastructure\WordPress\WordPressBoardProblemNumberChangeParticipant;
use CodingPanda\OjPlatform\Modules\Problems\Infrastructure\WordPress\WordPressProblemBookRepository;
use CodingPanda\OjPlatform\Modules\Problems\Infrastructure\WordPress\WordPressPlatformProblemNumberChangeParticipant;
use CodingPanda\OjPlatform\Modules\Problems\Infrastructure\WordPress\WordPressProblemAssignmentRepository;
use CodingPanda\OjPlatform\Modules\Problems\Infrastructure\WordPress\WordPressProblemMetadataRepository;
use CodingPanda\OjPlatform\Modules\Problems\Infrastructure\WordPress\WordPressProblemTopicDictionary;
use CodingPanda\OjPlatform\Modules\Problems\Infrastructure\WordPress\WordPressProblemTopicLinkRepository;
use CodingPanda\OjPlatform\Modules\Problems\Infrastructure\WordPress\WordPressProblemTransaction;
use CodingPanda\OjPlatform\Modules\Problems\Infrastructure\WordPress\WordPressQuizBankRepository;
use CodingPanda\OjPlatform\Modules\Problems\Infrastructure\WordPress\WordPressQuizRepository;
use CodingPanda\OjPlatform\Modules\Problems\Module;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\Action\ApplyProblemMetadataDraftsActionHandler;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\Action\ArchiveProblemActionHandler;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\Action\AddPersonalProblemBookActionHandler;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\Action\BulkArchiveProblemsActionHandler;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\Action\ChangeProblemNumberActionHandler;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\Action\CreateProblemTopicActionHandler;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\Action\DeleteProblemBookActionHandler;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\Action\GenerateProblemAiActionHandler;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\Action\GenerateProblemMetadataActionHandler;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\Action\ProblemTestDataActionHandler;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\Action\ProblemXmlExportActionHandler;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\Action\ProblemXmlImportActionHandler;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\Action\RemovePersonalProblemBookActionHandler;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\Action\SaveProblemActionHandler;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\Action\SaveProblemTestDataSettingsActionHandler;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\Action\SaveProblemBookActionHandler;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\Action\CreateQuizActionHandler;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\Action\DeleteQuizBankQuestionActionHandler;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\Action\ExportQuizQuestionToBankActionHandler;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\Action\ImportQuizBankQuestionActionHandler;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\Action\SaveQuizActionHandler;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\Action\SaveQuizBankQuestionActionHandler;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\Action\StartQuizAttemptActionHandler;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\Action\SubmitQuizAttemptActionHandler;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\Console\ProblemAdminListPageHandler;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\Console\ProblemAssignmentPanel;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\Console\ProblemBookAdminPageHandler;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\Console\ProblemArchivePageHandler;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\Console\ProblemEditForm;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\Console\ProblemEditPageHandler;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\Console\ProblemNewPageHandler;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\Console\ProblemNumberChangePageHandler;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\Console\ProblemTestDataPageHandler;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\Console\ProblemTestDataSettingsPageHandler;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\Console\ProblemTopicsPageHandler;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\Console\ProblemXmlPageHandler;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\Console\QuizAdminListPageHandler;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\Console\QuizAdminStatisticsPageHandler;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\Console\QuizBankEditPageHandler;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\Console\QuizBankListPageHandler;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\Console\QuizBankNewPageHandler;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\Console\QuizEditForm;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\Console\QuizEditPageHandler;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\Console\QuizNewPageHandler;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\ProblemHtml;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\ProblemDetailPageLoader;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\ProblemDetailPageView;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\ProblemConsoleMenuAccessRule;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\ProblemBookRequestFactory;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\ProblemRequestFactory;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\QuizHtml;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\QuizRequestFactory;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\Route\ProblemDetailPageHandler;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\Route\ProblemBookPageHandler;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\Route\ProblemListPageHandler;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\Route\ProblemRankingPageHandler;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\Route\ProblemStatisticsPageHandler;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\Route\QuizAttemptPageHandler;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\Route\QuizDetailPageHandler;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\Route\QuizListPageHandler;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\Route\QuizResultPageHandler;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\Route\QuizStatisticsPageHandler;
use CodingPanda\OjPlatform\Modules\Settings\Application\SettingsService;
use CodingPanda\OjPlatform\Modules\Settings\Application\FeatureService;
use CodingPanda\OjPlatform\Shared\Ai\AiTaskRouter;
use CodingPanda\OjPlatform\Shared\Clock\Clock;
use CodingPanda\OjPlatform\Shared\Judge\JudgeLanguageCatalog;
use CodingPanda\OjPlatform\Ui\Component\PaginationView;

final class ProblemsComposition
{
    /**
     * @param DefinitionRegistry<SchemaTableDefinition> $tableSchemas
     */
    public function assemble(
        ErrorReporter $errorReporter,
        PhpConfigLoader $loader,
        DefinitionRegistry $tableSchemas,
        ApplicationDatabase $applicationDatabase,
        JudgeConnectionSettingsProvider $judgeSettings,
        MysqliJudgeConnector $judgeConnector,
        SettingsService $settings,
        FeatureService $features,
        PermissionService $permissions,
        JobManager $jobManager,
        JobHandlerRegistry $jobHandlers,
        JobStepJournal $jobStepJournal,
        FormDraftStore $formDrafts,
        AiTaskRouter $aiTaskRouter,
        Clock $clock,
        AccountShellBundle $accountShell,
        string $featureResourceDirectory
    ): ProblemsBundle {
        $database = ProblemPlatformDatabase::fromGlobals();
        $accountErasure = new ProblemAccountErasureParticipant($applicationDatabase);
        $accountShell->accountErasureParticipants()->register($accountErasure);
        $quizRepository = new WordPressQuizRepository($database);
        $quizBankRepository = new WordPressQuizBankRepository($database);
        $bookRepository = new WordPressProblemBookRepository($database);
        $topicLinks = new WordPressProblemTopicLinkRepository($database);
        $metadata = new WordPressProblemMetadataRepository($database, $topicLinks);
        $assignments = new WordPressProblemAssignmentRepository($database);
        $topics = new WordPressProblemTopicDictionary(
            $loader->load('problem-topics')
        );
        $topicBootstrap = new ProblemTopicBootstrap($topics, $errorReporter);
        $transaction = new WordPressProblemTransaction($database);
        $judgeProblems = new MysqliProblemGateway(
            $judgeSettings,
            $judgeConnector
        );
        $problemGateway = $judgeProblems;
        $dataRoots = new SettingsProblemDataRootProvider($settings);
        $dataWorkspace = new ProblemDataWorkspace($dataRoots);
        $numberChangeLock = new FilesystemProblemNumberChangeLock($dataRoots);
        $fileManager = new RootOnlyProblemFileManager($dataWorkspace);
        $archiveManager = new ZipProblemArchiveManager($dataWorkspace);
        $compilerProfiles = new SettingsSpjCompilerProfileProvider($settings);
        $sandboxProfiles = new SettingsSpjSandboxProfileProvider($settings);
        $hostProcessRunner = new NativeBoundedProcessRunner();
        $processRunner = new BubblewrapBoundedProcessRunner(
            $sandboxProfiles,
            $hostProcessRunner
        );
        $spjCompiler = new NativeProblemSpjCompiler(
            $fileManager,
            $dataWorkspace,
            $compilerProfiles,
            $processRunner
        );
        $aiAvailability = new FeatureRoutedProblemAiAvailability(
            $features,
            $aiTaskRouter
        );
        $contextAccess = new ProblemContextAccessRegistry();
        $access = new ProblemAccessPolicy(
            $permissions,
            $assignments,
            $aiAvailability,
            $contextAccess
        );
        $learningProblems = new ProblemLearningProvider(
            $problemGateway,
            $metadata,
            $access
        );
        $assignmentService = new ProblemAssignmentService(
            $problemGateway,
            $assignments,
            $access,
            $accountShell->users()
        );
        $completion = new CodeCompletionTemplatePolicy();
        $catalog = new ProblemCatalogService(
            $problemGateway,
            $metadata,
            $assignments,
            $access,
            $permissions
        );
        $quizAccess = new QuizAccessPolicy($permissions, $access);
        $jobAccess = new ProblemJobAccessRule(
            $permissions,
            $access,
            $quizAccess
        );
        $quizKeys = new QuizKeyFactory();
        $quizGrader = new QuizGrader();
        $quizService = new QuizService(
            $quizRepository,
            $catalog,
            $quizAccess,
            $quizGrader,
            $clock
        );
        $quizBankService = new QuizBankService(
            $quizBankRepository,
            $permissions,
            $quizService,
            $quizKeys
        );
        $quizReapply = new QuizReapplyService(
            $quizRepository,
            $quizRepository,
            $quizAccess,
            $quizGrader,
            $jobManager
        );
        $bookService = new ProblemBookService(
            $bookRepository,
            $catalog,
            $permissions
        );
        $languages = new JudgeLanguageCatalog();
        $orderingAuthoring = new CodeOrderingAuthoringPolicy($settings);
        $editor = new ProblemEditorService(
            $problemGateway,
            $metadata,
            $topicLinks,
            $topics,
            $assignments,
            $transaction,
            $access,
            $completion,
            $numberChangeLock,
            $languages,
            $orderingAuthoring,
            new \CodingPanda\OjPlatform\Modules\Problems\Infrastructure\WordPress\WordPressProblemChangePublisher($errorReporter)
        );
        $xmlCodec = new ProblemXmlCodec();
        $xmlMaterializer = new ProblemXmlMaterializer(
            $problemGateway,
            $metadata,
            $topicLinks,
            $topics,
            $fileManager,
            $completion,
            $orderingAuthoring
        );
        $xmlPreviews = new ProblemXmlPreviewService(
            $problemGateway,
            $topics,
            $numberChangeLock,
            $access,
            $xmlCodec,
            $xmlMaterializer,
            $formDrafts
        );
        $xmlWriter = new ProblemXmlWriter(
            $problemGateway,
            $metadata,
            $topicLinks,
            $assignments,
            $transaction,
            $fileManager,
            $xmlMaterializer
        );
        $xmlImports = new ProblemXmlImportService(
            $problemGateway,
            $numberChangeLock,
            $xmlMaterializer,
            $xmlPreviews,
            $xmlWriter
        );
        $xmlExports = new ProblemXmlExportService(
            $access,
            $numberChangeLock,
            $xmlMaterializer,
            $xmlCodec
        );
        $visualTemplates = new PluginVisualGradingTemplates($featureResourceDirectory);
        $codePolicies = new ProblemCodePolicyService(
            $problemGateway,
            $metadata,
            $visualTemplates
        );
        $submissionProblems = new ProblemSubmissionProvider(
            $catalog,
            $codePolicies
        );
        $aiInputs = new ProblemAiInputFactory($topics);
        $aiOutputs = new ProblemAiOutputValidator($topics);
        $aiDrafts = new ProblemAiDraftService(
            $formDrafts,
            $problemGateway,
            $metadata,
            $topics,
            $aiInputs,
            $aiOutputs
        );
        $aiJobSupport = new ProblemAiJobSupport($aiInputs, $aiTaskRouter);
        $aiJobs = new ProblemAiJobService(
            $problemGateway,
            $metadata,
            $topics,
            $access,
            $aiInputs,
            $jobManager,
            $aiTaskRouter
        );
        $testData = new ProblemTestDataService(
            $problemGateway,
            $fileManager,
            $dataRoots,
            $access,
            $archiveManager,
            $numberChangeLock
        );
        $testDataSettings = new ProblemTestDataSettingsService($settings);
        $testDataJobs = new ProblemTestDataJobService(
            $problemGateway,
            $fileManager,
            $archiveManager,
            $access,
            $aiDrafts,
            $jobManager,
            $numberChangeLock,
            $compilerProfiles,
            $sandboxProfiles
        );
        $visualGrading = new VisualGradingService(
            $problemGateway,
            $metadata,
            $testData,
            $testDataJobs,
            $visualTemplates,
            new FilesystemVisualCaptureStore($dataRoots)
        );
        $numberChangeParticipants = [
            new MysqliProblemNumberChangeParticipant(
                $judgeSettings,
                $judgeConnector
            ),
            WordPressPlatformProblemNumberChangeParticipant::fromGlobals(),
            WordPressBoardProblemNumberChangeParticipant::fromGlobals(),
            new ProblemDataDirectoryNumberChangeParticipant($dataRoots),
        ];
        $numberChangeService = new ProblemNumberChangeService(
            $problemGateway,
            $access,
            $numberChangeParticipants,
            $jobManager
        );
        $numberChangeCoordinator = new ProblemNumberChangeCoordinator(
            $numberChangeParticipants,
            $numberChangeLock,
            $jobStepJournal
        );
        $jobHandlers->register(new ProblemTestcaseApplyJobHandler(
            $problemGateway,
            $archiveManager,
            $fileManager,
            $aiDrafts,
            $access
        ));
        $jobHandlers->register(new ProblemSpjCompileJobHandler(
            $problemGateway,
            $spjCompiler,
            $access
        ));
        $jobHandlers->register(new ProblemNumberChangeJobHandler(
            $numberChangeService,
            $numberChangeCoordinator,
            $access
        ));
        $jobHandlers->register(new QuizRevisionReapplyJobHandler(
            $quizReapply
        ));
        $jobHandlers->register(new ProblemMetadataGenerateJobHandler(
            $problemGateway,
            $metadata,
            $access,
            $aiInputs,
            $aiOutputs,
            $aiJobSupport,
            $formDrafts
        ));
        $jobHandlers->register(new ProblemAuthoringGenerateJobHandler(
            $problemGateway,
            $metadata,
            $access,
            $aiInputs,
            $aiOutputs,
            $aiJobSupport,
            $formDrafts
        ));
        $jobHandlers->register(new ProblemTestcaseGenerateJobHandler(
            $problemGateway,
            $metadata,
            $access,
            $aiInputs,
            $aiOutputs,
            $aiJobSupport,
            $formDrafts
        ));
        $topicService = new ProblemTopicService($topics, $access);
        $resultCatalog = new JudgeResultCatalog();
        $html = new ProblemHtml($topics);
        $detailLoader = new ProblemDetailPageLoader(
            $catalog,
            $access,
            $bookService,
            $errorReporter,
            $quizRepository,
            $features
        );
        $detailView = new ProblemDetailPageView(
            $html,
            $accountShell->actionUrls(),
            $accountShell->actionSecurity(),
            $accountShell->uiAssets(),
            $errorReporter
        );
        $detailSections = new ProblemDetailSectionRegistry();
        $submissionPanel = new ProblemSubmissionPanelRegistry();
        $requestFactory = new ProblemRequestFactory($languages);
        $quizHtml = new QuizHtml($html);
        $quizRequestFactory = new QuizRequestFactory($quizKeys);
        $bookRequestFactory = new ProblemBookRequestFactory();
        $menuAccessRule = new ProblemConsoleMenuAccessRule($assignments);
        $accountShell->consoleMenuAccessRules()->register(
            'problems.assigned',
            $menuAccessRule
        );
        $editForm = new ProblemEditForm(
            $topics,
            $accountShell->actionUrls(),
            $accountShell->actionSecurity(),
            $html,
            $languages
        );
        $assignmentPanel = new ProblemAssignmentPanel(
            $accountShell->actionUrls(),
            $accountShell->actionSecurity(),
            $html
        );
        $quizEditForm = new QuizEditForm(
            $accountShell->actionUrls(),
            $accountShell->actionSecurity(),
            $html,
            $quizKeys
        );

        $routes = $accountShell->routeHandlers();
        $paginationView = new PaginationView();
        $routes->register('problems.index', new ProblemListPageHandler(
            $accountShell->publicPages(),
            $catalog,
            $html,
            $paginationView
        ));
        $routes->register('problems.show', new ProblemDetailPageHandler(
            $accountShell->publicPages(),
            $accountShell->currentAccounts(),
            $detailLoader,
            $detailView,
            $detailSections,
            $submissionPanel
        ));
        $routes->register('problems.statistics', new ProblemStatisticsPageHandler(
            $accountShell->publicPages(),
            $accountShell->currentAccounts(),
            $catalog,
            $problemGateway,
            $resultCatalog,
            $html
        ));
        $routes->register('problems.ranking', new ProblemRankingPageHandler(
            $accountShell->publicPages(),
            $accountShell->currentAccounts(),
            $catalog,
            $problemGateway,
            $html
        ));
        $routes->register('quizzes.index', new QuizListPageHandler(
            $accountShell->publicPages(),
            $accountShell->currentAccounts(),
            $quizService,
            $quizHtml
        ));
        $routes->register('quizzes.show', new QuizDetailPageHandler(
            $accountShell->publicPages(),
            $accountShell->currentAccounts(),
            $quizService,
            $quizKeys,
            $accountShell->actionUrls(),
            $accountShell->actionSecurity(),
            $html,
            $quizHtml
        ));
        $routes->register('quizzes.attempt', new QuizAttemptPageHandler(
            $accountShell->publicPages(),
            $accountShell->currentAccounts(),
            $quizService,
            $accountShell->actionUrls(),
            $accountShell->actionSecurity(),
            $html,
            $quizHtml
        ));
        $routes->register('quizzes.result', new QuizResultPageHandler(
            $accountShell->publicPages(),
            $accountShell->currentAccounts(),
            $quizService,
            $html,
            $quizHtml
        ));
        $routes->register('quizzes.statistics', new QuizStatisticsPageHandler(
            $accountShell->publicPages(),
            $accountShell->currentAccounts(),
            $quizService,
            $quizHtml
        ));
        $routes->register('problem_books.index', new ProblemBookPageHandler(
            $accountShell->publicPages(),
            $accountShell->currentAccounts(),
            $bookService,
            $accountShell->actionUrls(),
            $accountShell->actionSecurity(),

            $html

        ));
        $routes->register('console.problems.index', new ProblemAdminListPageHandler(
            $accountShell->consolePages(),
            $accountShell->currentAccounts(),
            $catalog,
            $access,
            $accountShell->actionUrls(),
            $accountShell->actionSecurity(),

            $html,
            $paginationView

        ));
        $routes->register('console.problems.new', new ProblemNewPageHandler(
            $accountShell->consolePages(),
            $accountShell->currentAccounts(),
            $access,
            $problemGateway,
            $aiDrafts,
            $editForm,
            $html
        ));
        $routes->register('console.problems.edit', new ProblemEditPageHandler(
            $accountShell->consolePages(),
            $accountShell->currentAccounts(),
            $catalog,
            $access,
            $aiDrafts,
            $assignmentService,
            $editForm,
            $assignmentPanel,
            $html
        ));
        $routes->register('console.problems.delete', new ProblemArchivePageHandler(
            $accountShell->consolePages(),
            $accountShell->currentAccounts(),
            $catalog,
            $access,
            $accountShell->actionUrls(),
            $accountShell->actionSecurity(),

            $html

        ));
        $routes->register('console.problems.test_data', new ProblemTestDataPageHandler(
            $accountShell->consolePages(),
            $accountShell->currentAccounts(),
            $catalog,
            $access,
            $aiDrafts,
            $testData,
            $accountShell->actionUrls(),
            $accountShell->actionSecurity(),
            $html,
            $errorReporter,
            $visualGrading
        ));
        $routes->register(
            'console.problems.test_data_settings',
            new ProblemTestDataSettingsPageHandler(
                $accountShell->consolePages(),
                $settings,
                $accountShell->actionUrls(),
                $accountShell->actionSecurity(),
                $html,
                $compilerProfiles,
                $sandboxProfiles
            )
        );
        $routes->register('console.problems.topics', new ProblemTopicsPageHandler(
            $accountShell->consolePages(),
            $accountShell->currentAccounts(),
            $access,
            $topics,
            $accountShell->actionUrls(),
            $accountShell->actionSecurity(),

            $html

        ));
        $routes->register(
            'console.problems.change_id',
            new ProblemNumberChangePageHandler(
                $accountShell->consolePages(),
                $accountShell->currentAccounts(),
                $numberChangeService,
                $problemGateway,
                $access,
                $accountShell->actionUrls(),
                $accountShell->actionSecurity(),
                $html
            )
        );
        $routes->register(
            'console.problems.xml',
            new ProblemXmlPageHandler(
                $accountShell->consolePages(),
                $accountShell->currentAccounts(),
                $xmlPreviews,
                $accountShell->actionUrls(),
                $accountShell->actionSecurity(),
                $html
            )
        );
        $routes->register(
            'console.quizzes.index',
            new QuizAdminListPageHandler(
                $accountShell->consolePages(),
                $accountShell->currentAccounts(),
                $quizService,
                $quizHtml
            )
        );
        $routes->register(
            'console.quizzes.new',
            new QuizNewPageHandler(
                $accountShell->consolePages(),
                $accountShell->currentAccounts(),
                $quizService,
                $quizEditForm,
                $html
            )
        );
        $routes->register(
            'console.quizzes.edit',
            new QuizEditPageHandler(
                $accountShell->consolePages(),
                $accountShell->currentAccounts(),
                $quizService,
                $quizBankService,
                $quizEditForm,
                $html
            )
        );
        $routes->register(
            'console.quizzes.statistics',
            new QuizAdminStatisticsPageHandler(
                $accountShell->consolePages(),
                $accountShell->currentAccounts(),
                $accountShell->users(),
                $quizService,
                $quizHtml
            )
        );
        $routes->register(
            'console.quiz-bank.index',
            new QuizBankListPageHandler(
                $accountShell->consolePages(),
                $accountShell->currentAccounts(),
                $quizBankService,
                $accountShell->actionUrls(),
                $accountShell->actionSecurity(),
                $quizHtml
            )
        );
        $routes->register(
            'console.quiz-bank.new',
            new QuizBankNewPageHandler(
                $accountShell->consolePages(),
                $quizEditForm
            )
        );
        $routes->register(
            'console.quiz-bank.edit',
            new QuizBankEditPageHandler(
                $accountShell->consolePages(),
                $accountShell->currentAccounts(),
                $quizBankService,
                $quizEditForm
            )
        );
        $routes->register(
            'console.problem_books.index',
            new ProblemBookAdminPageHandler(
                $accountShell->consolePages(),
                $accountShell->currentAccounts(),
                $bookService,
                $accountShell->actionUrls(),
                $accountShell->actionSecurity(),
                $html
            )
        );

        $actions = $accountShell->actionHandlers();
        $actions->register('oj_problem_save', new SaveProblemActionHandler(
            $editor,
            $assignmentService,
            $aiDrafts,
            $requestFactory,
            $accountShell->actionGuard(),
            $accountShell->actionResponder(),
            $errorReporter
        ));
        $archiveAction = new ArchiveProblemActionHandler(
            'oj_problem_delete',
            $editor,
            $accountShell->actionGuard(),
            $accountShell->actionResponder(),
            $errorReporter
        );
        $actions->register('oj_problem_delete', $archiveAction);
        $actions->register(
            'oj_problem_toggle_visibility',
            new ArchiveProblemActionHandler(
                'oj_problem_toggle_visibility',
                $editor,
                $accountShell->actionGuard(),
                $accountShell->actionResponder(),
                $errorReporter
            )
        );
        $actions->register(
            'oj_problem_bulk_visibility',
            new BulkArchiveProblemsActionHandler(
                $editor,
                $accountShell->actionGuard(),
                $accountShell->actionResponder(),
                $errorReporter
            )
        );
        $actions->register(
            'oj_problem_topic_create',
            new CreateProblemTopicActionHandler(
                $topicService,
                $accountShell->actionGuard(),
                $accountShell->actionResponder(),
                $errorReporter
            )
        );
        $actions->register(
            'oj_problem_test_data_action',
            new ProblemTestDataActionHandler(
                $testData,
                $testDataJobs,
                $accountShell->actionGuard(),
                $accountShell->actionResponder(),
                $errorReporter,
                $visualGrading
            )
        );
        $actions->register(
            'oj_save_problem_test_data_settings',
            new SaveProblemTestDataSettingsActionHandler(
                $testDataSettings,
                $accountShell->actionGuard(),
                $accountShell->actionResponder(),
                $errorReporter
            )
        );
        $actions->register(
            'oj_problem_change_id',
            new ChangeProblemNumberActionHandler(
                $numberChangeService,
                $accountShell->actionGuard(),
                $accountShell->actionResponder(),
                $errorReporter
            )
        );
        $actions->register(
            'oj_problem_xml_import',
            new ProblemXmlImportActionHandler(
                $xmlPreviews,
                $xmlImports,
                $accountShell->actionGuard(),
                $accountShell->actionResponder(),
                $errorReporter
            )
        );
        $actions->register(
            'oj_problem_xml_export',
            new ProblemXmlExportActionHandler(
                $xmlExports,
                $accountShell->actionGuard(),
                $accountShell->actionResponder(),
                $errorReporter
            )
        );
        $actions->register(
            'oj_problem_generate_metadata',
            new GenerateProblemMetadataActionHandler(
                'oj_problem_generate_metadata',
                false,
                $aiJobs,
                $catalog,
                $accountShell->actionGuard(),
                $accountShell->actionResponder(),
                $errorReporter
            )
        );
        $actions->register(
            'oj_problem_generate_metadata_filtered',
            new GenerateProblemMetadataActionHandler(
                'oj_problem_generate_metadata_filtered',
                true,
                $aiJobs,
                $catalog,
                $accountShell->actionGuard(),
                $accountShell->actionResponder(),
                $errorReporter
            )
        );
        $metadataBulkApply = new ProblemMetadataBulkApplyService(
            $aiDrafts,
            $problemGateway,
            $metadata,
            $editor,
            $access,
            $errorReporter
        );
        $actions->register(
            'oj_problem_bulk_apply_metadata_draft',
            new ApplyProblemMetadataDraftsActionHandler(
                $metadataBulkApply,
                $accountShell->actionGuard(),
                $accountShell->actionResponder(),
                $errorReporter,
                $accountShell->consolePages()
            )
        );
        $actions->register(
            'oj_problem_upload_image',
            new UploadProblemImageActionHandler(
                new ProblemImageService($access, WordPressProblemImageStore::fromGlobals()),
                $accountShell->actionGuard(),
                $errorReporter
            )
        );
        $actions->register(
            'oj_problem_ai_generate',
            new GenerateProblemAiActionHandler(
                $aiJobs,
                $accountShell->actionGuard(),
                $errorReporter
            )
        );
        $actions->register(
            'oj_quiz_create',
            new CreateQuizActionHandler(
                $quizService,
                $quizRequestFactory,
                $accountShell->actionGuard(),
                $accountShell->actionResponder(),
                $errorReporter
            )
        );
        $actions->register(
            'oj_quiz_save',
            new SaveQuizActionHandler(
                $quizService,
                $quizReapply,
                $quizRequestFactory,
                $accountShell->actionGuard(),
                $accountShell->actionResponder(),
                $errorReporter
            )
        );
        $actions->register(
            'oj_quiz_start_attempt',
            new StartQuizAttemptActionHandler(
                $quizService,
                $accountShell->actionGuard(),
                $accountShell->actionResponder(),
                $errorReporter
            )
        );
        $actions->register(
            'oj_quiz_submit_attempt',
            new SubmitQuizAttemptActionHandler(
                $quizService,
                $quizRequestFactory,
                $accountShell->actionGuard(),
                $accountShell->actionResponder(),
                $errorReporter
            )
        );
        $actions->register(
            'oj_quiz_bank_save',
            new SaveQuizBankQuestionActionHandler(
                $quizBankService,
                $quizRequestFactory,
                $accountShell->actionGuard(),
                $accountShell->actionResponder(),
                $errorReporter
            )
        );
        $actions->register(
            'oj_quiz_bank_delete',
            new DeleteQuizBankQuestionActionHandler(
                $quizBankService,
                $accountShell->actionGuard(),
                $accountShell->actionResponder(),
                $errorReporter
            )
        );
        $actions->register(
            'oj_quiz_import_bank_question',
            new ImportQuizBankQuestionActionHandler(
                $quizBankService,
                $accountShell->actionGuard(),
                $accountShell->actionResponder(),
                $errorReporter
            )
        );
        $actions->register(
            'oj_quiz_export_to_bank',
            new ExportQuizQuestionToBankActionHandler(
                $quizBankService,
                $accountShell->actionGuard(),
                $accountShell->actionResponder(),
                $errorReporter
            )
        );
        $actions->register(
            'oj_problem_book_save_category',
            new SaveProblemBookActionHandler(
                $bookService,
                $bookRequestFactory,
                $accountShell->actionGuard(),
                $accountShell->actionResponder(),
                $errorReporter
            )
        );
        $actions->register(
            'oj_problem_book_delete_category',
            new DeleteProblemBookActionHandler(
                $bookService,
                $accountShell->actionGuard(),
                $accountShell->actionResponder(),
                $errorReporter
            )
        );
        $actions->register(
            'oj_problem_book_personal_add',
            new AddPersonalProblemBookActionHandler(
                $bookService,
                $accountShell->actionGuard(),
                $accountShell->actionResponder(),
                $errorReporter
            )
        );
        $actions->register(
            'oj_problem_book_personal_remove',
            new RemovePersonalProblemBookActionHandler(
                $bookService,
                $accountShell->actionGuard(),
                $accountShell->actionResponder(),
                $errorReporter
            )
        );

        return new ProblemsBundle(
            new Module($tableSchemas, $loader->load('problems-schema')),
            [
                ProblemPlatformDatabase::class => $database,
                ProblemAccountErasureParticipant::class => $accountErasure,
                QuizRepository::class => $quizRepository,
                QuizReapplyRepository::class => $quizRepository,
                ProblemBookRepository::class => $bookRepository,
                ProblemTopicLinkRepository::class => $topicLinks,
                ProblemMetadataRepository::class => $metadata,
                ProblemAssignmentRepository::class => $assignments,
                ProblemTopicDictionary::class => $topics,
                ProblemTopicBootstrap::class => $topicBootstrap,
                ProblemTransaction::class => $transaction,
                MysqliProblemGateway::class => $judgeProblems,
                ProblemGateway::class => $problemGateway,
                ProblemDataRootProvider::class => $dataRoots,
                ProblemDataWorkspace::class => $dataWorkspace,
                ProblemFileManager::class => $fileManager,
                ProblemArchiveManager::class => $archiveManager,
                ProblemNumberChangeLock::class => $numberChangeLock,
                ProblemAiAvailability::class => $aiAvailability,
                SpjCompilerProfileProvider::class => $compilerProfiles,
                SpjSandboxProfileProvider::class => $sandboxProfiles,
                BoundedProcessRunner::class => $processRunner,
                ProblemSpjCompiler::class => $spjCompiler,
                ProblemContextAccessRegistry::class => $contextAccess,
                ProblemAccessPolicy::class => $access,
                LearningProblemProvider::class => $learningProblems,
                ProblemJobAccessRule::class => $jobAccess,
                ProblemAssignmentService::class => $assignmentService,
                CodeCompletionTemplatePolicy::class => $completion,
                ProblemCatalogService::class => $catalog,
                QuizAccessPolicy::class => $quizAccess,
                QuizKeyFactory::class => $quizKeys,
                QuizGrader::class => $quizGrader,
                QuizService::class => $quizService,
                QuizReapplyService::class => $quizReapply,
                ProblemBookService::class => $bookService,
                ProblemEditorService::class => $editor,
                ProblemXmlCodec::class => $xmlCodec,
                ProblemXmlMaterializer::class => $xmlMaterializer,
                ProblemXmlPreviewService::class => $xmlPreviews,
                ProblemXmlWriter::class => $xmlWriter,
                ProblemXmlImportService::class => $xmlImports,
                ProblemXmlExportService::class => $xmlExports,
                ProblemCodePolicyService::class => $codePolicies,
                SubmissionProblemProvider::class => $submissionProblems,
                ProblemAiInputFactory::class => $aiInputs,
                ProblemAiOutputValidator::class => $aiOutputs,
                ProblemAiDraftService::class => $aiDrafts,
                ProblemAiJobSupport::class => $aiJobSupport,
                ProblemAiJobService::class => $aiJobs,
                ProblemTestDataService::class => $testData,
                ProblemTestDataSettingsService::class => $testDataSettings,
                ProblemTestDataJobService::class => $testDataJobs,
                ProblemNumberChangeService::class => $numberChangeService,
                ProblemNumberChangeCoordinator::class => $numberChangeCoordinator,
                ProblemTopicService::class => $topicService,
                JudgeResultCatalog::class => $resultCatalog,
                JudgeResults::class => $resultCatalog,
                ProblemHtml::class => $html,
                ProblemDetailSectionRegistry::class => $detailSections,
                ProblemSubmissionPanelRegistry::class => $submissionPanel,
                ProblemRequestFactory::class => $requestFactory,
                QuizHtml::class => $quizHtml,
                QuizRequestFactory::class => $quizRequestFactory,
                ProblemBookRequestFactory::class => $bookRequestFactory,
                ProblemConsoleMenuAccessRule::class => $menuAccessRule,
                ProblemEditForm::class => $editForm,
                ProblemAssignmentPanel::class => $assignmentPanel,
                QuizEditForm::class => $quizEditForm,
            ]
        );
    }
}
