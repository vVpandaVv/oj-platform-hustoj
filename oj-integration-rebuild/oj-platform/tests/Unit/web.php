<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\Kernel\Hook\HookRegistrar;
use CodingPanda\OjPlatform\Kernel\Module\ModuleId;
use CodingPanda\OjPlatform\Kernel\Registry\DefinitionRegistry;
use CodingPanda\OjPlatform\Modules\AccessRouter\Application\RouteHandlerRegistry;
use CodingPanda\OjPlatform\Modules\AccessRouter\Application\RoutePatternCompiler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\AccessRouter\Application\RouteMatcher;
use CodingPanda\OjPlatform\Modules\AccessRouter\Application\SafeRedirectResolver;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionUrlProvider;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteDefinition;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AccessRouter\Infrastructure\WordPress\WordPressRewriteManager;
use CodingPanda\OjPlatform\Modules\AccessRouter\Infrastructure\WordPress\WordPressRouteTransport;
use CodingPanda\OjPlatform\Modules\Auth\Application\CurrentAccountService;
use CodingPanda\OjPlatform\Modules\Auth\Contract\RegistrationPolicyProvider;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserProfileRepository;
use CodingPanda\OjPlatform\Modules\Auth\Contract\WordPressUserGateway;
use CodingPanda\OjPlatform\Modules\Auth\Contract\AccessStatus;
use CodingPanda\OjPlatform\Modules\Auth\Domain\ApprovalMode;
use CodingPanda\OjPlatform\Modules\Auth\Domain\RegistrationPolicy;
use CodingPanda\OjPlatform\Modules\Auth\Domain\RegistrationRequirements;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentity;
use CodingPanda\OjPlatform\Modules\Auth\Domain\UserProfile;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\Route\LoginPageHandler;
use CodingPanda\OjPlatform\Modules\Permission\Application\PermissionService;
use CodingPanda\OjPlatform\Modules\Permission\Contract\CapabilityChecker;
use CodingPanda\OjPlatform\Modules\Permission\Contract\CapabilityDefinition;
use CodingPanda\OjPlatform\Modules\PublicUi\Access\ConsoleRouteAccessRuleRegistry;
use CodingPanda\OjPlatform\Modules\PublicUi\Access\PlatformRouteAccessPolicy;
use CodingPanda\OjPlatform\Modules\PublicUi\Contract\AccountLinkProvider;
use CodingPanda\OjPlatform\Modules\PublicUi\Contract\PageResponder as PageResponderContract;
use CodingPanda\OjPlatform\Modules\PublicUi\Presentation\PublicRouteFailureResponder;
use CodingPanda\OjPlatform\Modules\PublicUi\Shell\PublicShell;
use CodingPanda\OjPlatform\Modules\Settings\Application\FeatureService;
use CodingPanda\OjPlatform\Modules\Settings\Application\FeatureDisableGuardRegistry;
use CodingPanda\OjPlatform\Modules\Settings\Application\SettingsService;
use CodingPanda\OjPlatform\Modules\Settings\Contract\FeatureDisableGuard;
use CodingPanda\OjPlatform\Modules\Settings\Contract\FeatureDefinition;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingDefinition;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingStore;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SiteBrandingProvider;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SiteFooterProvider;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SystemOperationPolicy;
use CodingPanda\OjPlatform\Ui\Asset\UiAssetLocator;
use CodingPanda\OjPlatform\Ui\View\HtmlDocumentRenderer;

$pluginRoot = dirname(__DIR__, 2);
require_once $pluginRoot . '/src/Kernel/Autoloader.php';
\CodingPanda\OjPlatform\Kernel\Autoloader::register($pluginRoot);

$GLOBALS['web_wp_options'] = ['permalink_structure' => ''];
$GLOBALS['web_wp_query_vars'] = [];
$GLOBALS['web_wp_filters'] = [];
$GLOBALS['web_wp_rewrite_rules'] = [];
$GLOBALS['web_wp_rewrite_flushes'] = 0;

function get_option(string $name, mixed $default = false): mixed
{
    return $GLOBALS['web_wp_options'][$name] ?? $default;
}

function update_option(string $name, mixed $value, mixed $autoload = null): bool
{
    unset($autoload);
    $GLOBALS['web_wp_options'][$name] = $value;

    return true;
}

function add_option(
    string $name,
    mixed $value = '',
    string $deprecated = '',
    mixed $autoload = 'yes'
): bool {
    unset($deprecated, $autoload);
    if (array_key_exists($name, $GLOBALS['web_wp_options'])) {
        return false;
    }

    $GLOBALS['web_wp_options'][$name] = $value;

    return true;
}

function delete_option(string $name): bool
{
    unset($GLOBALS['web_wp_options'][$name]);

    return true;
}

function home_url(string $path = ''): string
{
    return 'https://example.test/wordpress/' . ltrim($path, '/');
}

function get_query_var(string $name, mixed $default = ''): mixed
{
    return $GLOBALS['web_wp_query_vars'][$name] ?? $default;
}

function add_filter(
    string $hook,
    callable $callback,
    int $priority = 10,
    int $acceptedArguments = 1
): bool {
    $GLOBALS['web_wp_filters'][$hook][] = [
        'callback' => $callback,
        'priority' => $priority,
        'accepted_arguments' => $acceptedArguments,
    ];

    return true;
}

function add_rewrite_rule(
    string $regex,
    string|array $query,
    string $after = 'bottom'
): void {
    $rule = ['pattern' => $regex, 'query' => $query];
    if ($after === 'top') {
        array_unshift($GLOBALS['web_wp_rewrite_rules'], $rule);

        return;
    }

    $GLOBALS['web_wp_rewrite_rules'][] = $rule;
}

function flush_rewrite_rules(bool $hard = true): void
{
    webAssert($hard, '설치 동기화는 웹 서버 rewrite 파일까지 갱신해야 합니다.');
    ++$GLOBALS['web_wp_rewrite_flushes'];
}

$webAssertions = 0;

function webAssert(bool $condition, string $message): void
{
    global $webAssertions;
    ++$webAssertions;

    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function webSame(mixed $expected, mixed $actual, string $message): void
{
    webAssert(
        $expected === $actual,
        $message
        . PHP_EOL
        . 'Expected: ' . var_export($expected, true)
        . PHP_EOL
        . 'Actual: ' . var_export($actual, true)
    );
}

function webHtmlProtection(HttpResponse $response, string $referrerPolicy): void
{
    foreach ([
        'Content-Type' => 'text/html; charset=UTF-8',
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'SAMEORIGIN',
        'Content-Security-Policy' => "frame-ancestors 'self';",
        'Referrer-Policy' => $referrerPolicy,
        'Cache-Control' => 'private, no-store',
    ] as $name => $value) {
        webSame($value, $response->headers()[$name] ?? null, 'HTML 응답 보호 헤더가 누락되거나 변경되었습니다: ' . $name);
    }
    webAssert(
        ! array_key_exists('Strict-Transport-Security', $response->headers()),
        'HSTS는 HTTPS 서버에서 관리하고 애플리케이션 HTML 응답에 추가하면 안 됩니다.'
    );
}

$htmlResponseBody = '<script>window.ojpReady = true;</script><p>수식과 화면 내용</p>';
foreach ([
    200 => HttpResponse::html($htmlResponseBody),
    401 => HttpResponse::unauthorized($htmlResponseBody),
    403 => HttpResponse::forbidden($htmlResponseBody),
    404 => HttpResponse::notFound($htmlResponseBody),
    405 => HttpResponse::html($htmlResponseBody, 405),
    500 => HttpResponse::html($htmlResponseBody, 500),
] as $expectedStatus => $protectedResponse) {
    webHtmlProtection($protectedResponse, 'strict-origin-when-cross-origin');
    webSame($expectedStatus, $protectedResponse->status(), 'HTML 보호 헤더 추가가 응답 상태를 변경하면 안 됩니다.');
    webSame($htmlResponseBody, $protectedResponse->body(), 'HTML 보호 헤더 추가가 인라인 스크립트나 본문을 변경하면 안 됩니다.');
}

$jsonBody = '{"ok":true}';
$jsonHeaders = ['Content-Type' => 'application/json; charset=UTF-8'];
$jsonResponse = new HttpResponse(202, $jsonHeaders, $jsonBody);
webSame(202, $jsonResponse->status(), 'JSON 응답 상태는 보존해야 합니다.');
webSame($jsonHeaders, $jsonResponse->headers(), 'HTML 기본 보호 정책이 JSON 응답 헤더를 변경하면 안 됩니다.');
webSame($jsonBody, $jsonResponse->body(), 'JSON 응답 본문은 보존해야 합니다.');
$downloadBody = "id,score\n1,100\n";
$downloadResponse = HttpResponse::download('scores.csv', 'text/csv', $downloadBody);
webSame(200, $downloadResponse->status(), '다운로드 응답 상태는 보존해야 합니다.');
webSame([
    'Content-Type' => 'text/csv; charset=UTF-8',
    'Content-Disposition' => 'attachment; filename="scores.csv"',
    'Cache-Control' => 'private, no-store',
    'X-Content-Type-Options' => 'nosniff',
], $downloadResponse->headers(), '다운로드의 콘텐츠 형식·첨부·캐시 보호 헤더는 보존해야 합니다.');
webSame($downloadBody, $downloadResponse->body(), '다운로드 파일 본문은 보존해야 합니다.');
$redirectResponse = HttpResponse::redirect('/login', 303);
webSame(303, $redirectResponse->status(), '리다이렉트 응답 상태는 보존해야 합니다.');
webSame(['Location' => '/login'], $redirectResponse->headers(), 'HTML 기본 보호 정책이 리다이렉트 헤더를 변경하면 안 됩니다.');
webSame('', $redirectResponse->body(), '리다이렉트 본문은 비어 있어야 합니다.');

final class WebMemoryUsers implements WordPressUserGateway
{
    public ?UserIdentity $current = null;

    public function currentUser(): ?UserIdentity
    {
        return $this->current;
    }

    public function findById(int $userId): ?UserIdentity
    {
        return $this->current?->userId() === $userId ? $this->current : null;
    }

    public function findByLogin(string $userLogin): ?UserIdentity
    {
        return $this->current?->userLogin() === $userLogin ? $this->current : null;
    }

    public function findByEmail(string $email): ?UserIdentity
    {
        return $this->current !== null
            && strcasecmp($this->current->email(), $email) === 0
                ? $this->current
                : null;
    }

    public function verifyCredentials(string $userLogin, string $password): ?UserIdentity
    {
        unset($userLogin, $password);

        return null;
    }

    public function establishSession(int $userId, bool $remember): void
    {
        unset($userId, $remember);
    }

    public function createUser(
        string $userLogin,
        string $email,
        string $password,
        string $displayName,
        string $role
    ): UserIdentity {
        unset($userLogin, $email, $password, $displayName, $role);

        throw new LogicException('이 검사에서는 사용자를 생성하지 않습니다.');
    }

    public function updateUser(
        int $userId,
        string $email,
        string $displayName
    ): UserIdentity {
        unset($userId, $email, $displayName);

        throw new LogicException('이 검사에서는 사용자를 수정하지 않습니다.');
    }

    public function setRoles(int $userId, array $roles): void
    {
        unset($userId, $roles);
    }

    public function setPassword(int $userId, string $password): void
    {
        unset($userId, $password);
    }

    public function invalidateSessions(int $userId): void
    {
        unset($userId);
    }

    public function requestPasswordReset(string $userLogin): void
    {
        unset($userLogin);
    }

    public function resetPasswordWithKey(
        string $userLogin,
        string $resetKey,
        string $newPassword
    ): void {
        unset($userLogin, $resetKey, $newPassword);
    }

    public function deleteUser(int $userId): void
    {
        unset($userId);
    }

    public function findMany(array $userIds): array
    {
        if (
            $this->current !== null
            && in_array($this->current->userId(), $userIds, true)
        ) {
            return [$this->current];
        }

        return [];
    }
}

final class WebMemoryProfiles implements UserProfileRepository
{
    /** @var array<int, UserProfile> */
    public array $items = [];

    public function find(int $userId): ?UserProfile
    {
        return $this->items[$userId] ?? null;
    }

    public function create(UserProfile $profile): void
    {
        $this->items[$profile->userId()] = $profile;
    }

    public function save(UserProfile $profile, ?int $changedByUserId = null): void
    {
        unset($changedByUserId);
        $this->items[$profile->userId()] = $profile;
    }

    public function recordLogin(int $userId): void
    {
        unset($userId);
    }

    public function saveRegistrationRequirements(
        int $userId,
        RegistrationRequirements $requirements
    ): void {
        unset($userId, $requirements);
    }

    public function registrationRequirements(
        int $userId
    ): ?RegistrationRequirements {
        unset($userId);

        return null;
    }

    public function findPage(int $limit, int $offset = 0): array
    {
        return array_slice(array_values($this->items), $offset, $limit);
    }

    public function findByStatus(
        AccessStatus $status,
        int $limit,
        int $offset = 0
    ): array {
        return array_slice(
            array_values(array_filter(
                $this->items,
                static fn (UserProfile $profile): bool => $profile->accessStatus() === $status
            )),
            $offset,
            $limit
        );
    }

    public function findFiltered(
        ?array $userIds,
        ?AccessStatus $status,
        int $limit,
        int $offset = 0
    ): array {
        if ($userIds === []) {
            return [];
        }

        return array_slice(array_values(array_filter(
            $this->items,
            static function (UserProfile $profile) use ($userIds, $status): bool {
                if ($userIds !== null && ! in_array($profile->userId(), $userIds, true)) {
                    return false;
                }

                return $status === null || $profile->accessStatus() === $status;
            }
        )), $offset, $limit);
    }

    public function countFiltered(?array $userIds, ?AccessStatus $status): int
    {
        if ($userIds === []) {
            return 0;
        }

        return count(array_filter(
            $this->items,
            static function (UserProfile $profile) use ($userIds, $status): bool {
                if ($userIds !== null && ! in_array($profile->userId(), $userIds, true)) {
                    return false;
                }

                return $status === null || $profile->accessStatus() === $status;
            }
        ));
    }
}

final class WebRegistrationPolicy implements RegistrationPolicyProvider
{
    public bool $enabled = false;

    public function current(): RegistrationPolicy
    {
        return new RegistrationPolicy(
            $this->enabled,
            ApprovalMode::Manual,
            'subscriber',
            ['terms', 'privacy'],
            false,
            14,
            24
        );
    }
}

final class WebSettingStore implements SettingStore
{
    /** @var array<string, mixed> */
    public array $values = [];

    public function read(string $optionName, mixed $default = null): mixed
    {
        return $this->values[$optionName] ?? $default;
    }

    public function write(string $optionName, mixed $value): bool
    {
        $this->values[$optionName] = $value;

        return true;
    }
}

final class WebCapabilityChecker implements CapabilityChecker
{
    /** @var array<int, list<string>> */
    public array $capabilities = [];

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
        unset($userId);

        return false;
    }
}

final class WebAccountLinks implements AccountLinkProvider
{
    public function logoutUrl(string $redirectPath = '/login'): string
    {
        unset($redirectPath);

        return '/oj-action';
    }
    public function logoutFields(): string
    {
        return '<input type="hidden" name="action" value="oj_logout">'
            . '<input type="hidden" name="_oj_nonce" value="logout-nonce">';
    }
}

final class WebBranding implements SiteBrandingProvider, \CodingPanda\OjPlatform\Modules\Settings\Contract\SiteFooterVisibilityProvider
{
    public array $visibility = [];

    public function footerLinkVisible(string $path): bool { return $this->visibility[$path] ?? true; }

    public function serviceName(): string
    {
        return 'Coding Panda';
    }

    public function shortDescription(): string
    {
        return 'Learning judge';
    }

    public function supportLine(): string
    {
        return 'Support';
    }

    public function copyrightNotice(): string
    {
        return '© 2026 Coding Panda';
    }

    public function administratorEmail(): string
    {
        return 'admin@example.test';
    }

    public function footerLinks(): array
    {
        return [
            ['label' => '도움말', 'url' => 'https://help.example.test'],
            ['label' => '중복 이용약관', 'url' => '/terms'],
        ];
    }
}

final class WebSystemOperationPolicy implements SystemOperationPolicy
{
    public function maintenanceEnabled(): bool
    {
        return false;
    }

    public function maintenanceMessage(): string
    {
        return '';
    }

    public function maintenanceEndsAt(): ?string
    {
        return null;
    }

    public function isModuleWriteFrozen(string $moduleKey): bool
    {
        unset($moduleKey);
        return false;
    }

    public function allowsAction(string $moduleKey, string $actionKey): bool
    {
        unset($moduleKey, $actionKey);
        return true;
    }

    public function allowsJob(string $moduleKey, string $jobKey): bool
    {
        unset($moduleKey, $jobKey);
        return true;
    }
}

final class WebAssets implements UiAssetLocator
{
    public function stylesheetUrl(): string
    {
        return '/assets/app.css';
    }

    public function scriptUrl(): string
    {
        return '/assets/app.js';
    }

    public function mathJaxLoaderUrl(): string
    {
        return '/assets/mathjax-loader.js';
    }

    public function iconSpriteUrl(): string
    {
        return '/assets/icons.svg';
    }

    public function faviconUrl(): string
    {
        return '/assets/favicon.svg';
    }
}

final class WebRouteHandler implements RouteHandler
{
    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        unset($route, $request);

        return HttpResponse::html('ok');
    }
}

final class WebPageResponder implements PageResponderContract
{
    public int $guestCalls = 0;
    public int $authenticatedCalls = 0;
    public ?int $guestStatus = null;
    public ?HttpResponse $lastGuestResponse = null;

    public function guest(
        string $title,
        string $content,
        HttpRequest $request,
        int $status = 200
    ): HttpResponse {
        unset($title, $request);
        ++$this->guestCalls;

        return $this->lastGuestResponse = HttpResponse::html($content, $this->guestStatus ?? $status);
    }

    public function authenticated(
        string $title,
        string $content,
        HttpRequest $request,
        int $status = 200
    ): HttpResponse {
        unset($title, $request);
        ++$this->authenticatedCalls;

        return HttpResponse::html($content, $status);
    }
}

final class WebActionUrls implements ActionUrlProvider
{
    public function actionUrl(): string
    {
        return '/oj-action';
    }

    public function ajaxUrl(): string
    {
        return '/oj-ajax';
    }
}

final class WebActionSecurity implements ActionSecurity
{
    public function hiddenFields(string $actionKey): string
    {
        return '<input type="hidden" name="action" value="' . $actionKey . '">';
    }

    public function verify(string $actionKey, HttpRequest $request): bool
    {
        unset($actionKey, $request);

        return true;
    }
}

final class WebRoutingHooks implements HookRegistrar
{
    /** @var array<string, list<callable>> */
    public array $actions = [];

    public function addAction(
        string $hook,
        callable $callback,
        int $priority = 10,
        int $acceptedArguments = 0
    ): void {
        unset($priority, $acceptedArguments);
        $this->actions[$hook][] = $callback;
    }
}

final class WebWpRewrite
{
    /** @var list<string> */
    public array $structures = [];

    public function set_permalink_structure(string $structure): void
    {
        $this->structures[] = $structure;
        update_option('permalink_structure', $structure, false);
    }
}

/**
 * @param DefinitionRegistry<RouteDefinition> $routes
 */
function webRegisterRoute(
    DefinitionRegistry $routes,
    string $name,
    string $path,
    string $feature,
    string $authentication,
    string $area = 'public'
): RouteDefinition {
    $route = RouteDefinition::fromArray([
        'name' => $name,
        'owner_module' => str_starts_with($name, 'console.')
            ? 'admin_console'
            : 'public_ui',
        'feature_key' => $feature,
        'path' => $path,
        'area' => $area,
        'methods' => ['GET', 'HEAD'],
        'authentication' => $authentication,
        'response_type' => 'html',
    ]);
    $routes->register($route);

    return $route;
}

$users = new WebMemoryUsers();
$profiles = new WebMemoryProfiles();
$registrationPolicy = new WebRegistrationPolicy();
$currentAccounts = new CurrentAccountService($users, $profiles);

$featureRegistry = new DefinitionRegistry('web-features', FeatureDefinition::class);
foreach (['auth', 'registration', 'home', 'problems', 'admin_console', 'groups'] as $featureKey) {
    $featureRegistry->register(new FeatureDefinition(
        $featureKey,
        strtoupper($featureKey),
        false,
        true,
        []
    ));
}
$settingRegistry = new DefinitionRegistry('web-settings', SettingDefinition::class);
$settingRegistry->register(new SettingDefinition(
    'feature_flags',
    'oj_platform_feature_flags',
    new ModuleId('settings'),
    false
));
$settingStore = new WebSettingStore();
$settings = new SettingsService($settingRegistry, $settingStore);
$disableGuards = new FeatureDisableGuardRegistry();
$disableGuards->register(new class implements FeatureDisableGuard {
    public function featureKey(): string { return 'groups'; }
    public function blockers(): array { return ['수업 #1 알고리즘 수업']; }
});
$features = new FeatureService($featureRegistry, $settings, $disableGuards);
try {
    $features->save(['groups' => false], 0);
    throw new RuntimeException('연결이 남은 Groups OFF 요청이 허용되었습니다.');
} catch (DomainException $error) {
    webAssert(
        str_contains($error->getMessage(), '수업 #1 알고리즘 수업'),
        '기능 비활성 차단 메시지는 정리할 대상 목록을 포함해야 합니다.'
    );
}

$capabilityRegistry = new DefinitionRegistry(
    'web-capabilities',
    CapabilityDefinition::class
);
$capabilityRegistry->register(CapabilityDefinition::fromArray([
    'key' => 'oj_access_console',
    'owner_module' => 'admin_console',
    'feature_key' => 'admin_console',
    'scope' => 'global',
    'label' => '관리자 화면 접근',
    'description' => '관리자 화면에 접근합니다.',
]));
$capabilityChecker = new WebCapabilityChecker();
$permissions = new PermissionService($capabilityRegistry, $capabilityChecker);
$webBranding = new WebBranding();
$shell = new PublicShell(
    new HtmlDocumentRenderer(new WebAssets()),
    new WebAccountLinks(),
    $registrationPolicy,
    $webBranding,
    $webBranding
);
$policy = new PlatformRouteAccessPolicy(
    $currentAccounts,
    $registrationPolicy,
    $features,
    $permissions,
    $shell,
    new ConsoleRouteAccessRuleRegistry(),
    ['console.dashboard' => ['oj_access_console']],
    new WebSystemOperationPolicy()
);

$routeRegistry = new DefinitionRegistry('web-routes', RouteDefinition::class);
$homeRoute = webRegisterRoute($routeRegistry, 'home', '/', 'home', 'authenticated');
$loginRoute = webRegisterRoute(
    $routeRegistry,
    'auth.login',
    '/login',
    'auth',
    'anonymous'
);
$registerRoute = webRegisterRoute(
    $routeRegistry,
    'auth.register',
    '/register',
    'registration',
    'anonymous'
);
$settingsRoute = webRegisterRoute(
    $routeRegistry,
    'auth.settings',
    '/user-settings',
    'auth',
    'authenticated'
);
$problemsRoute = webRegisterRoute(
    $routeRegistry,
    'problems.index',
    '/problems',
    'problems',
    'authenticated'
);
$consoleRoute = webRegisterRoute(
    $routeRegistry,
    'console.dashboard',
    '/oj-console',
    'admin_console',
    'authenticated',
    'console'
);

$loginPages = new WebPageResponder();
$loginHandler = new LoginPageHandler(
    $loginPages,
    new WebActionUrls(),
    new WebActionSecurity(),
    $registrationPolicy
);
$loginResponse = $loginHandler->handle(
    new RouteMatch($loginRoute, []),
    new HttpRequest('GET', '/login')
);
webAssert(
    str_contains($loginResponse->body(), 'href="/login?mode=forgot"')
        && ! str_contains($loginResponse->body(), 'href="/terms"'),
    '로그인 카드에는 계정 작업만 두고 이용약관 링크를 중복 표시하면 안 됩니다.'
);

foreach ([
    'default' => [[], 'name="operation" value="login"'],
    'forgot' => [['mode' => 'forgot'], 'name="operation" value="request"'],
    'reset' => [
        ['mode' => 'reset', 'login' => 'dummy-user', 'key' => 'dummy-reset&token'],
        'name="reset_key" value="dummy-reset&amp;token"',
    ],
    'transition' => [
        ['mode' => 'transition', 'transition' => 'dummy-transition&token'],
        'name="transition_token" value="dummy-transition&amp;token"',
    ],
] as $loginMode => [$loginQuery, $expectedFormField]) {
    $protectedLogin = $loginHandler->handle(
        new RouteMatch($loginRoute, []),
        new HttpRequest('GET', '/login', $loginQuery)
    );
    webHtmlProtection($protectedLogin, 'no-referrer');
    webSame('private, no-store', $protectedLogin->headers()['Cache-Control'] ?? null, '모든 로그인 모드는 브라우저·공유 캐시 저장을 막아야 합니다: ' . $loginMode);
    webSame('noindex, nofollow', $protectedLogin->headers()['X-Robots-Tag'] ?? null, '모든 로그인 모드는 검색 색인을 막아야 합니다: ' . $loginMode);
    webSame(200, $protectedLogin->status(), '로그인 보호 헤더 추가가 성공 응답 상태를 변경하면 안 됩니다: ' . $loginMode);
    webSame($loginPages->lastGuestResponse?->body(), $protectedLogin->body(), '로그인 보호 헤더 추가가 렌더러 본문을 변경하면 안 됩니다: ' . $loginMode);
    webAssert(str_contains($protectedLogin->body(), $expectedFormField), '요청한 로그인 모드의 폼과 더미 토큰을 렌더링해야 합니다: ' . $loginMode);
    foreach (['dummy-user', 'dummy-reset', 'dummy-transition'] as $queryValue) {
        webAssert(! str_contains(implode("\n", $protectedLogin->headers()), $queryValue), '계정·복구 토큰 쿼리를 응답 헤더에 포함하면 안 됩니다.');
    }
}
$loginPages->guestStatus = 503;
$loginErrorResponse = $loginHandler->handle(
    new RouteMatch($loginRoute, []),
    new HttpRequest('GET', '/login')
);
webSame(503, $loginErrorResponse->status(), '로그인 보호 헤더 추가 시 렌더러의 오류 상태를 보존해야 합니다.');
webSame($loginPages->lastGuestResponse?->body(), $loginErrorResponse->body(), '로그인 보호 헤더 추가 시 렌더러의 오류 본문을 보존해야 합니다.');
webHtmlProtection($loginErrorResponse, 'no-referrer');
$loginPages->guestStatus = null;

$jobExportRoute = RouteDefinition::fromArray([
    'name' => 'console.jobs.export',
    'owner_module' => 'admin_console',
    'feature_key' => 'jobs',
    'path' => '/oj-console/jobs/{job_id}/export',
    'area' => 'response',
    'methods' => ['GET'],
    'authentication' => 'authenticated',
    'response_type' => 'file',
]);
$guestJobExport = $policy->decide(
    new RouteMatch($jobExportRoute, ['job_id' => '1']),
    new HttpRequest('GET', '/oj-console/jobs/1/export')
);
webSame(
    401,
    $guestJobExport->response()?->status(),
    '비로그인 사용자의 파일·작업 응답 요청은 로그인 화면 대신 401을 반환해야 합니다.'
);

$guestHome = $policy->decide(
    new RouteMatch($homeRoute, []),
    new HttpRequest('GET', '/')
);
webSame(302, $guestHome->response()?->status(), '비로그인 홈은 로그인으로 이동해야 합니다.');
webSame(
    '/login',
    $guestHome->response()?->headers()['Location'] ?? null,
    '홈 로그인 이동 주소가 다릅니다.'
);

$settingStore->values['oj_platform_feature_flags'] = [
    'revision' => 1,
    'value' => ['problems' => false],
];
$guestDisabledFeature = $policy->decide(
    new RouteMatch($problemsRoute, []),
    new HttpRequest('GET', '/problems')
);
webSame(
    '/login?redirect_to=%2Fproblems',
    $guestDisabledFeature->response()?->headers()['Location'] ?? null,
    '꺼진 보호 기능도 비로그인 사용자는 로그인으로 먼저 이동해야 합니다.'
);

$registrationClosed = $policy->decide(
    new RouteMatch($registerRoute, []),
    new HttpRequest('GET', '/register')
);
webSame(404, $registrationClosed->response()?->status(), '닫힌 회원가입은 404여야 합니다.');

$guestLogin = $policy->decide(
    new RouteMatch($loginRoute, []),
    new HttpRequest('GET', '/login')
);
webAssert($guestLogin->isAllowed(), '비로그인 사용자는 로그인 화면에 접근해야 합니다.');

$identity = new UserIdentity(
    51,
    'learner',
    'learner@example.test',
    '학습자',
    ['subscriber']
);
$authenticatedShell = $shell->authenticated(
    '홈',
    '<p>학습 화면</p>',
    $identity,
    [],
    false,
    '/'
);
webAssert(
    str_contains(
        $authenticatedShell,
        '<form class="ojp-account-logout" method="post" action="/oj-action">'
    ) && str_contains(
        $authenticatedShell,
        'name="action" value="oj_logout"'
    ),
    '로그아웃은 플러그인 전용 nonce POST 폼으로 렌더링해야 합니다.'
);
webAssert(
    str_contains($authenticatedShell, '<script src="/assets/mathjax-loader.js" defer></script>'),
    '실제 HTML 문서는 문제 본문용 MathJax 로더를 포함해야 합니다.'
);
webAssert(
    ! str_contains($authenticatedShell, 'wp-login.php?action=logout'),
    '플러그인 화면에서 WordPress 기본 로그아웃 주소를 노출하면 안 됩니다.'
);
$users->current = $identity;
$profileLogin = $policy->decide(
    new RouteMatch($loginRoute, []),
    new HttpRequest('GET', '/login')
);
webSame(
    '/user-settings?mode=profile-completion',
    $profileLogin->response()?->headers()['Location'] ?? null,
    '프로필이 없는 로그인 사용자는 로그인 화면에서도 프로필 보완으로 이동해야 합니다.'
);
$profiles->items[51] = new UserProfile(
    51,
    null,
    null,
    AccessStatus::Active,
    false
);
$activeLogin = $policy->decide(
    new RouteMatch($loginRoute, []),
    new HttpRequest('GET', '/login')
);
webSame(
    '/',
    $activeLogin->response()?->headers()['Location'] ?? null,
    '활성 로그인 사용자는 로그인 화면에서 홈으로 이동해야 합니다.'
);

unset($profiles->items[51]);
$profileCompletion = $policy->decide(
    new RouteMatch($homeRoute, []),
    new HttpRequest('GET', '/')
);
webSame(
    '/user-settings?mode=profile-completion',
    $profileCompletion->response()?->headers()['Location'] ?? null,
    '프로필이 없는 WordPress 사용자는 프로필 보완으로 이동해야 합니다.'
);
$profileSettings = $policy->decide(
    new RouteMatch($settingsRoute, []),
    new HttpRequest('GET', '/user-settings')
);
webAssert($profileSettings->isAllowed(), '프로필 보완 대상은 설정 화면에 접근해야 합니다.');

$profiles->items[51] = new UserProfile(
    51,
    null,
    null,
    AccessStatus::Active,
    true
);
$passwordRequired = $policy->decide(
    new RouteMatch($homeRoute, []),
    new HttpRequest('GET', '/')
);
webAssert(
    $passwordRequired->isAllowed(),
    '비밀번호 변경 대상도 로그인 이후에는 다른 화면을 정상적으로 이용할 수 있어야 합니다.'
);
$passwordRequiredLogin = $policy->decide(
    new RouteMatch($loginRoute, []),
    new HttpRequest('GET', '/login')
);
webSame(
    '/',
    $passwordRequiredLogin->response()?->headers()['Location'] ?? null,
    '비밀번호 변경 대상이 로그인 화면에 다시 접근하면 홈으로 이동해야 합니다.'
);

$profiles->items[51] = new UserProfile(
    51,
    null,
    null,
    AccessStatus::Active,
    false
);
$consoleDenied = $policy->decide(
    new RouteMatch($consoleRoute, []),
    new HttpRequest('GET', '/oj-console')
);
webSame(403, $consoleDenied->response()?->status(), '권한 없는 관리자 화면은 403이어야 합니다.');

$capabilityChecker->capabilities[51] = ['oj_access_console'];
$consoleAllowed = $policy->decide(
    new RouteMatch($consoleRoute, []),
    new HttpRequest('GET', '/oj-console')
);
webAssert($consoleAllowed->isAllowed(), '관리자 화면 권한이 있으면 접근해야 합니다.');

$profiles->items[51] = new UserProfile(
    51,
    null,
    null,
    AccessStatus::Suspended,
    false
);
$suspended = $policy->decide(
    new RouteMatch($homeRoute, []),
    new HttpRequest('GET', '/')
);
webSame(403, $suspended->response()?->status(), '이용 중지 사용자는 서비스 접근이 차단되어야 합니다.');

$routeHandlers = new RouteHandlerRegistry();
$routeHandlers->register('home', new WebRouteHandler());
$routeHandlers->register('auth.settings', new WebRouteHandler());
$redirects = new SafeRedirectResolver(
    new RouteMatcher($routeRegistry),
    $routeHandlers
);
webSame(
    '/user-settings',
    $redirects->afterLogin('/user-settings?mode=password'),
    '로그인 후 이동은 등록된 보호 화면의 경로만 허용해야 합니다.'
);
webSame('/', $redirects->afterLogin('/problems'), '미구현 화면으로 이동하면 안 됩니다.');
webSame('/', $redirects->afterLogin('/login'), '익명 화면을 로그인 후 이동 대상으로 쓰면 안 됩니다.');
webSame('/', $redirects->afterLogin('https://example.test'), '외부 주소를 허용하면 안 됩니다.');
webSame('/', $redirects->afterLogin('//example.test'), '프로토콜 상대 주소를 허용하면 안 됩니다.');

$problemDetailRoute = webRegisterRoute(
    $routeRegistry,
    'problems.detail',
    '/problems/{problem_id}',
    'problems',
    'authenticated'
);
unset($problemDetailRoute);
$routePatterns = new RoutePatternCompiler();
$routingMatcher = new RouteMatcher($routeRegistry, $routePatterns);
$routeTransport = new WordPressRouteTransport($routingMatcher);
$problemMatch = $routingMatcher->match('GET', '/problems/1024');
webAssert($problemMatch !== null, '동적 문제 상세 Route를 찾을 수 있어야 합니다.');
webSame(
    '1024',
    $problemMatch?->parameters()['problem_id'] ?? null,
    '문제 번호를 상세 핸들러 인수로 전달해야 합니다.'
);
webSame(
    '/problems/1024',
    $routeTransport->requestPath(
        '/wordpress/?oj_route=%2Fproblems%2F1024',
        ['oj_route' => '/problems/1024']
    ),
    '이전 query 주소에서도 문제 번호가 포함된 Route 경로를 복원해야 합니다.'
);
webSame(
    '/wordpress/problems/1024',
    $routeTransport->canonicalRedirectUrl(),
    '이전 query 주소는 정식 문제 주소로 이동해야 합니다.'
);
webSame(
    '/wordpress/oj-console?section=jobs#current',
    $routeTransport->routeUrl('/oj-console?section=jobs#current'),
    '내부 링크는 WordPress 고유주소 설정과 무관하게 정식 경로여야 합니다.'
);
webSame(
    '/oj-console',
    $routeTransport->requestPath(
        '/wordpress/?oj_route=%2Foj-console&section=jobs',
        ['oj_route' => '/oj-console', 'section' => 'jobs']
    ),
    '이전 oj_route 요청을 정식 Route 경로로 복원해야 합니다.'
);
webSame(
    '/wordpress/oj-console?section=jobs',
    $routeTransport->canonicalRedirectUrl(),
    '이전 query 주소의 화면 필터를 정식 주소에 보존해야 합니다.'
);
webSame(
    '/_oj_invalid_route_',
    $routeTransport->requestPath('/', ['oj_route' => '/oj-console?bad=1']),
    '이전 query Route에는 경로 외 입력을 허용하면 안 됩니다.'
);
webSame(
    null,
    $routeTransport->canonicalRedirectUrl(),
    '잘못된 이전 query Route를 정식 주소로 이동하면 안 됩니다.'
);
$rewrittenHtml = $routeTransport->rewriteHtml(
    '<!doctype html><html><body><a href="/oj-console">관리</a>'
    . '<a href="/assets/app.css">자산</a></body></html>'
);
webAssert(
    str_contains($rewrittenHtml, 'href="/wordpress/oj-console"'),
    '하위 설치의 화면 링크는 정식 경로를 사용해야 합니다.'
);
webAssert(
    str_contains($rewrittenHtml, 'href="/assets/app.css"'),
    '등록되지 않은 자산 주소를 Route 주소로 바꾸면 안 됩니다.'
);

$directTransport = new WordPressRouteTransport($routingMatcher);
webSame(
    '/wordpress/oj-console',
    $directTransport->routeUrl('/oj-console'),
    '고유주소가 켜져 있으면 하위 디렉터리를 포함한 정식 경로를 생성해야 합니다.'
);
webSame(
    '/oj-console',
    $directTransport->requestPath('/wordpress/oj-console', []),
    '하위 디렉터리 설치의 요청 경로를 정식 Route 경로로 정규화해야 합니다.'
);

$GLOBALS['wp_rewrite'] = new WebWpRewrite();
$rewriteManager = new WordPressRewriteManager($routeRegistry, $routePatterns);
$routingHooks = new WebRoutingHooks();
$rewriteManager->registerHooks($routingHooks);
webSame(1, count($routingHooks->actions['init'] ?? []), 'rewrite 등록은 init 한 곳에서 실행해야 합니다.');
$queryVarFilter = $GLOBALS['web_wp_filters']['query_vars'][0]['callback'] ?? null;
webAssert(is_callable($queryVarFilter), 'oj_route query var 필터를 등록해야 합니다.');
webAssert(
    in_array('oj_route', $queryVarFilter([]), true),
    'WordPress 공개 query var에 oj_route가 포함되어야 합니다.'
);
$rewriteManager->registerAndSynchronize();
webSame(
    '/%postname%/',
    $GLOBALS['web_wp_options']['permalink_structure'] ?? null,
    '비어 있는 고유주소는 설치용 정식 경로 구조로 준비해야 합니다.'
);
webSame(
    ['/%postname%/'],
    $GLOBALS['wp_rewrite']->structures,
    'WordPress rewrite API로 고유주소 구조를 변경해야 합니다.'
);
webSame(1, $GLOBALS['web_wp_rewrite_flushes'], '새 Route 서명은 rewrite를 한 번 갱신해야 합니다.');
$rulesByPattern = [];
foreach ($GLOBALS['web_wp_rewrite_rules'] as $rule) {
    $rulesByPattern[$rule['pattern']] = $rule['query'];
}
webSame(
    'index.php?oj_route=/oj-console',
    $rulesByPattern['^oj\\-console/?$'] ?? null,
    '관리자 Route rewrite 규칙이 누락되었습니다.'
);
webSame(
    'index.php?oj_route=/problems/$matches[1]',
    $rulesByPattern['^problems/([^/]+)/?$'] ?? null,
    '동적 문제 Route rewrite 규칙이 누락되었습니다.'
);
$rewriteManager->registerAndSynchronize();
webSame(1, $GLOBALS['web_wp_rewrite_flushes'], '같은 Route 서명에서 rewrite를 반복 갱신하면 안 됩니다.');
update_option('permalink_structure', '/%post_id%/', false);
$rewriteManager->registerAndSynchronize();
webSame(
    '/%post_id%/',
    $GLOBALS['web_wp_options']['permalink_structure'] ?? null,
    '사용자가 선택한 숫자 고유주소 구조를 변경하면 안 됩니다.'
);
webSame(2, $GLOBALS['web_wp_rewrite_flushes'], '고유주소 구조 변경 시 Route rewrite를 다시 갱신해야 합니다.');

$errorPages = new WebPageResponder();
$routeFailures = new PublicRouteFailureResponder($errorPages, $currentAccounts);
$errorResponse = $routeFailures->unexpected(new HttpRequest('GET', '/problems'));
webSame(500, $errorResponse->status(), '예상하지 못한 Route 오류는 500으로 응답해야 합니다.');
webSame(
    1,
    $errorPages->authenticatedCalls,
    '로그인 Route 오류는 공통 사용자 셸을 사용해야 합니다.'
);
webAssert(
    str_contains($errorResponse->body(), '요청을 처리하지 못했습니다.'),
    'Route 오류 화면에 사용자 안내가 없습니다.'
);
$users->current = null;
$guestErrorResponse = $routeFailures->unexpected(new HttpRequest('GET', '/login'));
webSame(500, $guestErrorResponse->status(), '비로그인 Route 오류도 500으로 응답해야 합니다.');
webSame(
    1,
    $errorPages->guestCalls,
    '비로그인 Route 오류는 공통 비로그인 셸을 사용해야 합니다.'
);

foreach ([$shell->guest('로그인', '<h1>로그인</h1>'), $authenticatedShell] as $legalShell) {
    webSame(1, substr_count($legalShell, 'href="/open-source-licenses"'), '오픈소스 고지는 비로그인·로그인 공통 푸터에서 열 수 있어야 합니다.');
    webAssert(str_contains($legalShell, 'aria-label="약관 및 정책 문서"'), 'Both guest and authenticated pages expose legal navigation.');
    foreach (\CodingPanda\OjPlatform\Modules\Auth\Contract\AgreementDocumentType::cases() as $documentType) {
        webAssert(str_contains($legalShell, 'href="' . $documentType->publicPath() . '"'), 'All five legal documents remain discoverable.');
    }
    webSame(1, substr_count($legalShell, 'href="/terms"'), '기본 약관 주소와 같은 추가 링크는 중복 표시하면 안 됩니다.');
    webAssert(
        str_contains($legalShell, 'href="https://help.example.test" target="_blank" rel="noopener noreferrer"'),
        '관리자 추가 외부 링크는 안전한 새 창 속성과 함께 표시해야 합니다.'
    );
    webAssert(
        str_contains($legalShell, '© 2026 Coding Panda')
            && str_contains($legalShell, '>Support</span>')
            && str_contains($legalShell, 'href="mailto:admin@example.test"'),
        '공통 푸터에 저작권, 운영 안내, 관리자 이메일을 표시해야 합니다.'
    );
}

$webBranding->visibility = ['/terms' => false, '/open-source-licenses' => false];
foreach ([$shell->guest('로그인', '<h1>로그인</h1>'), $shell->authenticated('홈', '', $identity, [], false)] as $hiddenFooter) {
    webAssert(! str_contains($hiddenFooter, 'href="/terms"'), '숨긴 기본 링크를 중복 추가 링크가 되살리면 안 됩니다.');
    webAssert(! str_contains($hiddenFooter, 'href="/open-source-licenses"'), '오픈소스 하단 링크 표시 설정이 반영되어야 합니다.');
    webAssert(str_contains($hiddenFooter, 'href="/privacy"'), '선택하지 않은 다른 링크는 유지해야 합니다.');
}

$notificationProbe = new class implements \CodingPanda\OjPlatform\Modules\PublicUi\Contract\MessageNotificationProvider {
    public int $count = 123;
    public bool $enabled = true;
    public bool $fail = false;
    public function isEnabled(): bool { return $this->enabled; }
    public function unreadCount(int $userId): int {
        if ($this->fail) { throw new RuntimeException('notification storage unavailable'); }
        return $this->count;
    }
};
$shell->attachMessageNotifications($notificationProbe);
$notificationHtml = $shell->authenticated('홈', '', $identity, [], false);
webAssert(str_contains($notificationHtml, 'data-ojp-message-notification'), '상단 쪽지 진입점이 있어야 합니다.');
webAssert(str_contains($notificationHtml, '>99+</span>'), '읽지 않은 쪽지는 99+로 축약해야 합니다.');
webAssert(str_contains($notificationHtml, '읽지 않은 쪽지 123개'), '보조기기에 정확한 개수를 제공해야 합니다.');
webAssert(str_contains($notificationHtml, 'href="/messages/find"'), '프로필 메뉴에서 사용자를 찾을 수 있어야 합니다.');
$notificationProbe->count = 0;
$notificationHtml = $shell->authenticated('홈', '', $identity, [], false);
webAssert(str_contains($notificationHtml, 'data-ojp-message-count hidden'), '0개는 배지를 숨겨야 합니다.');
$notificationProbe->fail = true;
webAssert(str_contains($shell->authenticated('홈', '', $identity, [], false), 'ojp-main'), '알림 저장소 장애가 화면 전체를 막으면 안 됩니다.');
$notificationProbe->enabled = false;
webAssert(!str_contains($shell->authenticated('홈', '', $identity, [], false), 'data-ojp-message-notification'), '쪽지 비활성화 시 상단 진입점을 숨겨야 합니다.');

fwrite(
    STDOUT,
    sprintf("R-04 웹 접근 단위 검사 통과: assertion %d개\n", $webAssertions)
);
