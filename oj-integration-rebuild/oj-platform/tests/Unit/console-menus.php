<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentity;
use CodingPanda\OjPlatform\Modules\PublicUi\Contract\AccountLinkProvider;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SiteBrandingProvider;
use CodingPanda\OjPlatform\Ui\Asset\UiAssetLocator;
use CodingPanda\OjPlatform\Ui\View\HtmlDocumentRenderer;
use CodingPanda\OjPlatform\Kernel\Configuration\PhpConfigLoader;
use CodingPanda\OjPlatform\Kernel\Registry\DefinitionRegistry;
use CodingPanda\OjPlatform\Modules\AccessRouter\Application\RouteHandlerRegistry;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\AccessRouter\Application\RouteUrlGenerator;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteDefinition;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AdminConsole\Application\MenuTree;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\MenuDefinition;
use CodingPanda\OjPlatform\Modules\AdminConsole\Navigation\ConsoleMenuItem;
use CodingPanda\OjPlatform\Modules\AdminConsole\Navigation\ConsoleMenuAccessRuleRegistry;
use CodingPanda\OjPlatform\Modules\AdminConsole\Navigation\ConsoleMenuResolver;
use CodingPanda\OjPlatform\Modules\AdminConsole\Shell\ConsoleShell;
use CodingPanda\OjPlatform\Modules\Permission\Application\PermissionService;
use CodingPanda\OjPlatform\Modules\Permission\Contract\CapabilityChecker;
use CodingPanda\OjPlatform\Modules\Permission\Contract\CapabilityDefinition;
use CodingPanda\OjPlatform\Modules\Permission\Contract\RoleDefinition;
use CodingPanda\OjPlatform\Modules\Settings\Application\FeatureService;
use CodingPanda\OjPlatform\Modules\Settings\Application\SettingsService;
use CodingPanda\OjPlatform\Modules\Settings\Contract\FeatureDefinition;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingDefinition;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingStore;

$pluginRoot = dirname(__DIR__, 2);
require_once $pluginRoot . '/src/Kernel/Autoloader.php';
\CodingPanda\OjPlatform\Kernel\Autoloader::register($pluginRoot);

$menuAssertions = 0;

function menuAssert(bool $condition, string $message): void
{
    global $menuAssertions;
    ++$menuAssertions;

    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function menuSame(mixed $expected, mixed $actual, string $message): void
{
    menuAssert(
        $expected === $actual,
        $message
        . PHP_EOL
        . 'Expected: ' . var_export($expected, true)
        . PHP_EOL
        . 'Actual: ' . var_export($actual, true)
    );
}

final class MenuTestStore implements SettingStore
{
    /**
     * @param array<string, bool> $flags
     */
    public function __construct(private readonly array $flags)
    {
    }

    public function read(string $optionName, mixed $default = null): mixed
    {
        if ($optionName !== 'oj_platform_feature_flags') {
            return $default;
        }

        return ['revision' => 1, 'value' => $this->flags];
    }

    public function write(string $optionName, mixed $value): bool
    {
        unset($optionName, $value);

        return false;
    }
}

final class MenuTestCapabilities implements CapabilityChecker
{
    /**
     * @param array<int, list<string>> $capabilities
     * @param list<int> $superAdmins
     */
    public function __construct(
        private readonly array $capabilities,
        private readonly array $superAdmins = []
    ) {
    }

    public function userCan(int $userId, string $capability): bool
    {
        return in_array(
            $capability,
            $this->capabilities[$userId] ?? [],
            true
        );
    }

    public function isSuperAdmin(int $userId): bool
    {
        return in_array($userId, $this->superAdmins, true);
    }
}

final class MenuTestHandler implements RouteHandler
{
    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        unset($route, $request);

        return HttpResponse::html('');
    }
}

/**
 * @param list<ConsoleMenuItem> $items
 * @return list<string>
 */
function menuKeys(array $items): array
{
    $keys = [];
    foreach ($items as $item) {
        $keys[] = $item->key();
        foreach ($item->children() as $child) {
            $keys[] = $child->key();
        }
    }

    return $keys;
}

$loader = new PhpConfigLoader($pluginRoot . '/config');

$capabilityDefinitions = new DefinitionRegistry(
    'menu-test-capabilities',
    CapabilityDefinition::class
);
$roleDefinitions = new DefinitionRegistry(
    'menu-test-roles',
    RoleDefinition::class
);
$permissionConfiguration = $loader->load('capabilities');
foreach ($permissionConfiguration['capabilities'] as $definition) {
    $capabilityDefinitions->register(CapabilityDefinition::fromArray($definition));
}
foreach ($permissionConfiguration['roles'] as $definition) {
    $roleDefinitions->register(RoleDefinition::fromArray($definition));
}

$featureDefinitions = new DefinitionRegistry(
    'menu-test-features',
    FeatureDefinition::class
);
$featureFlags = [];
foreach ($loader->load('features') as $definition) {
    $feature = FeatureDefinition::fromArray($definition);
    $featureDefinitions->register($feature);
    $featureFlags[$feature->key()] = true;
}
$settingDefinitions = new DefinitionRegistry(
    'menu-test-settings',
    SettingDefinition::class
);
foreach ($loader->load('settings') as $definition) {
    if (($definition['key'] ?? '') === 'feature_flags') {
        $settingDefinitions->register(SettingDefinition::fromArray($definition));
    }
}
$features = new FeatureService(
    $featureDefinitions,
    new SettingsService($settingDefinitions, new MenuTestStore($featureFlags))
);

$routeDefinitions = new DefinitionRegistry(
    'menu-test-routes',
    RouteDefinition::class
);
foreach ($loader->load('routes') as $definition) {
    $routeDefinitions->register(RouteDefinition::fromArray($definition));
}
$menuDefinitions = new DefinitionRegistry(
    'menu-test-menus',
    MenuDefinition::class
);
foreach ($loader->load('menus') as $definition) {
    $menuDefinitions->register(MenuDefinition::fromArray($definition));
}

$currentLearningMenuParents = [
    'classes' => null,
    'classes.index' => 'classes',
    'classes.new' => 'classes',
    'classes.deleted' => 'classes',
    'classes.groups' => 'classes',
    'classes.groups_new' => 'classes',
    'classes.groups_deleted' => 'classes',
    // D-010: 대회·평가는 수업 아래로, 쪽지는 커뮤니티 아래로 옮겼다.
    'contests.index' => 'classes',
    'contests.deleted' => 'classes',
    'contests.new' => 'classes',
    'contests.deleted_submissions' => 'classes',
    'messaging.inbox' => 'community',
    'messaging.reports' => 'community',
    'messaging.settings' => 'community',
];
foreach (['contests', 'messaging'] as $removedRoot) {
    menuAssert(
        $menuDefinitions->find($removedRoot) === null,
        $removedRoot . ' 1차 메뉴는 D-010에서 통합됐습니다.'
    );
}
foreach ($currentLearningMenuParents as $menuKey => $expectedParent) {
    $menuDefinition = $menuDefinitions->find($menuKey);
    menuAssert(
        $menuDefinition instanceof MenuDefinition,
        $menuKey . ' 현재 메뉴 정의가 필요합니다.'
    );
    menuSame(
        $expectedParent,
        $menuDefinition->parent(),
        $menuKey . ' 메뉴의 현재 parent 정본이 바뀌었습니다.'
    );
}

$legacyCurrentMenuKeys = [
    'learning.classes',
    'learning.classes_new',
    'learning.classes_deleted',
    'learning.contests',
    'learning.contests_new',
    'learning.contests_deleted_submissions',
    'learning.groups',
    'learning.groups_new',
    'learning.groups_deleted',
];
$currentMenuSourceRoots = [
    $pluginRoot . '/modules/Classes/Presentation',
    $pluginRoot . '/modules/Contests/Presentation',
    $pluginRoot . '/modules/Groups/Presentation',
    $pluginRoot . '/src/Kernel/Integration/Learning',
];
$legacyCurrentMenuReferences = [];
$normalizedPluginRoot = rtrim(str_replace('\\', '/', $pluginRoot), '/') . '/';
foreach ($currentMenuSourceRoots as $sourceRoot) {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceRoot));
    foreach ($files as $file) {
        if (! $file->isFile() || strtolower($file->getExtension()) !== 'php') {
            continue;
        }
        $source = file_get_contents($file->getPathname());
        menuAssert(is_string($source), '현재 메뉴 키 검사 대상 파일을 읽을 수 있어야 합니다.');
        $relativePath = str_replace('\\', '/', $file->getPathname());
        if (str_starts_with($relativePath, $normalizedPluginRoot)) {
            $relativePath = substr($relativePath, strlen($normalizedPluginRoot));
        }
        foreach ($legacyCurrentMenuKeys as $legacyKey) {
            if (str_contains($source, "'" . $legacyKey . "'")) {
                $legacyCurrentMenuReferences[] = $relativePath . ': ' . $legacyKey;
            }
        }
    }
}
menuSame(
    [],
    $legacyCurrentMenuReferences,
    'Console handler와 menu access rule은 config/menus.php에 없는 이전 learning.* 키를 전달하면 안 됩니다.'
);

$classesRoot = new ConsoleMenuItem(
    'classes',
    '수업',
    '/oj-console/classes',
    [
        new ConsoleMenuItem('classes.index', '수업 목록', '/oj-console/classes'),
        new ConsoleMenuItem(
            'addon.stubns.console-menu',
            'AI 활동지',
            '/oj-console/addons/stub-addon/worksheets'
        ),
    ]
);
$shellReflection = new ReflectionClass(ConsoleShell::class);
$shell = $shellReflection->newInstanceWithoutConstructor();
$containsCurrent = $shellReflection->getMethod('containsCurrent');
$containsCurrent->setAccessible(true);
menuAssert(
    $containsCurrent->invoke($shell, $classesRoot, 'classes.index') === true,
    'core 수업 화면에서는 classes root가 펼쳐져야 합니다.'
);
menuAssert(
    $containsCurrent->invoke($shell, $classesRoot, 'addon.stubns.console-menu') === true,
    '수업 anchor의 애드온 화면에서도 classes root가 펼쳐져야 합니다.'
);
menuAssert(
    $containsCurrent->invoke($shell, $classesRoot, 'learning.classes') === false,
    'config에 없는 이전 메뉴 키를 현재 메뉴로 허용하면 안 됩니다.'
);

$communitySettingsMenu = $menuDefinitions->find('community.settings');
menuAssert(
    $communitySettingsMenu instanceof MenuDefinition,
    '커뮤니티 설정 메뉴 정의가 필요합니다.'
);
menuSame(
    ['board', 'notices'],
    $communitySettingsMenu->anyFeatureKeys(),
    '커뮤니티 설정 메뉴는 게시판 또는 공지 기능이 켜져 있을 때만 표시해야 합니다.'
);

$handlers = new RouteHandlerRegistry();
foreach (
    [
        'console.dashboard',
        'console.users.index',
        'console.users.new',
        'console.users.bulk_import',
        'console.users.deleted',
        'console.roles.index',
        'console.agreements.index',
        'console.users.registration',
        'console.problems.topics',
    ] as $routeName
) {
    $handlers->register($routeName, new MenuTestHandler());
}

$roleCapabilities = [];
$roleUserIds = [];
$nextUserId = 100;
foreach ($roleDefinitions->all() as $role) {
    $roleUserIds[$role->key()] = $nextUserId;
    $roleCapabilities[$nextUserId] = $role->capabilities();
    ++$nextUserId;
}
$checker = new MenuTestCapabilities($roleCapabilities, [999]);
$resolver = new ConsoleMenuResolver(
    new MenuTree($menuDefinitions),
    $handlers,
    new RouteUrlGenerator($routeDefinitions),
    $features,
    new PermissionService($capabilityDefinitions, $checker),
    new ConsoleMenuAccessRuleRegistry()
);

foreach ($roleDefinitions->all() as $role) {
    $keys = menuKeys($resolver->resolve($roleUserIds[$role->key()]));
    menuAssert(
        in_array('dashboard', $keys, true),
        $role->label() . '에게 공통 대시보드가 보여야 합니다.'
    );
    menuAssert(
        ! in_array('problems.topics', $keys, true),
        $role->label() . '에게 최고 관리자 전용 주제 관리가 보이면 안 됩니다.'
    );
}

menuSame(
    [
        'dashboard',
        'users',
        'users.index',
        'users.deleted',
        'users.new',
        'users.bulk_import',
    ],
    menuKeys($resolver->resolve($roleUserIds['oj_user_manager'])),
    '사용자 관리자는 구현된 사용자 업무 메뉴만 확인해야 합니다.'
);
menuSame(
    ['dashboard', 'users', 'users.roles'],
    menuKeys($resolver->resolve($roleUserIds['oj_role_manager'])),
    '역할 관리자는 역할 메뉴만 확인해야 합니다.'
);
menuSame(
    ['dashboard', 'users', 'users.registration', 'users.agreements'],
    menuKeys($resolver->resolve($roleUserIds['oj_registration_policy_manager'])),
    '가입 정책 관리자는 약관과 가입 설정만 확인해야 합니다.'
);
menuSame(
    ['dashboard', 'users', 'users.index'],
    menuKeys($resolver->resolve($roleUserIds['oj_registration_approver'])),
    '가입 승인 담당자는 승인 목록만 확인해야 합니다.'
);

$superAdminKeys = menuKeys($resolver->resolve(999));
menuAssert(
    in_array('problems.topics', $superAdminKeys, true),
    '최고 관리자에게는 구현된 전용 주제 관리 메뉴가 보여야 합니다.'
);

// --- D-010: 같은 일을 하는 화면은 사이드바 한 항목과 화면 안 세그먼트로 묶는다 ---

$localNavGroups = $loader->load('console-local-nav');
$groupedKeys = [];
foreach ($localNavGroups as $group) {
    $memberKeys = array_map(static fn (array $member): string => $member[0], $group['members']);
    menuAssert(in_array($group['key'], $memberKeys, true), $group['key'] . ' 묶음은 자기 대표 화면을 포함해야 합니다.');
    $parents = [];
    foreach ($memberKeys as $memberKey) {
        $definition = $menuDefinitions->find($memberKey);
        menuAssert($definition instanceof MenuDefinition, $memberKey . ' 묶음 화면이 config/menus.php에 있어야 합니다.');
        menuAssert(! isset($groupedKeys[$memberKey]), $memberKey . ' 화면은 한 묶음에만 속해야 합니다.');
        $groupedKeys[$memberKey] = true;
        $parents[$definition->parent()] = true;
    }
    menuSame(1, count($parents), $group['key'] . ' 묶음의 화면은 같은 1차 메뉴 아래 있어야 합니다.');
}
$foldedResolver = new ConsoleMenuResolver(
    new MenuTree($menuDefinitions),
    $handlers,
    new RouteUrlGenerator($routeDefinitions),
    $features,
    new PermissionService($capabilityDefinitions, $checker),
    new ConsoleMenuAccessRuleRegistry(),
    $localNavGroups
);
$menuEntry = static function (array $menu, string $key): ?ConsoleMenuItem {
    foreach ($menu as $root) {
        foreach ($root->children() as $child) {
            if ($child->key() === $key) {
                return $child;
            }
        }
    }

    return null;
};
$memberSummary = static fn (ConsoleMenuItem $item): array => array_map(
    static fn (ConsoleMenuItem $member): string => $member->key() . ($member->isAction() ? ':action' : ''),
    $item->members()
);

$userManagerMenu = $foldedResolver->resolve($roleUserIds['oj_user_manager']);
menuSame(
    ['dashboard', 'users', 'users.index'],
    menuKeys($userManagerMenu),
    '사용자 목록·삭제됨·추가·일괄 등록은 사이드바 한 항목이어야 합니다.'
);
$usersEntry = $menuEntry($userManagerMenu, 'users.index');
menuSame('사용자', $usersEntry?->label(), '묶은 항목은 그룹 이름을 사이드바 이름으로 씁니다.');
menuSame(
    ['users.index', 'users.deleted', 'users.new:action', 'users.bulk_import:action'],
    $memberSummary($usersEntry),
    '묶은 화면은 보기 세그먼트와 작업 버튼으로 나뉘어야 합니다.'
);
menuAssert($usersEntry->matches('users.new'), '추가 화면에서도 사용자 항목이 현재 위치여야 합니다.');

menuSame(
    ['dashboard', 'users', 'users.registration'],
    menuKeys($foldedResolver->resolve($roleUserIds['oj_registration_policy_manager'])),
    '가입 설정과 약관은 "가입과 약관" 한 항목이어야 합니다.'
);
$approverMenu = $foldedResolver->resolve($roleUserIds['oj_registration_approver']);
menuSame(
    ['users.index'],
    $memberSummary($menuEntry($approverMenu, 'users.index')),
    '권한이 없는 화면은 세그먼트에서도 빠져야 합니다.'
);

$shellForFold = new ConsoleShell(
    new HtmlDocumentRenderer(new class implements UiAssetLocator {
        public function stylesheetUrl(): string { return '/assets/oj-ui-system.css'; }
        public function scriptUrl(): string { return '/assets/oj-ui.js'; }
        public function mathJaxLoaderUrl(): string { return '/assets/mathjax-loader.js'; }
        public function iconSpriteUrl(): string { return '/assets/lucide.svg'; }
        public function faviconUrl(): string { return '/assets/favicon.svg'; }
    }),
    new class implements AccountLinkProvider {
        public function logoutUrl(string $redirectPath = '/login'): string { return '/logout'; }
        public function logoutFields(): string { return ''; }
    },
    new class implements SiteBrandingProvider {
        public function serviceName(): string { return 'OJ'; }
        public function shortDescription(): string { return ''; }
        public function supportLine(): string { return ''; }
    }
);
$foldedHtml = $shellForFold->render(
    '삭제된 사용자',
    '<header class="ojp-page-header"><div><h1>삭제된 사용자</h1></div></header><p>본문</p>',
    'users.deleted',
    new UserIdentity(1, 'admin', 'admin@example.test', '관리자'),
    $userManagerMenu
);
menuAssert(
    str_contains($foldedHtml, '</header><div class="ojp-local-nav-bar"><nav class="ojp-local-nav"'),
    '세그먼트는 페이지 머리말 바로 아래에 그려야 합니다.'
);
menuAssert(
    str_contains($foldedHtml, 'href="/oj-console/users/deleted" class="is-current" aria-current="true">삭제됨</a>'),
    '현재 화면의 세그먼트가 선택돼야 합니다.'
);
menuAssert(
    str_contains($foldedHtml, 'href="/oj-console/users/new"><svg')
    && str_contains($foldedHtml, '<span>사용자 추가</span>'),
    '화면에 없는 작업은 버튼으로 보여야 합니다.'
);
menuAssert(
    str_contains($foldedHtml, '<li><a href="/oj-console/users">사용자</a></li><li><span aria-current="page">삭제된 사용자</span></li>'),
    '경로는 묶은 항목을 거쳐 현재 화면 제목으로 끝나야 합니다.'
);
$dedupedHtml = $shellForFold->render(
    '사용자',
    '<header class="ojp-page-header"><div><h1>사용자</h1></div><a class="ojp-button" href="/oj-console/users/new">사용자 추가</a></header>',
    'users.index',
    new UserIdentity(1, 'admin', 'admin@example.test', '관리자'),
    $userManagerMenu
);
menuSame(
    1,
    substr_count($dedupedHtml, 'href="/oj-console/users/new"><svg') + substr_count($dedupedHtml, '>사용자 추가</a>'),
    '화면이 이미 가진 작업 링크는 버튼으로 반복하지 않아야 합니다.'
);

$communityHandlers = new RouteHandlerRegistry();
$communityHandlers->register(
    'console.community.settings',
    new MenuTestHandler()
);
$communityMenuKeys = static function (array $flags) use (
    $capabilityDefinitions,
    $checker,
    $communityHandlers,
    $featureDefinitions,
    $menuDefinitions,
    $routeDefinitions,
    $settingDefinitions
): array {
    $featureService = new FeatureService(
        $featureDefinitions,
        new SettingsService(
            $settingDefinitions,
            new MenuTestStore($flags)
        )
    );

    return menuKeys((new ConsoleMenuResolver(
        new MenuTree($menuDefinitions),
        $communityHandlers,
        new RouteUrlGenerator($routeDefinitions),
        $featureService,
        new PermissionService($capabilityDefinitions, $checker),
        new ConsoleMenuAccessRuleRegistry()
    ))->resolve(999));
};

$boardOnlyFlags = $featureFlags;
$boardOnlyFlags['board'] = true;
$boardOnlyFlags['notices'] = false;
menuAssert(
    in_array('community.settings', $communityMenuKeys($boardOnlyFlags), true),
    '게시판 기능만 켜져 있어도 커뮤니티 설정 메뉴가 보여야 합니다.'
);

$noticeOnlyFlags = $featureFlags;
$noticeOnlyFlags['board'] = false;
$noticeOnlyFlags['notices'] = true;
menuAssert(
    in_array('community.settings', $communityMenuKeys($noticeOnlyFlags), true),
    '공지 기능만 켜져 있어도 커뮤니티 설정 메뉴가 보여야 합니다.'
);

$communityDisabledFlags = $featureFlags;
$communityDisabledFlags['board'] = false;
$communityDisabledFlags['notices'] = false;
menuAssert(
    ! in_array('community.settings', $communityMenuKeys($communityDisabledFlags), true),
    '게시판과 공지 기능이 모두 꺼지면 커뮤니티 설정 메뉴가 숨겨져야 합니다.'
);

$capabilityDefinitions->register(CapabilityDefinition::fromArray([
    'key' => 'oj_addon_stubns_view',
    'owner_module' => 'addon_bridge',
    'feature_key' => 'admin_console',
    'scope' => 'global',
    'label' => '스텁 애드온 보기',
    'description' => '스텁 애드온 콘솔 접근',
]));
$addonRoutes = new DefinitionRegistry('addon-menu-test-routes', RouteDefinition::class);
$addonRoutes->register(RouteDefinition::fromArray([
    'name' => 'addon.stubns.dashboard',
    'owner_module' => 'addon_bridge',
    'feature_key' => 'admin_console',
    'path' => '/oj-console/addons/stub/dashboard',
    'area' => 'console',
    'methods' => ['GET'],
    'authentication' => 'authenticated',
    'response_type' => 'html',
]));
$addonMenus = new DefinitionRegistry('addon-menu-test-menus', MenuDefinition::class);
$addonMenus->register(new MenuDefinition(
    'addon.stubns.dashboard-menu',
    '스텁 애드온',
    null,
    'addon.stubns.dashboard',
    null,
    ['oj_access_console', 'oj_addon_stubns_view'],
    10,
    'all_capabilities'
));
$addonHandlers = new RouteHandlerRegistry();
$addonHandlers->register('addon.stubns.dashboard', new MenuTestHandler());
$resolveAddonMenu = static function (array $granted) use (
    $addonHandlers,
    $addonMenus,
    $addonRoutes,
    $capabilityDefinitions,
    $features
): array {
    $authorization = new PermissionService(
        $capabilityDefinitions,
        new MenuTestCapabilities([700 => $granted])
    );

    return menuKeys((new ConsoleMenuResolver(
        new MenuTree($addonMenus),
        $addonHandlers,
        new RouteUrlGenerator($addonRoutes),
        $features,
        $authorization,
        new ConsoleMenuAccessRuleRegistry()
    ))->resolve(700));
};
menuAssert(
    ! in_array('addon.stubns.dashboard-menu', $resolveAddonMenu(['oj_addon_stubns_view']), true),
    '애드온 권한만으로 콘솔 메뉴가 보이면 안 됩니다.'
);
menuAssert(
    ! in_array('addon.stubns.dashboard-menu', $resolveAddonMenu(['oj_access_console']), true),
    '콘솔 진입 권한만으로 애드온 메뉴가 보이면 안 됩니다.'
);
menuAssert(
    in_array(
        'addon.stubns.dashboard-menu',
        $resolveAddonMenu(['oj_access_console', 'oj_addon_stubns_view']),
        true
    ),
    '두 권한을 모두 가진 경우에만 애드온 콘솔 메뉴가 보여야 합니다.'
);

// 애드온이 같은 상위 메뉴에 여러 항목을 등록하면, order 값이 다른 항목들 사이에
// 끼어 있더라도 같은 애드온끼리는 서로 붙어서 나와야 한다. 반면 항목이 하나뿐인
// 애드온이나 플랫폼 고유 항목은 기존처럼 order 순서대로 자연스럽게 섞여야 한다.
$groupingMenus = new DefinitionRegistry('menu-grouping-test-menus', MenuDefinition::class);
foreach ([
    ['core.a', 10],
    ['addon.foo.first', 15],
    ['addon.bar.only', 20],
    ['addon.foo.second', 25],
    ['core.b', 30],
] as [$key, $order]) {
    $groupingMenus->register(new MenuDefinition(
        $key,
        $key,
        'group_test_root',
        'first_accessible_child',
        null,
        [],
        $order,
        null
    ));
}
$groupedOrder = array_map(
    static fn (MenuDefinition $menu): string => $menu->key(),
    (new MenuTree($groupingMenus))->childrenOf('group_test_root')
);
menuSame(
    ['core.a', 'addon.foo.first', 'addon.foo.second', 'addon.bar.only', 'core.b'],
    $groupedOrder,
    '같은 애드온(addon.foo)의 메뉴 두 개는 순서상 다른 항목이 그 사이에 끼어들지 않고 붙어 나와야 합니다.'
);

// menuIcon()이 반환할 수 있는 모든 값은 실제 아이콘 스프라이트(lucide.svg)에 존재해야
// 한다 — 스프라이트에 없는 이름을 쓰면 화면에는 빈 아이콘만 남고 어떤 테스트도 이를
// 잡아내지 못한다(학습지원 메뉴에 'life-buoy'를 썼다가 스프라이트에 없어서 발생했던
// 실제 회귀를 재현한다).
$menuIconMethod = $shellReflection->getMethod('menuIcon');
$menuIconMethod->setAccessible(true);
$sprite = (string) file_get_contents($pluginRoot . '/resources/ui/icons/lucide.svg');
foreach ([
    'dashboard', 'users', 'problems', 'submissions', 'classes',
    'contests', 'learning_support', 'community', 'messaging', 'operations',
    'unrecognized_key',
] as $rootKey) {
    $iconName = $menuIconMethod->invoke($shell, $rootKey);
    menuAssert(
        is_string($iconName) && str_contains($sprite, 'id="' . $iconName . '"'),
        $rootKey . ' 메뉴 아이콘(' . $iconName . ')이 아이콘 스프라이트에 없습니다.'
    );
}

fwrite(
    STDOUT,
    sprintf(
        "R-04 역할별 관리자 메뉴 단위 검사 통과: assertion %d개\n",
        $menuAssertions
    )
);
