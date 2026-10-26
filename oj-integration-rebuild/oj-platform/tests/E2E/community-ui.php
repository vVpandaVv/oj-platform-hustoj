<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\Kernel\Autoloader;
use CodingPanda\OjPlatform\Kernel\Boot\Kernel;
use CodingPanda\OjPlatform\Kernel\Composition\CompositionRoot;
use CodingPanda\OjPlatform\Kernel\Composition\FoundationServiceIds;
use CodingPanda\OjPlatform\Kernel\Environment\PluginEnvironment;
use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Kernel\Hook\HookRegistrar;
use CodingPanda\OjPlatform\Kernel\Registry\DefinitionRegistry;
use CodingPanda\OjPlatform\Modules\AccessRouter\Application\ActionHandlerRegistry;
use CodingPanda\OjPlatform\Modules\AccessRouter\Application\RouteHandlerRegistry;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionDefinition;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteDefinition;

$pluginRoot = dirname(__DIR__, 2);
require_once $pluginRoot . '/src/Kernel/Autoloader.php';
Autoloader::register($pluginRoot);

$communityUiAssertions = 0;

function communityUiAssert(bool $condition, string $message): void
{
    global $communityUiAssertions;
    ++$communityUiAssertions;
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function communityUiContains(
    string $needle,
    string $contents,
    string $message
): void {
    communityUiAssert(str_contains($contents, $needle), $message);
}

final class CommunityUiHooks implements HookRegistrar
{
    public function addAction(
        string $hook,
        callable $callback,
        int $priority = 10,
        int $acceptedArguments = 0
    ): void {
        unset($hook, $callback, $priority, $acceptedArguments);
    }
}

final class CommunityUiErrors implements ErrorReporter
{
    public function report(Throwable $error): void
    {
        unset($error);
    }
}

$environment = PluginEnvironment::fromPluginFile(
    $pluginRoot . '/oj-platform.php',
    '2.0.0-dev.102',
    '6.6',
    '8.1'
);
$container = (new CompositionRoot(
    $environment,
    new CommunityUiHooks(),
    new CommunityUiErrors()
))->build();
$container->get(Kernel::class)->boot();

$expectedPaths = [
    'board.index' => '/board',
    'board.new' => '/board/new',
    'board.thread' => '/board/thread/{board_thread_id}',
    'board.edit' => '/board/thread/{board_thread_id}/edit',
    'notices.index' => '/notices',
    'notices.show' => '/notices/{notice_id}',
    'notices.new' => '/notices/new',
    'console.board.index' => '/oj-console/board',
    'console.board.new' => '/oj-console/board/new',
    'console.board.thread' => '/oj-console/board/thread/{board_thread_id}',
    'console.board.edit' => '/oj-console/board/thread/{board_thread_id}/edit',
    'console.board.comments' => '/oj-console/board/thread/{board_thread_id}/comments',
    'console.board.trash_thread' => '/oj-console/board/thread/{board_thread_id}/trash',
    'console.board.trash' => '/oj-console/board/trash',
    'console.notices.index' => '/oj-console/notices',
    'console.notices.new' => '/oj-console/notices/new',
    'console.notices.edit' => '/oj-console/notices/{notice_id}/edit',
    'console.notices.comments' => '/oj-console/notices/{notice_id}/comments',
    'console.notices.trash_notice' => '/oj-console/notices/{notice_id}/trash',
    'console.notices.trash' => '/oj-console/notices/trash',
    'console.community.settings' => '/oj-console/community/settings',
    'community.attachments.download' =>
        '/community/attachments/{attachment_id}',
];
$routeHandlers = $container->get(RouteHandlerRegistry::class);
/** @var DefinitionRegistry<RouteDefinition> $routes */
$routes = $container->get(FoundationServiceIds::ROUTES);
foreach ($expectedPaths as $routeKey => $path) {
    communityUiAssert(
        $routeHandlers->find($routeKey) !== null,
        'R-09 Route Handler가 없습니다: ' . $routeKey
    );
    $definition = $routes->find($routeKey);
    communityUiAssert(
        $definition instanceof RouteDefinition,
        'R-09 Route 정의가 없습니다: ' . $routeKey
    );
    communityUiAssert(
        $definition instanceof RouteDefinition && $definition->path() === $path,
        'R-09 Route 경로가 일치하지 않습니다: ' . $routeKey
    );
    communityUiAssert(
        $definition instanceof RouteDefinition
            && $definition->owner()->value() === 'board',
        'R-09 Route 소유 모듈이 board가 아닙니다: ' . $routeKey
    );
}

$expectedRouteFeatures = [
    'board.index' => 'board',
    'notices.index' => 'notices',
    'console.community.settings' => 'admin_console',
    'community.attachments.download' => 'auth',
];
foreach ($expectedRouteFeatures as $routeKey => $featureKey) {
    $definition = $routes->find($routeKey);
    communityUiAssert(
        $definition instanceof RouteDefinition
            && $definition->featureKey() === $featureKey,
        'R-09 Route 기능 키가 일치하지 않습니다: ' . $routeKey
    );
}
$expectedActions = [
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
    'oj_notice_add_comment',
    'oj_notice_create',
    'oj_notice_create_console',
    'oj_notice_delete_comment',
    'oj_notice_restore',
    'oj_notice_trash',
    'oj_notice_update',
    'oj_save_board_settings',
];
$actionHandlers = $container->get(ActionHandlerRegistry::class);
/** @var DefinitionRegistry<ActionDefinition> $actions */
$actions = $container->get(FoundationServiceIds::ACTIONS);
foreach ($expectedActions as $actionKey) {
    communityUiAssert(
        $actionHandlers->find($actionKey) !== null,
        'R-09 Action Handler가 없습니다: ' . $actionKey
    );
    $definition = $actions->find($actionKey);
    communityUiAssert(
        $definition instanceof ActionDefinition
            && $definition->owner()->value() === 'board',
        'R-09 Action 소유 모듈이 board가 아닙니다: ' . $actionKey
    );
}

$routeAccess = require $pluginRoot . '/config/route-access.php';
$boardConsoleRoutes = [
    'console.board.index',
    'console.board.new',
    'console.board.thread',
    'console.board.edit',
    'console.board.comments',
    'console.board.trash_thread',
    'console.board.trash',
];
foreach ($boardConsoleRoutes as $routeKey) {
    communityUiAssert(
        ($routeAccess[$routeKey] ?? null) === ['oj_manage_board'],
        '게시판 관리자 Route 권한이 일치하지 않습니다: ' . $routeKey
    );
}
$noticeConsoleRoutes = [
    'console.notices.index',
    'console.notices.new',
    'console.notices.edit',
    'console.notices.comments',
    'console.notices.trash_notice',
    'console.notices.trash',
];
foreach ($noticeConsoleRoutes as $routeKey) {
    communityUiAssert(
        ($routeAccess[$routeKey] ?? null) === ['oj_manage_notices'],
        '공지 관리자 Route 권한이 일치하지 않습니다: ' . $routeKey
    );
}
communityUiAssert(
    ($routeAccess['console.community.settings'] ?? null)
        === ['oj_manage_settings'],
    '커뮤니티 설정 Route는 설정 관리 권한을 사용해야 합니다.'
);

$menus = require $pluginRoot . '/config/menus.php';
$menuIndex = [];
foreach ($menus as $menu) {
    $menuIndex[(string) $menu['key']] = $menu;
}
communityUiAssert(
    ($menuIndex['community.board']['feature_key'] ?? null) === 'board',
    '게시판 관리자 메뉴는 board 기능을 따라야 합니다.'
);
communityUiAssert(
    ($menuIndex['community.notices']['feature_key'] ?? null) === 'notices',
    '공지 관리자 메뉴는 notices 기능을 따라야 합니다.'
);
communityUiAssert(
    ($menuIndex['community.settings']['feature_key'] ?? null)
        === 'admin_console',
    '커뮤니티 설정은 게시판과 공지 중 하나만 켜져도 열 수 있어야 합니다.'
);
communityUiAssert(
    ($menuIndex['community.settings']['any_feature_keys'] ?? null)
        === ['board', 'notices'],
    '커뮤니티 설정 메뉴는 게시판 또는 공지 기능이 켜진 경우에만 보여야 합니다.'
);

$communityHtml = file_get_contents(
    $pluginRoot . '/modules/Board/Presentation/CommunityHtml.php'
);
$boardActions = file_get_contents(
    $pluginRoot . '/modules/Board/Presentation/Action/BoardActionHandler.php'
);
$noticeActions = file_get_contents(
    $pluginRoot . '/modules/Board/Presentation/Action/NoticeActionHandler.php'
);
$settingsActions = file_get_contents(
    $pluginRoot
        . '/modules/Board/Presentation/Action/CommunitySettingsActionHandler.php'
);
$featurePolicy = file_get_contents(
    $pluginRoot . '/modules/Board/Application/CommunityFeaturePolicy.php'
);
$attachmentRoot = file_get_contents(
    $pluginRoot
        . '/modules/Board/Infrastructure/Storage/WordPressProtectedAttachmentRootProvider.php'
);
$storage = file_get_contents(
    $pluginRoot
        . '/modules/Board/Infrastructure/Storage/WordPressProtectedAttachmentStorage.php'
);
$download = file_get_contents(
    $pluginRoot
        . '/modules/Board/Presentation/Response/AttachmentDownloadHandler.php'
);
$composition = file_get_contents(
    $pluginRoot . '/src/Kernel/Composition/BoardComposition.php'
);
$publicMenu = file_get_contents(
    $pluginRoot . '/modules/PublicUi/Application/PublicMenuSettingsService.php'
);
$home = file_get_contents(
    $pluginRoot . '/modules/PublicUi/Home/NoticeHomeBlockProvider.php'
);
$problemDetail = file_get_contents(
    $pluginRoot
        . '/modules/Problems/Presentation/Route/ProblemDetailPageHandler.php'
) . file_get_contents(
    $pluginRoot . '/modules/Problems/Presentation/ProblemDetailPageView.php'
);
$boardPage = file_get_contents(
    $pluginRoot . '/modules/Board/Presentation/Route/BoardPageHandler.php'
);
$noticePage = file_get_contents(
    $pluginRoot . '/modules/Board/Presentation/Route/NoticePageHandler.php'
);
$boardConsole = file_get_contents(
    $pluginRoot . '/modules/Board/Presentation/Console/BoardConsolePageHandler.php'
);
$noticeConsole = file_get_contents(
    $pluginRoot . '/modules/Board/Presentation/Console/NoticeConsolePageHandler.php'
);
$attachmentService = file_get_contents(
    $pluginRoot . '/modules/Board/Application/AttachmentService.php'
);
$threadService = file_get_contents(
    $pluginRoot . '/modules/Board/Application/ThreadService.php'
);
$noticeService = file_get_contents(
    $pluginRoot . '/modules/Board/Application/NoticeService.php'
);
$accessPolicy = file_get_contents(
    $pluginRoot . '/modules/Board/Application/CommunityAccessPolicy.php'
);
$problemContext = file_get_contents(
    $pluginRoot . '/src/Kernel/Integration/Community/ProblemCommunityContextProvider.php'
);
$contestContext = file_get_contents(
    $pluginRoot . '/src/Kernel/Integration/Community/ContestCommunityContextProvider.php'
);
$actionGuard = file_get_contents(
    $pluginRoot . '/modules/Auth/Presentation/ActionGuard.php'
);
$css = file_get_contents(
    $pluginRoot . '/resources/ui/css/oj-ui-system.css'
);
foreach ([
    $communityHtml,
    $boardActions,
    $noticeActions,
    $settingsActions,
    $featurePolicy,
    $attachmentRoot,
    $storage,
    $download,
    $composition,
    $publicMenu,
    $home,
    $problemDetail,
    $boardPage,
    $noticePage,
    $boardConsole,
    $noticeConsole,
    $attachmentService,
    $threadService,
    $noticeService,
    $accessPolicy,
    $problemContext,
    $contestContext,
    $actionGuard,
    $css,
] as $contents) {
    communityUiAssert(
        is_string($contents),
        'R-09 화면 계약 파일을 읽을 수 없습니다.'
    );
}

communityUiContains(
    'hiddenFields($action)',
    (string) $communityHtml,
    '게시판·공지 폼은 공통 Action 보안 필드를 사용해야 합니다.'
);
communityUiContains(
    'authenticatedActor',
    (string) $boardActions,
    '사용자 게시판 Action은 로그인과 nonce 검사를 거쳐야 합니다.'
);
communityUiContains(
    'oj_manage_notices',
    (string) $noticeActions,
    '공지 운영 Action은 공지 관리 권한을 검사해야 합니다.'
);
communityUiContains(
    'oj_manage_settings',
    (string) $settingsActions,
    '커뮤니티 설정 Action은 설정 관리 권한을 검사해야 합니다.'
);
communityUiContains(
    'requireBoard()',
    (string) $boardActions,
    '게시판 직접 POST는 board 기능 ON을 다시 확인해야 합니다.'
);
communityUiContains(
    'requireNotices()',
    (string) $noticeActions,
    '공지 직접 POST는 notices 기능 ON을 다시 확인해야 합니다.'
);
communityUiContains(
    'requireAny()',
    (string) $settingsActions,
    '커뮤니티 설정 저장은 게시판 또는 공지 기능 ON을 확인해야 합니다.'
);
$throttleScopes = [];
foreach ([(string) $boardActions, (string) $noticeActions] as $source) {
    preg_match_all("/->throttle\\(\\s*'([^']+)'/s", $source, $matches);
    foreach ($matches[1] ?? [] as $scope) {
        $throttleScopes[] = $scope;
        communityUiAssert(
            preg_match('/^[a-z][a-z0-9_]{1,63}$/', $scope) === 1,
            '작성 제한 scope가 공통 AttemptLimiter 형식과 다릅니다: ' . $scope
        );
    }
}
sort($throttleScopes);
communityUiAssert(
    $throttleScopes === [
        'board_comment_create',
        'board_thread_create',
        'notice_comment_create',
    ],
    '게시글·댓글 작성 제한 scope 구성이 일치하지 않습니다.'
);
communityUiAssert(
    ! str_contains((string) $composition, 'Modules\\Jobs'),
    'Board 조립부는 Jobs 계약에 숨은 필수 의존을 만들면 안 됩니다.'
);
communityUiContains(
    'OJ_PLATFORM_PRIVATE_DATA_DIR',
    (string) $attachmentRoot,
    '보호 첨부 저장소는 공통 비공개 데이터 경로 설정을 사용해야 합니다.'
);
communityUiContains(
    'is_uploaded_file',
    (string) $storage,
    '보호 첨부 저장 시 실제 업로드 파일을 검사해야 합니다.'
);
communityUiContains(
    'random_bytes(24)',
    (string) $storage,
    '보호 첨부파일은 추측할 수 없는 저장 이름을 사용해야 합니다.'
);
communityUiContains(
    'FILEINFO_MIME_TYPE',
    (string) $storage,
    '보호 첨부파일은 서버 MIME 검사를 수행해야 합니다.'
);
communityUiContains(
    "'guid' => ''",
    (string) $storage,
    '보호 첨부파일에 공개 미디어 주소를 만들지 않아야 합니다.'
);
communityUiContains(
    'integrityReport(',
    (string) $storage,
    '보호 첨부 저장소는 삭제 없이 고아 상태를 보고하는 검사를 제공해야 합니다.'
);
communityUiContains(
    "'file_orphaned'",
    (string) $storage,
    '보호 첨부 무결성 검사는 미참조 파일을 구분해야 합니다.'
);
communityUiContains(
    '압축 파일은 내부 파일의 안전 여부를 확인한 뒤 사용하세요.',
    (string) $communityHtml,
    'ZIP 첨부에는 다운로드 전 안전 확인 안내를 표시해야 합니다.'
);
communityUiContains(
    "'Cache-Control' => 'private, no-store'",
    (string) $download,
    '보호 첨부 응답은 비공개 캐시 정책을 사용해야 합니다.'
);
communityUiContains(
    "'X-Content-Type-Options' => 'nosniff'",
    (string) $download,
    '보호 첨부 응답은 MIME 추측을 차단해야 합니다.'
);
communityUiContains(
    'homeNotices()->register',
    (string) $composition,
    '공지 Provider를 홈 확장 지점에 등록해야 합니다.'
);
communityUiContains(
    'detailSections()->register',
    (string) $composition,
    '문제 질문 Provider를 문제 상세 확장 지점에 등록해야 합니다.'
);
communityUiContains(
    "'커뮤니티'",
    (string) $publicMenu,
    '사용자 1차 메뉴에 커뮤니티를 제공해야 합니다.'
);
communityUiContains(
    "'게시판'",
    (string) $publicMenu,
    '사용자 커뮤니티 2차 메뉴에 게시판을 제공해야 합니다.'
);
communityUiContains(
    "'공지사항'",
    (string) $publicMenu,
    '사용자 커뮤니티 2차 메뉴에 공지사항을 제공해야 합니다.'
);
communityUiContains(
    'current($context->userId(), $limit)',
    (string) $home,
    '홈 공지는 운영 설정의 표시 개수만큼 조회해야 합니다.'
);
communityUiContains(
    'ojp-problem-community',
    (string) $problemDetail,
    '문제 상세에 문제별 질문 영역을 표시해야 합니다.'
);

communityUiContains(
    '$this->features->noticesEnabled()',
    (string) $boardPage,
    'Notices OFF이면 게시판 화면의 공지 교차 링크를 숨겨야 합니다.'
);
communityUiContains(
    '$this->features->boardEnabled()',
    (string) $noticePage,
    'Board OFF이면 공지 화면의 게시판 교차 링크를 숨겨야 합니다.'
);
communityUiContains(
    "throw new DomainException('휴지통의 게시글은 복원한 뒤 수정해 주세요.')",
    (string) $threadService,
    '휴지통 게시글의 직접 수정 요청을 서버에서 차단해야 합니다.'
);
communityUiContains(
    "throw new DomainException('휴지통의 공지는 복원한 뒤 수정해 주세요.')",
    (string) $noticeService,
    '휴지통 공지의 직접 수정 요청을 서버에서 차단해야 합니다.'
);
communityUiContains(
    '$post->isTrashed()',
    (string) $accessPolicy,
    '휴지통 글의 댓글 작성은 공통 접근 정책에서 차단해야 합니다.'
);
communityUiContains(
    'array_reverse($stored)',
    (string) $attachmentService,
    '첨부 저장 실패 시 먼저 저장된 파일 연결을 롤백해야 합니다.'
);
communityUiContains(
    "['preparation', 'running']",
    (string) $problemContext,
    '문제 질문은 대회 준비·진행 중 보호해야 합니다.'
);
communityUiContains(
    "['preparation', 'running']",
    (string) $contestContext,
    '대회 질문은 준비·진행 중 보호하고 종료·보관 뒤 열어야 합니다.'
);
communityUiContains(
    '! $account->isActive()',
    (string) $actionGuard,
    '직접 POST 액션도 active 계정만 수행할 수 있어야 합니다.'
);
communityUiContains(
    '<h1>삭제된 공지</h1>',
    (string) $noticeConsole,
    '휴지통 공지는 수정 폼 대신 읽기 전용 확인 화면을 제공해야 합니다.'
);
communityUiAssert(
    ! str_contains(
        (string) $boardConsole,
        "\$post->isTrashed() ? 'community.board_trash'"
    ),
    '게시판 휴지통 확인 화면에 정의되지 않은 $post 참조가 남아 있습니다.'
);
communityUiAssert(
    ! str_contains(
        (string) $noticeConsole,
        '$post !== null && $post->isTrashed()'
    ),
    '공지 휴지통 확인 화면에 잘못 복사된 분기가 남아 있습니다.'
);

$requiredClasses = [
    '.ojp-breadcrumb',
    '.ojp-community-editor__body',
    '.ojp-community-comments',
    '.ojp-community-comment',
    '.ojp-community-attachments',
    '.ojp-attachment-inputs',
    '.ojp-file-input',
    '.ojp-home-notices',
    '.ojp-problem-community',
    '.ojp-pagination--pages',
];
foreach ($requiredClasses as $className) {
    communityUiContains(
        $className,
        (string) $css,
        '공통 UI CSS 클래스가 없습니다: ' . $className
    );
}

$r09Roots = [
    $pluginRoot . '/modules/Board',
    $pluginRoot . '/src/Kernel/Integration/Community',
];
foreach ($r09Roots as $root) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $root,
            FilesystemIterator::SKIP_DOTS
        )
    );
    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $contents = file_get_contents($file->getPathname());
        communityUiAssert(
            is_string($contents)
                && preg_match('/\\\\u[0-9a-fA-F]{4}/', $contents) !== 1,
            'R-09 PHP 파일에 유니코드 이스케이프 한글이 있습니다: '
                . $file->getPathname()
        );
        communityUiAssert(
            is_string($contents)
                && preg_match('/style\\s*=\\s*["\']/', $contents) !== 1,
            'R-09 화면에 인라인 style이 있습니다: ' . $file->getPathname()
        );
    }
}

fwrite(
    STDOUT,
    sprintf(
        "R-09 커뮤니티 E2E 계약 검사 통과: Route %d개, Action %d개, assertion %d개\n",
        count($expectedPaths),
        count($expectedActions),
        $communityUiAssertions
    )
);
