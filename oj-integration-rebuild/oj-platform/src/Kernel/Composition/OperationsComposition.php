<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Composition;

use CodingPanda\OjPlatform\Kernel\Addon\AddonAiTaskContributions;
use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Kernel\Integration\Operations\AiSystemStatusProvider;
use CodingPanda\OjPlatform\Kernel\Integration\Operations\PlatformAiUsageStore;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteUrlProvider;
use CodingPanda\OjPlatform\Modules\PlatformDb\Contract\ApplicationDatabase;
use CodingPanda\OjPlatform\Modules\Settings\Application\AiUsageRecorder;
use CodingPanda\OjPlatform\Modules\Settings\Contract\AiUsageStore;
use CodingPanda\OjPlatform\Modules\Settings\Infrastructure\Ai\MeteredAiProviderClient;
use CodingPanda\OjPlatform\Modules\Settings\Presentation\Console\AiUsagePageHandler;
use CodingPanda\OjPlatform\Shared\Clock\Clock;
use CodingPanda\OjPlatform\Kernel\Integration\Operations\JobsSystemStatusProvider;
use CodingPanda\OjPlatform\Kernel\Integration\Operations\JudgeSystemStatusProvider;
use CodingPanda\OjPlatform\Kernel\Integration\Operations\LastFatalSystemStatusProvider;
use CodingPanda\OjPlatform\Kernel\Integration\Operations\RuntimeSystemStatusProvider;
use CodingPanda\OjPlatform\Kernel\Integration\Operations\SeparatedAiEndpointPolicy;
use CodingPanda\OjPlatform\Modules\AdminConsole\Application\SystemStatusRegistry;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\SystemStatusReader;
use CodingPanda\OjPlatform\Modules\AdminConsole\Presentation\Action\ClearLastFatalActionHandler;
use CodingPanda\OjPlatform\Modules\AdminConsole\Presentation\Console\OperationsOverviewPageHandler;
use CodingPanda\OjPlatform\Modules\AdminConsole\Presentation\Console\SystemStatusPageHandler;
use CodingPanda\OjPlatform\Modules\JudgeDb\Application\JudgeConnectionCommandService;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeConnectionProbe;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeConnectionSettingsProvider;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeSchemaExtensionInstaller;
use CodingPanda\OjPlatform\Modules\JudgeDb\Presentation\Action\SaveJudgeDatabaseSettingsActionHandler;
use CodingPanda\OjPlatform\Modules\JudgeDb\Presentation\Console\JudgeDatabaseSettingsPageHandler;
use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;
use CodingPanda\OjPlatform\Modules\PublicUi\Presentation\Action\ResetPublicMenuSettingsActionHandler;
use CodingPanda\OjPlatform\Modules\PublicUi\Presentation\Action\SaveHomeSettingsActionHandler;
use CodingPanda\OjPlatform\Modules\PublicUi\Presentation\Action\SavePublicMenuSettingsActionHandler;
use CodingPanda\OjPlatform\Modules\PublicUi\Presentation\Console\HomeSettingsPageHandler;
use CodingPanda\OjPlatform\Modules\PublicUi\Presentation\Console\PublicMenuSettingsPageHandler;
use CodingPanda\OjPlatform\Modules\Settings\Application\AiProviderCandidateService;
use CodingPanda\OjPlatform\Modules\Settings\Application\AiProviderCatalog;
use CodingPanda\OjPlatform\Modules\Settings\Application\AiProviderClientRegistry;
use CodingPanda\OjPlatform\Modules\Settings\Application\AiProviderProbeService;
use CodingPanda\OjPlatform\Modules\Settings\Application\AiProviderSettingsService;
use CodingPanda\OjPlatform\Modules\Settings\Application\AiTaskPromptFactory;
use CodingPanda\OjPlatform\Modules\Settings\Application\AiTaskRoutingService;
use CodingPanda\OjPlatform\Modules\Settings\Application\FeatureService;
use CodingPanda\OjPlatform\Modules\Settings\Application\SiteSettingsService;
use CodingPanda\OjPlatform\Modules\Settings\Application\SmtpSettingsService;
use CodingPanda\OjPlatform\Modules\Settings\Application\StoredAiTaskRouter;
use CodingPanda\OjPlatform\Modules\Settings\Application\SystemSettingsService;
use CodingPanda\OjPlatform\Modules\Settings\Contract\AiEndpointPolicy;
use CodingPanda\OjPlatform\Modules\Settings\Contract\AiHttpTransport;
use CodingPanda\OjPlatform\Modules\Settings\Contract\CandidateProofStore;
use CodingPanda\OjPlatform\Modules\Settings\Contract\HostAddressResolver;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SecretVault;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SiteBrandingProvider;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsManager;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SmtpTransport;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SystemOperationPolicy;
use CodingPanda\OjPlatform\Modules\Settings\Contract\ModuleWriteFreezeManager;
use CodingPanda\OjPlatform\Modules\Settings\Infrastructure\Ai\AiHttpJson;
use CodingPanda\OjPlatform\Modules\Settings\Infrastructure\Ai\GeminiClient;
use CodingPanda\OjPlatform\Modules\Settings\Infrastructure\Ai\OllamaClient;
use CodingPanda\OjPlatform\Modules\Settings\Infrastructure\Ai\OpenAiCompatibleClient;
use CodingPanda\OjPlatform\Modules\Settings\Infrastructure\Network\DnsHostAddressResolver;
use CodingPanda\OjPlatform\Modules\Settings\Infrastructure\WordPress\WordPressAiHttpTransport;
use CodingPanda\OjPlatform\Modules\Settings\Infrastructure\WordPress\WordPressAiProbeRunGuard;
use CodingPanda\OjPlatform\Modules\Settings\Infrastructure\WordPress\WordPressCandidateProofStore;
use CodingPanda\OjPlatform\Modules\Settings\Infrastructure\WordPress\WordPressSmtpConfigurator;
use CodingPanda\OjPlatform\Modules\Settings\Infrastructure\WordPress\WordPressSmtpTransport;
use CodingPanda\OjPlatform\Shared\Clock\SystemClock;
use CodingPanda\OjPlatform\Modules\Settings\Presentation\Action\SaveAiProviderSettingsActionHandler;
use CodingPanda\OjPlatform\Modules\Settings\Presentation\Action\SaveAiTaskRoutingActionHandler;
use CodingPanda\OjPlatform\Modules\Settings\Presentation\Action\SaveFeatureFlagsActionHandler;
use CodingPanda\OjPlatform\Modules\Settings\Presentation\Action\SaveSiteSettingsActionHandler;
use CodingPanda\OjPlatform\Modules\Settings\Presentation\Action\SaveSmtpSettingsActionHandler;
use CodingPanda\OjPlatform\Modules\Settings\Presentation\Action\SaveSystemSettingsActionHandler;
use CodingPanda\OjPlatform\Modules\Settings\Presentation\Action\TestSmtpSettingsActionHandler;
use CodingPanda\OjPlatform\Modules\Settings\Presentation\Console\AiProviderSettingsPageHandler;
use CodingPanda\OjPlatform\Modules\Settings\Presentation\Console\AiTaskRoutingPageHandler;
use CodingPanda\OjPlatform\Modules\Settings\Presentation\Console\FeatureSettingsPageHandler;
use CodingPanda\OjPlatform\Modules\Settings\Presentation\Console\SiteSettingsPageHandler;
use CodingPanda\OjPlatform\Modules\Settings\Presentation\Console\SmtpSettingsPageHandler;
use CodingPanda\OjPlatform\Modules\Settings\Presentation\Console\SystemSettingsPageHandler;
use CodingPanda\OjPlatform\Shared\Ai\AiTaskRouterRegistry;

final class OperationsComposition
{
    public function assemble(
        ErrorReporter $errors,
        SettingsManager $settings,
        FeatureService $features,
        SiteSettingsService $siteSettings,
        SystemSettingsService $systemSettings,
        SecretVault $vault,
        JudgeConnectionSettingsProvider $judgeSettings,
        JudgeConnectionProbe $judgeProbe,
        JudgeSchemaExtensionInstaller $judgeExtensions,
        Authorization $permissions,
        AiTaskRouterRegistry $aiTaskRouters,
        AccountShellBundle $accountShell,
        AddonAiTaskContributions $addonAiTasks,
        ApplicationDatabase $database,
        Clock $clock,
        Clock $siteClock,
        RouteUrlProvider $routeUrls
    ): OperationsBundle {
        $proofs = new WordPressCandidateProofStore($vault);
        $judgeCommands = new JudgeConnectionCommandService(
            $settings,
            $vault,
            $proofs,
            $judgeProbe,
            $judgeSettings,
            $judgeExtensions
        );

        $hostAddresses = new DnsHostAddressResolver();
        $endpointPolicy = new SeparatedAiEndpointPolicy(
            $hostAddresses,
            $judgeSettings
        );
        $aiTransport = new WordPressAiHttpTransport($hostAddresses);
        $aiUsageStore = new PlatformAiUsageStore($database);
        $aiUsage = new AiUsageRecorder($aiUsageStore, $clock, $errors);
        $aiHttp = new AiHttpJson($aiTransport, $aiUsage);
        $aiClients = new AiProviderClientRegistry([
            new MeteredAiProviderClient(new OpenAiCompatibleClient($aiHttp), $aiUsage),
            new MeteredAiProviderClient(new OllamaClient($aiHttp), $aiUsage),
            new MeteredAiProviderClient(new GeminiClient($aiHttp, $aiTransport), $aiUsage),
        ]);
        $aiCatalog = new AiProviderCatalog($addonAiTasks);
        $aiPrompts = new AiTaskPromptFactory($addonAiTasks);
        $aiProfiles = new AiProviderSettingsService($settings, $vault);
        $aiRouting = new AiTaskRoutingService(
            $settings,
            $aiProfiles,
            $aiCatalog
        );
        $aiProbes = new AiProviderProbeService(
            $aiCatalog,
            $aiClients,
            $endpointPolicy,
            $aiPrompts,
            $aiUsage
        );
        $aiCandidates = new AiProviderCandidateService(
            $aiProbes,
            $aiProfiles,
            $proofs,
            $vault
        );
        $aiProbeRuns = new WordPressAiProbeRunGuard(new SystemClock());
        $storedAiRouter = new StoredAiTaskRouter(
            $aiCatalog,
            $aiProfiles,
            $aiRouting,
            $aiClients,
            $endpointPolicy,
            $aiPrompts,
            $aiUsage
        );
        $aiTaskRouters->register($storedAiRouter);

        $smtpTransport = new WordPressSmtpTransport();
        $smtpSettings = new SmtpSettingsService(
            $settings,
            $vault,
            $proofs,
            $smtpTransport
        );
        $smtpConfigurator = new WordPressSmtpConfigurator($smtpSettings);

        $status = new SystemStatusRegistry([
            new RuntimeSystemStatusProvider(),
            new JudgeSystemStatusProvider($judgeSettings, $judgeProbe),
            new AiSystemStatusProvider($aiProfiles, $aiRouting),
            new JobsSystemStatusProvider(),
            new LastFatalSystemStatusProvider($settings),
        ]);

        $pages = $accountShell->consolePages();
        $actionUrls = $accountShell->actionUrls();
        $security = $accountShell->actionSecurity();
        $accounts = $accountShell->currentAccounts();
        $guard = $accountShell->actionGuard();
        $responses = $accountShell->actionResponder();

        $routeHandlers = [
            'console.settings.index' => new OperationsOverviewPageHandler(
                $pages,
                $status,
                $accounts,
                $permissions
            ),
            'console.settings.features' => new FeatureSettingsPageHandler(
                $pages,
                $features,
                $actionUrls,
                $security
            ),
            'console.settings.site' => new SiteSettingsPageHandler(
                $pages,
                $siteSettings,
                $actionUrls,
                $security
            ),
            'console.settings.menu' => new PublicMenuSettingsPageHandler(
                $pages,
                $accountShell->publicMenuSettings(),
                $actionUrls,
                $security
            ),
            'console.settings.home' => new HomeSettingsPageHandler(
                $pages,
                $accountShell->homeSettings(),
                $actionUrls,
                $security
            ),
            'console.settings.database' => new JudgeDatabaseSettingsPageHandler(
                $pages,
                $judgeCommands,
                $accounts,
                $actionUrls,
                $security
            ),
            'console.settings.smtp' => new SmtpSettingsPageHandler(
                $pages,
                $smtpSettings,
                $accounts,
                $actionUrls,
                $security
            ),
            'console.settings.ai_provider' => new AiProviderSettingsPageHandler(
                $pages,
                $aiCatalog,
                $aiProfiles,
                $aiCandidates,
                $accounts,
                $actionUrls,
                $security
            ),
            'console.settings.ai_routing' => new AiTaskRoutingPageHandler(
                $pages,
                $aiCatalog,
                $aiProfiles,
                $aiRouting,
                $actionUrls,
                $security
            ),
            'console.settings.ai_usage' => new AiUsagePageHandler(
                $pages,
                $aiUsageStore,
                $aiCatalog,
                $siteClock,
                $routeUrls,
                $errors
            ),
            'console.settings.system' => new SystemSettingsPageHandler(
                $pages,
                $systemSettings,
                $siteSettings,
                $actionUrls,
                $security,
                $accountShell->loginThrottle(),
                new \CodingPanda\OjPlatform\Modules\Settings\Infrastructure\WordPress\WordPressServerInstallationLink()
            ),
            'console.system_status' => new SystemStatusPageHandler(
                $pages,
                $status,
                $settings,
                $accounts,
                $permissions,
                $actionUrls,
                $security
            ),
        ];
        foreach ($routeHandlers as $key => $handler) {
            $accountShell->routeHandlers()->register($key, $handler);
        }

        $actionHandlers = [
            'oj_save_feature_flags' => new SaveFeatureFlagsActionHandler(
                $features,
                $guard,
                $responses,
                $errors
            ),
            'oj_save_site_settings' => new SaveSiteSettingsActionHandler(
                $siteSettings,
                $guard,
                $responses,
                $errors
            ),
            'oj_save_public_menu_settings' => new SavePublicMenuSettingsActionHandler(
                $accountShell->publicMenuSettings(),
                $guard,
                $responses,
                $errors
            ),
            'oj_reset_public_menu_settings' => new ResetPublicMenuSettingsActionHandler(
                $accountShell->publicMenuSettings(),
                $guard,
                $responses,
                $errors
            ),
            'oj_save_home_settings' => new SaveHomeSettingsActionHandler(
                $accountShell->homeSettings(),
                $guard,
                $responses,
                $errors
            ),
            'oj_save_judge_db_settings' => new SaveJudgeDatabaseSettingsActionHandler(
                $judgeCommands,
                $guard,
                $responses,
                $errors
            ),
            'oj_send_smtp_test_mail' => new TestSmtpSettingsActionHandler(
                $smtpSettings,
                $guard,
                $responses,
                $errors
            ),
            'oj_save_smtp_settings' => new SaveSmtpSettingsActionHandler(
                $smtpSettings,
                $guard,
                $responses,
                $errors
            ),
            'oj_save_ai_provider_settings' => new SaveAiProviderSettingsActionHandler(
                $aiCandidates,
                $guard,
                $responses,
                $errors,
                $aiProbeRuns
            ),
            'oj_save_ai_task_routing' => new SaveAiTaskRoutingActionHandler(
                $aiRouting,
                $guard,
                $responses,
                $errors
            ),
            'oj_save_system_settings' => new SaveSystemSettingsActionHandler(
                $systemSettings,
                $siteSettings,
                $guard,
                $responses,
                $errors,
                $accountShell->loginThrottle()
            ),
            'oj_platform_clear_last_fatal' => new ClearLastFatalActionHandler(
                $settings,
                $guard,
                $responses,
                $errors
            ),
        ];
        foreach ($actionHandlers as $key => $handler) {
            $accountShell->actionHandlers()->register($key, $handler);
        }

        return new OperationsBundle(
            $smtpConfigurator,
            [
                CandidateProofStore::class => $proofs,
                WordPressCandidateProofStore::class => $proofs,
                SiteBrandingProvider::class => $siteSettings,
                SystemOperationPolicy::class => $systemSettings,
                ModuleWriteFreezeManager::class => $systemSettings,
                JudgeConnectionCommandService::class => $judgeCommands,
                HostAddressResolver::class => $hostAddresses,
                DnsHostAddressResolver::class => $hostAddresses,
                AiEndpointPolicy::class => $endpointPolicy,
                SeparatedAiEndpointPolicy::class => $endpointPolicy,
                AiHttpTransport::class => $aiTransport,
                WordPressAiHttpTransport::class => $aiTransport,
                AiHttpJson::class => $aiHttp,
                AiUsageStore::class => $aiUsageStore,
                AiUsageRecorder::class => $aiUsage,
                AiProviderClientRegistry::class => $aiClients,
                AiProviderCatalog::class => $aiCatalog,
                AiTaskPromptFactory::class => $aiPrompts,
                AiProviderSettingsService::class => $aiProfiles,
                AiTaskRoutingService::class => $aiRouting,
                AiProviderProbeService::class => $aiProbes,
                AiProviderCandidateService::class => $aiCandidates,
                StoredAiTaskRouter::class => $storedAiRouter,
                SmtpTransport::class => $smtpTransport,
                WordPressSmtpTransport::class => $smtpTransport,
                SmtpSettingsService::class => $smtpSettings,
                WordPressSmtpConfigurator::class => $smtpConfigurator,
                SystemStatusRegistry::class => $status,
                SystemStatusReader::class => $status,
            ] + $this->classMap($routeHandlers) + $this->classMap($actionHandlers)
        );
    }

    /**
     * @param array<string, object> $services
     * @return array<class-string, object>
     */
    private function classMap(array $services): array
    {
        $indexed = [];
        foreach ($services as $service) {
            $indexed[$service::class] = $service;
        }

        return $indexed;
    }
}
