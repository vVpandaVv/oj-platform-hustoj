<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Composition;

use CodingPanda\OjPlatform\Kernel\Configuration\PhpConfigLoader;
use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Kernel\Environment\PluginEnvironment;
use CodingPanda\OjPlatform\Kernel\Registry\DefinitionRegistry;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeConnectionSettingsProvider;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeConnector;
use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;
use CodingPanda\OjPlatform\Modules\PlatformDb\Contract\SchemaTableDefinition;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsManager;
use CodingPanda\OjPlatform\Modules\Submissions\Application\CodeCompletionSourceMode;
use CodingPanda\OjPlatform\Modules\Submissions\Application\CodeOrderingSourceMode;
use CodingPanda\OjPlatform\Modules\Submissions\Application\FullCodeSourceMode;
use CodingPanda\OjPlatform\Modules\Submissions\Application\PerformanceModePolicyRegistry;
use CodingPanda\OjPlatform\Modules\Submissions\Application\AiFeedbackSubmissionService;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionAiFeedbackPolicyRegistry;
use CodingPanda\OjPlatform\Modules\Submissions\Application\RuntimeErrorParser;
use CodingPanda\OjPlatform\Modules\Submissions\Application\JudgeExecutionParser;
use CodingPanda\OjPlatform\Modules\Submissions\Presentation\JudgeExecutionHtml;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionContextProviderRegistry;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionDetailExtensionRegistry;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionEditorExtensionRegistry;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionFormRenderer;
use CodingPanda\OjPlatform\Modules\Submissions\Integration\Problems\InlineSubmissionPanelProvider;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionLanguageCatalog;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionOperationService;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionProjectionService;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionQueryService;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionSettingsProvider;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionSettingsService;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionSourceComposer;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionSourceModeRegistry;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionSourcePolicyValidator;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionStaffScopeRegistry;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionStoredSourceDecoderRegistry;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionVisibilityPolicy;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmitSubmissionService;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\PerformanceModePolicy;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\AiFeedbackSubmissionProvider;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionCodeEditorAssets;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionContextStore;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionEventStore;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionGateway;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionLanguageSettingsLog;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionManagedScopeProvider;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionProjectionProvider;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionRejudgeControlRenderer;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionSourceViewProvider;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\UserSubmissionProfileProvider;
use CodingPanda\OjPlatform\Modules\Submissions\Infrastructure\Judge\MysqliSubmissionAccountErasureParticipant;
use CodingPanda\OjPlatform\Modules\Submissions\Infrastructure\Judge\MysqliSubmissionGateway;
use CodingPanda\OjPlatform\Modules\Submissions\Infrastructure\Judge\MysqliUserSubmissionProfileProvider;
use CodingPanda\OjPlatform\Modules\Submissions\Infrastructure\WordPress\SubmissionPlatformDatabase;
use CodingPanda\OjPlatform\Modules\Submissions\Infrastructure\WordPress\WordPressAceEditorAssets;
use CodingPanda\OjPlatform\Modules\Submissions\Infrastructure\WordPress\WordPressSubmissionContextStore;
use CodingPanda\OjPlatform\Modules\Submissions\Infrastructure\WordPress\WordPressSubmissionEventStore;
use CodingPanda\OjPlatform\Modules\Submissions\Infrastructure\WordPress\WordPressSubmissionLanguageSettingsLog;
use CodingPanda\OjPlatform\Modules\Submissions\Module;
use CodingPanda\OjPlatform\Modules\Submissions\Presentation\Action\SaveSubmissionDisplaySettingsActionHandler;
use CodingPanda\OjPlatform\Modules\Submissions\Presentation\Action\SaveSubmissionSettingsActionHandler;
use CodingPanda\OjPlatform\Modules\Submissions\Presentation\Action\SubmissionOperationActionHandler;
use CodingPanda\OjPlatform\Modules\Submissions\Presentation\Action\SubmissionPollActionHandler;
use CodingPanda\OjPlatform\Modules\Submissions\Presentation\Action\SubmitSubmissionActionHandler;
use CodingPanda\OjPlatform\Modules\Submissions\Presentation\Console\SubmissionAdminDetailPageHandler;
use CodingPanda\OjPlatform\Modules\Submissions\Presentation\Console\SubmissionAdminListPageHandler;
use CodingPanda\OjPlatform\Modules\Submissions\Presentation\Console\SubmissionEventsPageHandler;
use CodingPanda\OjPlatform\Modules\Submissions\Presentation\Console\SubmissionSettingsPageHandler;
use CodingPanda\OjPlatform\Modules\Submissions\Presentation\Route\SubmissionDetailPageHandler;
use CodingPanda\OjPlatform\Modules\Submissions\Presentation\Route\SubmissionFormPageHandler;
use CodingPanda\OjPlatform\Modules\Submissions\Presentation\Route\SubmissionListPageHandler;
use CodingPanda\OjPlatform\Modules\Submissions\Presentation\SubmissionEventText;
use CodingPanda\OjPlatform\Modules\Submissions\Presentation\SubmissionHtml;
use CodingPanda\OjPlatform\Modules\Submissions\Presentation\SubmissionRequestFactory;
use CodingPanda\OjPlatform\Modules\Submissions\Presentation\SubmissionRejudgeControls;
use CodingPanda\OjPlatform\Shared\Clock\Clock;

final class SubmissionsComposition
{
    /**
     * @param DefinitionRegistry<SchemaTableDefinition> $tableSchemas
     */
    public function assemble(
        PluginEnvironment $environment,
        ErrorReporter $errors,
        PhpConfigLoader $loader,
        DefinitionRegistry $tableSchemas,
        JudgeConnectionSettingsProvider $judgeSettings,
        JudgeConnector $judgeConnector,
        SettingsManager $settings,
        Authorization $permissions,
        Clock $storageClock,
        Clock $siteClock,
        AccountShellBundle $accountShell,
        ProblemsBundle $problems,
        SubmissionManagedScopeProvider $managedScope,
        \CodingPanda\OjPlatform\Modules\Jobs\Contract\FormDraftStore $formDrafts
    ): SubmissionsBundle {
        $platformDatabase = SubmissionPlatformDatabase::fromGlobals();
        $storedContexts = new WordPressSubmissionContextStore($platformDatabase);
        $events = new WordPressSubmissionEventStore($platformDatabase);
        $languageLog = new WordPressSubmissionLanguageSettingsLog(
            $platformDatabase
        );
        $gateway = new MysqliSubmissionGateway(
            $judgeSettings,
            $judgeConnector
        );
        $userProfiles = new MysqliUserSubmissionProfileProvider(
            $judgeSettings,
            $judgeConnector,
            $siteClock
        );
        $accountErasure = new MysqliSubmissionAccountErasureParticipant(
            $judgeSettings,
            $judgeConnector
        );
        $accountShell->accountErasureParticipants()->register($accountErasure);
        $codeExecution = new \CodingPanda\OjPlatform\Modules\Submissions\Infrastructure\Judge\MysqliCodeExecutionEngine($judgeSettings, $judgeConnector,
            static fn():array=>\CodingPanda\OjPlatform\Modules\Submissions\Application\CodeExecutionSupport::limits((array)$settings->read('private_execution',[])->value()));
        $accountShell->accountErasureParticipants()->register(
            new \CodingPanda\OjPlatform\Modules\Submissions\Infrastructure\Judge\CodeExecutionAccountErasureParticipant(
                $codeExecution, static fn (): string => (new \CodingPanda\OjPlatform\Kernel\WordPress\WordPressCodeExecutionIdentityKey())->key(),
                static fn():string=>function_exists('wp_salt')?wp_salt('auth'):''
            )
        );
        $languages = new SubmissionLanguageCatalog();
        $codeEditorAssets = new WordPressAceEditorAssets(
            $environment,
            $languages
        );
        $settingProvider = new SubmissionSettingsProvider($settings);
        $executionSupport=new \CodingPanda\OjPlatform\Modules\Submissions\Application\CodeExecutionSupport($settings,$settingProvider,$codeExecution,
            static fn():string=>(new \CodingPanda\OjPlatform\Kernel\WordPress\WordPressCodeExecutionIdentityKey())->key());
        $composer = new SubmissionSourceComposer();
        $sourcePolicy = new SubmissionSourcePolicyValidator(
            $languages,
            $composer
        );
        $sourceModes = new SubmissionSourceModeRegistry([
            new FullCodeSourceMode(),
            new CodeCompletionSourceMode($languages),
            new CodeOrderingSourceMode($languages),
        ]);
        $editorExtensions = new SubmissionEditorExtensionRegistry();
        $performanceModes = new PerformanceModePolicyRegistry();
        $staffScopes = new SubmissionStaffScopeRegistry();
        $aiFeedbackPolicies = new SubmissionAiFeedbackPolicyRegistry();
        $contexts = new SubmissionContextProviderRegistry();
        $detailExtensions = new SubmissionDetailExtensionRegistry();
        $storedSourceDecoders = new SubmissionStoredSourceDecoderRegistry();
        $visibility = new SubmissionVisibilityPolicy(
            $permissions,
            $performanceModes,
            $staffScopes
        );
        $submit = new SubmitSubmissionService(
            $problems->submissionProblems(),
            $gateway,
            $storedContexts,
            $events,
            $settingProvider,
            $sourceModes,
            $sourcePolicy,
            $contexts,
            $storageClock,
            $errors
        );
        $query = new SubmissionQueryService(
            $gateway,
            $storedContexts,
            $events,
            $visibility,
            $settingProvider,
            $problems->judgeResults(),
            $errors
        );
        $aiFeedbackSubmissions = new AiFeedbackSubmissionService(
            $query,
            $accountShell->users(),
            $aiFeedbackPolicies
        );
        $operations = new SubmissionOperationService(
            $gateway,
            $events,
            $storedContexts,
            $staffScopes,
            $permissions
        );
        $projections = new SubmissionProjectionService(
            $gateway,
            $storedContexts,
            $visibility,
            $problems->judgeResults()
        );
        $settingService = new SubmissionSettingsService(
            $settings,
            $languageLog,
            $languages
        );
        $runtimeErrors = new RuntimeErrorParser();
        $judgeExecutionParser = new JudgeExecutionParser($runtimeErrors);
        $judgeExecutionHtml = new JudgeExecutionHtml($judgeExecutionParser, $problems->judgeResults());
        $html = new SubmissionHtml(
            $languages,
            $query,
            $judgeExecutionHtml
        );
        $eventText = new SubmissionEventText($problems->judgeResults());
        $requestFactory = new SubmissionRequestFactory();
        $rejudgeControls = new SubmissionRejudgeControls(
            $accountShell->actionUrls(),
            $accountShell->actionSecurity()
        );

        $routes = $accountShell->routeHandlers();
        $formRenderer = new SubmissionFormRenderer(
            $settingProvider,
            $languages,
            $codeEditorAssets,
            $editorExtensions,
            $accountShell->actionUrls(),
            $accountShell->actionSecurity(),
            $html
        );
        $drafts = new \CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionDraftService($formDrafts,
            $accountShell->currentAccounts(), $problems->submissionProblems(), $contexts, $settingProvider, $languages, $accountShell->actionGuard());
        $form = new SubmissionFormPageHandler(
            $accountShell->publicPages(),
            $accountShell->currentAccounts(),
            $accountShell->submissionPreferences(),
            $problems->submissionProblems(),
            $formRenderer,
            $contexts,
            $html,
            $drafts
        );
        $routes->register('submissions.form', $form);
        $routes->register('submissions.form.problem', $form);
        $problems->submissionPanel()->set(new InlineSubmissionPanelProvider(
            $accountShell->submissionPreferences(),
            $problems->submissionProblems(),
            $formRenderer,
            $contexts
        ));
        $list = new SubmissionListPageHandler(
            $accountShell->publicPages(),
            $accountShell->currentAccounts(),
            $query,
            $performanceModes,
            $settingProvider,
            $html
        );
        $routes->register('submissions.index', $list);
        $routes->register('submissions.problem', $list);
        $routes->register('submissions.detail', new SubmissionDetailPageHandler(
            $accountShell->publicPages(),
            $accountShell->currentAccounts(),
            $query,
            $settingProvider,
            $storedSourceDecoders,
            $aiFeedbackSubmissions,
            $detailExtensions,
            $accountShell->actionUrls(),
            $accountShell->actionSecurity(),
            $html
        ));
        $routes->register(
            'console.submissions.index',
            $this->adminList(
                $accountShell,
                $permissions,
                $query,
                $settingProvider,
                $languages,
                $problems,
                $html,
                $managedScope,
                '제출 목록',
                'submissions.index',
                true
            )
        );
        $routes->register(
            'console.submissions.deleted',
            $this->adminList(
                $accountShell,
                $permissions,
                $query,
                $settingProvider,
                $languages,
                $problems,
                $html,
                $managedScope,
                '무효 처리된 제출',
                'submissions.deleted',
                false
            )
        );
        $routes->register(
            'console.submissions.detail',
            new SubmissionAdminDetailPageHandler(
                $accountShell->consolePages(),
                $accountShell->currentAccounts(),
                $permissions,
                $query,
                $storedSourceDecoders,
                $accountShell->actionUrls(),
                $accountShell->actionSecurity(),
                $html,
                $detailExtensions
            )
        );
        $routes->register(
            'console.submissions.events',
            new SubmissionEventsPageHandler(
                $accountShell->consolePages(),
                $query,
                $html,
                $eventText
            )
        );
        $routes->register(
            'console.submissions.settings',
            new SubmissionSettingsPageHandler(
                $accountShell->consolePages(),
                $settings,
                $settingProvider,
                $languages,
                $accountShell->actionUrls(),
                $accountShell->actionSecurity(),
                $html,
                $executionSupport
            )
        );

        $actions = $accountShell->actionHandlers();
        $actions->register('oj_private_execution_control',new \CodingPanda\OjPlatform\Modules\Submissions\Presentation\Action\PrivateExecutionControlActionHandler(
            $executionSupport,$accountShell->actionGuard(),$accountShell->actionResponder(),$errors));
        $actions->register(
            'oj_submit_submission',
            new SubmitSubmissionActionHandler(
                $submit,
                $requestFactory,
                $performanceModes,
                $accountShell->actionGuard(),
                $accountShell->actionResponder(),
                $errors
            )
        );
        $actions->register(
            'oj_submission_poll',
            new SubmissionPollActionHandler(
                $query,
                $accountShell->actionGuard(),
                $errors
            )
        );
        foreach ([
            ['oj_submission_rejudge', 'rejudge'],
            ['oj_status_rejudge', 'rejudge'],
            ['oj_submission_delete', 'invalidate'],
            ['oj_status_delete', 'invalidate'],
            ['oj_submission_restore', 'restore'],
        ] as [$action, $operation]) {
            $actions->register(
                $action,
                new SubmissionOperationActionHandler(
                    $action,
                    $operation,
                    $operations,
                    $accountShell->actionGuard(),
                    $accountShell->actionResponder(),
                    $errors
                )
            );
        }
        $actions->register(
            'oj_save_submission_settings',
            new SaveSubmissionSettingsActionHandler(
                $settingService,
                $accountShell->actionGuard(),
                $accountShell->actionResponder(),
                $errors
            )
        );
        $actions->register(
            'oj_save_submission_display_settings',
            new SaveSubmissionDisplaySettingsActionHandler(
                $settingService,
                $accountShell->actionGuard(),
                $accountShell->actionResponder(),
                $errors
            )
        );

        return new SubmissionsBundle(
            new Module($tableSchemas, $loader->load('submissions-schema')),
            [
                SubmissionPlatformDatabase::class => $platformDatabase,
                SubmissionContextStore::class => $storedContexts,
                SubmissionEventStore::class => $events,
                SubmissionLanguageSettingsLog::class => $languageLog,
                SubmissionGateway::class => $gateway,
                \CodingPanda\OjPlatform\Modules\Submissions\Contract\CodeExecutionEngine::class => $codeExecution,
                \CodingPanda\OjPlatform\Modules\Submissions\Application\CodeExecutionSupport::class => $executionSupport,
                UserSubmissionProfileProvider::class => $userProfiles,
                MysqliUserSubmissionProfileProvider::class => $userProfiles,
                MysqliSubmissionAccountErasureParticipant::class => $accountErasure,
                SubmissionLanguageCatalog::class => $languages,
                SubmissionCodeEditorAssets::class => $codeEditorAssets,
                WordPressAceEditorAssets::class => $codeEditorAssets,
                SubmissionSettingsProvider::class => $settingProvider,
                SubmissionSourceComposer::class => $composer,
                SubmissionSourcePolicyValidator::class => $sourcePolicy,
                SubmissionSourceModeRegistry::class => $sourceModes,
                SubmissionEditorExtensionRegistry::class => $editorExtensions,
                PerformanceModePolicyRegistry::class => $performanceModes,
                PerformanceModePolicy::class => $performanceModes,
                SubmissionStaffScopeRegistry::class => $staffScopes,
                SubmissionContextProviderRegistry::class => $contexts,
                SubmissionDetailExtensionRegistry::class => $detailExtensions,
                SubmissionAiFeedbackPolicyRegistry::class => $aiFeedbackPolicies,
                SubmissionStoredSourceDecoderRegistry::class => $storedSourceDecoders,
                SubmissionSourceViewProvider::class => $storedSourceDecoders,
                SubmissionVisibilityPolicy::class => $visibility,
                SubmitSubmissionService::class => $submit,
                SubmissionQueryService::class => $query,
                SubmissionOperationService::class => $operations,
                SubmissionProjectionService::class => $projections,
                SubmissionProjectionProvider::class => $projections,
                AiFeedbackSubmissionProvider::class => $aiFeedbackSubmissions,
                SubmissionSettingsService::class => $settingService,
                RuntimeErrorParser::class => $runtimeErrors,
                JudgeExecutionParser::class => $judgeExecutionParser,
                JudgeExecutionHtml::class => $judgeExecutionHtml,
                SubmissionHtml::class => $html,
                SubmissionRequestFactory::class => $requestFactory,
                \CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionDraftService::class => $drafts,
                SubmissionRejudgeControlRenderer::class => $rejudgeControls,
                SubmissionRejudgeControls::class => $rejudgeControls,
            ]
        );
    }

    private function adminList(
        AccountShellBundle $accountShell,
        Authorization $permissions,
        SubmissionQueryService $query,
        SubmissionSettingsProvider $settings,
        SubmissionLanguageCatalog $languages,
        ProblemsBundle $problems,
        SubmissionHtml $html,
        SubmissionManagedScopeProvider $managedScope,
        string $title,
        string $currentMenu,
        bool $valid
    ): SubmissionAdminListPageHandler {
        return new SubmissionAdminListPageHandler(
            $accountShell->consolePages(),
            $accountShell->currentAccounts(),
            $permissions,
            $query,
            $settings,
            $languages,
            $problems->judgeResults(),
            $accountShell->actionUrls(),
            $accountShell->actionSecurity(),
            $html,
            $managedScope,
            $title,
            $currentMenu,
            $valid
        );
    }
}
