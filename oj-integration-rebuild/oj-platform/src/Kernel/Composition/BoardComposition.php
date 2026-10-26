<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Composition;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Kernel\Integration\Community\ClassCommunityContextProvider;
use CodingPanda\OjPlatform\Kernel\Integration\Community\ContestCommunityContextProvider;
use CodingPanda\OjPlatform\Kernel\Integration\Community\GroupCommunityContextProvider;
use CodingPanda\OjPlatform\Kernel\Integration\Community\ProblemCommunityContextProvider;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteUrlProvider;
use CodingPanda\OjPlatform\Modules\Board\Application\AttachmentService;
use CodingPanda\OjPlatform\Modules\Board\Application\BoardSettingsService;
use CodingPanda\OjPlatform\Modules\Board\Application\CommentService;
use CodingPanda\OjPlatform\Modules\Board\Application\CommunityAccessPolicy;
use CodingPanda\OjPlatform\Modules\Board\Application\CommunityContextRegistry;
use CodingPanda\OjPlatform\Modules\Board\Application\CommunityFeaturePolicy;
use CodingPanda\OjPlatform\Modules\Board\Application\CommunityQueryService;
use CodingPanda\OjPlatform\Modules\Board\Application\ModerationService;
use CodingPanda\OjPlatform\Modules\Board\Application\NoticeService;
use CodingPanda\OjPlatform\Modules\Board\Application\ThreadService;
use CodingPanda\OjPlatform\Modules\Board\Contract\CommunityContentSanitizer;
use CodingPanda\OjPlatform\Modules\Board\Contract\CommunityRepository;
use CodingPanda\OjPlatform\Modules\Board\Contract\ProtectedAttachmentRootProvider;
use CodingPanda\OjPlatform\Modules\Board\Contract\ProtectedAttachmentStorage;
use CodingPanda\OjPlatform\Modules\Board\Infrastructure\Storage\WordPressProtectedAttachmentRootProvider;
use CodingPanda\OjPlatform\Modules\Board\Infrastructure\Storage\WordPressProtectedAttachmentStorage;
use CodingPanda\OjPlatform\Modules\Board\Infrastructure\WordPress\WordPressCommunityContentSanitizer;
use CodingPanda\OjPlatform\Modules\Board\Infrastructure\WordPress\WordPressCommunityPostTypeRegistrar;
use CodingPanda\OjPlatform\Modules\Board\Infrastructure\WordPress\WordPressCommunityRepository;
use CodingPanda\OjPlatform\Modules\Board\Integration\Problems\ProblemCommunitySectionProvider;
use CodingPanda\OjPlatform\Modules\Board\Integration\PublicUi\BoardHomeNoticeProvider;
use CodingPanda\OjPlatform\Modules\Board\Module;
use CodingPanda\OjPlatform\Modules\Board\Presentation\Action\BoardActionHandler;
use CodingPanda\OjPlatform\Modules\Board\Presentation\Action\CommunitySettingsActionHandler;
use CodingPanda\OjPlatform\Modules\Board\Presentation\Action\NoticeActionHandler;
use CodingPanda\OjPlatform\Modules\Board\Presentation\CommunityHtml;
use CodingPanda\OjPlatform\Modules\Board\Presentation\Console\BoardConsolePageHandler;
use CodingPanda\OjPlatform\Modules\Board\Presentation\Console\CommunitySettingsPageHandler;
use CodingPanda\OjPlatform\Modules\Board\Presentation\Console\NoticeConsolePageHandler;
use CodingPanda\OjPlatform\Modules\Board\Presentation\Response\AttachmentDownloadHandler;
use CodingPanda\OjPlatform\Modules\Board\Presentation\Route\BoardPageHandler;
use CodingPanda\OjPlatform\Modules\Board\Presentation\Route\NoticePageHandler;
use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;
use CodingPanda\OjPlatform\Modules\Settings\Contract\FeatureFlags;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsManager;
use CodingPanda\OjPlatform\Shared\Clock\Clock;

final class BoardComposition
{
    public function assemble(
        ErrorReporter $errors,
        SettingsManager $settingsManager,
        FeatureFlags $features,
        Authorization $permissions,
        Clock $clock,
        RouteUrlProvider $urls,
        AccountShellBundle $accountShell,
        ProblemsBundle $problems,
        ClassesBundle $classes,
        ContestsBundle $contests,
        GroupsBundle $groups
    ): BoardBundle {
        $repository = new WordPressCommunityRepository();
        $sanitizer = new WordPressCommunityContentSanitizer();
        $settings = new BoardSettingsService($settingsManager);
        $communityFeatures = new CommunityFeaturePolicy($features);
        $contexts = new CommunityContextRegistry(
            $settings,
            $features,
            $permissions
        );
        $contexts->register(new ProblemCommunityContextProvider(
            $problems->learningProblems(),
            $contests->service()
        ));
        $contexts->register(new ClassCommunityContextProvider($classes->service()));
        $contexts->register(new ContestCommunityContextProvider($contests->service()));
        $contexts->register(new GroupCommunityContextProvider($groups->service()));
        $access = new CommunityAccessPolicy(
            $contexts,
            $settings,
            $permissions,
            $clock
        );
        $attachmentRoot = new WordPressProtectedAttachmentRootProvider();
        $attachmentStorage = new WordPressProtectedAttachmentStorage($attachmentRoot);
        $attachments = new AttachmentService(
            $repository,
            $attachmentStorage,
            $settings,
            $access
        );
        $queries = new CommunityQueryService(
            $repository,
            $attachmentStorage,
            $contexts,
            $access,
            $accountShell->users()
        );
        $threads = new ThreadService(
            $repository,
            $sanitizer,
            $contexts,
            $access,
            $settings,
            $attachments
        );
        $notices = new NoticeService(
            $repository,
            $sanitizer,
            $access,
            $settings,
            $attachments,
            $clock
        );
        $comments = new CommentService(
            $repository,
            $sanitizer,
            $access,
            $attachments
        );
        $moderation = new ModerationService($repository, $access);
        $html = new CommunityHtml(
            $accountShell->actionUrls(),
            $accountShell->actionSecurity(),
            $settings
        );

        $boardPages = new BoardPageHandler(
            $accountShell->publicPages(),
            $accountShell->currentAccounts(),
            $queries,
            $contexts,
            $attachments,
            $communityFeatures,
            $html
        );
        $noticePages = new NoticePageHandler(
            $accountShell->publicPages(),
            $accountShell->currentAccounts(),
            $queries,
            $access,
            $communityFeatures,
            $html
        );
        $boardConsole = new BoardConsolePageHandler(
            $accountShell->consolePages(),
            $accountShell->currentAccounts(),
            $queries,
            $contexts,
            $attachments,
            $html
        );
        $noticeConsole = new NoticeConsolePageHandler(
            $accountShell->consolePages(),
            $accountShell->currentAccounts(),
            $queries,
            $access,
            $attachments,
            $html
        );
        $settingsPage = new CommunitySettingsPageHandler(
            $accountShell->consolePages(),
            $settings,
            $features,
            $accountShell->actionUrls(),
            $accountShell->actionSecurity()
        );
        $download = new AttachmentDownloadHandler(
            $accountShell->currentAccounts(),
            $attachments
        );
        $this->registerRoutes(
            $accountShell,
            $boardPages,
            $noticePages,
            $boardConsole,
            $noticeConsole,
            $settingsPage,
            $download
        );

        $boardActions = new BoardActionHandler(
            $threads,
            $comments,
            $moderation,
            $communityFeatures,
            $settings,
            $accountShell->actionGuard(),
            $accountShell->actionResponder(),
            $errors
        );
        $noticeActions = new NoticeActionHandler(
            $notices,
            $comments,
            $moderation,
            $communityFeatures,
            $settings,
            $accountShell->actionGuard(),
            $accountShell->actionResponder(),
            $errors
        );
        $settingsAction = new CommunitySettingsActionHandler(
            $settings,
            $communityFeatures,
            $accountShell->actionGuard(),
            $accountShell->actionResponder(),
            $errors
        );
        $this->registerActions(
            $accountShell,
            $boardActions,
            $noticeActions,
            $settingsAction
        );

        $postTypes = new WordPressCommunityPostTypeRegistrar();
        $accountShell->homeNotices()->register(
            'board',
            new BoardHomeNoticeProvider($queries, $features, $urls)
        );
        $problemSection = new ProblemCommunitySectionProvider(
            $contexts,
            $queries,
            $features,
            $urls
        );
        $problems->detailSections()->register($problemSection);

        return new BoardBundle(new Module(), [
            CommunityRepository::class => $repository,
            CommunityContentSanitizer::class => $sanitizer,
            BoardSettingsService::class => $settings,
            CommunityFeaturePolicy::class => $communityFeatures,
            CommunityContextRegistry::class => $contexts,
            CommunityAccessPolicy::class => $access,
            ProtectedAttachmentRootProvider::class => $attachmentRoot,
            ProtectedAttachmentStorage::class => $attachmentStorage,
            AttachmentService::class => $attachments,
            CommunityQueryService::class => $queries,
            ThreadService::class => $threads,
            NoticeService::class => $notices,
            CommentService::class => $comments,
            ModerationService::class => $moderation,
            CommunityHtml::class => $html,
            BoardPageHandler::class => $boardPages,
            NoticePageHandler::class => $noticePages,
            BoardConsolePageHandler::class => $boardConsole,
            NoticeConsolePageHandler::class => $noticeConsole,
            CommunitySettingsPageHandler::class => $settingsPage,
            AttachmentDownloadHandler::class => $download,
            BoardActionHandler::class => $boardActions,
            NoticeActionHandler::class => $noticeActions,
            CommunitySettingsActionHandler::class => $settingsAction,
            WordPressCommunityPostTypeRegistrar::class => $postTypes,
            ProblemCommunitySectionProvider::class => $problemSection,
        ]);
    }

    private function registerRoutes(
        AccountShellBundle $accountShell,
        BoardPageHandler $board,
        NoticePageHandler $notices,
        BoardConsolePageHandler $boardConsole,
        NoticeConsolePageHandler $noticeConsole,
        CommunitySettingsPageHandler $settings,
        AttachmentDownloadHandler $download
    ): void {
        foreach (['board.index', 'board.new', 'board.thread', 'board.edit'] as $key) {
            $accountShell->routeHandlers()->register($key, $board);
        }
        foreach (['notices.index', 'notices.show', 'notices.new'] as $key) {
            $accountShell->routeHandlers()->register($key, $notices);
        }
        foreach ([
            'console.board.index',
            'console.board.new',
            'console.board.thread',
            'console.board.edit',
            'console.board.comments',
            'console.board.trash_thread',
            'console.board.trash',
        ] as $key) {
            $accountShell->routeHandlers()->register($key, $boardConsole);
        }
        foreach ([
            'console.notices.index',
            'console.notices.new',
            'console.notices.edit',
            'console.notices.comments',
            'console.notices.trash_notice',
            'console.notices.trash',
        ] as $key) {
            $accountShell->routeHandlers()->register($key, $noticeConsole);
        }
        $accountShell->routeHandlers()->register(
            'console.community.settings',
            $settings
        );
        $accountShell->routeHandlers()->register(
            'community.attachments.download',
            $download
        );
    }

    private function registerActions(
        AccountShellBundle $accountShell,
        BoardActionHandler $board,
        NoticeActionHandler $notices,
        CommunitySettingsActionHandler $settings
    ): void {
        foreach ([
            'oj_board_add_comment',
            'oj_board_add_thread',
            'oj_board_create_thread',
            'oj_board_delete_comment',
            'oj_board_restore_thread',
            'oj_board_toggle_comment_hidden',
            'oj_board_toggle_thread_hidden',
            'oj_board_toggle_thread_pinned',
            'oj_board_trash_thread',
            'oj_board_update_thread',
            'oj_board_update_thread_public',
        ] as $key) {
            $accountShell->actionHandlers()->register($key, $board);
        }
        foreach ([
            'oj_notice_add_comment',
            'oj_notice_create',
            'oj_notice_create_console',
            'oj_notice_delete_comment',
            'oj_notice_restore',
            'oj_notice_trash',
            'oj_notice_update',
        ] as $key) {
            $accountShell->actionHandlers()->register($key, $notices);
        }
        $accountShell->actionHandlers()->register(
            'oj_save_board_settings',
            $settings
        );
    }
}
