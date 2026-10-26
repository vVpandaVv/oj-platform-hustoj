<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AccessRouter\Infrastructure\WordPress {
    function header(string $header, bool $replace = true, int $responseCode = 0): void
    {
        unset($replace, $responseCode);
        $GLOBALS['login_entry_emission'][] = ['header', $header];
    }

    function status_header(int $status): void
    {
        $GLOBALS['login_entry_emission'][] = ['status', $status];
    }

    function wp_safe_redirect(string $location, int $status = 302): bool
    {
        $GLOBALS['login_entry_emission'][] = ['redirect', $location, $status];
        throw new \LoginEntryEmissionStopped();
    }
}

namespace {
    use CodingPanda\OjPlatform\Kernel\Autoloader;
    use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
    use CodingPanda\OjPlatform\Kernel\Hook\HookRegistrar;
    use CodingPanda\OjPlatform\Kernel\Registry\DefinitionRegistry;
    use CodingPanda\OjPlatform\Modules\AccessRouter\Application\RouteHandlerRegistry;
    use CodingPanda\OjPlatform\Modules\AccessRouter\Application\RouteMatcher;
    use CodingPanda\OjPlatform\Modules\AccessRouter\Application\SafeRedirectResolver;
    use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
    use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
    use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ResponseEmitter;
    use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteDefinition;
    use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
    use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
    use CodingPanda\OjPlatform\Modules\AccessRouter\Infrastructure\WordPress\WordPressLoginGuard;
    use CodingPanda\OjPlatform\Modules\AccessRouter\Infrastructure\WordPress\WordPressResponseEmitter;
    use CodingPanda\OjPlatform\Modules\AccessRouter\Infrastructure\WordPress\WordPressRouteTransport;
    use CodingPanda\OjPlatform\Modules\Settings\Contract\FeatureFlags;

    $pluginRoot = dirname(__DIR__, 2);
    require_once $pluginRoot . '/src/Kernel/Autoloader.php';
    Autoloader::register($pluginRoot);

    $loginEntryAssertions = 0;
    $GLOBALS['login_entry_filters'] = [];
    $GLOBALS['login_entry_recovery'] = false;
    $GLOBALS['login_entry_logged_in'] = false;

    function loginEntrySame(mixed $expected, mixed $actual, string $message): void
    {
        ++$GLOBALS['loginEntryAssertions'];
        if ($expected !== $actual) {
            throw new RuntimeException($message . PHP_EOL . 'Expected: ' . var_export($expected, true) . PHP_EOL . 'Actual: ' . var_export($actual, true));
        }
    }

    function home_url(string $path = ''): string
    {
        return 'https://example.test/wordpress/' . ltrim($path, '/');
    }

    function wp_is_recovery_mode(): bool
    {
        return $GLOBALS['login_entry_recovery'];
    }

    function is_user_logged_in(): bool
    {
        return $GLOBALS['login_entry_logged_in'];
    }

    function wp_unslash(mixed $value): mixed
    {
        return is_array($value) ? array_map('wp_unslash', $value) : (is_string($value) ? stripslashes($value) : $value);
    }

    function add_filter(string $hook, callable $callback, int $priority = 10, int $acceptedArguments = 1): bool
    {
        $GLOBALS['login_entry_filters'][$hook][] = compact('callback', 'priority', 'acceptedArguments');
        return true;
    }

    function loginEntryFilter(string $hook, mixed ...$arguments): mixed
    {
        $registrations = $GLOBALS['login_entry_filters'][$hook] ?? [];
        loginEntrySame(1, count($registrations), '필터를 정확히 한 번 등록해야 합니다: ' . $hook);
        $registration = $registrations[0];
        return ($registration['callback'])(...array_slice($arguments, 0, $registration['acceptedArguments']));
    }

    function wp_login_url(string $redirect = '', bool $forceReauth = false): string
    {
        $url = 'https://example.test/wordpress/wp-login.php';
        foreach ($GLOBALS['login_entry_filters']['login_url'] ?? [] as $registration) {
            $arguments = [$url, $redirect, $forceReauth];
            $url = ($registration['callback'])(...array_slice($arguments, 0, $registration['acceptedArguments']));
        }
        return $url;
    }

    final class LoginEntryEmissionStopped extends RuntimeException {}

    final class LoginEntryEmitter implements ResponseEmitter
    {
        public ?HttpResponse $response = null;
        public bool $headRequest = false;
        public function emit(HttpResponse $response, bool $headRequest = false): void
        {
            $this->response = $response;
            $this->headRequest = $headRequest;
            throw new LoginEntryEmissionStopped();
        }
    }

    final class LoginEntryHooks implements HookRegistrar
    {
        public array $actions = [];
        public function addAction(string $hook, callable $callback, int $priority = 10, int $acceptedArguments = 0): void
        {
            $this->actions[$hook][] = compact('callback', 'priority', 'acceptedArguments');
        }
    }

    final class LoginEntryFeatures implements FeatureFlags
    {
        public bool $enabled = true;
        public bool $fail = false;
        public function isEnabled(string $featureKey): bool
        {
            loginEntrySame('auth', $featureKey, 'OJ 인증 기능 상태를 확인해야 합니다.');
            if ($this->fail) {
                throw new RuntimeException('Feature storage unavailable');
            }
            return $this->enabled;
        }
    }

    final class LoginEntryErrors implements ErrorReporter
    {
        public array $errors = [];
        public function report(Throwable $error): void { $this->errors[] = $error; }
    }

    function loginEntryFixture(?array $allowedPaths = null, bool $enabled = true, bool $fail = false): array
    {
        $GLOBALS['login_entry_filters'] = [];
        $routes = new DefinitionRegistry('login-entry-routes', RouteDefinition::class);
        $handlers = new RouteHandlerRegistry();
        $handler = new class implements RouteHandler {
            public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
            {
                unset($route, $request);
                return HttpResponse::html('ok');
            }
        };
        foreach (['/' => 'home', '/login' => 'auth.login', '/register' => 'auth.register', '/problems' => 'problems.index'] as $path => $name) {
            $routes->register(RouteDefinition::fromArray([
                'name' => $name,
                'owner_module' => 'auth',
                'feature_key' => 'auth',
                'path' => $path,
                'area' => 'public',
                'methods' => ['GET', 'HEAD'],
                'authentication' => in_array($path, ['/login', '/register'], true) ? 'anonymous' : 'authenticated',
                'response_type' => 'html',
            ]));
            $handlers->register($name, $handler);
        }
        $matcher = new RouteMatcher($routes);
        $transport = new WordPressRouteTransport($matcher);
        $emitter = new LoginEntryEmitter();
        $features = new LoginEntryFeatures();
        $features->enabled = $enabled;
        $features->fail = $fail;
        $errors = new LoginEntryErrors();
        $hooks = new LoginEntryHooks();
        $guard = new WordPressLoginGuard($emitter, $features, new SafeRedirectResolver($matcher, $handlers), $errors);
        $guard->registerHooks($hooks, $allowedPaths);
        return [$guard, $emitter, $hooks, $errors, $transport];
    }

    function loginEntryRequest(LoginEntryHooks $hooks, LoginEntryEmitter $emitter, string $action, string $method = 'GET', array $query = [], array $body = []): ?HttpResponse
    {
        $emitter->response = null;
        $emitter->headRequest = false;
        $GLOBALS['action'] = $action;
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI'] = '/wordpress/wp-login.php';
        $_GET = $query;
        $_POST = $body;
        $_REQUEST = array_replace($query, $body);
        try {
            ($hooks->actions['login_init'][0]['callback'])();
        } catch (LoginEntryEmissionStopped) {
        }
        return $emitter->response;
    }

    function loginEntryRedirect(?HttpResponse $response, string $location, int $status = 302): void
    {
        loginEntrySame($status, $response?->status(), 'WordPress 로그인 진입은 요청 방식에 맞게 이동해야 합니다.');
        loginEntrySame($location, $response?->headers()['Location'] ?? null, 'OJ 로그인 이동 주소가 다릅니다.');
        loginEntrySame('private, no-store', $response?->headers()['Cache-Control'] ?? null, '인증 이동 응답은 저장하지 않아야 합니다.');
        loginEntrySame('no-referrer', $response?->headers()['Referrer-Policy'] ?? null, '복구 키가 referrer로 유출되면 안 됩니다.');
        loginEntrySame('noindex, nofollow', $response?->headers()['X-Robots-Tag'] ?? null, '인증 이동 응답의 색인을 막아야 합니다.');
        loginEntrySame('', $response?->body(), '이동 응답에 로그인 폼이나 자격 증명을 출력하면 안 됩니다.');
    }

    [$guard, $emitter, $hooks, $errors, $transport] = loginEntryFixture();
    loginEntrySame(1, count($hooks->actions['login_init'] ?? []), '기본 로그인 처리보다 먼저 login_init을 등록해야 합니다.');
    loginEntrySame(0, $hooks->actions['login_init'][0]['priority'] ?? null, '기본 로그인 우회를 막도록 우선순위 0에서 처리해야 합니다.');
    foreach (['login', 'interim-login', 'reauth', 'customize', 'unknown-action'] as $action) {
        loginEntryRedirect(loginEntryRequest($hooks, $emitter, $action, 'GET', ['interim-login' => '1', 'reauth' => '1', 'customize-login' => '1']), '/login');
    }
    loginEntryRedirect(loginEntryRequest($hooks, $emitter, 'login', 'HEAD'), '/login');
    loginEntrySame(true, $emitter->headRequest, 'HEAD 진입은 emitter에 HEAD 요청으로 전달해야 합니다.');
    foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
        $response = loginEntryRequest($hooks, $emitter, 'login', $method, [], ['log' => 'dummy-user', 'pwd' => 'dummy-password', 'rememberme' => 'forever']);
        loginEntryRedirect($response, '/login', 303);
        loginEntrySame(false, str_contains(implode("\n", $response?->headers() ?? []), 'dummy-'), '자격 증명을 OJ 이동 주소나 헤더로 전달하면 안 됩니다.');
    }
    foreach (['logout', 'postpass', 'confirmaction'] as $action) {
        loginEntrySame(null, loginEntryRequest($hooks, $emitter, $action, 'POST'), '비인증용 WordPress 코어 작업은 유지해야 합니다: ' . $action);
    }
    loginEntryRedirect(loginEntryRequest($hooks, $emitter, 'confirm_admin_email'), '/login');
    $GLOBALS['login_entry_logged_in'] = true;
    loginEntrySame(null, loginEntryRequest($hooks, $emitter, 'confirm_admin_email'), '관리자 이메일 확인은 로그인한 사용자에게 코어 처리를 허용해야 합니다.');
    $GLOBALS['login_entry_logged_in'] = false;
    foreach (['logout', 'postpass', 'confirmaction'] as $rawAction) {
        loginEntryRedirect(loginEntryRequest($hooks, $emitter, 'login', 'GET', ['action' => $rawAction]), '/login');
        loginEntryRedirect(loginEntryRequest($hooks, $emitter, 'login', 'POST', [], ['action' => $rawAction]), '/login', 303);
    }
    loginEntryRedirect(loginEntryRequest($hooks, $emitter, 'enter_recovery_mode', 'GET', ['action' => 'enter_recovery_mode', 'rm_key' => 'dummy-key', 'rm_token' => 'dummy-token']), '/login');
    foreach (['lostpassword', 'retrievepassword'] as $action) {
        loginEntryRedirect(loginEntryRequest($hooks, $emitter, $action), '/login?mode=forgot');
        loginEntryRedirect(loginEntryRequest($hooks, $emitter, $action, 'POST', [], ['user_login' => 'dummy-user']), '/login?mode=forgot', 303);
    }
    $resetQuery = ['key' => 'dummy-key&= value', 'login' => 'dummy-user+name', 'pwd' => 'dummy-password', 'redirect_to' => 'https://outside.test'];
    foreach (['rp', 'resetpass'] as $action) {
        foreach (['GET', 'HEAD'] as $method) {
            $response = loginEntryRequest($hooks, $emitter, $action, $method, $resetQuery);
            $location = $response?->headers()['Location'] ?? '';
            parse_str((string) parse_url($location, PHP_URL_QUERY), $resetParameters);
            ksort($resetParameters);
            loginEntrySame(['key' => $resetQuery['key'], 'login' => $resetQuery['login'], 'mode' => 'reset'], $resetParameters, '복구 이동에는 mode, key, login만 보존해야 합니다.');
            loginEntrySame(false, str_contains($location, '+'), '복구 쿼리는 RFC3986 방식으로 공백과 +를 구분해야 합니다.');
            loginEntryRedirect($response, $location);
            loginEntrySame('/login', parse_url($location, PHP_URL_PATH), '이동 응답은 emitter가 변환할 OJ 복구 경로를 전달해야 합니다.');
        }
        loginEntryRedirect(loginEntryRequest($hooks, $emitter, $action, 'POST', $resetQuery, ['pass1' => 'dummy-password']), '/login?mode=forgot', 303);
        foreach ([[], ['key' => 'dummy-key'], ['login' => 'dummy-user'], ['key' => ['dummy-key'], 'login' => 'dummy-user']] as $invalidResetQuery) {
            loginEntryRedirect(loginEntryRequest($hooks, $emitter, $action, 'GET', $invalidResetQuery), '/login?mode=forgot');
        }
    }
    loginEntryRedirect(loginEntryRequest($hooks, $emitter, 'register'), '/register');
    loginEntryRedirect(loginEntryRequest($hooks, $emitter, 'login', 'GET', ['redirect_to' => '/problems']), '/login?redirect_to=%2Fproblems');
    foreach (['/wp-admin/', 'https://outside.test/', '//outside.test/', 'https://example.test/wordpress/wp-admin/'] as $candidate) {
        loginEntryRedirect(loginEntryRequest($hooks, $emitter, 'login', 'GET', ['redirect_to' => $candidate]), '/login');
    }
    loginEntryRedirect(loginEntryRequest($hooks, $emitter, 'login', 'GET', ['redirect_to' => ['/problems']]), '/login');
    loginEntrySame([], $errors->errors, '정상 로그인 진입과 emitter 종료는 기능 조회 오류로 기록하면 안 됩니다.');
    foreach (['login_url', 'lostpassword_url', 'register_url'] as $urlFilter) {
        loginEntrySame([], $GLOBALS['login_entry_filters'][$urlFilter] ?? [], '코어 메일과 링크의 기본 WordPress URL 생성을 변경하면 안 됩니다: ' . $urlFilter);
    }
    loginEntrySame('https://example.test/wordpress/wp-login.php', wp_login_url(), '코어 메일이 action을 덧붙일 wp_login_url()의 원래 주소를 유지해야 합니다.');
    foreach ([
        ['action' => 'enter_recovery_mode', 'rm_token' => 'dummy-token', 'rm_key' => 'dummy-key'],
        ['action' => 'confirmaction', 'request_id' => '1', 'confirm_key' => 'dummy-key'],
    ] as $mailParameters) {
        $mailUrl = wp_login_url() . '?' . http_build_query($mailParameters, '', '&', PHP_QUERY_RFC3986);
        loginEntrySame('/wordpress/wp-login.php', parse_url($mailUrl, PHP_URL_PATH), '코어 복구·개인정보 확인 메일은 WordPress action 처리 주소를 유지해야 합니다.');
        parse_str((string) parse_url($mailUrl, PHP_URL_QUERY), $actualParameters);
        loginEntrySame($mailParameters, $actualParameters, '코어 복구·개인정보 확인 메일의 action과 검증 인수를 보존해야 합니다.');
    }
    loginEntrySame(false, loginEntryFilter('wp_auth_check_same_domain', true), 'WordPress 재로그인 팝업이 기본 로그인 폼을 다시 열면 안 됩니다.');

    [, $limitedEmitter, $limitedHooks] = loginEntryFixture(['/login']);
    loginEntryRedirect(loginEntryRequest($limitedHooks, $limitedEmitter, 'register'), '/login');
    loginEntrySame([], $GLOBALS['login_entry_filters']['register_url'] ?? [], '제한 런타임도 코어 회원가입 URL 생성은 바꾸지 않아야 합니다.');

    foreach ([[false, false], [true, true]] as [$enabled, $fail]) {
        [, , $inactiveHooks, $inactiveErrors] = loginEntryFixture(null, $enabled, $fail);
        loginEntrySame([], $inactiveHooks->actions, '인증 비활성화 또는 조회 오류에서 WordPress 복구 진입을 유지해야 합니다.');
        loginEntrySame([], $GLOBALS['login_entry_filters'], '인증 비활성화 또는 조회 오류에서는 링크 필터도 등록하면 안 됩니다.');
        if ($fail) { loginEntrySame(1, count($inactiveErrors->errors), '인증 기능 조회 오류는 진단에 기록해야 합니다.'); }
    }
    $GLOBALS['login_entry_recovery'] = true;
    [, , $recoveryHooks] = loginEntryFixture();
    loginEntrySame([], $recoveryHooks->actions, 'WordPress가 검증한 복구 모드에서는 코어 로그인 진입을 보존해야 합니다.');
    loginEntrySame([], $GLOBALS['login_entry_filters'], '검증된 복구 모드에서는 링크 필터도 등록하지 않아야 합니다.');
    $GLOBALS['login_entry_recovery'] = false;

    $GLOBALS['login_entry_emission'] = [];
    try {
        (new WordPressResponseEmitter($transport))->emit(new HttpResponse(303, [
            'Location' => '/login',
            'Cache-Control' => 'private, no-store',
            'Referrer-Policy' => 'no-referrer',
            'X-Robots-Tag' => 'noindex, nofollow',
        ], ''));
    } catch (LoginEntryEmissionStopped) {
    }
    loginEntrySame([
        ['header', 'Cache-Control: private, no-store'],
        ['header', 'Referrer-Policy: no-referrer'],
        ['header', 'X-Robots-Tag: noindex, nofollow'],
        ['redirect', '/wordpress/login', 303],
    ], $GLOBALS['login_entry_emission'], '리다이렉트 emitter도 보호 헤더를 먼저 보내고 WordPress 안전 이동을 호출해야 합니다.');

    define('OJ_PLATFORM_ALLOW_WORDPRESS_LOGIN', true);
    [, , $emergencyHooks] = loginEntryFixture();
    loginEntrySame([], $emergencyHooks->actions, '명시적인 서버 복구 상수는 WordPress 로그인 진입을 허용해야 합니다.');
    loginEntrySame([], $GLOBALS['login_entry_filters'], '서버 복구 상수가 켜지면 링크 필터도 남기면 안 됩니다.');
    fwrite(STDOUT, sprintf("WordPress 로그인 진입 단위 검사 통과: assertion %d개\n", $loginEntryAssertions));
}
