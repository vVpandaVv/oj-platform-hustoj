<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\AddonApi\V1\AddonAiTaskDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCapabilityDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCapabilityKeyV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonConsoleLocalNavItemV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonConsoleMenuDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonConsolePageDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageHandlerV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageResponseV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPublicMenuDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPublicPageDefinitionV1;
use CodingPanda\OjPlatform\Kernel\Addon\AcceptedAddonCatalog;
use CodingPanda\OjPlatform\Kernel\Addon\AddonAiTaskContributions;
use CodingPanda\OjPlatform\Kernel\Addon\AddonAiTaskContributor;
use CodingPanda\OjPlatform\Kernel\Addon\AddonCapabilityContributor;
use CodingPanda\OjPlatform\Kernel\Addon\AddonCatalogEntry;
use CodingPanda\OjPlatform\Kernel\Addon\AddonContributionComposer;
use CodingPanda\OjPlatform\Kernel\Addon\AddonCorrelationIdFactory;
use CodingPanda\OjPlatform\Kernel\Addon\AddonManifest;
use CodingPanda\OjPlatform\Kernel\Addon\AddonMenuContributor;
use CodingPanda\OjPlatform\Kernel\Addon\AddonPublicMenuContributions;
use CodingPanda\OjPlatform\Kernel\Addon\AddonRouteContributor;
use CodingPanda\OjPlatform\Kernel\Addon\AddonRouteHandler;
use CodingPanda\OjPlatform\Kernel\Addon\AddonRuntimeReadiness;
use CodingPanda\OjPlatform\Kernel\Integration\Operations\AddonSystemStatusProvider;
use CodingPanda\OjPlatform\Kernel\Registry\DefinitionRegistry;
use CodingPanda\OjPlatform\Modules\AccessRouter\Application\RouteHandlerRegistry;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteDefinition;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteUrlProvider;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleResponder;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\MenuDefinition;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccount;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentity;
use CodingPanda\OjPlatform\Modules\Permission\Application\PermissionInstaller;
use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;
use CodingPanda\OjPlatform\Modules\Permission\Contract\CapabilityDefinition;
use CodingPanda\OjPlatform\Modules\Permission\Contract\PermissionContext;
use CodingPanda\OjPlatform\Modules\Permission\Contract\RoleDefinition;
use CodingPanda\OjPlatform\Modules\Permission\Contract\RoleSynchronizer;
use CodingPanda\OjPlatform\Modules\Permission\Contract\RoleSyncReport;
use CodingPanda\OjPlatform\Modules\Permission\Domain\CapabilityScope;
use CodingPanda\OjPlatform\Modules\Permission\Infrastructure\WordPress\WordPressAddonCapabilityAssignmentGateway;
use CodingPanda\OjPlatform\Modules\Permission\Infrastructure\WordPress\WordPressRoleSynchronizer;
use CodingPanda\OjPlatform\Modules\PublicUi\Access\ConsoleRouteAccessRuleRegistry;
use CodingPanda\OjPlatform\Modules\PublicUi\Application\PublicMenuSettingsService;
use CodingPanda\OjPlatform\Modules\PublicUi\Contract\PageResponder;
use CodingPanda\OjPlatform\Modules\PublicUi\Domain\PublicMenuEntry;
use CodingPanda\OjPlatform\Modules\Settings\Application\AiProviderCatalog;
use CodingPanda\OjPlatform\Modules\Settings\Contract\FeatureDefinition;
use CodingPanda\OjPlatform\Modules\Settings\Contract\FeatureFlags;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsManager;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsSnapshot;

$pluginRoot = dirname(__DIR__, 2);
require_once $pluginRoot . '/src/Kernel/Autoloader.php';
\CodingPanda\OjPlatform\Kernel\Autoloader::register($pluginRoot);

function addonCompAssert(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function addonCompSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . ' 예상=' . var_export($expected, true)
            . ' 실제=' . var_export($actual, true)
        );
    }
}

function addonCompThrowsInvalid(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (InvalidArgumentException) {
        return;
    }
    throw new RuntimeException($message);
}

final class StubAddonPageHandler implements AddonPageHandlerV1
{
    public ?AddonPageRequestV1 $lastRequest = null;

    public function handle(AddonPageRequestV1 $request): AddonPageResponseV1
    {
        $this->lastRequest = $request;

        return AddonPageResponseV1::html('스텁 화면', '<p>스텁 본문</p>');
    }
}

final class FakeAuthorization implements Authorization
{
    /** @var array<string, bool> */
    public array $granted = [];

    public bool $superAdmin = false;

    public function can(int $userId, string $capability, ?PermissionContext $context = null): bool
    {
        unset($userId, $context);

        return $this->granted[$capability] ?? false;
    }

    public function canAny(int $userId, array $capabilities): bool
    {
        foreach ($capabilities as $capability) {
            if ($this->can($userId, $capability)) {
                return true;
            }
        }

        return false;
    }

    public function isSuperAdmin(int $userId): bool
    {
        unset($userId);

        return $this->superAdmin;
    }
}

final class FakeCurrentAccountProvider implements CurrentAccountProvider
{
    public function current(): CurrentAccount
    {
        return new CurrentAccount(new UserIdentity(9, 'stub-user', 'stub@example.test', '스텁'), null);
    }
}

final class FakeRouteUrlProvider implements RouteUrlProvider
{
    public function path(string $routeName, array $parameters = []): string
    {
        return '/' . $routeName . (($parameters !== []) ? '?' . http_build_query($parameters) : '');
    }
}

final class FakeConsoleResponder implements ConsoleResponder
{
    public string $lastMenu = '';

    public function respond(
        string $title,
        string $content,
        string $currentMenu,
        HttpRequest $request,
        int $status = 200
    ): HttpResponse {
        unset($title, $request);
        $this->lastMenu = $currentMenu;

        return HttpResponse::html($content, $status);
    }
}

final class FakePageResponder implements PageResponder
{
    public function guest(string $title, string $content, HttpRequest $request, int $status = 200): HttpResponse
    {
        unset($title, $request);

        return HttpResponse::html($content, $status);
    }

    public function authenticated(string $title, string $content, HttpRequest $request, int $status = 200): HttpResponse
    {
        unset($title, $request);

        return HttpResponse::html($content, $status);
    }
}

final class FakeRoleSynchronizer implements RoleSynchronizer
{
    /** @var list<string> */
    public array $capturedAdministratorCapabilities = [];

    public function synchronize(array $roles, array $administratorCapabilities): RoleSyncReport
    {
        unset($roles);
        $this->capturedAdministratorCapabilities = $administratorCapabilities;

        return new RoleSyncReport([], ['administrator']);
    }
}

final class FakeWordPressRole
{
    /** @param array<string, bool> $capabilities */
    public function __construct(public array $capabilities)
    {
    }

    public function add_cap(string $capability, bool $grant = true): void
    {
        $this->capabilities[$capability] = $grant;
    }

    public function remove_cap(string $capability): void
    {
        unset($this->capabilities[$capability]);
    }
}

if (! function_exists('get_role')) {
    function get_role(string $role): ?FakeWordPressRole
    {
        $value = $GLOBALS['addon_comp_wordpress_roles'][$role] ?? null;

        return $value instanceof FakeWordPressRole ? $value : null;
    }
}

if (! function_exists('wp_roles')) {
    function wp_roles(): object
    {
        $roles = [];
        foreach (($GLOBALS['addon_comp_wordpress_roles'] ?? []) as $roleKey => $role) {
            if ($role instanceof FakeWordPressRole) {
                $roles[$roleKey] = ['capabilities' => $role->capabilities];
            }
        }

        return (object) ['roles' => $roles];
    }
}

if (! function_exists('add_role')) {
    /** @param array<string, bool> $capabilities */
    function add_role(string $role, string $displayName, array $capabilities = []): ?FakeWordPressRole
    {
        unset($displayName);
        if (isset($GLOBALS['addon_comp_wordpress_roles'][$role])) {
            return null;
        }
        $created = new FakeWordPressRole($capabilities);
        $GLOBALS['addon_comp_wordpress_roles'][$role] = $created;

        return $created;
    }
}

final class FakeSettingsManager implements SettingsManager
{
    public function __construct(private readonly mixed $value)
    {
    }

    public function read(string $key, mixed $default = null): SettingsSnapshot
    {
        unset($key, $default);

        return new SettingsSnapshot(1, $this->value);
    }

    public function save(string $key, mixed $value, int $expectedRevision): SettingsSnapshot
    {
        unset($key, $value, $expectedRevision);

        throw new RuntimeException('사용하지 않습니다.');
    }
}

final class FakeAlwaysOnFeatureFlags implements FeatureFlags
{
    public function isEnabled(string $featureKey): bool
    {
        unset($featureKey);

        return true;
    }
}

// --- Build one accepted addon manifest with one of every contribution kind ---

$pageHandler = new StubAddonPageHandler();
$manifest = new AddonManifest(
    'stubns',
    '1.0.0',
    __FILE__,
    '^2.0',
    '^1.0',
    '스텁 애드온',
    'https://example.test/support',
    [new AddonCapabilityDefinitionV1('stubns_view', '스텁 보기', 'operator')],
    [new AddonConsolePageDefinitionV1(
        'addon.stubns.dashboard',
        '/oj-console/addons/stub-addon/dashboard',
        $pageHandler,
        'stubns_view'
    )],
    [new AddonPublicPageDefinitionV1(
        'addon.stubns.page',
        '/oj-addons/stub-addon/page',
        $pageHandler,
        'stubns_view'
    )],
    [new AddonConsoleMenuDefinitionV1(
        'addon.stubns.console-menu',
        '스텁 메뉴',
        'classes',
        'addon.stubns.dashboard',
        'stubns_view',
        10
    )],
    [new AddonPublicMenuDefinitionV1(
        'addon.stubns.public-menu',
        '스텁 학습',
        'problems',
        'addon.stubns.page',
        'stubns_view',
        10,
        'learner'
    )],
    [],
    [new AddonAiTaskDefinitionV1('addon.stubns.summarize', '스텁 요약', '1.0', 'none')]
);

$catalog = new AcceptedAddonCatalog();
$catalog->commitAndFreeze([new AddonCatalogEntry('stub-addon', $manifest, 'accepted', '')]);

$acceptedReadiness = new AddonRuntimeReadiness();
$acceptedReadiness->register('stub-addon', false);
$acceptedAddonStatus = (new AddonSystemStatusProvider($catalog, $acceptedReadiness))->current();
addonCompSame('success', $acceptedAddonStatus->tone(), '승인된 애드온만 있으면 상태가 정상이어야 합니다.');
addonCompSame(
    '1개',
    $acceptedAddonStatus->technicalDetails()['승인'] ?? '',
    '애드온 상태에 승인 수량이 표시돼야 합니다.'
);
addonCompSame(
    '1개',
    $acceptedAddonStatus->technicalDetails()['런타임 준비 불필요'] ?? '',
    'onReady가 없는 승인 애드온은 준비 불필요로 구분돼야 합니다.'
);

$failedReadiness = new AddonRuntimeReadiness();
$failedReadiness->register('stub-addon', true);
$failedReadiness->markFailed('stub-addon');
$failedAddonStatus = (new AddonSystemStatusProvider($catalog, $failedReadiness))->current();
addonCompSame('danger', $failedAddonStatus->tone(), 'onReady 실패는 운영 상태에서 위험으로 보여야 합니다.');
addonCompAssert(
    str_contains(
        $failedAddonStatus->technicalDetails()['애드온 stub-addon'] ?? '',
        'addon_runtime_callback_failed'
    ),
    'onReady 실패의 안정적인 상태 코드가 운영 상태에 표시돼야 합니다.'
);

$rejectedCatalog = new AcceptedAddonCatalog();
$rejectedCatalog->commitAndFreeze([
    new AddonCatalogEntry('broken-addon', null, 'rejected', 'addon_registration_failed'),
]);
$rejectedAddonStatus = (new AddonSystemStatusProvider(
    $rejectedCatalog,
    new AddonRuntimeReadiness()
))->current();
addonCompSame('danger', $rejectedAddonStatus->tone(), '등록 거부 애드온은 위험 상태여야 합니다.');
addonCompAssert(
    str_contains(
        $rejectedAddonStatus->technicalDetails()['애드온 broken-addon'] ?? '',
        'addon_registration_failed'
    ),
    '관리자는 거부된 애드온의 안전한 상태 코드를 확인할 수 있어야 합니다.'
);

// --- Compose into real registries ---

$routes = new DefinitionRegistry('routes', RouteDefinition::class);
$menus = new DefinitionRegistry('menus', MenuDefinition::class);
$capabilities = new DefinitionRegistry('capabilities', CapabilityDefinition::class);
$features = new DefinitionRegistry('features', FeatureDefinition::class);
$routeHandlers = new RouteHandlerRegistry();
$addonPublicMenus = new AddonPublicMenuContributions();
$addonAiTasks = new AddonAiTaskContributions();
$consoleRouteAccessRules = new ConsoleRouteAccessRuleRegistry();
$auth = new FakeAuthorization();

$composer = new AddonContributionComposer(
    $features,
    new AddonCapabilityContributor($capabilities),
    new AddonRouteContributor(
        $routes,
        $routeHandlers,
        new FakeCurrentAccountProvider(),
        $auth,
        new AddonCorrelationIdFactory(),
        new FakeRouteUrlProvider(),
        new FakeConsoleResponder(),
        new FakePageResponder()
    ),
    new AddonMenuContributor($menus, $addonPublicMenus),
    new AddonAiTaskContributor($addonAiTasks),
    $consoleRouteAccessRules,
    $auth
);
$composer->compose($catalog);

addonCompSame(2, $routes->count(), '애드온 Route 2개(콘솔+공개)가 등록돼야 합니다.');
addonCompSame(1, $menus->count(), '애드온 콘솔 메뉴 1개가 등록돼야 합니다.');
addonCompSame(1, $capabilities->count(), '애드온 권한 1개가 등록돼야 합니다.');
addonCompSame(1, $features->count(), '애드온 공용 기능 1개가 등록돼야 합니다.');
addonCompSame(1, count($addonPublicMenus->all()), '애드온 공개 메뉴 1개가 등록돼야 합니다.');
addonCompSame(
    'oj_addon_stubns_view',
    $addonPublicMenus->capability('addon.stubns.public-menu'),
    '애드온 공개 메뉴가 선언 권한을 잃으면 안 됩니다.'
);
addonCompAssert($addonAiTasks->has('addon.stubns.summarize'), '애드온 AI 작업이 등록돼야 합니다.');

$consoleMenu = $menus->find('addon.stubns.console-menu');
addonCompAssert($consoleMenu instanceof MenuDefinition, '애드온 콘솔 메뉴가 등록돼야 합니다.');
addonCompSame(
    'classes',
    $consoleMenu->parent(),
    '애드온 콘솔 메뉴는 선언한 현재 core anchor를 그대로 유지해야 합니다.'
);
addonCompSame(
    ['oj_access_console', 'oj_addon_stubns_view'],
    $consoleMenu->capabilities(),
    '애드온 콘솔 메뉴는 콘솔 진입 권한과 애드온 권한을 함께 요구해야 합니다.'
);
addonCompSame(
    'all_capabilities',
    $consoleMenu->policy(),
    '애드온 콘솔 메뉴의 두 권한은 OR가 아니라 AND로 평가돼야 합니다.'
);

$capability = $capabilities->find('oj_addon_stubns_view');
addonCompAssert($capability instanceof CapabilityDefinition, '변환된 권한 키가 등록돼야 합니다.');
addonCompSame('addon_bridge', $capability->owner()->value(), '애드온 권한 소유자는 addon_bridge여야 합니다.');
addonCompSame(CapabilityScope::Global, $capability->scope(), '애드온 권한 범위는 global이어야 합니다.');

$consoleRoute = $routes->find('addon.stubns.dashboard');
addonCompAssert($consoleRoute instanceof RouteDefinition, '콘솔 Route가 등록돼야 합니다.');
addonCompSame('console', $consoleRoute->area()->value, '콘솔 페이지는 console 영역이어야 합니다.');
addonCompSame('addon_bridge', $consoleRoute->owner()->value(), '콘솔 Route 소유자는 addon_bridge여야 합니다.');

$publicRoute = $routes->find('addon.stubns.page');
addonCompAssert($publicRoute instanceof RouteDefinition, '공개 Route가 등록돼야 합니다.');
addonCompSame('public', $publicRoute->area()->value, '공개 페이지는 public 영역이어야 합니다.');

$handler = $routeHandlers->find('addon.stubns.dashboard');
addonCompAssert($handler instanceof AddonRouteHandler, '콘솔 Route Handler가 등록돼야 합니다.');

// --- Console route access rule: fails closed without capability, opens with it ---

$routeMatch = new RouteMatch($consoleRoute, []);
addonCompAssert(
    ! $consoleRouteAccessRules->allows(9, $routeMatch),
    '권한이 없으면 콘솔 문맥 권한 규칙이 거부해야 합니다.'
);
$auth->granted['oj_addon_stubns_view'] = true;
addonCompAssert(
    ! $consoleRouteAccessRules->allows(9, $routeMatch),
    '애드온 권한만 있고 콘솔 진입 권한이 없으면 거부해야 합니다.'
);
$auth->granted['oj_access_console'] = true;
addonCompAssert(
    $consoleRouteAccessRules->allows(9, $routeMatch),
    '콘솔 진입 권한과 애드온 권한을 모두 가지면 허용해야 합니다.'
);

// --- Dispatch through the handler itself: inline capability gate + real page ---

$auth->granted['oj_addon_stubns_view'] = false;
$request = new HttpRequest('GET', '/oj-console/addons/stub-addon/dashboard');
$forbidden = $handler->handle($routeMatch, $request);
addonCompSame(403, $forbidden->status(), '애드온 권한이 없으면 403을 반환해야 합니다.');

$auth->granted['oj_addon_stubns_view'] = true;
$auth->granted['oj_access_console'] = false;
$consoleForbidden = $handler->handle($routeMatch, $request);
addonCompSame(403, $consoleForbidden->status(), '콘솔 진입 권한이 없으면 직접 URL도 403이어야 합니다.');

$auth->granted['oj_access_console'] = true;
$ok = $handler->handle($routeMatch, $request);
addonCompSame(200, $ok->status(), '두 권한이 모두 있으면 200을 반환해야 합니다.');
addonCompAssert(
    str_contains($ok->body(), '스텁 본문'),
    '애드온 페이지 핸들러의 실제 본문이 응답에 담겨야 합니다.'
);
addonCompAssert(
    $pageHandler->lastRequest instanceof AddonPageRequestV1
    && $pageHandler->lastRequest->actorUserId() === 9,
    '애드온 핸들러가 실제 사용자 번호를 전달받아야 합니다.'
);

$publicHandler = $routeHandlers->find('addon.stubns.page');
addonCompAssert($publicHandler instanceof AddonRouteHandler, '공개 Route Handler가 등록돼야 합니다.');
$publicRouteMatch = new RouteMatch($publicRoute, []);
$publicRequest = new HttpRequest('GET', '/oj-addons/stub-addon/page');
$auth->granted['oj_addon_stubns_view'] = false;
addonCompSame(
    403,
    $publicHandler->handle($publicRouteMatch, $publicRequest)->status(),
    '공개 페이지도 애드온 권한이 없으면 직접 접근을 거부해야 합니다.'
);
$auth->granted['oj_addon_stubns_view'] = true;
$auth->granted['oj_access_console'] = false;
addonCompSame(
    200,
    $publicHandler->handle($publicRouteMatch, $publicRequest)->status(),
    '공개 페이지는 애드온 권한만으로 접근할 수 있어야 합니다.'
);

// --- Addon API 1.3: local navigation, layout and dark-mode markers ---

$navPageHandler = new class implements AddonPageHandlerV1 {
    public function handle(AddonPageRequestV1 $request): AddonPageResponseV1
    {
        return $request->routeKey() === 'addon.navns.bank'
            ? AddonPageResponseV1::page('문제은행', '<p>은행 본문</p>', 'immersive', true)
            : AddonPageResponseV1::html('수업 게임', '<p>게임 본문</p>');
    }
};
$navManifest = new AddonManifest(
    'navns',
    '1.0.0',
    __FILE__,
    '^2.0',
    '^1.3',
    '내비 애드온',
    'https://example.test/support',
    [
        new AddonCapabilityDefinitionV1('navns_teach', '수업 운영', 'educator'),
        new AddonCapabilityDefinitionV1('navns_bank', '문제은행', 'educator'),
    ],
    [
        new AddonConsolePageDefinitionV1('addon.navns.home', '/oj-console/addons/nav-addon', $navPageHandler, 'navns_teach'),
        new AddonConsolePageDefinitionV1('addon.navns.bank', '/oj-console/addons/nav-addon/bank', $navPageHandler, 'navns_bank'),
    ],
    [],
    [new AddonConsoleMenuDefinitionV1('addon.navns.menu', '내비 애드온', 'learning_support', 'addon.navns.home', 'navns_teach', 10)],
    [],
    [],
    [],
    [
        new AddonConsoleLocalNavItemV1('addon.navns.nav-bank', '문제은행', 'addon.navns.menu', 'addon.navns.bank', 20),
        new AddonConsoleLocalNavItemV1('addon.navns.nav-home', '수업 게임', 'addon.navns.menu', 'addon.navns.home', 10),
    ]
);
addonCompSame(2, $navManifest->contributionCounts()['console_local_nav'], '로컬 내비 수가 집계돼야 합니다.');
addonCompAssert(! array_key_exists('console_local_nav', $manifest->contributionCounts()), '로컬 내비를 쓰지 않는 애드온의 집계 키 목록은 1.2와 같아야 합니다.');
$navCatalog = new AcceptedAddonCatalog();
$navCatalog->commitAndFreeze([new AddonCatalogEntry('nav-addon', $navManifest, 'accepted', '')]);
$navRoutes = new DefinitionRegistry('routes', RouteDefinition::class);
$navHandlers = new RouteHandlerRegistry();
$navAuth = new FakeAuthorization();
$navResponder = new FakeConsoleResponder();
(new AddonContributionComposer(
    new DefinitionRegistry('features', FeatureDefinition::class),
    new AddonCapabilityContributor(new DefinitionRegistry('capabilities', CapabilityDefinition::class)),
    new AddonRouteContributor(
        $navRoutes,
        $navHandlers,
        new FakeCurrentAccountProvider(),
        $navAuth,
        new AddonCorrelationIdFactory(),
        new FakeRouteUrlProvider(),
        $navResponder,
        new FakePageResponder()
    ),
    new AddonMenuContributor(new DefinitionRegistry('menus', MenuDefinition::class), new AddonPublicMenuContributions()),
    new AddonAiTaskContributor(new AddonAiTaskContributions()),
    new ConsoleRouteAccessRuleRegistry(),
    $navAuth
))->compose($navCatalog);

$navAuth->granted = ['oj_access_console' => true, 'oj_addon_navns_teach' => true, 'oj_addon_navns_bank' => true];
$home = $navHandlers->find('addon.navns.home')->handle(
    new RouteMatch($navRoutes->find('addon.navns.home'), []),
    new HttpRequest('GET', '/oj-console/addons/nav-addon')
);
addonCompSame('addon.navns.menu', $navResponder->lastMenu, '메뉴 화면은 자기 메뉴를 강조해야 합니다.');
addonCompAssert(str_contains($home->body(), 'class="ojp-local-nav"'), '로컬 내비가 그려져야 합니다.');
addonCompAssert(
    strpos($home->body(), '수업 게임') < strpos($home->body(), '문제은행'),
    '로컬 내비는 order 순서여야 합니다.'
);
addonCompAssert(
    str_contains($home->body(), 'href="/addon.navns.home" class="is-current" aria-current="page"'),
    '현재 화면 구획에 현재 표시가 있어야 합니다.'
);
addonCompAssert(str_contains($home->body(), 'data-ojp-light-only'), 'html() 응답은 밝은 화면을 유지해야 합니다.');
addonCompAssert(! str_contains($home->body(), 'data-ojp-layout='), '기본 레이아웃 표식은 없어야 합니다.');

$bank = $navHandlers->find('addon.navns.bank')->handle(
    new RouteMatch($navRoutes->find('addon.navns.bank'), []),
    new HttpRequest('GET', '/oj-console/addons/nav-addon/bank')
);
addonCompSame('addon.navns.menu', $navResponder->lastMenu, '메뉴가 없는 하위 화면도 부모 메뉴를 강조해야 합니다.');
addonCompAssert(str_contains($bank->body(), 'data-ojp-layout="immersive"'), '몰입형 레이아웃 표식이 있어야 합니다.');
addonCompAssert(! str_contains($bank->body(), 'data-ojp-light-only'), '어두운 화면을 지원하는 페이지는 밝은 화면 표식이 없어야 합니다.');

$navAuth->granted['oj_addon_navns_bank'] = false;
$homeWithoutBank = $navHandlers->find('addon.navns.home')->handle(
    new RouteMatch($navRoutes->find('addon.navns.home'), []),
    new HttpRequest('GET', '/oj-console/addons/nav-addon')
);
addonCompAssert(
    ! str_contains($homeWithoutBank->body(), 'ojp-local-nav'),
    '갈 수 있는 구획이 하나뿐이면 로컬 내비를 그리지 않아야 합니다.'
);
addonCompThrowsInvalid(
    static fn () => AddonPageResponseV1::page('제목', '<p>본문</p>', 'fullscreen'),
    '알 수 없는 레이아웃은 거부해야 합니다.'
);

// --- AiProviderCatalog composite ---

$aiCatalog = new AiProviderCatalog($addonAiTasks);
addonCompAssert(
    in_array('addon.stubns.summarize', $aiCatalog->allTaskKeys(), true),
    '애드온 AI 작업이 전체 작업 목록에 있어야 합니다.'
);
addonCompAssert($aiCatalog->isAddonTask('addon.stubns.summarize'), '애드온 AI 작업으로 표시돼야 합니다.');
addonCompAssert(! $aiCatalog->isAddonTask('submission_feedback'), '핵심 AI 작업은 애드온으로 표시되면 안 됩니다.');
addonCompAssert($aiCatalog->supportsTask('addon.stubns.summarize'), '애드온 AI 작업을 지원해야 합니다.');
addonCompSame('스텁 요약', $aiCatalog->taskLabel('addon.stubns.summarize'), '애드온 AI 작업 이름이 그대로 나와야 합니다.');
addonCompSame(5, count(AiProviderCatalog::TASK_TYPES), '합성 프롬프트 검증을 포함한 핵심 AI 작업은 5개여야 합니다.');

// --- PermissionInstaller must exclude addon-owned capabilities from the admin auto-grant ---

$roles = new DefinitionRegistry('roles', RoleDefinition::class);
$fakeSync = new FakeRoleSynchronizer();
(new PermissionInstaller($capabilities, $roles, $fakeSync))->synchronizeRoles();
addonCompAssert(
    ! in_array('oj_addon_stubns_view', $fakeSync->capturedAdministratorCapabilities, true),
    '애드온 권한은 administrator 자동 부여 목록에서 제외돼야 합니다.'
);

// --- WordPressRoleSynchronizer must never strip a manually granted oj_addon_* capability ---

$GLOBALS['addon_comp_wordpress_roles'] = [
    'administrator' => new FakeWordPressRole([
        'oj_addon_stubns_view' => true,
        'oj_stale_core_capability' => true,
    ]),
];
(new WordPressRoleSynchronizer())->synchronize([], []);
$administrator = get_role('administrator');
addonCompAssert(
    $administrator instanceof FakeWordPressRole
    && ($administrator->capabilities['oj_addon_stubns_view'] ?? false) === true,
    '수동으로 부여한 애드온 권한은 동기화 후에도 유지돼야 합니다.'
);
addonCompAssert(
    ! isset($administrator->capabilities['oj_stale_core_capability']),
    '더 이상 원하지 않는 core 성격의 oj_ 권한은 여전히 정리돼야 합니다.'
);

// --- Multiple addon capability grants must coexist and revoke independently ---

$assignmentGateway = new WordPressAddonCapabilityAssignmentGateway();
$gamesCapability = AddonCapabilityKeyV1::wordpress('hustoj_edu_games_play');
$libraryCapability = AddonCapabilityKeyV1::wordpress('readcontents_library_view');
$assignmentGateway->grantToRole('administrator', $gamesCapability);
$assignmentGateway->grantToRole('administrator', $libraryCapability);
$administrator = get_role('administrator');
addonCompAssert(
    $administrator instanceof FakeWordPressRole
    && ($administrator->capabilities[$gamesCapability] ?? false) === true
    && ($administrator->capabilities[$libraryCapability] ?? false) === true,
    '서로 다른 애드온 권한을 같은 역할에 함께 부여할 수 있어야 합니다.'
);
addonCompSame(
    ['administrator'],
    $assignmentGateway->rolesGrantedTo($libraryCapability),
    '두 번째 애드온 권한의 역할 배정 상태를 읽을 수 있어야 합니다.'
);
$assignmentGateway->revokeFromRole('administrator', $gamesCapability);
addonCompAssert(
    $administrator instanceof FakeWordPressRole
    && ! isset($administrator->capabilities[$gamesCapability])
    && ($administrator->capabilities[$libraryCapability] ?? false) === true,
    '한 애드온 권한을 해제해도 다른 애드온 권한은 유지돼야 합니다.'
);

// --- PublicMenuSettingsService: addon entry surfaces, and a stale row is dropped after deactivation ---

function addonCompRegisterCoreRouteStubs(DefinitionRegistry $routes): void
{
    $coreRouteKeys = [
        'home', 'submissions.index', 'problems.index', 'quizzes.index', 'problem_books.index',
        'classes.index', 'contests.index', 'groups.index', 'board.index', 'notices.index', 'messaging.inbox', 'messaging.find',
        'learning_dashboard.index', 'research.index', 'ranking.index', 'auth.profile', 'auth.settings', 'auth.agreements',
    ];
    foreach ($coreRouteKeys as $key) {
        $routes->register(new RouteDefinition(
            $key,
            new \CodingPanda\OjPlatform\Kernel\Module\ModuleId('public_ui'),
            'core',
            '/' . str_replace('.', '/', $key),
            \CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteArea::PublicArea,
            ['GET'],
            \CodingPanda\OjPlatform\Modules\AccessRouter\Contract\AuthenticationRequirement::Authenticated,
            'html'
        ));
    }
}

$menuTestRoutes = new DefinitionRegistry('routes', RouteDefinition::class);
addonCompRegisterCoreRouteStubs($menuTestRoutes);
$menuTestRoutes->register($publicRoute);
$activeMenuSettings = new PublicMenuSettingsService(
    new FakeSettingsManager(['items' => []]),
    $menuTestRoutes,
    $routeHandlers,
    new FakeRouteUrlProvider(),
    new FakeAlwaysOnFeatureFlags(),
    new FakeAuthorization(),
    $addonPublicMenus
);
$defaultsWithAddon = $activeMenuSettings->defaults();
$hasAddonMenu = false;
foreach ($defaultsWithAddon as $entry) {
    if ($entry->key() === 'addon.stubns.public-menu') {
        $hasAddonMenu = true;
        addonCompSame(2, $entry->level(), '애드온 공개 메뉴는 항상 2차 메뉴여야 합니다.');
        addonCompSame('problems', $entry->parentKey(), '애드온 공개 메뉴의 상위는 core 앵커여야 합니다.');
    }
}
addonCompAssert($hasAddonMenu, 'defaults()에 애드온 공개 메뉴가 포함돼야 합니다.');

$activeEntries = $activeMenuSettings->entries();
$activeHasAddonMenu = false;
foreach ($activeEntries as $entry) {
    if ($entry->key() === 'addon.stubns.public-menu') {
        $activeHasAddonMenu = true;
    }
}
addonCompAssert($activeHasAddonMenu, '애드온이 켜져 있으면 entries()에도 애드온 메뉴가 나와야 합니다.');

$adminAuthorization = new FakeAuthorization();
$adminAuthorization->superAdmin = true;
$adminAuthorization->granted['oj_addon_stubns_view'] = true;
$adminMenuSettings = new PublicMenuSettingsService(
    new FakeSettingsManager(['items' => []]),
    $menuTestRoutes,
    $routeHandlers,
    new FakeRouteUrlProvider(),
    new FakeAlwaysOnFeatureFlags(),
    $adminAuthorization,
    $addonPublicMenus
);
$adminMenuHasAddon = false;
$adminUser = new UserIdentity(
    1,
    'administrator',
    'administrator@example.test',
    '관리자',
    ['administrator']
);
foreach ($adminMenuSettings->resolve($adminUser) as $rootItem) {
    foreach ($rootItem->children() as $childItem) {
        if ($childItem->key() === 'addon.stubns.public-menu') {
            $adminMenuHasAddon = true;
        }
    }
}
addonCompAssert(
    $adminMenuHasAddon,
    '시스템 관리자는 learner 범위의 애드온 사용자 메뉴를 미리 볼 수 있어야 합니다.',
);

$learnerAuthorization = new FakeAuthorization();
$learnerMenuSettings = new PublicMenuSettingsService(
    new FakeSettingsManager(['items' => []]),
    $menuTestRoutes,
    $routeHandlers,
    new FakeRouteUrlProvider(),
    new FakeAlwaysOnFeatureFlags(),
    $learnerAuthorization,
    $addonPublicMenus
);
$learner = new UserIdentity(
    77,
    'learner',
    'learner@example.test',
    '학습자',
    ['oj_learner']
);
$learnerHasAddon = static function () use ($learnerMenuSettings, $learner): bool {
    foreach ($learnerMenuSettings->resolve($learner) as $rootItem) {
        foreach ($rootItem->children() as $childItem) {
            if ($childItem->key() === 'addon.stubns.public-menu') {
                return true;
            }
        }
    }

    return false;
};
addonCompAssert(
    ! $learnerHasAddon(),
    'learner 범위만 충족하고 애드온 권한이 없으면 공개 메뉴가 보이면 안 됩니다.'
);
$learnerAuthorization->granted['oj_addon_stubns_view'] = true;
addonCompAssert(
    $learnerHasAddon(),
    'learner 범위와 애드온 권한을 모두 충족하면 공개 메뉴가 보여야 합니다.'
);

$storedWithAddonRow = [
    'items' => array_map(
        static fn ($entry) => $entry->toArray(),
        $defaultsWithAddon
    ),
];
$staleRoutes = new DefinitionRegistry('routes', RouteDefinition::class);
addonCompRegisterCoreRouteStubs($staleRoutes);
$deactivatedMenuSettings = new PublicMenuSettingsService(
    new FakeSettingsManager($storedWithAddonRow),
    $staleRoutes,
    new RouteHandlerRegistry(),
    new FakeRouteUrlProvider(),
    new FakeAlwaysOnFeatureFlags(),
    new FakeAuthorization(),
    new AddonPublicMenuContributions()
);
$entriesAfterDeactivation = $deactivatedMenuSettings->entries();
$staleStillPresent = false;
foreach ($entriesAfterDeactivation as $entry) {
    if ($entry->key() === 'addon.stubns.public-menu') {
        $staleStillPresent = true;
    }
}
addonCompAssert(
    ! $staleStillPresent,
    '애드온이 비활성화되면 저장돼 있던 애드온 메뉴 행은 조용히 제거돼야 합니다.'
);

// --- PublicMenuSettingsService must reject saving zero enabled top-level menus ---

$allDisabledRootRejected = false;
try {
    $activeMenuSettings->save(
        [
            new PublicMenuEntry(
                'home',
                '홈',
                1,
                null,
                'home',
                null,
                'all',
                1,
                'primary',
                false
            ),
        ],
        1
    );
} catch (DomainException $error) {
    $allDisabledRootRejected = str_contains($error->getMessage(), '활성화된 1차 메뉴');
}
addonCompAssert(
    $allDisabledRootRejected,
    '활성화된 1차 메뉴가 하나도 없으면 사용자 메뉴 저장을 거부해야 합니다.'
);

fwrite(
    STDOUT,
    "C28-I04 애드온 실연동 계약 검사 통과: Route 2개, 메뉴 1개, 권한 1개, AI 작업 1개, 콘솔 문맥 권한 fail-closed 확인\n"
);
