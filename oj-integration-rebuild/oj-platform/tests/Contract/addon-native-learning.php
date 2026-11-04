<?php
declare(strict_types=1);
use CodingPanda\OjPlatform\AddonApi\V1\AddonNativeLearningProviderV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonNativeLearningProviderDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonNativeLearningQueryV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonNativeLearningPageV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonNativeLearningItemV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonNativeLearningLinkV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonConsolePageDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPublicPageDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageHandlerV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageResponseV1;
use CodingPanda\OjPlatform\Kernel\Addon\AcceptedAddonCatalog;
use CodingPanda\OjPlatform\Kernel\Addon\AddonCatalogEntry;
use CodingPanda\OjPlatform\Kernel\Addon\AddonManifest;
use CodingPanda\OjPlatform\Kernel\Addon\NativeLearningCatalog;
use CodingPanda\OjPlatform\Kernel\Addon\AddonCorrelationIdFactory;
use CodingPanda\OjPlatform\Kernel\Addon\AddonRouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteUrlProvider;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccount;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentity;
use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;
use CodingPanda\OjPlatform\Modules\Permission\Contract\PermissionContext;
use CodingPanda\OjPlatform\Modules\PublicUi\Contract\PageResponder;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleResponder;
use CodingPanda\OjPlatform\Modules\Settings\Contract\FeatureFlags;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemCatalogService;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemAccessPolicy;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\ProblemHtml;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\Route\ProblemListPageHandler;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\Console\ProblemAdminListPageHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionUrlProvider;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity;
use CodingPanda\OjPlatform\Ui\Component\PaginationView;

$pluginRoot = dirname(__DIR__, 2);
require_once $pluginRoot . '/src/Kernel/Autoloader.php';
\CodingPanda\OjPlatform\Kernel\Autoloader::register($pluginRoot);
$checks = 0;
$assert = static function (bool $ok, string $message) use (&$checks): void { ++$checks; if (!$ok) { throw new RuntimeException($message); } };
$throws = static function (callable $fn) use ($assert): void { try { $fn(); } catch (InvalidArgumentException) { $assert(true, 'guard'); return; } $assert(false, '잘못된 외부 목록 DTO를 허용했습니다.'); };
$accounts = new class implements CurrentAccountProvider {
    public bool $loggedIn = true;
    public function current(): CurrentAccount { return new CurrentAccount($this->loggedIn ? new UserIdentity(7, 'actor', 'actor@example.test', '교사') : null, null); }
};
$permissions = new class implements Authorization {
    public bool $allowed = true;
    public bool $console = true;
    public function can(int $userId, string $capability, ?PermissionContext $context = null): bool { return $userId === 7 && ($capability === 'oj_access_console' ? $this->console : $this->allowed && $capability === 'oj_addon_view'); }
    public function canAny(int $userId, array $capabilities): bool { foreach ($capabilities as $cap) { if ($this->can($userId, $cap)) { return true; } } return false; }
    public function isSuperAdmin(int $userId): bool { return false; }
};
$urls = new class implements RouteUrlProvider {
    public bool $unsafe = false;
    public function path(string $routeName, array $parameters = []): string { return $this->unsafe ? '//outside.test' : '/registered/' . str_replace('.', '/', $routeName); }
};
$flags = new class implements FeatureFlags { public bool $enabled = true; public function isEnabled(string $featureKey): bool { return $this->enabled; } };
$handler = new class implements AddonPageHandlerV1 {
    public ?AddonPageRequestV1 $last = null;
    public function handle(AddonPageRequestV1 $request): AddonPageResponseV1 { $this->last = $request; return AddonPageResponseV1::html('화면', '본문'); }
};
$provider = new class implements AddonNativeLearningProviderV1 {
    public array $queries = [];
    public string $mode = 'normal';
    public function query(AddonNativeLearningQueryV1 $query): AddonNativeLearningPageV1 {
        $this->queries[] = $query;
        if ($this->mode === 'throw') { throw new RuntimeException('SECRET SQL CREDENTIAL'); }
        $link = new AddonNativeLearningLinkV1($this->mode === 'foreign_route' ? 'addon.other.page' : 'addon.native_test.page', '풀기', [], ['exerciseId' => '40', 'q' => 'a&b']);
        if ($this->mode === 'empty') { return new AddonNativeLearningPageV1([], null, [$link]); }
        $row = new AddonNativeLearningItemV1(($this->mode === 'foreign_id' ? 'other' : 'native_test') . ':exercise:40', '센서 & LED', '마이크로비트', $link, [], '읽기 전용 · 보관됨');
        if ($this->mode === 'too_many') { $rows=[]; for ($i=1; $i<=21; ++$i) { $rows[] = new AddonNativeLearningItemV1('native_test:exercise:' . $i, '문제', '유형', $link); } return new AddonNativeLearningPageV1($rows); }
        return new AddonNativeLearningPageV1([$row], $this->mode === 'bad_cursor' ? $query->beforeId() : ($query->beforeId() === 0 ? 40 : null));
    }
};
$definition = new AddonNativeLearningProviderDefinitionV1('addon.native_test.catalog', '마이크로비트', $provider);
$manifest = static function (array $definitions) use ($handler): AddonManifest { return new AddonManifest(
    'native_test', '1.0.0', __FILE__, '^2.5', '^1.9', '네이티브 목록', '', [],
    [new AddonConsolePageDefinitionV1('addon.native_test.teacher', '/oj-console/addons/native-test', $handler, 'view', [], 'classes.index')],
    [new AddonPublicPageDefinitionV1('addon.native_test.page', '/oj-addons/native-test', $handler, 'view', [], 'problems.index')],
    [], [], [], [], nativeLearningProviders: $definitions
); };
$catalog = new AcceptedAddonCatalog();
$catalog->commitAndFreeze([new AddonCatalogEntry('native-test', $manifest([$definition]), 'accepted', '')]);
$native = new NativeLearningCatalog($catalog, $accounts, $permissions, $urls, $flags);
$request = new HttpRequest('GET', '/problems', ['actorUserId' => 999, 'type' => $definition->key(), 'evil' => 'SECRET']);
$html = $native->problems($request);
$assert(str_contains($html, '센서 &amp; LED') && !str_contains($html, '센서 & LED'), '제목을 HTML escape해야 합니다.');
$assert(str_contains($html, 'exerciseId=40&amp;q=a%26b'), '등록 route만 사용하고 query를 안전하게 인코딩해야 합니다.');
$assert(str_contains($html, 'native_test:exercise:40') && str_contains($html, '보관됨'), '독립 ID와 보관 상태 메타데이터를 유지해야 합니다.');
$assert(!str_contains($html, 'SECRET'), '목록 페이지네이션에서 임의 query를 전달하지 않아야 합니다.');
$query = $provider->queries[0];
$assert($query->actorUserId() === 7 && $query->surface() === 'problems.public' && $query->limit() === 20, '현재 actor의 20개 한정 공개 목록을 조회해야 합니다.');
$assert(!$native->showsProgramming($request), '외부 문제만 선택하면 기본 문제 조회를 생략해야 합니다.');
$tabs = $native->problemFilters($request);
$nativePreview = $tabs . $html;
$assert(str_contains($tabs, 'ojp-tab is-active') && substr_count($tabs, 'aria-current="page"') === 1 && str_contains($tabs, '전체 유형') && str_contains($tabs, '프로그래밍'), '현재 유형만 공통 활성 스타일로 표시해야 합니다.');
$before = count($provider->queries);
$native->problems(new HttpRequest('GET', '/problems', ['type' => 'programming']));
$assert(count($provider->queries) === $before, '프로그래밍 유형에서 외부 제공자를 조회하지 않아야 합니다.');
$native->classAssignments(new HttpRequest('GET', '/classes/42'), 42, false);
$query = end($provider->queries);
$assert($query->contextId() === 42 && $query->surface() === 'class.student', '수업 목록은 한 수업 범위로 제공자를 한 번 조회해야 합니다.');
$before = count($provider->queries);
$native->classAssignments(new HttpRequest('GET', '/classes/42'), 42, true);
$assert(count($provider->queries) === $before + 1 && end($provider->queries)->surface() === 'class.teacher', '교사 수업 목록에서도 행별 추가 조회를 하지 않아야 합니다.');
$cursorKey = 'native_before_' . substr(hash('sha256', $definition->key()), 0, 10);
$native->problems(new HttpRequest('GET', '/problems', ['type' => $definition->key(), $cursorKey => '40']));
$assert(end($provider->queries)->beforeId() === 40, '제공자 전용 keyset cursor를 유지해야 합니다.');
foreach (['throw', 'foreign_id', 'foreign_route', 'too_many', 'bad_cursor'] as $mode) {
    $provider->mode = $mode;
    $html = $native->problems(new HttpRequest('GET', '/problems', ['type' => $definition->key(), $cursorKey => '40']));
    $assert(str_contains($html, '불러오지 못했습니다') && !str_contains($html, 'SECRET SQL'), '외부 오류와 경계 위반을 격리해야 합니다: ' . $mode);
}
$provider->mode = 'normal';
$urls->unsafe = true;
$assert(str_contains($native->problems($request), '불러오지 못했습니다'), '외부/프로토콜 상대 URL을 거부해야 합니다.');
$urls->unsafe = false;
$before = count($provider->queries);
$native->problems(new HttpRequest('GET', '/problems', [$cursorKey => '-1']));
$assert(count($provider->queries) === $before, '잘못된 cursor에서 제공자를 실행하지 않아야 합니다.');
$provider->mode = 'empty';
$assert(str_contains($native->classAssignments(new HttpRequest('GET', '/classes/42'), 42, true), '>풀기</a>'), '빈 수업 목록도 교사의 적용 작업을 표시해야 합니다.');
$provider->mode = 'normal';
foreach (['permission', 'feature', 'anonymous', 'console'] as $mode) {
    $before = count($provider->queries);
    if ($mode === 'permission') { $permissions->allowed = false; }
    if ($mode === 'feature') { $flags->enabled = false; }
    if ($mode === 'anonymous') { $accounts->loggedIn = false; }
    if ($mode === 'console') { $permissions->console = false; }
    $out = $mode === 'console' ? $native->classAssignments(new HttpRequest('GET', '/classes/42'), 42, true) : $native->problems($request);
    $assert($out === '' && count($provider->queries) === $before, '기능/권한이 없으면 제공자를 호출하지 않아야 합니다: ' . $mode);
    $permissions->allowed = true; $permissions->console = true; $flags->enabled = true; $accounts->loggedIn = true;
}
// A wrongly initialized core catalog would fail on any query. External-only rendering must never touch it.
$uninitialized = static fn(string $class): object => (new ReflectionClass($class))->newInstanceWithoutConstructor();
$pages = new class implements PageResponder, ConsoleResponder {
    public string $menu = ''; public ?HttpRequest $lastRequest = null;
    public function guest(string $title, string $content, HttpRequest $request, int $status = 200): HttpResponse { return $this->authenticated($title, $content, $request, $status); }
    public function authenticated(string $title, string $content, HttpRequest $request, int $status = 200): HttpResponse { $this->lastRequest = $request; return HttpResponse::html($content, $status); }
    public function respond(string $title, string $content, string $currentMenu, HttpRequest $request, int $status = 200): HttpResponse { $this->menu = $currentMenu; return $this->authenticated($title, $content, $request, $status); }
};
$route = $uninitialized(RouteMatch::class);
$public = new ProblemListPageHandler($pages, $uninitialized(ProblemCatalogService::class), $uninitialized(ProblemHtml::class), new PaginationView(), $native);
$assert($public->handle($route, $request)->status() === 200, '외부 유형만 선택한 공개 목록에서 기본 COUNT/OFFSET/metadata 조회를 모두 생략해야 합니다.');
$teacher = new ProblemAdminListPageHandler($pages, $accounts, $uninitialized(ProblemCatalogService::class), $uninitialized(ProblemAccessPolicy::class), new class implements ActionUrlProvider { public function actionUrl(): string { throw new RuntimeException('unused'); } public function ajaxUrl(): string { throw new RuntimeException('unused'); } }, new class implements ActionSecurity { public function hiddenFields(string $actionKey): string { throw new RuntimeException('unused'); } public function verify(string $actionKey, HttpRequest $request): bool { throw new RuntimeException('unused'); } }, $uninitialized(ProblemHtml::class), new PaginationView(), $native);
$assert($teacher->handle($route, $request)->status() === 200, '외부 유형만 선택한 교사 목록에서 기본 COUNT/OFFSET/metadata 조회를 모두 생략해야 합니다.');
$alias = (new HttpRequest('GET', '/oj-addons/native-test', ['x' => '1']))->withNavigationPath('/problems');
$assert($alias->path() === '/oj-addons/native-test' && $alias->navigationPath() === '/problems' && $alias->queryString('x') === '1', '메뉴 경로 별칭은 라우팅/권한/요청 데이터 경로를 변경하지 않아야 합니다.');
$throws(static fn() => new AddonNativeLearningQueryV1(0, 'problems.public'));
$throws(static fn() => new AddonNativeLearningQueryV1(7, 'class.student', 0));
$throws(static fn() => new AddonNativeLearningQueryV1(7, 'problems.public', 42));
$throws(static fn() => new AddonNativeLearningQueryV1(7, 'problems.public', 0, 0, 51));
$throws(static fn() => new AddonNativeLearningItemV1('40', '제목', '종류', new AddonNativeLearningLinkV1('addon.native_test.page', '풀기')));
$throws(static fn() => new AddonPageRequestV1('addon.native_test.page', 7, [], [], 'ko_KR', 'correlation', ['problems.index' => '//outside.test']));

// Four global providers are the maximum synchronous contribution work per native page.
$definitions = [];
for ($i = 1; $i <= 5; ++$i) { $definitions[] = new AddonNativeLearningProviderDefinitionV1('addon.native_test.catalog' . $i, '유형' . $i, $provider); }
$manyCatalog = new AcceptedAddonCatalog();
$manyCatalog->commitAndFreeze([new AddonCatalogEntry('native-test', $manifest($definitions), 'accepted', '')]);
$manyNative = new NativeLearningCatalog($manyCatalog, $accounts, $permissions, $urls, $flags);
$before = count($provider->queries);
$manyNative->problems(new HttpRequest('GET', '/problems'));
$assert(count($provider->queries) === $before + 4, '네이티브 목록은 전체 제공자 수가 증가해도 최대 네 번만 조회해야 합니다.');

// Fallback discoverability reuses actual core menu authority without adding any grant.
$menuRegistry = new \CodingPanda\OjPlatform\Kernel\Registry\DefinitionRegistry('menus', \CodingPanda\OjPlatform\Modules\AdminConsole\Contract\MenuDefinition::class);
$menuRegistry->register(new \CodingPanda\OjPlatform\Modules\AdminConsole\Contract\MenuDefinition('problems', '문제', null, 'first_accessible_child', null, [], 1, null));
$menuRegistry->register(new \CodingPanda\OjPlatform\Modules\AdminConsole\Contract\MenuDefinition('problems.index', '문제 목록', 'problems', 'console.problems.index', 'problems', ['oj_manage_problems'], 1, null));
$menuRegistry->register(new \CodingPanda\OjPlatform\Modules\AdminConsole\Contract\MenuDefinition('addon.native_test.fallback', '마이크로비트 문제', 'problems', 'addon.native_test.teacher', null, ['oj_access_console', 'oj_addon_view'], 2, 'all_capabilities', [], null, '', 'problems.index'));
$menuHandlers = new \CodingPanda\OjPlatform\Modules\AccessRouter\Application\RouteHandlerRegistry();
$menuHandlers->register('console.problems.index', $public);
$menuHandlers->register('addon.native_test.teacher', $public);
$menuPermissions = new class implements Authorization {
    public bool $native = false;
    public function can(int $userId, string $capability, ?PermissionContext $context = null): bool { return in_array($capability, ['oj_manage_problems', 'oj_manage_classes'], true) ? $this->native : in_array($capability, ['oj_access_console', 'oj_addon_view'], true); }
    public function canAny(int $userId, array $capabilities): bool { foreach ($capabilities as $cap) { if ($this->can($userId, $cap)) { return true; } } return false; }
    public function isSuperAdmin(int $userId): bool { return false; }
};
$menuResolver = new \CodingPanda\OjPlatform\Modules\AdminConsole\Navigation\ConsoleMenuResolver(new \CodingPanda\OjPlatform\Modules\AdminConsole\Application\MenuTree($menuRegistry), $menuHandlers, $urls, $flags, $menuPermissions, new \CodingPanda\OjPlatform\Modules\AdminConsole\Navigation\ConsoleMenuAccessRuleRegistry());
$resolved = $menuResolver->resolve(7);
$assert(count($resolved) === 1 && count($resolved[0]->children()) === 1 && $resolved[0]->children()[0]->key() === 'addon.native_test.fallback', '애드온 전용 교사는 기존 전용 권한으로 대체 메뉴를 사용할 수 있어야 합니다.');
$assert(!$menuPermissions->can(7, 'oj_manage_problems') && !$menuResolver->canAccessMenu(7, 'problems.index'), '대체 메뉴는 기본 문제 작성 또는 POST 권한을 부여하지 않아야 합니다.');
$menuPermissions->native = true;
$resolved = $menuResolver->resolve(7);
$assert(count($resolved[0]->children()) === 1 && $resolved[0]->children()[0]->key() === 'problems.index', '기본 교사는 문제 메뉴만 표시하고 중복 대체 메뉴를 숨겨야 합니다.');
$ownRoutes = ['addon.native_test.teacher' => ['capability' => 'oj_addon_view', 'console' => true], 'addon.native_test.page' => ['capability' => 'oj_addon_view', 'console' => false], 'addon.native_test.denied' => ['capability' => 'oj_forbidden', 'console' => true]];
$addonRoute = new AddonRouteHandler('addon.native_test.teacher', $handler, 'oj_addon_view', $accounts, $menuPermissions, new AddonCorrelationIdFactory(), $urls, $pages, null, 'addon.native_test.fallback', navigationRoutes: $ownRoutes, navigationMenuKey: 'problems.index', menuVisibility: $menuResolver);
$routeMatch = new RouteMatch($uninitialized(\CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteDefinition::class), []);
$addonRoute->handle($routeMatch, new HttpRequest('GET', '/oj-console/addons/native-test'));
$assert($pages->menu === 'problems.index', '기본 권한 교사의 실습 상세도 실제 문제 메뉴를 선택해야 합니다.');
$assert(isset($handler->last->navigationUrls()['problems.index']) && isset($handler->last->navigationUrls()['addon.native_test.page']) && !isset($handler->last->navigationUrls()['addon.native_test.denied']), '상세 화면에는 허용된 등록 경로만 제공해야 합니다.');
$menuPermissions->native = false;
$addonRoute->handle($routeMatch, new HttpRequest('GET', '/oj-console/addons/native-test'));
$assert($pages->menu === 'addon.native_test.fallback' && !isset($handler->last->navigationUrls()['problems.index']), '애드온 전용 교사의 상세는 접근할 수 있는 대체 메뉴를 선택해야 합니다.');
$publicRoute = new AddonRouteHandler('addon.native_test.page', $handler, 'oj_addon_view', $accounts, $permissions, new AddonCorrelationIdFactory(), $urls, null, $pages, null, navigationRoutes: $ownRoutes, navigationMenuKey: 'problems.index');
$publicRoute->handle($routeMatch, new HttpRequest('GET', '/oj-addons/native-test'));
$assert($pages->lastRequest->navigationPath() === '/problems' && $pages->lastRequest->path() === '/oj-addons/native-test', '공개 문제 상세는 실제 라우팅을 보존하며 문제 메뉴만 선택해야 합니다.');

// Exercise the actual contributor -> handler -> PageRequest producer. A DTO with
// manually prefixed URLs cannot catch missing WordPress transport adaptation.
if (!function_exists('home_url')) {
    function home_url(string $path = ''): string { return 'https://example.test' . ($GLOBALS['native_test_home_path'] ?? '') . '/' . ltrim($path, '/'); }
}
$browserHandler = new class implements AddonPageHandlerV1 {
    public ?AddonPageRequestV1 $last = null;
    public function handle(AddonPageRequestV1 $request): AddonPageResponseV1 {
        $this->last = $request;
        return AddonPageResponseV1::html('경로 검사', '<section id="native-config" data-config="' . htmlspecialchars(json_encode(['navigationUrls' => $request->navigationUrls(), 'query' => $request->query()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') . '"></section>');
    }
};
$menuRegistry->register(new \CodingPanda\OjPlatform\Modules\AdminConsole\Contract\MenuDefinition('classes', '수업', null, 'first_accessible_child', null, [], 1, null));
$menuRegistry->register(new \CodingPanda\OjPlatform\Modules\AdminConsole\Contract\MenuDefinition('classes.index', '수업 목록', 'classes', 'console.classes.index', 'classes', ['oj_manage_classes'], 1, null));
$menuHandlers->register('console.classes.index', $public);
$menuPermissions->native = true;
foreach (['', '/school'] as $homePath) {
    $GLOBALS['native_test_home_path'] = $homePath;
    $browserRoutes = new \CodingPanda\OjPlatform\Kernel\Registry\DefinitionRegistry('routes', \CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteDefinition::class);
    foreach (['problems.index' => '/problems', 'classes.index' => '/classes', 'console.problems.index' => '/oj-console/problems', 'console.classes.index' => '/oj-console/classes'] as $name => $routePath) {
        $browserRoutes->register(\CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteDefinition::fromArray(['name' => $name, 'owner_module' => 'public_ui', 'feature_key' => 'public_ui', 'path' => $routePath, 'area' => str_starts_with($name, 'console.') ? 'console' : 'public', 'methods' => ['GET'], 'authentication' => 'authenticated', 'response_type' => 'html']));
    }
    $browserGenerator = new \CodingPanda\OjPlatform\Modules\AccessRouter\Application\RouteUrlGenerator($browserRoutes);
    $browserMatcher = new \CodingPanda\OjPlatform\Modules\AccessRouter\Application\RouteMatcher($browserRoutes);
    $transport = new \CodingPanda\OjPlatform\Modules\AccessRouter\Infrastructure\WordPress\WordPressRouteTransport($browserMatcher);
    $browserUrls = new \CodingPanda\OjPlatform\Modules\AccessRouter\Infrastructure\WordPress\WordPressBrowserRouteUrlProvider($browserGenerator, $transport);
    $browserHandlers = new \CodingPanda\OjPlatform\Modules\AccessRouter\Application\RouteHandlerRegistry();
    $browserManifest = new AddonManifest('browser_test', '1.0.0', __FILE__, '^2.5', '^1.9', '경로 검사', 'example.test/browser', [],
        [new AddonConsolePageDefinitionV1('addon.browser_test.teacher', '/oj-console/addons/browser-test', $browserHandler, 'view', [], 'classes.index')],
        [new AddonPublicPageDefinitionV1('addon.browser_test.page', '/oj-addons/browser-test', $browserHandler, 'view', [], 'problems.index'), new AddonPublicPageDefinitionV1('addon.browser_test.denied', '/oj-addons/browser-test/denied', $browserHandler, 'forbidden')], [], [], [], []);
    $contributor = new \CodingPanda\OjPlatform\Kernel\Addon\AddonRouteContributor($browserRoutes, $browserHandlers, $accounts, $permissions, new AddonCorrelationIdFactory(), $browserUrls, $pages, $pages, menuVisibility: $menuResolver);
    $contributor->contribute($browserManifest);
    $browserRoutes->freeze();
    $browserRequest = new HttpRequest('GET', '/oj-addons/browser-test', ['mbView' => 'detail', 'exerciseId' => '40', 'actorUserId' => '999']);
    $browserResponse = $browserHandlers->find('addon.browser_test.page')->handle($browserMatcher->match('GET', '/oj-addons/browser-test'), $browserRequest);
    $produced = $browserHandler->last;
    $assert($produced->navigationUrls()['problems.index'] === $homePath . '/problems' && $produced->navigationUrls()['classes.index'] === $homePath . '/classes', '실제 PageRequest 생성 시 기본 메뉴 URL에 WordPress 설치 경로를 정확히 적용해야 합니다.');
    $assert($produced->navigationUrls()['addon.browser_test.page'] === $homePath . '/oj-addons/browser-test' && $produced->navigationUrls()['addon.browser_test.teacher'] === $homePath . '/oj-console/addons/browser-test', '실제 생성자는 현재 애드온의 public/console 페이지 URL에 같은 설치 경로를 적용해야 합니다.');
    $assert(!isset($produced->navigationUrls()['addon.browser_test.denied']) && !isset($produced->navigationUrls()['addon.native_test.page']) && $produced->actorUserId() === 7, '설치 경로 변환 후에도 권한·애드온 namespace·현재 actor 범위를 유지해야 합니다.');
    $scopedUrl = $produced->navigationUrls()['addon.browser_test.page'] . '?' . http_build_query(['exerciseId' => $produced->query()['exerciseId'], 'mbView' => $produced->query()['mbView']], '', '&', PHP_QUERY_RFC3986);
    $assert($transport->routeUrl($scopedUrl) === $homePath . '/oj-addons/browser-test?exerciseId=40&mbView=detail', '이미 변환된 URL의 query 범위를 유지하고 설치 경로를 두 번 붙이지 않아야 합니다.');
    $document = new DOMDocument();
    @$document->loadHTML($transport->rewriteHtml($browserResponse->body()));
    $payload = json_decode($document->getElementById('native-config')->getAttribute('data-config'), true, 512, JSON_THROW_ON_ERROR);
    $assert($payload['navigationUrls'] === $produced->navigationUrls() && $payload['query']['exerciseId'] === '40' && $payload['query']['mbView'] === 'detail', '최종 HTML transport를 거친 data-config JSON도 설치 경로와 문제 query를 보존해야 합니다.');
    $assert($pages->lastRequest->navigationPath() === '/problems' && $pages->lastRequest->path() === '/oj-addons/browser-test', '브라우저 URL만 prefix를 붙이고 shell 메뉴 및 실제 라우팅 경로는 내부 원래 경로를 유지해야 합니다.');
    $publicShell = $uninitialized(\CodingPanda\OjPlatform\Modules\PublicUi\Shell\PublicShell::class);
    $menuHtml = (new ReflectionMethod($publicShell, 'renderMenu'))->invoke($publicShell, [new \CodingPanda\OjPlatform\Modules\PublicUi\Navigation\PublicMenuItem('problems.index', '문제', '/problems'), new \CodingPanda\OjPlatform\Modules\PublicUi\Navigation\PublicMenuItem('classes.index', '수업', '/classes')], $pages->lastRequest->navigationPath());
    @$document->loadHTML('<?xml encoding="UTF-8">' . $transport->rewriteHtml($menuHtml));
    $menuXPath = new DOMXPath($document);
    $selectedMenu = $menuXPath->query('//a[@aria-current="page"]');
    $assert($selectedMenu->length === 1 && $selectedMenu->item(0)->textContent === '문제' && $selectedMenu->item(0)->getAttribute('href') === $homePath . '/problems', '최종 transport 이후에도 실제 공개 shell 메뉴 코드는 문제만 선택하고 올바른 설치 경로로 연결해야 합니다.');
    $browserHandlers->find('addon.browser_test.teacher')->handle($browserMatcher->match('GET', '/oj-console/addons/browser-test'), new HttpRequest('GET', '/oj-console/addons/browser-test', ['assignmentId' => '21', 'mbView' => 'results']));
    $assert($pages->menu === 'classes.index' && $pages->lastRequest->path() === '/oj-console/addons/browser-test' && $browserHandler->last->navigationUrls()['classes.index'] === $homePath . '/oj-console/classes' && $browserHandler->last->query()['assignmentId'] === '21', '하위 경로의 실제 교사 페이지도 수업 메뉴 선택, 내부 요청 경로와 과제 결과 범위를 보존해야 합니다.');

}
unset($GLOBALS['native_test_home_path']);
echo "Addon API 1.9 native catalog: {$checks} checks passed\n";

if (in_array('--render', $argv, true)) { file_put_contents(dirname($pluginRoot, 2) . '/_notes/tmp/native-catalog.html', '<!doctype html><html lang="ko"><meta charset="UTF-8"><link rel="stylesheet" href="../../oj-integration-rebuild/oj-platform/resources/ui/css/oj-ui-system.css"><body><main class="ojp-app">' . $nativePreview . '</main></body></html>'); }
