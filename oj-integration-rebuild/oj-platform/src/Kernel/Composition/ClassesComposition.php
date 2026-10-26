<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Composition;

use CodingPanda\OjPlatform\Kernel\Configuration\PhpConfigLoader;
use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Kernel\Integration\Learning\ClassConsoleMenuAccessRule;
use CodingPanda\OjPlatform\Kernel\Integration\Learning\ClassConsoleRouteAccessRule;
use CodingPanda\OjPlatform\Kernel\Integration\Learning\ClassProblemContextAccessProvider;
use CodingPanda\OjPlatform\Kernel\Integration\Learning\ClassSubmissionContextProvider;
use CodingPanda\OjPlatform\Kernel\Integration\Learning\ClassSubmissionStaffScope;
use CodingPanda\OjPlatform\Kernel\Registry\DefinitionRegistry;
use CodingPanda\OjPlatform\Modules\Classes\Application\ClassAccountErasureParticipant;
use CodingPanda\OjPlatform\Modules\Classes\Application\ClassRepository;
use CodingPanda\OjPlatform\Modules\Classes\Application\ClassService;
use CodingPanda\OjPlatform\Modules\Classes\Application\ClassStaffPolicy;
use CodingPanda\OjPlatform\Modules\Classes\Contract\ClassSubmissionAccess;
use CodingPanda\OjPlatform\Modules\Classes\Infrastructure\WordPress\WordPressClassRepository;
use CodingPanda\OjPlatform\Modules\Classes\Module;
use CodingPanda\OjPlatform\Modules\Classes\Presentation\Action\ClassLifecycleActionHandler;
use CodingPanda\OjPlatform\Modules\Classes\Presentation\Action\ClassMembershipActionHandler;
use CodingPanda\OjPlatform\Modules\Classes\Presentation\Action\ExportClassProgressActionHandler;
use CodingPanda\OjPlatform\Modules\Classes\Presentation\Action\SaveClassActionHandler;
use CodingPanda\OjPlatform\Modules\Classes\Presentation\ClassHtml;
use CodingPanda\OjPlatform\Modules\Classes\Presentation\ClassRequestFactory;
use CodingPanda\OjPlatform\Modules\Classes\Presentation\Console\ClassManagementPageHandler;
use CodingPanda\OjPlatform\Modules\Classes\Presentation\Console\ClassMemberPageHandler;
use CodingPanda\OjPlatform\Modules\Classes\Presentation\Console\ClassReportPageHandler as ConsoleClassReportPageHandler;
use CodingPanda\OjPlatform\Modules\Classes\Presentation\Route\ClassCatalogPageHandler;
use CodingPanda\OjPlatform\Modules\Classes\Presentation\Route\ClassReportPageHandler;
use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;
use CodingPanda\OjPlatform\Modules\PlatformDb\Contract\ApplicationDatabase;
use CodingPanda\OjPlatform\Modules\PlatformDb\Contract\SchemaTableDefinition;
use CodingPanda\OjPlatform\Shared\Selection\ChoiceProvider;
use CodingPanda\OjPlatform\Shared\Clock\Clock;
use CodingPanda\OjPlatform\Ui\Component\PaginationView;
use CodingPanda\OjPlatform\Ui\Icon\IconRenderer;

final class ClassesComposition
{
    /**
     * @param DefinitionRegistry<SchemaTableDefinition> $tableSchemas
     */
    public function assemble(
        ErrorReporter $errors,
        PhpConfigLoader $loader,
        DefinitionRegistry $tableSchemas,
        ApplicationDatabase $database,
        Authorization $permissions,
        AccountShellBundle $accountShell,
        ProblemsBundle $problems,
        SubmissionsBundle $submissions,
        ChoiceProvider $groupChoices,
        Clock $storageClock,
        Clock $siteClock,
        \CodingPanda\OjPlatform\Modules\Settings\Contract\FeatureFlags $features
    ): ClassesBundle {
        $repository = new WordPressClassRepository($database, $storageClock);
        $accountErasure = new ClassAccountErasureParticipant($database);
        $accountShell->accountErasureParticipants()->register($accountErasure);
        $staff = new ClassStaffPolicy($repository, $permissions);
        $service = new ClassService(
            $repository,
            $staff,
            $accountShell->users(),
            $problems->submissionProblems(),
            $submissions->projections(),
            $groupChoices,
            $siteClock,
            new \CodingPanda\OjPlatform\Modules\Classes\Infrastructure\WordPress\WordPressClassProblemChangePublisher($errors)
        );
        $problems->contextAccess()->register(
            new ClassProblemContextAccessProvider($service)
        );
        $html = new ClassHtml(
            $problems->judgeResults(),
            icons: new IconRenderer($accountShell->uiAssets()),
            features: $features
        );
        $requests = new ClassRequestFactory($accountShell->users());

        $classesPagination = new PaginationView();
        $catalog = new ClassCatalogPageHandler(
            $accountShell->publicPages(),
            $accountShell->currentAccounts(),
            $service,
            $accountShell->actionUrls(),
            $accountShell->actionSecurity(),
            $html,
            $classesPagination
        );
        $routes = $accountShell->routeHandlers();
        $routes->register('classes.index', $catalog);
        $routes->register('classes.show', $catalog);
        $report = new ClassReportPageHandler(
            $accountShell->publicPages(),
            $accountShell->currentAccounts(),
            $service,
            $submissions->performanceModePolicy(),
            $html
        );
        $routes->register('classes.submissions', $report);
        $routes->register('classes.progress', $report);

        $management = new ClassManagementPageHandler(
            $accountShell->consolePages(),
            $accountShell->currentAccounts(),
            $service,
            $accountShell->actionUrls(),
            $accountShell->actionSecurity(),
            $html,
            $groupChoices,
            $classesPagination
        );
        foreach ([
            'console.classes.index',
            'console.classes.new',
            'console.classes.edit',
            'console.classes.delete',
            'console.classes.deleted',
        ] as $routeName) {
            $routes->register($routeName, $management);
        }
        $routes->register(
            'console.classes.participants',
            new ClassMemberPageHandler(
                $accountShell->consolePages(),
                $accountShell->currentAccounts(),
                $accountShell->users(),
                $service,
                $accountShell->actionUrls(),
                $accountShell->actionSecurity(),
                $html
            )
        );
        $consoleReport = new ConsoleClassReportPageHandler(
            $accountShell->consolePages(),
            $accountShell->currentAccounts(),
            $service,
            $accountShell->actionUrls(),
            $accountShell->actionSecurity(),
            $submissions->rejudgeControls(),
            $html
        );
        foreach ([
            'console.classes.submissions',
            'console.classes.progress',
            'console.classes.deleted_submissions',
            'console.classes.deleted_progress',
        ] as $routeName) {
            $routes->register($routeName, $consoleReport);
        }

        $actions = $accountShell->actionHandlers();
        $actions->register('oj_class_save', new SaveClassActionHandler(
            $service,
            $requests,
            $accountShell->actionGuard(),
            $accountShell->actionResponder(),
            $errors
        ));
        $actions->register(
            'oj_class_membership_command',
            new ClassMembershipActionHandler(
                $service,
                $accountShell->actionGuard(),
                $accountShell->actionResponder(),
                $errors
            )
        );
        $actions->register(
            'oj_class_lifecycle_command',
            new ClassLifecycleActionHandler(
                $service,
                $accountShell->actionGuard(),
                $accountShell->actionResponder(),
                $errors
            )
        );
        $actions->register(
            'oj_class_export_progress',
            new ExportClassProgressActionHandler(
                $service,
                $accountShell->actionGuard(),
                $accountShell->actionResponder(),
                $errors
            )
        );

        $submissions->contexts()->register(
            new ClassSubmissionContextProvider($service)
        );
        $submissions->staffScopes()->register(
            new ClassSubmissionStaffScope($service)
        );
        $consoleRouteRule = new ClassConsoleRouteAccessRule($service);
        $consoleMenuRule = new ClassConsoleMenuAccessRule($service);
        $accountShell->consoleRouteAccessRules()->register(
            'classes.context',
            $consoleRouteRule
        );
        $accountShell->consoleMenuAccessRules()->register(
            'classes.context',
            $consoleMenuRule
        );
        return new ClassesBundle(
            new Module($tableSchemas, $loader->load('classes-schema')),
            [
                ClassRepository::class => $repository,
                ClassAccountErasureParticipant::class => $accountErasure,
                ClassService::class => $service,
                ClassStaffPolicy::class => $staff,
                ClassSubmissionAccess::class => $service,
                ClassHtml::class => $html,
                ClassRequestFactory::class => $requests,
                ClassConsoleRouteAccessRule::class => $consoleRouteRule,
                ClassConsoleMenuAccessRule::class => $consoleMenuRule,
            ]
        );
    }
}
