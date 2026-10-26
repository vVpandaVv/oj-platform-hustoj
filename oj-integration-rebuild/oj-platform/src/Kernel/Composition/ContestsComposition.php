<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Composition;

use CodingPanda\OjPlatform\Kernel\Configuration\PhpConfigLoader;
use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Kernel\Integration\Learning\ContestSubmissionAiFeedbackPolicy;
use CodingPanda\OjPlatform\Kernel\Integration\Learning\ContestConsoleMenuAccessRule;
use CodingPanda\OjPlatform\Kernel\Integration\Learning\ContestConsoleRouteAccessRule;
use CodingPanda\OjPlatform\Kernel\Integration\Learning\ContestSubmissionContextProvider;
use CodingPanda\OjPlatform\Kernel\Integration\Learning\ContestSubmissionStaffScope;
use CodingPanda\OjPlatform\Kernel\Registry\DefinitionRegistry;
use CodingPanda\OjPlatform\Modules\Contests\Application\ContestAccountErasureParticipant;
use CodingPanda\OjPlatform\Modules\Contests\Application\ContestJudgeRepository;
use CodingPanda\OjPlatform\Modules\Contests\Application\ContestPlatformRepository;
use CodingPanda\OjPlatform\Modules\Contests\Application\ContestProblemResultCalculator;
use CodingPanda\OjPlatform\Modules\Contests\Application\ContestRankingCalculator;
use CodingPanda\OjPlatform\Modules\Contests\Application\ContestService;
use CodingPanda\OjPlatform\Modules\Contests\Application\ContestStatisticsCalculator;
use CodingPanda\OjPlatform\Modules\Contests\Application\ContestStaffPolicy;
use CodingPanda\OjPlatform\Modules\Contests\Contract\ContestSubmissionAccess;
use CodingPanda\OjPlatform\Modules\Contests\Infrastructure\Judge\MysqliContestJudgeRepository;
use CodingPanda\OjPlatform\Modules\Contests\Infrastructure\WordPress\WordPressContestPlatformRepository;
use CodingPanda\OjPlatform\Modules\Contests\Module;
use CodingPanda\OjPlatform\Modules\Contests\Presentation\Action\ContestIpActionHandler;
use CodingPanda\OjPlatform\Modules\Contests\Presentation\Action\ContestLifecycleActionHandler;
use CodingPanda\OjPlatform\Modules\Contests\Presentation\Action\ContestParticipationActionHandler;
use CodingPanda\OjPlatform\Modules\Contests\Presentation\Action\ExportContestActionHandler;
use CodingPanda\OjPlatform\Modules\Contests\Presentation\Action\SaveContestActionHandler;
use CodingPanda\OjPlatform\Modules\Contests\Presentation\Console\ContestAccessPageHandler as ConsoleContestAccessPageHandler;
use CodingPanda\OjPlatform\Modules\Contests\Presentation\Console\ContestManagementPageHandler;
use CodingPanda\OjPlatform\Modules\Contests\Presentation\Console\ContestReportPageHandler as ConsoleContestReportPageHandler;
use CodingPanda\OjPlatform\Modules\Contests\Presentation\ContestHtml;
use CodingPanda\OjPlatform\Modules\Contests\Presentation\ContestRequestFactory;
use CodingPanda\OjPlatform\Modules\Contests\Presentation\Route\ContestAccessPageHandler;
use CodingPanda\OjPlatform\Modules\Contests\Presentation\Route\ContestCatalogPageHandler;
use CodingPanda\OjPlatform\Modules\Contests\Presentation\Route\ContestReportPageHandler;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeConnectionSettingsProvider;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeConnector;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeResults;
use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;
use CodingPanda\OjPlatform\Modules\PlatformDb\Contract\ApplicationDatabase;
use CodingPanda\OjPlatform\Modules\PlatformDb\Contract\SchemaTableDefinition;
use CodingPanda\OjPlatform\Shared\Clock\Clock;
use CodingPanda\OjPlatform\Shared\Selection\ChoiceProvider;
use CodingPanda\OjPlatform\Ui\Component\PaginationView;

final class ContestsComposition
{
    /**
     * @param DefinitionRegistry<SchemaTableDefinition> $tableSchemas
     */
    public function assemble(
        ErrorReporter $errors,
        PhpConfigLoader $loader,
        DefinitionRegistry $tableSchemas,
        ApplicationDatabase $database,
        JudgeConnectionSettingsProvider $judgeSettings,
        JudgeConnector $judgeConnector,
        JudgeResults $judgeResults,
        Authorization $permissions,
        Clock $clock,
        ProblemsBundle $problems,
        AccountShellBundle $accountShell,
        SubmissionsBundle $submissions,
        ChoiceProvider $groupChoices
    ): ContestsBundle {
        $judge = new MysqliContestJudgeRepository(
            $judgeSettings,
            $judgeConnector
        );
        $platform = new WordPressContestPlatformRepository($database);
        $accountErasure = new ContestAccountErasureParticipant($database);
        $accountShell->accountErasureParticipants()->register($accountErasure);
        $staff = new ContestStaffPolicy($platform, $permissions);
        $ranking = new ContestRankingCalculator(
            new ContestProblemResultCalculator($clock)
        );
        $statistics = new ContestStatisticsCalculator();
        $service = new ContestService(
            $judge,
            $platform,
            $staff,
            $accountShell->users(),
            $problems->submissionProblems(),
            $submissions->projections(),
            $ranking,
            $statistics,
            $clock,
            $groupChoices
        );
        $html = new ContestHtml($judgeResults, $clock);
        $requests = new ContestRequestFactory($accountShell->users());
        $routes = $accountShell->routeHandlers();

        $contestsPagination = new PaginationView();
        $catalog = new ContestCatalogPageHandler(
            $accountShell->publicPages(),
            $accountShell->currentAccounts(),
            $service,
            $accountShell->actionUrls(),
            $accountShell->actionSecurity(),
            $html,
            $contestsPagination
        );
        $routes->register('contests.index', $catalog);
        $routes->register('contests.show', $catalog);
        $report = new ContestReportPageHandler(
            $accountShell->publicPages(),
            $accountShell->currentAccounts(),
            $service,
            $submissions->performanceModePolicy(),
            $html
        );
        foreach ([
            'contests.submissions',
            'contests.ranking',
            'contests.statistics',
        ] as $routeName) {
            $routes->register($routeName, $report);
        }
        $access = new ContestAccessPageHandler(
            $accountShell->publicPages(),
            $accountShell->currentAccounts(),
            $service,
            $accountShell->actionUrls(),
            $accountShell->actionSecurity(),
            $html
        );
        $routes->register('contests.ip_check', $access);
        $routes->register('contests.ip_change', $access);

        $management = new ContestManagementPageHandler(
            $accountShell->consolePages(),
            $accountShell->currentAccounts(),
            $service,
            $accountShell->actionUrls(),
            $accountShell->actionSecurity(),
            $html,
            $groupChoices,
            $clock,
            $contestsPagination
        );
        foreach ([
            'console.contests.index',
            'console.contests.deleted',
            'console.contests.new',
            'console.contests.edit',
            'console.contests.delete',
        ] as $routeName) {
            $routes->register($routeName, $management);
        }
        $consoleReport = new ConsoleContestReportPageHandler(
            $accountShell->consolePages(),
            $accountShell->currentAccounts(),
            $service,
            $accountShell->actionUrls(),
            $accountShell->actionSecurity(),
            $submissions->rejudgeControls(),
            $html
        );
        foreach ([
            'console.contests.submissions',
            'console.contests.ranking',
            'console.contests.statistics',
            'console.contests.deleted_submissions',
        ] as $routeName) {
            $routes->register($routeName, $consoleReport);
        }
        $routes->register(
            'console.contests.ip_check',
            new ConsoleContestAccessPageHandler(
                $accountShell->consolePages(),
                $accountShell->currentAccounts(),
                $service,
                $accountShell->actionUrls(),
                $accountShell->actionSecurity(),
                $html
            )
        );

        $actions = $accountShell->actionHandlers();
        $actions->register('oj_contest_save', new SaveContestActionHandler(
            $service,
            $requests,
            $accountShell->actionGuard(),
            $accountShell->actionResponder(),
            $errors
        ));
        $actions->register(
            'oj_contest_participation_command',
            new ContestParticipationActionHandler(
                $service,
                $accountShell->actionGuard(),
                $accountShell->actionResponder(),
                $errors
            )
        );
        $actions->register(
            'oj_contest_lifecycle_command',
            new ContestLifecycleActionHandler(
                $service,
                $accountShell->actionGuard(),
                $accountShell->actionResponder(),
                $errors
            )
        );
        foreach ([
            ['oj_contest_allowed_ip', false],
            ['oj_contest_admin_allowed_ip', true],
            ['oj_contest_public_admin_allowed_ip', true],
        ] as [$action, $admin]) {
            $actions->register($action, new ContestIpActionHandler(
                $action,
                $admin,
                $service,
                $accountShell->actionGuard(),
                $accountShell->actionResponder(),
                $errors
            ));
        }
        $submissions->aiFeedbackPolicies()->register(
            new ContestSubmissionAiFeedbackPolicy($service)
        );
        $actions->register(
            'oj_contest_export',
            new ExportContestActionHandler(
                $service,
                $accountShell->actionGuard(),
                $accountShell->actionResponder(),
                $errors
            )
        );

        $submissions->contexts()->register(
            new ContestSubmissionContextProvider($service)
        );
        $submissions->staffScopes()->register(
            new ContestSubmissionStaffScope($service)
        );
        $consoleRouteRule = new ContestConsoleRouteAccessRule($service);
        $consoleMenuRule = new ContestConsoleMenuAccessRule($service);
        $accountShell->consoleRouteAccessRules()->register(
            'contests.context',
            $consoleRouteRule
        );
        $accountShell->consoleMenuAccessRules()->register(
            'contests.context',
            $consoleMenuRule
        );
        return new ContestsBundle(
            new Module($tableSchemas, $loader->load('contests-schema')),
            [
                ContestJudgeRepository::class => $judge,
                ContestAccountErasureParticipant::class => $accountErasure,
                ContestPlatformRepository::class => $platform,
                ContestRankingCalculator::class => $ranking,
                ContestStatisticsCalculator::class => $statistics,
                ContestService::class => $service,
                ContestStaffPolicy::class => $staff,
                ContestSubmissionAccess::class => $service,
                ContestHtml::class => $html,
                ContestRequestFactory::class => $requests,
                ContestConsoleRouteAccessRule::class => $consoleRouteRule,
                ContestConsoleMenuAccessRule::class => $consoleMenuRule,
            ]
        );
    }
}
