<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Composition;

use CodingPanda\OjPlatform\Modules\Auth\Contract\UserProfileRepository;
use CodingPanda\OjPlatform\Modules\Auth\Infrastructure\WordPress\WordPressManagedUserNameGuard;
use CodingPanda\OjPlatform\Kernel\Addon\AcceptedAddonCatalog;
use CodingPanda\OjPlatform\Kernel\Addon\AddonAiTaskContributions;
use CodingPanda\OjPlatform\Kernel\Addon\AddonAiTaskContributor;
use CodingPanda\OjPlatform\Kernel\Addon\AddonCapabilityContributor;
use CodingPanda\OjPlatform\Kernel\Addon\AddonConsoleRouteAccessRule;
use CodingPanda\OjPlatform\Kernel\Addon\AddonContributionComposer;
use CodingPanda\OjPlatform\Kernel\Addon\AddonCorrelationIdFactory;
use CodingPanda\OjPlatform\Kernel\Addon\AddonDocumentExtensions;
use CodingPanda\OjPlatform\Kernel\Addon\AddonLifecycle;
use CodingPanda\OjPlatform\Kernel\Addon\AddonLifecycleManager;
use CodingPanda\OjPlatform\Kernel\Addon\AddonManifestValidator;
use CodingPanda\OjPlatform\Kernel\Addon\AddonMenuContributor;
use CodingPanda\OjPlatform\Kernel\Addon\AddonPublicMenuContributions;
use CodingPanda\OjPlatform\Kernel\Addon\AddonPrivacyInventory;
use CodingPanda\OjPlatform\Kernel\Addon\AddonRouteContributor;
use CodingPanda\OjPlatform\Kernel\Addon\AddonRuntimeReadiness;
use CodingPanda\OjPlatform\Kernel\Addon\AddonRuntimeServiceFactory;
use CodingPanda\OjPlatform\Kernel\Addon\RegistryCoreContributionSnapshotProvider;
use CodingPanda\OjPlatform\Kernel\Addon\RealAddonRuntimeServiceFactory;
use CodingPanda\OjPlatform\Kernel\Addon\RealAddonEditorGateway;
use CodingPanda\OjPlatform\Kernel\Addon\WordPressAddonAssetRenderer;
use CodingPanda\OjPlatform\Kernel\Addon\WordPressAddonHookPublisher;
use CodingPanda\OjPlatform\Kernel\WordPress\WordPressAddonNamespaceLedger;
use CodingPanda\OjPlatform\Kernel\WordPress\WordPressTrackingLogger;


use CodingPanda\OjPlatform\Kernel\Boot\BootReport;
use CodingPanda\OjPlatform\Kernel\Boot\Kernel;
use CodingPanda\OjPlatform\Kernel\Configuration\PhpConfigLoader;
use CodingPanda\OjPlatform\Kernel\Environment\PluginEnvironment;
use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Kernel\Hook\HookRegistrar;
use CodingPanda\OjPlatform\Kernel\Integration\Home\ContinueLearningHomeBlockProvider;
use CodingPanda\OjPlatform\Kernel\Integration\Learning\ContestProblemContextAccessProvider;
use CodingPanda\OjPlatform\Kernel\Integration\Home\EndingContestDashboardProvider;
use CodingPanda\OjPlatform\Kernel\Integration\Home\HomeSubmissionFeed;
use CodingPanda\OjPlatform\Kernel\Integration\Home\LearningSpaceHomeCatalog;
use CodingPanda\OjPlatform\Kernel\Integration\Home\LearningSpacesHomeBlockProvider;
use CodingPanda\OjPlatform\Kernel\Integration\Home\ManagedLearningDashboardProvider;
use CodingPanda\OjPlatform\Kernel\Integration\Home\RecentJobsDashboardProvider;
use CodingPanda\OjPlatform\Kernel\Integration\Home\RecentSubmissionsHomeBlockProvider;
use CodingPanda\OjPlatform\Kernel\Integration\Home\StaffShortcutHomeBlockProvider;
use CodingPanda\OjPlatform\Kernel\Integration\Home\SystemAlertsDashboardProvider;
use CodingPanda\OjPlatform\Kernel\Integration\Operations\AddonSystemStatusProvider;
use CodingPanda\OjPlatform\Kernel\Integration\Operations\RecordingErrorReporter;
use CodingPanda\OjPlatform\Kernel\Integration\Profile\SubmissionPublicProfileSectionProvider;
use CodingPanda\OjPlatform\Kernel\Integration\Learning\AiLearnerLevelSummaryProvider;
use CodingPanda\OjPlatform\Kernel\Integration\Learning\ContestLearningHistoryEligibility;
use CodingPanda\OjPlatform\Kernel\Integration\Learning\LearningConsolePageHandler;
use CodingPanda\OjPlatform\Kernel\Integration\Learning\GroupsFeatureDisableGuard;
use CodingPanda\OjPlatform\Kernel\Integration\Learning\LearningGroupChoiceBridge;
use CodingPanda\OjPlatform\Kernel\Integration\Learning\SubmissionConsoleMenuAccessRule;
use CodingPanda\OjPlatform\Kernel\Integration\Learning\SubmissionConsoleRouteAccessRule;
use CodingPanda\OjPlatform\Kernel\Integration\Learning\SubmissionManagedScopeProvider;
use CodingPanda\OjPlatform\Kernel\Infrastructure\WordPress\WordPressClock;
use CodingPanda\OjPlatform\Kernel\Module\ModuleCatalog;
use CodingPanda\OjPlatform\Kernel\Module\ModuleGraph;
use CodingPanda\OjPlatform\Kernel\Module\ModuleManifest;
use CodingPanda\OjPlatform\Kernel\Registry\DefinitionRegistry;
use CodingPanda\OjPlatform\Kernel\Registry\FoundationContractValidator;
use CodingPanda\OjPlatform\Kernel\Registry\JobHandlerContractValidator;
use CodingPanda\OjPlatform\Kernel\Registry\RegistrySet;
use CodingPanda\OjPlatform\Kernel\Registry\WebHandlerContractValidator;
use CodingPanda\OjPlatform\Kernel\Setup\CompositeSetupReadinessProbe;
use CodingPanda\OjPlatform\Kernel\Setup\InstallationIdFactory;
use CodingPanda\OjPlatform\Kernel\Setup\PhpExtensionSetupReadinessProbe;
use CodingPanda\OjPlatform\Kernel\Setup\PlatformConnectionSetupReadinessProbe;
use CodingPanda\OjPlatform\Kernel\Setup\SetupBootstrap;
use CodingPanda\OjPlatform\Kernel\Setup\SetupCoordinator;
use CodingPanda\OjPlatform\Kernel\Setup\SetupLock;

use CodingPanda\OjPlatform\Kernel\Setup\SetupScreen;
use CodingPanda\OjPlatform\Kernel\Setup\WordPress\WordPressSetupLock;
use CodingPanda\OjPlatform\Kernel\Setup\WordPress\WordPressSetupScreen;
use CodingPanda\OjPlatform\Modules\AccessRouter\Application\RoutePatternCompiler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Application\RouteUrlGenerator;
use CodingPanda\OjPlatform\Modules\AccessRouter\Application\RouteMatcher;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionDefinition;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteDefinition;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteUrlProvider;
use CodingPanda\OjPlatform\Modules\AccessRouter\Infrastructure\WordPress\WordPressRewriteManager;
use CodingPanda\OjPlatform\Modules\AccessRouter\Infrastructure\WordPress\WordPressRouteTransport;
use CodingPanda\OjPlatform\Modules\AccessRouter\Infrastructure\WordPress\WordPressWebRuntime;
use CodingPanda\OjPlatform\Modules\AI\Application\AiFeedbackJobAccessRule;
use CodingPanda\OjPlatform\Modules\AdminConsole\Application\MenuTree;
use CodingPanda\OjPlatform\Modules\AdminConsole\Application\SystemStatusRegistry;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\MenuDefinition;
use CodingPanda\OjPlatform\Modules\Board\Infrastructure\WordPress\WordPressCommunityPostTypeRegistrar;
use CodingPanda\OjPlatform\Modules\Auth\Application\LegacyJudgePasswordVerifier;
use CodingPanda\OjPlatform\Modules\Auth\Application\VerifiedLegacyJudgeUserReader;
use CodingPanda\OjPlatform\Modules\Auth\Contract\LegacyJudgeUserRecordSource;
use CodingPanda\OjPlatform\Modules\Auth\Infrastructure\Judge\MysqliLegacyJudgeUserRecordSource;
use CodingPanda\OjPlatform\Modules\Jobs\Application\IdempotencyKeyFactory;
use CodingPanda\OjPlatform\Modules\Jobs\Application\JobAccessRuleRegistry;
use CodingPanda\OjPlatform\Modules\Jobs\Application\JobManager;
use CodingPanda\OjPlatform\Modules\Jobs\Application\JobWorker;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\FormDraftStore;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobDispatcher;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobDefinition;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobQueue;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobResultJournal;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobStepJournal;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\PrivateDataRootProvider;
use CodingPanda\OjPlatform\Modules\Jobs\Infrastructure\Filesystem\EncryptedFileFormDraftStore;
use CodingPanda\OjPlatform\Modules\Jobs\Infrastructure\WordPress\WordPressJobQueue;
use CodingPanda\OjPlatform\Modules\Jobs\Infrastructure\WordPress\WordPressJobQuery;
use CodingPanda\OjPlatform\Modules\Jobs\Infrastructure\WordPress\WordPressJobResultJournal;
use CodingPanda\OjPlatform\Modules\Jobs\Infrastructure\WordPress\WordPressJobScheduler;
use CodingPanda\OjPlatform\Modules\Jobs\Infrastructure\WordPress\WordPressJobWakeup;
use CodingPanda\OjPlatform\Modules\Jobs\Infrastructure\WordPress\WordPressJobStepJournal;
use CodingPanda\OjPlatform\Modules\Jobs\Infrastructure\WordPress\WordPressPrivateDataRootProvider;
use CodingPanda\OjPlatform\Modules\Jobs\Registry\JobHandlerRegistry;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeProfileDefinition;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeConnectionProbe;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeConnectionSettingsProvider;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeConnector;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeSchemaExtensionInstaller;
use CodingPanda\OjPlatform\Modules\JudgeDb\Infrastructure\Mysqli\MysqliJudgeConnectionProbe;
use CodingPanda\OjPlatform\Modules\JudgeDb\Infrastructure\Mysqli\MysqliJudgeConnector;
use CodingPanda\OjPlatform\Modules\JudgeDb\Infrastructure\Mysqli\MysqliJudgeSchemaExtensionInstaller;
use CodingPanda\OjPlatform\Modules\JudgeDb\Infrastructure\Settings\SecretVaultJudgeConnectionSettingsProvider;
use CodingPanda\OjPlatform\Modules\Permission\Application\PermissionService;
use CodingPanda\OjPlatform\Modules\Permission\Application\PermissionInstaller;
use CodingPanda\OjPlatform\Modules\Permission\Contract\CapabilityChecker;
use CodingPanda\OjPlatform\Modules\Permission\Contract\CapabilityDefinition;
use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;
use CodingPanda\OjPlatform\Modules\Permission\Contract\PermissionSetup;
use CodingPanda\OjPlatform\Modules\Permission\Contract\PermissionPresetDefinition;
use CodingPanda\OjPlatform\Modules\Permission\Contract\RoleDefinition;
use CodingPanda\OjPlatform\Modules\Permission\Contract\RoleSynchronizer;
use CodingPanda\OjPlatform\Modules\Permission\Infrastructure\WordPress\WordPressCapabilityChecker;
use CodingPanda\OjPlatform\Modules\Permission\Infrastructure\WordPress\WordPressPermissionManager;
use CodingPanda\OjPlatform\Modules\Permission\Infrastructure\WordPress\WordPressRoleSynchronizer;
use CodingPanda\OjPlatform\Modules\Problems\Infrastructure\WordPress\ProblemTopicBootstrap;
use CodingPanda\OjPlatform\Modules\PlatformDb\Application\InstallationPlanner;
use CodingPanda\OjPlatform\Modules\PlatformDb\Application\SchemaInstaller;
use CodingPanda\OjPlatform\Modules\PlatformDb\Application\SchemaOwnershipMarker;
use CodingPanda\OjPlatform\Modules\PlatformDb\Application\SchemaSqlCompiler;
use CodingPanda\OjPlatform\Modules\PlatformDb\Contract\ApplicationDatabase;
use CodingPanda\OjPlatform\Modules\PlatformDb\Contract\PlatformConnection;
use CodingPanda\OjPlatform\Modules\PlatformDb\Contract\SchemaCompiler;
use CodingPanda\OjPlatform\Modules\PlatformDb\Contract\SchemaOwnership;
use CodingPanda\OjPlatform\Modules\PlatformDb\Contract\SchemaTableDefinition;
use CodingPanda\OjPlatform\Modules\PlatformDb\Contract\TargetTableDefinition;
use CodingPanda\OjPlatform\Modules\PlatformDb\Infrastructure\WordPress\WordPressApplicationDatabase;
use CodingPanda\OjPlatform\Modules\PlatformDb\Infrastructure\WordPress\WordPressPlatformConnection;
use CodingPanda\OjPlatform\Modules\Settings\Application\FeatureService;
use CodingPanda\OjPlatform\Modules\Settings\Application\FeatureDisableGuardRegistry;
use CodingPanda\OjPlatform\Modules\Settings\Application\SiteSettingsService;
use CodingPanda\OjPlatform\Modules\Settings\Application\SettingsService;
use CodingPanda\OjPlatform\Modules\Settings\Application\SystemSettingsService;
use CodingPanda\OjPlatform\Modules\Settings\Contract\FeatureDefinition;
use CodingPanda\OjPlatform\Modules\Settings\Contract\FeatureFlags;
use CodingPanda\OjPlatform\Modules\Settings\Contract\PayloadCipher;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SecretRecordStore;
use CodingPanda\OjPlatform\Modules\Settings\Application\AiProviderCatalog;
use CodingPanda\OjPlatform\Modules\Settings\Application\AiProviderSettingsService;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SecretVault;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingDefinition;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsManager;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingStore;
use CodingPanda\OjPlatform\Modules\Settings\Domain\SecretKeyRing;
use CodingPanda\OjPlatform\Modules\Settings\Infrastructure\Security\AesGcmPayloadCipher;
use CodingPanda\OjPlatform\Modules\Settings\Infrastructure\Security\AesGcmSecretVault;
use CodingPanda\OjPlatform\Modules\Settings\Infrastructure\WordPress\WordPressCandidateProofStore;
use CodingPanda\OjPlatform\Modules\Settings\Infrastructure\WordPress\WordPressSecretRecordStore;
use CodingPanda\OjPlatform\Modules\Settings\Infrastructure\WordPress\WordPressSettingStore;
use CodingPanda\OjPlatform\Modules\Settings\Infrastructure\WordPress\WordPressSmtpConfigurator;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\LearningHistoryEligibility;
use CodingPanda\OjPlatform\Shared\Clock\Clock;
use CodingPanda\OjPlatform\Shared\Clock\SystemClock;
use CodingPanda\OjPlatform\Shared\Ai\AiTaskRouter;
use CodingPanda\OjPlatform\Shared\Ai\AiTaskRouterRegistry;
use CodingPanda\OjPlatform\Ui\Asset\UiAssetEnqueuer;
use CodingPanda\OjPlatform\Ui\Infrastructure\WordPress\WordPressUiAssetEnqueuer;

final class CompositionRoot
{
    private const MIGRATION_RUNTIME_ACTIONS = [
        'oj_login',
        'oj_logout',
        'oj_lost_password',
        'oj_save_user_settings',
        'oj_change_password',
        'oj_accept_user_agreements',
        'oj_job_status',
        'oj_job_cancel',
        'oj_migration_scan',
        'oj_save_migration_settings',
        'oj_migration_dry_run',
        'oj_migration_run',
        'oj_migration_validate',
        'oj_migration_cleanup_report',
        'oj_migration_deactivate_legacy_plugins',
    ];

    private const MIGRATION_RUNTIME_ROUTES = [
        '/login',
        '/user-settings',
        '/user-agreements',
        '/oj-console/settings/migration',
    ];

    public function __construct(
        private readonly PluginEnvironment $environment,
        private readonly HookRegistrar $hooks,
        private readonly ErrorReporter $errorReporter
    ) {
    }

    public function boot(): BootReport
    {
        $container = $this->build();
        $bootableKernel = $container->get(Kernel::class);
        if (! $bootableKernel instanceof Kernel) {
            throw new \LogicException('The build container returned an invalid Kernel service.');
        }

        $report = $bootableKernel->boot();
        $setupBootstrap = $container->get(SetupBootstrap::class);
        if (! $setupBootstrap instanceof SetupBootstrap) {
            throw new \LogicException('The build container returned an invalid SetupBootstrap service.');
        }
        $setupCoordinator = $container->get(SetupCoordinator::class);
        if (! $setupCoordinator instanceof SetupCoordinator) {
            throw new \LogicException('The build container returned an invalid SetupCoordinator service.');
        }

        $setupSnapshot = $setupCoordinator->inspectRuntime();
        $setupBootstrap->registerTemporaryHooks($setupSnapshot);
        if ($setupSnapshot->allowsRuntime()) {
            $permissionManager = $container->get(WordPressPermissionManager::class);
            if (! $permissionManager instanceof WordPressPermissionManager) {
                throw new \LogicException(
                    'The build container returned an invalid permission manager service.'
                );
            }
            $permissionManager->registerHooks($this->hooks);

            $migrationRuntime = $setupSnapshot->isMigrationRuntime();
            $webRuntime = $container->get(WordPressWebRuntime::class);
            if (! $webRuntime instanceof WordPressWebRuntime) {
                throw new \LogicException('The build container returned an invalid web runtime service.');
            }
            $webRuntime->registerHooks(
                $migrationRuntime ? self::MIGRATION_RUNTIME_ACTIONS : null,
                $migrationRuntime ? self::MIGRATION_RUNTIME_ROUTES : null,
                $setupSnapshot->isRuntimeReady()
            );
            if (! $migrationRuntime && $setupSnapshot->isRuntimeReady()) {
                $profiles = $container->get(UserProfileRepository::class);
                if (! $profiles instanceof UserProfileRepository) {
                    throw new \LogicException('The build container returned an invalid user profile repository.');
                }
                (new WordPressManagedUserNameGuard($profiles))->register();
            }
            $jobScheduler = $container->get(WordPressJobScheduler::class);
            if (! $jobScheduler instanceof WordPressJobScheduler) {
                throw new \LogicException('The build container returned an invalid Jobs scheduler.');
            }
            $this->hooks->addAction(
                'init',
                [$jobScheduler, 'ensureScheduled'],
                20
            );
            $this->hooks->addAction(
                WordPressJobScheduler::HOOK,
                [$jobScheduler, 'run'],
                10
            );
            foreach (['admin_post_', 'admin_post_nopriv_'] as $prefix) {
                $this->hooks->addAction(
                    $prefix . WordPressJobWakeup::ACTION,
                    [$jobScheduler, 'handleWakeup'],
                    10
                );
            }
            if ($migrationRuntime) {
                return $report;
            }

            $smtpConfigurator = $container->get(WordPressSmtpConfigurator::class);
            if (! $smtpConfigurator instanceof WordPressSmtpConfigurator) {
                throw new \LogicException(
                    'The build container returned an invalid SMTP configurator.'
                );
            }
            $this->hooks->addAction(
                'phpmailer_init',
                [$smtpConfigurator, 'configure'],
                20
            );
            $candidateProofs = $container->get(
                WordPressCandidateProofStore::class
            );
            if (! $candidateProofs instanceof WordPressCandidateProofStore) {
                throw new \LogicException(
                    'The build container returned an invalid candidate proof store.'
                );
            }
            $this->hooks->addAction(
                WordPressCandidateProofStore::CLEANUP_HOOK,
                [$candidateProofs, 'cleanupExpired'],
                10
            );
            $privateMaintenance=$container->get(\CodingPanda\OjPlatform\Kernel\WordPress\WordPressPrivateExecutionMaintenance::class);
            $this->hooks->addAction('init',[$privateMaintenance,'schedule'],10);
            $this->hooks->addAction(\CodingPanda\OjPlatform\Kernel\WordPress\WordPressPrivateExecutionMaintenance::HOOK,[$privateMaintenance,'run'],10);
            $topicBootstrap = $container->get(ProblemTopicBootstrap::class);
            if (! $topicBootstrap instanceof ProblemTopicBootstrap) {
                throw new \LogicException('The build container returned an invalid topic bootstrap.');
            }
            $this->hooks->addAction('init', [$topicBootstrap, 'run'], 9);
            $communityPostTypes = $container->get(
                WordPressCommunityPostTypeRegistrar::class
            );
            if (! $communityPostTypes instanceof WordPressCommunityPostTypeRegistrar) {
                throw new \LogicException(
                    'The build container returned an invalid community post type registrar.'
                );
            }
            $this->hooks->addAction(
                'init',
                [$communityPostTypes, 'register'],
                8
            );
        }

        return $report;
    }

    public function build(): BuildContainer
    {
        $container = new BuildContainer();
        $clock = new SystemClock();
        $siteClock = new WordPressClock();
        $addonApiVersion = defined('OJ_PLATFORM_ADDON_API_VERSION')
            ? (string) constant('OJ_PLATFORM_ADDON_API_VERSION')
            : '1.6.0';
        $extensions = (new \CodingPanda\OjPlatform\Kernel\WordPress\WordPressAddonExtensionLoader())->load($addonApiVersion);
        $researchExtension = $extensions->research();
        $loader = new PhpConfigLoader($this->environment->pluginDirectory() . 'config', $researchExtension?->configuration() ?? []);
        $applicationDatabase = WordPressApplicationDatabase::fromGlobals();
        $platformConnection = WordPressPlatformConnection::fromGlobals();
        $ownershipMarker = new SchemaOwnershipMarker();
        $sqlCompiler = new SchemaSqlCompiler($ownershipMarker);
        $manifest = new ModuleManifest($loader->load('modules'));

        $capabilities = new DefinitionRegistry('capabilities', CapabilityDefinition::class);
        $roles = new DefinitionRegistry('roles', RoleDefinition::class);
        $presets = new DefinitionRegistry('permission-presets', PermissionPresetDefinition::class);
        $features = new DefinitionRegistry('features', FeatureDefinition::class);
        $settings = new DefinitionRegistry('settings', SettingDefinition::class);
        $targetTables = new DefinitionRegistry('target-tables', TargetTableDefinition::class);
        $tableSchemas = new DefinitionRegistry('table-schemas', SchemaTableDefinition::class);
        $judgeProfiles = new DefinitionRegistry('judge-profiles', JudgeProfileDefinition::class);
        $jobs = new DefinitionRegistry('jobs', JobDefinition::class);
        $routes = new DefinitionRegistry('routes', RouteDefinition::class);
        $actions = new DefinitionRegistry('actions', ActionDefinition::class);
        $menus = new DefinitionRegistry('menus', MenuDefinition::class);
        $judgeProfile = JudgeProfileDefinition::fromArray($loader->load('judge'));

        $registryValidator = new FoundationContractValidator(
            $manifest,
            $capabilities,
            $roles,
            $presets,
            $features,
            $settings,
            $targetTables,
            $tableSchemas,
            $judgeProfiles,
            $jobs,
            $routes,
            $actions,
            $menus
        );
        $settingStore = new WordPressSettingStore();
        $settingsService = new SettingsService($settings, $settingStore);
        $runtimeErrorReporter = new RecordingErrorReporter(
            $this->errorReporter,
            $settingStore,
            new WordPressTrackingLogger()
        );
        $siteSettings = new SiteSettingsService($settingsService);
        $systemSettings = new SystemSettingsService($settingsService);
        $secretRecordStore = new WordPressSecretRecordStore();
        $secretKeyRing = SecretKeyRing::fromConstants();
        $payloadCipher = new AesGcmPayloadCipher($secretKeyRing);
        $secretVault = new AesGcmSecretVault(
            $secretRecordStore,
            $secretKeyRing
        );
        $privateDataRoots = new WordPressPrivateDataRootProvider();
        $formDrafts = new EncryptedFileFormDraftStore(
            $privateDataRoots,
            $payloadCipher
        );
        $aiTaskRouter = new AiTaskRouterRegistry();
        $judgeConnectionSettings = new SecretVaultJudgeConnectionSettingsProvider(
            $settingsService,
            $secretVault
        );
        $judgeConnector = new MysqliJudgeConnector();
        $judgeProbe = new MysqliJudgeConnectionProbe(
            $judgeProfile,
            $judgeConnector
        );
        $judgeExtensions = new MysqliJudgeSchemaExtensionInstaller($judgeConnector);
        $legacyJudgeUserSource = new MysqliLegacyJudgeUserRecordSource(
            $judgeConnectionSettings,
            $judgeConnector,
            $runtimeErrorReporter
        );
        $legacyJudgeUsers = new VerifiedLegacyJudgeUserReader(
            $legacyJudgeUserSource,
            new LegacyJudgePasswordVerifier()
        );
        $idempotencyKeyFactory = new IdempotencyKeyFactory();
        $jobQueue = WordPressJobQueue::fromGlobals();
        $jobResultJournal = WordPressJobResultJournal::fromGlobals();
        $jobStepJournal = WordPressJobStepJournal::fromGlobals();
        $jobHandlers = new JobHandlerRegistry();
        $jobWakeup = WordPressJobWakeup::fromGlobals($runtimeErrorReporter);
        $jobManager = new JobManager(
            $jobs,
            $idempotencyKeyFactory,
            $formDrafts,
            $jobQueue,
            $jobWakeup
        );
        $jobWorker = new JobWorker(
            $jobQueue,
            $jobHandlers,
            $jobs,
            $systemSettings,
            $formDrafts,
            $jobResultJournal,
            $runtimeErrorReporter
        );
        $jobScheduler = new WordPressJobScheduler(
            $jobWorker,
            $formDrafts,
            $runtimeErrorReporter,
            $jobWakeup,
            $jobQueue
        );
        $featureDisableGuards = new FeatureDisableGuardRegistry();
        $featureService = new FeatureService($features, $settingsService, $featureDisableGuards);
        $capabilityChecker = new WordPressCapabilityChecker();
        $permissionService = new PermissionService($capabilities, $capabilityChecker);
        $roleSynchronizer = new WordPressRoleSynchronizer();
        $permissionInstaller = new PermissionInstaller($capabilities, $roles, $roleSynchronizer);
        $permissionManager = new WordPressPermissionManager(
            $permissionInstaller,
            $capabilities,
            $roles,
            $runtimeErrorReporter
        );
        $routePatterns = new RoutePatternCompiler();
        $routeUrlGenerator = new RouteUrlGenerator($routes);
        $routeMatcher = new RouteMatcher($routes, $routePatterns);
        $routeTransport = new WordPressRouteTransport($routeMatcher);
        $rewriteManager = new WordPressRewriteManager($routes, $routePatterns);
        $menuTree = new MenuTree($menus);

        $permissionModule = new \CodingPanda\OjPlatform\Modules\Permission\Module(
            $capabilities,
            $roles,
            $presets,
            $loader->load('capabilities')
        );
        $settingsModule = new \CodingPanda\OjPlatform\Modules\Settings\Module(
            $features,
            $settings,
            $loader->load('features'),
            $loader->load('settings'),
            $tableSchemas,
            $loader->load('settings-schema')
        );
        $platformDbModule = new \CodingPanda\OjPlatform\Modules\PlatformDb\Module(
            $targetTables,
            $loader->load('platform-tables')
        );
        $judgeDbModule = new \CodingPanda\OjPlatform\Modules\JudgeDb\Module(
            $judgeProfiles,
            $judgeProfile
        );
        $jobsModule = new \CodingPanda\OjPlatform\Modules\Jobs\Module(
            $jobs,
            $tableSchemas,
            $loader->load('jobs'),
            $loader->load('jobs-schema')
        );
        $accessRouterModule = new \CodingPanda\OjPlatform\Modules\AccessRouter\Module(
            $routes,
            $actions,
            $loader->load('routes'),
            $loader->load('actions')
        );
        $adminConsoleModule = new \CodingPanda\OjPlatform\Modules\AdminConsole\Module(
            $menus,
            $loader->load('menus')
        );
        $addonPublicMenus = new AddonPublicMenuContributions();
        $addonDocuments = new AddonDocumentExtensions(new WordPressAddonAssetRenderer());
        $addonPrivacy = new AddonPrivacyInventory();
        $accountShell = (new AccountShellComposition())->assemble(
            $this->environment,
            $this->hooks,
            $runtimeErrorReporter,
            $clock,
            $siteClock,
            $loader,
            $tableSchemas,
            $roles,
            $actions,
            $routes,
            $routeMatcher,
            $routeUrlGenerator,
            $routeTransport,
            $rewriteManager,
            $menuTree,
            $settingsService,
            $siteSettings,
            $systemSettings,
            $featureService,
            $permissionService,
            $permissionInstaller,
            $applicationDatabase,
            $formDrafts,
            $jobManager,
            $jobHandlers,
            $jobStepJournal,
            $legacyJudgeUsers,
            $addonPublicMenus,
            $capabilities,
            $addonDocuments,
            $addonPrivacy
        );
        $addonAiTasks = new AddonAiTaskContributions();
        $operations = (new OperationsComposition())->assemble(
            $runtimeErrorReporter,
            $settingsService,
            $featureService,
            $siteSettings,
            $systemSettings,
            $secretVault,
            $judgeConnectionSettings,
            $judgeProbe,
            $judgeExtensions,
            $permissionService,
            $aiTaskRouter,
            $accountShell,
            $addonAiTasks,
            $applicationDatabase,
            $clock,
            $siteClock,
            $routeUrlGenerator
        );
        $accountShell->dashboardBlocks()->register(
            new SystemAlertsDashboardProvider($operations->status(), $permissionService)
        );
        $accountShell->dashboardBlocks()->register(
            new \CodingPanda\OjPlatform\Kernel\Integration\Home\ServerPreparationDashboardProvider($capabilityChecker)
        );
        $problems = (new ProblemsComposition())->assemble(
            $runtimeErrorReporter,
            $loader,
            $tableSchemas,
            $applicationDatabase,
            $judgeConnectionSettings,
            $judgeConnector,
            $settingsService,
            $featureService,
            $permissionService,
            $jobManager,
            $jobHandlers,
            $jobStepJournal,
            $formDrafts,
            $aiTaskRouter,
            $siteClock,
            $accountShell,
            $this->environment->pluginDirectory() . 'resources/features'
        );
        $migration = (new MigrationComposition())->assemble(
            $runtimeErrorReporter,
            $tableSchemas,
            $sqlCompiler,
            $ownershipMarker,
            $settingsService,
            $systemSettings,
            $systemSettings,
            $judgeConnectionSettings,
            $judgeProbe,
            $permissionService,
            $jobManager,
            $jobHandlers,
            $jobStepJournal,
            $problems->topics(),
            $clock,
            $siteClock,
            $accountShell,
            $loader->load('migration-extra-tables')
        );
        $submissionManagedScope = new SubmissionManagedScopeProvider();
        $submissions = (new SubmissionsComposition())->assemble(
            $this->environment,
            $runtimeErrorReporter,
            $loader,
            $tableSchemas,
            $judgeConnectionSettings,
            $judgeConnector,
            $settingsService,
            $permissionService,
            $clock,
            $siteClock,
            $accountShell,
            $problems,
            $submissionManagedScope,
            $formDrafts
        );
        $accountShell->publicProfileSections()->register(
            new SubmissionPublicProfileSectionProvider(
                $submissions->userProfiles(),
                $problems->learningProblems(),
                $problems->topics(),
                $routeUrlGenerator
            )
        );
        $groupChoices = new LearningGroupChoiceBridge($featureService);
        $classes = (new ClassesComposition())->assemble(
            $runtimeErrorReporter,
            $loader,
            $tableSchemas,
            $applicationDatabase,
            $permissionService,
            $accountShell,
            $problems,
            $submissions,
            $groupChoices,
            $clock,
            $siteClock,
            $featureService
        );
        $contests = (new ContestsComposition())->assemble(
            $runtimeErrorReporter,
            $loader,
            $tableSchemas,
            $applicationDatabase,
            $judgeConnectionSettings,
            $judgeConnector,
            $problems->judgeResults(),
            $permissionService,
            $siteClock,
            $problems,
            $accountShell,
            $submissions,
            $groupChoices
        );
        $problems->contextAccess()->register(
            new ContestProblemContextAccessProvider($contests->service())
        );
        $featureDisableGuards->register(new GroupsFeatureDisableGuard(
            $classes->service(),
            $contests->service()
        ));
        $groups = (new GroupsComposition())->assemble(
            $runtimeErrorReporter,
            $loader,
            $tableSchemas,
            $applicationDatabase,
            $problems->judgeResults(),
            $featureService,
            $permissionService,
            $accountShell,
            $submissions,
            $jobManager,
            $jobHandlers,
            $siteClock
        );
        $groupChoices->attach($groups->service());
        $submissionManagedScope->attach(
            $classes->service(),
            $contests->service(),
            $groups->service()
        );
        $submissionConsoleRouteRule = new SubmissionConsoleRouteAccessRule(
            $classes->service(),
            $contests->service(),
            $groups->service(),
            $submissions->queries()
        );
        $accountShell->consoleRouteAccessRules()->register(
            'submissions.context',
            $submissionConsoleRouteRule
        );
        $accountShell->consoleMenuAccessRules()->register(
            'submissions.context',
            new SubmissionConsoleMenuAccessRule(
                $classes->service(),
                $contests->service(),
                $groups->service()
            )
        );
        $learningSpaces = new LearningSpaceHomeCatalog(
            $classes->service(),
            $contests->service(),
            $groups->service(),
            $featureService,
            $runtimeErrorReporter
        );
        $accountShell->homeBlocks()->register(
            new LearningSpacesHomeBlockProvider($learningSpaces)
        );
        $accountShell->routeHandlers()->register('learning_spaces.index', new \CodingPanda\OjPlatform\Kernel\Integration\Home\LearningSpacesPageHandler(
            $accountShell->publicPages(), $accountShell->currentAccounts(), $learningSpaces,
            $accountShell->homeView(), $siteClock, new \CodingPanda\OjPlatform\Ui\Component\PaginationView()
        ));
        $accountShell->homeBlocks()->register(
            new StaffShortcutHomeBlockProvider($learningSpaces, $permissionService)
        );
        $accountShell->dashboardBlocks()->register(
            new ManagedLearningDashboardProvider($learningSpaces)
        );
        $accountShell->dashboardBlocks()->register(
            new EndingContestDashboardProvider($contests->service(), $featureService)
        );
        $homeSubmissions = new HomeSubmissionFeed(
            $submissions->queries(),
            $submissions->languages(),
            $problems->learningProblems(),
            $submissions->performanceModePolicy()
        );
        $accountShell->homeBlocks()->register(
            new ContinueLearningHomeBlockProvider($homeSubmissions)
        );
        $accountShell->homeBlocks()->register(
            new RecentSubmissionsHomeBlockProvider($homeSubmissions)
        );
        $accountShell->routeHandlers()->register(
            'console.learning.index',
            new LearningConsolePageHandler(
                $accountShell->consolePages(),
                $accountShell->currentAccounts(),
                $classes->service(),
                $contests->service(),
                $groups->service(),
                $featureService,
                $runtimeErrorReporter
            )
        );
        $jobQuery = WordPressJobQuery::fromGlobals();
        $learningHistory = new ContestLearningHistoryEligibility(
            $contests->service(),
            $featureService
        );
        $aiFeedbackJobAccess = new AiFeedbackJobAccessRule(
            $permissionService
        );
        $jobAccessRules = new JobAccessRuleRegistry([
            $accountShell->userImportJobAccessRule(),
            $accountShell->accountErasureJobAccessRule(),
            $problems->jobAccessRule(),
            $migration->jobAccessRule(),
            $groups->jobAccessRule(),
            $aiFeedbackJobAccess,
        ]);
        $jobsUi = (new JobsUiComposition())->assemble(
            $runtimeErrorReporter,
            $jobQuery,
            $jobQueue,
            $formDrafts,
            $jobAccessRules,
            $accountShell,
            $jobWakeup
        );
        $accountShell->dashboardBlocks()->register(
            new RecentJobsDashboardProvider($jobQuery, $jobsUi->access())
        );
        $researchPolicy = new \CodingPanda\OjPlatform\Modules\AI\Application\ResearchPolicyBridge();
        $aiFeedback = (new AiFeedbackComposition())->assemble(
            $runtimeErrorReporter,
            $loader,
            $tableSchemas,
            $applicationDatabase,
            $privateDataRoots,
            $settingsService,
            $featureService,
            $permissionService,
            $aiTaskRouter,
            $jobManager,
            $jobQuery,
            $jobsUi->controlService(),
            $aiFeedbackJobAccess,
            $jobHandlers,
            $accountShell,
            $problems,
            $submissions,
            $learningHistory,
            $clock,
            $researchPolicy
        );
        $research = $researchExtension?->build(new \CodingPanda\OjPlatform\AddonApi\V1\Research\ResearchHostV1(
            $applicationDatabase, $featureService, $permissionService, $aiTaskRouter, $clock,
            $runtimeErrorReporter, $tableSchemas, $groups->service(), $accountShell->users(),
            new \CodingPanda\OjPlatform\Kernel\Integration\Learning\PlatformResearchLearningData(
                $aiFeedback->levels(), $accountShell->users(), $submissions->projections(), $problems->learningProblems(), $aiFeedback->feedbackJobs()
            ),
            $accountShell->currentAccounts(), $accountShell->consolePages(), $accountShell->publicPages(),
            $accountShell->actionUrls(), $accountShell->actionSecurity(), $accountShell->actionGuard()
        ));
        $missingResearch = new \CodingPanda\OjPlatform\Kernel\Integration\Learning\UnavailableResearchPolicy($applicationDatabase);
        $researchPolicy->attach($research?->feedback ?? $missingResearch);
        $submissions->submitter()->attachResearch($research?->submissions ?? $missingResearch);
        $accountShell->accountErasureParticipants()->register($research?->erasure ?? $missingResearch);
        if ($research !== null) {
            $featureDisableGuards->register($research->disableGuard);
            $problems->detailSections()->register($research->problemSection);
            foreach ($research->routes as $key => $handler) { $accountShell->routeHandlers()->register($key, $handler); }
            foreach ($research->actions as $key => $handler) { $accountShell->actionHandlers()->register($key, $handler); }
            foreach ($research->publicMenus as $entry) { $addonPublicMenus->add($entry, null); }
        }
        $learningDashboard = (new LearningDashboardComposition())->assemble(
            $accountShell,
            $problems,
            $submissions,
            $learningHistory,
            new AiLearnerLevelSummaryProvider(
                $aiFeedback->levels(),
                $featureService
            ),
            $siteClock,
            $research?->recommendations ?? $missingResearch
        );
        $ranking = (new RankingComposition())->assemble(
            $accountShell,
            $judgeConnectionSettings,
            $judgeConnector,
            $routeUrlGenerator
        );
        $blockly = (new BlocklyComposition())->assemble(
            $this->environment,
            $runtimeErrorReporter,
            $settingsService,
            $featureService,
            $accountShell,
            $submissions
        );
        $board = (new BoardComposition())->assemble(
            $runtimeErrorReporter,
            $settingsService,
            $featureService,
            $permissionService,
            $siteClock,
            $routeUrlGenerator,
            $accountShell,
            $problems,
            $classes,
            $contests,
            $groups
        );
        $messaging = (new MessagingComposition())->assemble(
            $runtimeErrorReporter,
            $loader,
            $tableSchemas,
            $applicationDatabase,
            $settingsService,
            $featureService,
            $groups->service(),
            $siteClock,
            $accountShell
        );
        $registries = new RegistrySet([
            $capabilities,
            $roles,
            $presets,
            $features,
            $settings,
            $targetTables,
            $tableSchemas,
            $judgeProfiles,
            $jobs,
            $routes,
            $actions,
            $menus,
        ], [
            $registryValidator,
            new WebHandlerContractValidator(
                $routes,
                $actions,
                $accountShell->routeHandlers(),
                $accountShell->actionHandlers(),
                [
                    'auth',
                    'jobs',
                    'permission',
                    'problems',
                    'public_ui',
                    'submissions',
                    'classes',
                    'contests',
                    'groups',
                    'blockly',
                    'ai_feedback',
                    'learning_dashboard',
                    'research',
                    'ranking',
                    'board',
                    'messaging',
                    'settings',
                    'judge_db',
                    'admin_console',
                    'migration',
                ],
                $loader->load('deferred-actions')
            ),
            new JobHandlerContractValidator(
                $jobs,
                $jobHandlers,
                ['auth', 'problems', 'groups', 'ai_feedback', 'migration']
            ),
        ]);

        $modules = new ModuleCatalog([
            $permissionModule,
            $settingsModule,
            $platformDbModule,
            $judgeDbModule,
            $jobsModule,
            $accessRouterModule,
            $accountShell->authModule(),
            $accountShell->publicUiModule(),
            $problems->module(),
            $submissions->module(),
            $classes->module(),
            $contests->module(),
            $groups->module(),
            $aiFeedback->module(),
            ...($research === null ? [] : [$research->module]),
            $learningDashboard->module(),
            $ranking->module(),
            $blockly->module(),
            $board->module(),
            $messaging->module(),
            $adminConsoleModule,
            $migration->module(),
        ]);
        $manifest->assertLoadedCatalog($modules);
        $addonCatalog = new AcceptedAddonCatalog();
        $addonCorrelations = new AddonCorrelationIdFactory();
        $addonAiLedger = new \CodingPanda\OjPlatform\Kernel\Integration\Operations\PlatformAddonAiRequestLedger($applicationDatabase);
        $accountShell->accountErasureParticipants()->register(new \CodingPanda\OjPlatform\Kernel\Integration\Operations\AddonAiLedgerAccountErasure($addonAiLedger));
        $container->bind(\CodingPanda\OjPlatform\Modules\Settings\Contract\AddonAiRequestLedger::class,$addonAiLedger);
        $container->bind(\CodingPanda\OjPlatform\Kernel\WordPress\WordPressPrivateExecutionMaintenance::class,
            new \CodingPanda\OjPlatform\Kernel\WordPress\WordPressPrivateExecutionMaintenance($submissions->codeExecution(),$addonAiLedger,$runtimeErrorReporter));
        $addonRuntimeServices = new RealAddonRuntimeServiceFactory(
            $addonCorrelations,
            $classes->service(),
            $groups->service(),
            $accountShell->users(),
            $addonCatalog,
            $routeUrlGenerator,
            $problems->catalog(),
            $submissions->queries(),
            $permissionService,
            $aiTaskRouter,
            $operations->service(AiProviderSettingsService::class),
            $research?->actorAiAccess ?? $missingResearch,
            new RealAddonEditorGateway(
                $accountShell->currentAccounts(),
                $accountShell->submissionPreferences(),
                $submissions->codeEditorAssets()
            ),
            $submissions->aiFeedbackSubmissions(),
            $contests->service(),
            $problems->submissionProblems(),
            $submissions->languages(),
            $submissions->codeExecution(),
            $addonAiLedger,
            static fn():array=>$submissions->executionSupport()->allowed(),
            static fn():string=>(new \CodingPanda\OjPlatform\Kernel\WordPress\WordPressCodeExecutionIdentityKey())->key(),
            $submissions->drafts()
        );
        $addonComposer = new AddonContributionComposer(
            $features,
            new AddonCapabilityContributor($capabilities),
            new AddonRouteContributor(
                $routes,
                $accountShell->routeHandlers(),
                $accountShell->currentAccounts(),
                $permissionService,
                $addonCorrelations,
                $routeUrlGenerator,
                $accountShell->consolePages(),
                $accountShell->publicPages(),
                new WordPressAddonAssetRenderer()
            ),
            new AddonMenuContributor($menus, $addonPublicMenus),
            new AddonAiTaskContributor($addonAiTasks),
            $accountShell->consoleRouteAccessRules(),
            $permissionService,
            $addonDocuments,
            $addonPrivacy
        );
        $addonRuntimeReadiness = new AddonRuntimeReadiness();
        $addonLifecycle = new AddonLifecycleManager(
            new WordPressAddonHookPublisher(),
            new AddonManifestValidator(
                $this->environment->version(),
                $addonApiVersion,
                new RegistryCoreContributionSnapshotProvider(
                    $routes,
                    $menus,
                    $capabilities,
                    $accountShell->publicMenuSettings(),
                    [
                        'oj-platform-ui-system',
                        'oj-platform-ui',
                        'oj-platform-mathjax-loader',
                    ],
                    AiProviderCatalog::TASK_TYPES
                ),
                new WordPressAddonNamespaceLedger()
            ),
            $addonCatalog,
            $addonRuntimeServices,
            $this->environment->version(),
            $addonApiVersion,
            $addonCorrelations,
            $runtimeErrorReporter,
            $addonComposer,
            $addonRuntimeReadiness
        );
        $operations->service(SystemStatusRegistry::class)->register(
            new AddonSystemStatusProvider($addonCatalog, $addonRuntimeReadiness)
        );
        $kernel = new Kernel(
            $modules,
            new ModuleGraph(),
            $registries,
            $clock,
            $addonLifecycle
        );

        $planner = new InstallationPlanner(
            $targetTables,
            $tableSchemas,
            $platformConnection,
            $ownershipMarker
        );
        $installer = new SchemaInstaller($platformConnection, $sqlCompiler);
        $setupLock = new WordPressSetupLock();
        $setupCoordinator = new SetupCoordinator(
            $targetTables,
            $tableSchemas,
            $platformConnection,
            $planner,
            $installer,
            $permissionInstaller,
            $settingsService,
            new InstallationIdFactory(),
            $setupLock,
            $migration->legacyPresence(),
            new CompositeSetupReadinessProbe([
                new PhpExtensionSetupReadinessProbe(),
                new PlatformConnectionSetupReadinessProbe($platformConnection),
            ]),
            $loader->load('additive-install-modules'),
            new \CodingPanda\OjPlatform\Kernel\Setup\WordPress\WordPressSetupInspectionCache($this->environment->version())
        );
        $uiAssets = new WordPressUiAssetEnqueuer($this->environment);
        $setupScreen = new WordPressSetupScreen(
            $setupCoordinator,
            $uiAssets,
            $this->errorReporter,
            $rewriteManager,
            $routeTransport,
            $judgeConnectionSettings
        );
        $setupBootstrap = new SetupBootstrap(
            $this->hooks,
            $setupCoordinator,
            $setupScreen
        );

        $container->bind(PluginEnvironment::class, $this->environment);
        $container->bind(HookRegistrar::class, $this->hooks);
        $container->bind(ErrorReporter::class, $runtimeErrorReporter);
        $container->bind(RecordingErrorReporter::class, $runtimeErrorReporter);
        $container->bind(AddonLifecycle::class, $addonLifecycle);
        $container->bind(AcceptedAddonCatalog::class, $addonCatalog);
        $container->bind(AddonRuntimeServiceFactory::class, $addonRuntimeServices);
        $container->bind(Clock::class, $clock);
        $container->bind(PhpConfigLoader::class, $loader);
        $container->bind(ModuleManifest::class, $manifest);
        $container->bind(ModuleCatalog::class, $modules);
        $container->bind(RegistrySet::class, $registries);
        $container->bind(Kernel::class, $kernel);
        $container->bind(FoundationServiceIds::CAPABILITIES, $capabilities);
        $container->bind(FoundationServiceIds::ROLES, $roles);
        $container->bind(FoundationServiceIds::PERMISSION_PRESETS, $presets);
        $container->bind(FoundationServiceIds::FEATURES, $features);
        $container->bind(FoundationServiceIds::SETTINGS, $settings);
        $container->bind(FoundationServiceIds::TARGET_TABLES, $targetTables);
        $container->bind(FoundationServiceIds::TABLE_SCHEMAS, $tableSchemas);
        $container->bind(FoundationServiceIds::JUDGE_PROFILES, $judgeProfiles);
        $container->bind(FoundationServiceIds::JOBS, $jobs);
        $container->bind(FoundationServiceIds::ROUTES, $routes);
        $container->bind(FoundationServiceIds::ACTIONS, $actions);
        $container->bind(FoundationServiceIds::MENUS, $menus);
        $container->bind(SettingStore::class, $settingStore);
        $container->bind(SettingsService::class, $settingsService);
        $container->bind(SettingsManager::class, $settingsService);
        $container->bind(SecretRecordStore::class, $secretRecordStore);
        $container->bind(SecretKeyRing::class, $secretKeyRing);
        $container->bind(PayloadCipher::class, $payloadCipher);
        $container->bind(SecretVault::class, $secretVault);
        $container->bind(PrivateDataRootProvider::class, $privateDataRoots);
        $container->bind(FormDraftStore::class, $formDrafts);
        $container->bind(AiTaskRouter::class, $aiTaskRouter);
        $container->bind(AiTaskRouterRegistry::class, $aiTaskRouter);
        $container->bind(
            JudgeConnectionSettingsProvider::class,
            $judgeConnectionSettings
        );
        $container->bind(MysqliJudgeConnector::class, $judgeConnector);
        $container->bind(JudgeConnector::class, $judgeConnector);
        $container->bind(JudgeSchemaExtensionInstaller::class, $judgeExtensions);
        $container->bind(
            LegacyJudgeUserRecordSource::class,
            $legacyJudgeUserSource
        );
        $container->bind(JobQueue::class, $jobQueue);
        $container->bind(JobResultJournal::class, $jobResultJournal);
        $container->bind(JobStepJournal::class, $jobStepJournal);
        $container->bind(JobHandlerRegistry::class, $jobHandlers);
        $container->bind(JobManager::class, $jobManager);
        $container->bind(JobDispatcher::class, $jobManager);
        $container->bind(JobWorker::class, $jobWorker);
        $container->bind(WordPressJobScheduler::class, $jobScheduler);
        $jobsUi->bindInto($container);
        $container->bind(FeatureService::class, $featureService);
        $container->bind(FeatureFlags::class, $featureService);
        $container->bind(
            LearningHistoryEligibility::class,
            $learningHistory
        );
        $container->bind(CapabilityChecker::class, $capabilityChecker);
        $container->bind(PermissionService::class, $permissionService);
        $container->bind(Authorization::class, $permissionService);
        $container->bind(RoleSynchronizer::class, $roleSynchronizer);
        $container->bind(PermissionInstaller::class, $permissionInstaller);
        $container->bind(PermissionSetup::class, $permissionInstaller);
        $container->bind(WordPressPermissionManager::class, $permissionManager);
        $container->bind(
            JudgeConnectionProbe::class,
            $judgeProbe
        );
        $container->bind(ApplicationDatabase::class, $applicationDatabase);
        $container->bind(PlatformConnection::class, $platformConnection);
        $container->bind(SchemaOwnershipMarker::class, $ownershipMarker);
        $container->bind(SchemaSqlCompiler::class, $sqlCompiler);
        $container->bind(SchemaOwnership::class, $ownershipMarker);
        $container->bind(SchemaCompiler::class, $sqlCompiler);
        $container->bind(InstallationPlanner::class, $planner);
        $container->bind(SchemaInstaller::class, $installer);
        $container->bind(IdempotencyKeyFactory::class, $idempotencyKeyFactory);
        $container->bind(RoutePatternCompiler::class, $routePatterns);
        $container->bind(RouteUrlGenerator::class, $routeUrlGenerator);
        $container->bind(RouteUrlProvider::class, $routeUrlGenerator);
        $container->bind(RouteMatcher::class, $routeMatcher);
        $container->bind(MenuTree::class, $menuTree);
        $container->bind(SetupLock::class, $setupLock);
        $container->bind(SetupCoordinator::class, $setupCoordinator);
        $container->bind(UiAssetEnqueuer::class, $uiAssets);
        $container->bind(SetupScreen::class, $setupScreen);
        $container->bind(SetupBootstrap::class, $setupBootstrap);
        $accountShell->bindInto($container);
        $problems->bindInto($container);
        $submissions->bindInto($container);
        $classes->bindInto($container);
        $contests->bindInto($container);
        $groups->bindInto($container);
        $aiFeedback->bindInto($container);

        $learningDashboard->bindInto($container);
        $ranking->bindInto($container);
        $blockly->bindInto($container);
        $board->bindInto($container);
        $migration->bindInto($container);
        $operations->bindInto($container);
        $container->freeze();

        return $container;
    }
}
