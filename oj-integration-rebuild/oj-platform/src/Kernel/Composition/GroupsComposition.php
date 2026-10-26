<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Composition;

use CodingPanda\OjPlatform\Kernel\Configuration\PhpConfigLoader;
use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Kernel\Integration\Learning\GroupConsoleMenuAccessRule;
use CodingPanda\OjPlatform\Kernel\Integration\Learning\GroupConsoleRouteAccessRule;
use CodingPanda\OjPlatform\Kernel\Integration\Learning\GroupPerformanceModePolicy;
use CodingPanda\OjPlatform\Kernel\Integration\Learning\GroupSubmissionStaffScope;
use CodingPanda\OjPlatform\Kernel\Registry\DefinitionRegistry;
use CodingPanda\OjPlatform\Modules\Groups\Application\GroupAccountErasureParticipant;
use CodingPanda\OjPlatform\Modules\Groups\Application\GroupBulkMemberImportJobAccessRule;
use CodingPanda\OjPlatform\Modules\Groups\Application\GroupBulkMemberImportJobHandler;
use CodingPanda\OjPlatform\Modules\Groups\Application\GroupBulkMemberImportService;
use CodingPanda\OjPlatform\Modules\Groups\Application\GroupRepository;
use CodingPanda\OjPlatform\Modules\Groups\Application\GroupService;
use CodingPanda\OjPlatform\Modules\Groups\Application\GroupMemberNamesService;
use CodingPanda\OjPlatform\Modules\Groups\Contract\GroupSubmissionAccess;
use CodingPanda\OjPlatform\Modules\Groups\Infrastructure\WordPress\WordPressGroupRepository;
use CodingPanda\OjPlatform\Modules\Groups\Module;
use CodingPanda\OjPlatform\Modules\Groups\Presentation\Action\GroupBulkMembersActionHandler;
use CodingPanda\OjPlatform\Modules\Groups\Presentation\Action\GroupLifecycleActionHandler;
use CodingPanda\OjPlatform\Modules\Groups\Presentation\Action\GroupManagerActionHandler;
use CodingPanda\OjPlatform\Modules\Groups\Presentation\Action\GroupMembershipActionHandler;
use CodingPanda\OjPlatform\Modules\Groups\Presentation\Action\GroupPasswordResetActionHandler;
use CodingPanda\OjPlatform\Modules\Groups\Presentation\Action\SaveGroupActionHandler;
use CodingPanda\OjPlatform\Modules\Groups\Presentation\Action\SaveGroupMemberNamesActionHandler;
use CodingPanda\OjPlatform\Modules\Groups\Presentation\Console\GroupManagementPageHandler;
use CodingPanda\OjPlatform\Modules\Groups\Presentation\Console\GroupMemberPageHandler;
use CodingPanda\OjPlatform\Modules\Groups\Presentation\Console\GroupReportPageHandler;
use CodingPanda\OjPlatform\Modules\Groups\Presentation\GroupHtml;
use CodingPanda\OjPlatform\Modules\Groups\Presentation\GroupRequestFactory;
use CodingPanda\OjPlatform\Modules\Groups\Presentation\Route\GroupPageHandler;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeResults;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobDispatcher;
use CodingPanda\OjPlatform\Modules\Jobs\Registry\JobHandlerRegistry;
use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;
use CodingPanda\OjPlatform\Modules\PlatformDb\Contract\ApplicationDatabase;
use CodingPanda\OjPlatform\Modules\PlatformDb\Contract\SchemaTableDefinition;
use CodingPanda\OjPlatform\Modules\Settings\Contract\FeatureFlags;
use CodingPanda\OjPlatform\Shared\Clock\Clock;

final class GroupsComposition
{
    /**
     * @param DefinitionRegistry<SchemaTableDefinition> $tableSchemas
     */
    public function assemble(
        ErrorReporter $errors,
        PhpConfigLoader $loader,
        DefinitionRegistry $tableSchemas,
        ApplicationDatabase $database,
        JudgeResults $judgeResults,
        FeatureFlags $features,
        Authorization $permissions,
        AccountShellBundle $accountShell,
        SubmissionsBundle $submissions,
        JobDispatcher $jobs,
        JobHandlerRegistry $jobHandlers,
        Clock $siteClock
    ): GroupsBundle {
        $repository = new WordPressGroupRepository($database);
        $accountErasure = new GroupAccountErasureParticipant($database);
        $accountShell->accountErasureParticipants()->register($accountErasure);
        $service = new GroupService(
            $repository,
            $permissions,
            $accountShell->users(),
            $accountShell->learnerAccountProvisioner(),
            $accountShell->authorizedPasswordResetter(),
            $submissions->projections(),
            $siteClock
        );
        $bulkImports = new GroupBulkMemberImportService(
            $service,
            $accountShell->users(),
            $jobs
        );
        $memberNames = new GroupMemberNamesService(
            $service,
            $accountShell->users(),
            $accountShell->managedUserNames(),
            $permissions,
            $errors
        );
        $bulkImportHandler = new GroupBulkMemberImportJobHandler($service);
        $bulkImportAccess = new GroupBulkMemberImportJobAccessRule($service);
        $jobHandlers->register($bulkImportHandler);
        $html = new GroupHtml($judgeResults, features: $features);
        $requests = new GroupRequestFactory();
        $routes = $accountShell->routeHandlers();

        $public = new GroupPageHandler(
            $accountShell->publicPages(),
            $accountShell->currentAccounts(),
            $accountShell->users(),
            $service,
            $submissions->performanceModePolicy(),
            $accountShell->actionUrls(),
            $accountShell->actionSecurity(),
            $html
        );
        foreach ([
            'groups.index',
            'groups.show',
            'groups.members',
            'groups.submissions',
            'groups.ranking',
        ] as $routeName) {
            $routes->register($routeName, $public);
        }

        $management = new GroupManagementPageHandler(
            $accountShell->consolePages(),
            $accountShell->currentAccounts(),
            $service,
            $accountShell->actionUrls(),
            $accountShell->actionSecurity(),
            $html
        );
        foreach ([
            'console.groups.index',
            'console.groups.new',
            'console.groups.edit',
            'console.groups.delete',
            'console.groups.deleted',
        ] as $routeName) {
            $routes->register($routeName, $management);
        }
        $members = new GroupMemberPageHandler(
            $accountShell->consolePages(),
            $accountShell->currentAccounts(),
            $accountShell->users(),
            $service,
            $accountShell->actionUrls(),
            $accountShell->actionSecurity(),
            $html,
            $memberNames
        );
        foreach ([
            'console.groups.members',
            'console.groups.approvals',
            'console.groups.managers',
            'console.groups.bulk_members',
        ] as $routeName) {
            $routes->register($routeName, $members);
        }
        $reports = new GroupReportPageHandler(
            $accountShell->consolePages(),
            $accountShell->currentAccounts(),
            $service,
            $submissions->rejudgeControls(),
            $html
        );
        foreach ([
            'console.groups.submissions',
            'console.groups.ranking',
            'console.groups.deleted_submissions',
        ] as $routeName) {
            $routes->register($routeName, $reports);
        }

        $actions = $accountShell->actionHandlers();
        $actions->register('oj_group_save', new SaveGroupActionHandler(
            $service,
            $requests,
            $accountShell->actionGuard(),
            $accountShell->actionResponder(),
            $errors
        ));
        foreach ([
            ['oj_group_join', 'join', true],
            ['oj_group_cancel_request', 'cancel', true],
            ['oj_group_leave', 'leave', true],
            ['oj_group_add_member', 'add', false],
            ['oj_group_approve_membership', 'approve', false],
            ['oj_group_reject_membership', 'reject', false],
            ['oj_group_remove_membership', 'remove', false],
            ['oj_group_public_approve_membership', 'approve', true],
            ['oj_group_public_reject_membership', 'reject', true],
            ['oj_group_public_remove_membership', 'remove', true],
        ] as [$action, $operation, $publicRedirect]) {
            $actions->register(
                $action,
                new GroupMembershipActionHandler(
                    $action,
                    $operation,
                    $publicRedirect,
                    $service,
                    $accountShell->actionGuard(),
                    $accountShell->actionResponder(),
                    $errors
                )
            );
        }
        foreach ([
            ['oj_group_grant_manager', true],
            ['oj_group_revoke_manager', false],
        ] as [$action, $enabled]) {
            $actions->register(
                $action,
                new GroupManagerActionHandler(
                    $action,
                    $enabled,
                    $service,
                    $accountShell->actionGuard(),
                    $accountShell->actionResponder(),
                    $errors
                )
            );
        }
        foreach ([
            ['oj_group_archive', true],
            ['oj_group_restore', false],
        ] as [$action, $archived]) {
            $actions->register(
                $action,
                new GroupLifecycleActionHandler(
                    $action,
                    $archived,
                    $service,
                    $accountShell->actionGuard(),
                    $accountShell->actionResponder(),
                    $errors
                )
            );
        }
        $actions->register(
            'oj_group_bulk_members',
            new GroupBulkMembersActionHandler(
                $bulkImports,
                $accountShell->actionGuard(),
                $accountShell->actionResponder(),
                $errors
            )
        );
        $actions->register(
            'oj_group_reset_member_password',
            new GroupPasswordResetActionHandler(
                $service,
                $accountShell->actionGuard(),
                $accountShell->consolePages(),
                $errors
            )
        );

        $actions->register(SaveGroupMemberNamesActionHandler::ACTION, new SaveGroupMemberNamesActionHandler(
            $memberNames,
            $accountShell->actionGuard(),
            $errors
        ));

        $access = new GroupPerformanceModePolicy($service, $features);
        $scope = new GroupSubmissionStaffScope($service, $features);
        $submissions->performanceModes()->register('groups', $access);
        $submissions->staffScopes()->register($scope);
        $consoleRouteRule = new GroupConsoleRouteAccessRule($service);
        $consoleMenuRule = new GroupConsoleMenuAccessRule($service);
        $accountShell->consoleRouteAccessRules()->register(
            'groups.context',
            $consoleRouteRule
        );
        $accountShell->consoleMenuAccessRules()->register(
            'groups.context',
            $consoleMenuRule
        );
        return new GroupsBundle(
            new Module($tableSchemas, $loader->load('groups-schema')),
            [
                GroupRepository::class => $repository,
                GroupAccountErasureParticipant::class => $accountErasure,
                GroupBulkMemberImportService::class => $bulkImports,
                GroupBulkMemberImportJobHandler::class => $bulkImportHandler,
                GroupBulkMemberImportJobAccessRule::class => $bulkImportAccess,
                GroupService::class => $service,
                GroupMemberNamesService::class => $memberNames,
                GroupSubmissionAccess::class => $service,
                GroupHtml::class => $html,
                GroupRequestFactory::class => $requests,
                GroupPerformanceModePolicy::class => $access,
                GroupSubmissionStaffScope::class => $scope,
                GroupConsoleRouteAccessRule::class => $consoleRouteRule,
                GroupConsoleMenuAccessRule::class => $consoleMenuRule,
            ]
        );
    }
}
