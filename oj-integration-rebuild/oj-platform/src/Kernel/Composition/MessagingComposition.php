<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Composition;

use CodingPanda\OjPlatform\Kernel\Configuration\PhpConfigLoader;
use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Kernel\Registry\DefinitionRegistry;
use CodingPanda\OjPlatform\Modules\Messaging\Application\MessageReportService;
use CodingPanda\OjPlatform\Modules\Messaging\Application\MessagingScopePolicy;
use CodingPanda\OjPlatform\Modules\Messaging\Application\MessagingService;
use CodingPanda\OjPlatform\Modules\Messaging\Application\MessagingSettingsService;
use CodingPanda\OjPlatform\Modules\Messaging\Contract\MessageReportRepository;
use CodingPanda\OjPlatform\Modules\Messaging\Contract\MessageRepository;
use CodingPanda\OjPlatform\Modules\Messaging\Infrastructure\WordPress\WordPressMessageReportRepository;
use CodingPanda\OjPlatform\Modules\Messaging\Infrastructure\WordPress\WordPressMessageRepository;
use CodingPanda\OjPlatform\Modules\Messaging\Module;
use CodingPanda\OjPlatform\Modules\Messaging\Presentation\Action\MessageModerationActionHandler;
use CodingPanda\OjPlatform\Modules\Messaging\Presentation\Action\MessagingActionHandler;
use CodingPanda\OjPlatform\Modules\Messaging\Presentation\Action\MessagingSettingsActionHandler;
use CodingPanda\OjPlatform\Modules\Messaging\Presentation\Console\MessageReportsPageHandler;
use CodingPanda\OjPlatform\Modules\Messaging\Presentation\Console\MessagingConsolePageHandler;
use CodingPanda\OjPlatform\Modules\Messaging\Presentation\Console\MessagingSettingsPageHandler;
use CodingPanda\OjPlatform\Modules\Messaging\Presentation\Route\MessagesPageHandler;
use CodingPanda\OjPlatform\Modules\PlatformDb\Contract\ApplicationDatabase;
use CodingPanda\OjPlatform\Modules\PlatformDb\Contract\SchemaTableDefinition;
use CodingPanda\OjPlatform\Modules\Settings\Contract\FeatureFlags;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsManager;
use CodingPanda\OjPlatform\Shared\Clock\Clock;
use CodingPanda\OjPlatform\Shared\Selection\GroupMembershipProvider;

final class MessagingComposition
{
    /**
     * @param DefinitionRegistry<SchemaTableDefinition> $tableSchemas
     */
    public function assemble(
        ErrorReporter $errors,
        PhpConfigLoader $loader,
        DefinitionRegistry $tableSchemas,
        ApplicationDatabase $database,
        SettingsManager $settingsManager,
        FeatureFlags $features,
        GroupMembershipProvider $groupMembership,
        Clock $siteClock,
        AccountShellBundle $accountShell
    ): MessagingBundle {
        $messages = new WordPressMessageRepository($database);
        $reportsRepository = new WordPressMessageReportRepository($database);
        $settings = new MessagingSettingsService($settingsManager, $features);
        $scopePolicy = new MessagingScopePolicy($settings, $groupMembership);
        $messaging = new MessagingService(
            $messages,
            $accountShell->profiles(),
            $scopePolicy,
            $settings,
            $siteClock
        );
        $reports = new MessageReportService($reportsRepository, $messages);
        $accountShell->publicShell()->attachMessageNotifications(
            new class ($messaging, $features) implements \CodingPanda\OjPlatform\Modules\PublicUi\Contract\MessageNotificationProvider {
                public function __construct(private readonly MessagingService $messaging, private readonly FeatureFlags $features) {}
                public function isEnabled(): bool { return $this->features->isEnabled('messaging'); }
                public function unreadCount(int $userId): int { return $this->messaging->unreadCount($userId); }
            }
        );

        $publicPages = new MessagesPageHandler(
            $accountShell->publicPages(),
            $accountShell->currentAccounts(),
            $messaging,
            $accountShell->users(),
            $accountShell->actionUrls(),
            $accountShell->actionSecurity(),
            search: new \CodingPanda\OjPlatform\Modules\Auth\Infrastructure\WordPress\WordPressPublicUserSearch()
        );
        $consolePages = new MessagingConsolePageHandler(
            $accountShell->consolePages(),
            $messaging,
            $accountShell->users(),
            $accountShell->actionUrls(),
            $accountShell->actionSecurity()
        );
        $reportsPage = new MessageReportsPageHandler(
            $accountShell->consolePages(),
            $reports,
            $messaging,
            $accountShell->users(),
            $accountShell->actionUrls(),
            $accountShell->actionSecurity()
        );
        $settingsPage = new MessagingSettingsPageHandler(
            $accountShell->consolePages(),
            $settings,
            $features,
            $accountShell->actionUrls(),
            $accountShell->actionSecurity()
        );
        $this->registerRoutes(
            $accountShell,
            $publicPages,
            $consolePages,
            $reportsPage,
            $settingsPage
        );

        $messagingActions = new MessagingActionHandler(
            $messaging,
            $reports,
            $settings,
            $accountShell->actionGuard(),
            $accountShell->actionResponder(),
            $errors,
            new \CodingPanda\OjPlatform\Modules\Auth\Infrastructure\WordPress\WordPressAttemptLimiter($siteClock)
        );
        $moderationActions = new MessageModerationActionHandler(
            $messaging,
            $reports,
            $accountShell->actionGuard(),
            $accountShell->actionResponder(),
            $errors
        );
        $settingsAction = new MessagingSettingsActionHandler(
            $settings,
            $accountShell->actionGuard(),
            $accountShell->actionResponder(),
            $errors
        );
        $this->registerActions(
            $accountShell,
            $messagingActions,
            $moderationActions,
            $settingsAction
        );

        return new MessagingBundle(
            new Module($tableSchemas, $loader->load('messaging-schema')),
            [
                MessageRepository::class => $messages,
                MessageReportRepository::class => $reportsRepository,
                MessagingSettingsService::class => $settings,
                MessagingScopePolicy::class => $scopePolicy,
                MessagingService::class => $messaging,
                MessageReportService::class => $reports,
                MessagesPageHandler::class => $publicPages,
                MessagingConsolePageHandler::class => $consolePages,
                MessageReportsPageHandler::class => $reportsPage,
                MessagingSettingsPageHandler::class => $settingsPage,
                MessagingActionHandler::class => $messagingActions,
                MessageModerationActionHandler::class => $moderationActions,
                MessagingSettingsActionHandler::class => $settingsAction,
            ]
        );
    }

    private function registerRoutes(
        AccountShellBundle $accountShell,
        MessagesPageHandler $publicPages,
        MessagingConsolePageHandler $consolePages,
        MessageReportsPageHandler $reportsPage,
        MessagingSettingsPageHandler $settingsPage
    ): void {
        foreach ([
            'messaging.inbox',
            'messaging.sent',
            'messaging.thread',
            'messaging.compose',
            'messaging.find',
        ] as $key) {
            $accountShell->routeHandlers()->register($key, $publicPages);
        }
        foreach ([
            'console.messaging.index',
            'console.messaging.thread',
        ] as $key) {
            $accountShell->routeHandlers()->register($key, $consolePages);
        }
        $accountShell->routeHandlers()->register('console.messaging.reports', $reportsPage);
        $accountShell->routeHandlers()->register('console.messaging.settings', $settingsPage);
    }

    private function registerActions(
        AccountShellBundle $accountShell,
        MessagingActionHandler $messagingActions,
        MessageModerationActionHandler $moderationActions,
        MessagingSettingsActionHandler $settingsAction
    ): void {
        foreach ([
            'oj_message_send',
            'oj_message_delete',
            'oj_message_report',
        ] as $key) {
            $accountShell->actionHandlers()->register($key, $messagingActions);
        }
        foreach ([
            'oj_message_admin_hide',
            'oj_message_report_resolve',
        ] as $key) {
            $accountShell->actionHandlers()->register($key, $moderationActions);
        }
        $accountShell->actionHandlers()->register('oj_save_messaging_settings', $settingsAction);
    }
}
