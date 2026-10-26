<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\Kernel\Autoloader;
use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Kernel\Module\ModuleId;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionUrlProvider;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\AuthenticationRequirement;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteArea;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteDefinition;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleResponder;
use CodingPanda\OjPlatform\Modules\Auth\Application\LoginThrottleService;
use CodingPanda\OjPlatform\Modules\Auth\Contract\AccessStatus;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionResponse;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccount;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentity;
use CodingPanda\OjPlatform\Modules\Auth\Domain\UserProfile;
use CodingPanda\OjPlatform\Modules\Auth\Infrastructure\WordPress\WordPressAttemptLimiter;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\ActionGuard;
use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;
use CodingPanda\OjPlatform\Modules\Permission\Contract\PermissionContext;
use CodingPanda\OjPlatform\Modules\Settings\Application\SiteSettingsService;
use CodingPanda\OjPlatform\Modules\Settings\Application\SystemSettingsService;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsManager;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsSnapshot;
use CodingPanda\OjPlatform\Modules\Settings\Presentation\Action\SaveSystemSettingsActionHandler;
use CodingPanda\OjPlatform\Modules\Settings\Presentation\Console\SystemSettingsPageHandler;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;

$pluginRoot = dirname(__DIR__, 2);
require_once $pluginRoot . '/src/Kernel/Autoloader.php';
Autoloader::register($pluginRoot);
require_once $pluginRoot . '/tests/Support/wordpress-stubs.php';

$transients = [];
function get_transient(string $key): mixed { return $GLOBALS['transients'][$key] ?? false; }
function set_transient(string $key, mixed $value, int $expiration): bool
{
    if (($GLOBALS['transient_write_mode'] ?? '') === 'fail') { return false; }
    $GLOBALS['transients'][$key] = $value;
    return ($GLOBALS['transient_write_mode'] ?? '') !== 'unchanged';
}
function delete_transient(string $key): bool
{
    $exists = isset($GLOBALS['transients'][$key]);
    unset($GLOBALS['transients'][$key]);
    return $exists;
}

$assertions = 0;
function loginSecurityAssert(bool $condition, string $message): void
{
    ++$GLOBALS['assertions'];
    if (! $condition) { throw new RuntimeException($message); }
}

$settings = new class implements SettingsManager {
    private array $snapshots = [];
    public bool $failWrites = false;
    public function read(string $key, mixed $default = null): SettingsSnapshot
    {
        return $this->snapshots[$key] ?? new SettingsSnapshot(0, $default);
    }
    public function save(string $key, mixed $value, int $expectedRevision): SettingsSnapshot
    {
        if ($this->failWrites || $this->read($key)->revision() !== $expectedRevision) {
            throw new RuntimeException('설정을 저장하지 못했습니다.');
        }
        return $this->snapshots[$key] = new SettingsSnapshot($expectedRevision + 1, $value);
    }
};
$storage = new WordPressAttemptLimiter();
$GLOBALS['transient_write_mode'] = 'unchanged';
loginSecurityAssert($storage->consume('submission', 'concurrent-test', 10, 60), '동시 요청에서 동일 값이 저장되면 허용해야 합니다.');
$GLOBALS['transient_write_mode'] = 'fail';
try {
    $storage->consume('submission', 'failed-write-test', 10, 60);
    throw new LogicException('저장 실패 허용');
} catch (RuntimeException $error) {
    loginSecurityAssert($error->getMessage() === '요청 횟수 제한 상태를 저장하지 못했습니다.', '실제 저장 실패는 차단해야 합니다.');
}
$GLOBALS['transient_write_mode'] = '';
$limiter = new LoginThrottleService($storage, $settings);
loginSecurityAssert($limiter->snapshot()->value()['enabled'] === true, '처음에는 로그인 보호를 켜야 합니다.');
$settings->save('registration', ['enabled' => true], 0);
for ($i = 0; $i < 10; ++$i) {
    loginSecurityAssert($limiter->consume('login', '203.0.113.1|student-a', 10, 600), '한도 내 요청은 허용해야 합니다.');
}
loginSecurityAssert(! $limiter->consume('login', '203.0.113.1|student-a', 10, 600), '같은 계정의 초과 요청을 차단해야 합니다.');
for ($i = 1; $i <= 40; ++$i) {
    loginSecurityAssert($limiter->consume('login', '203.0.113.1|student-' . $i, 10, 600), '동일 IP의 다른 학생을 차단하면 안 됩니다.');
}
foreach (['lost_password', 'registration', 'guardian_consent', 'ai_provider_probe'] as $scope) {
    $limiter->consume($scope, '203.0.113.1', 1, 600);
}
$oldGeneration = $limiter->snapshot()->value()['generation'];
$limiter->reset(0);
// Simulate a pre-reset request writing after reset while the cache remains intact.
$storage->consume('login', $oldGeneration . "\0" . '203.0.113.1|student-a', 100, 600);
loginSecurityAssert($limiter->consume('login', '203.0.113.1|student-a', 10, 600), '초기화 직후 로그인할 수 있어야 합니다.');
foreach (['lost_password', 'registration', 'guardian_consent', 'ai_provider_probe'] as $scope) {
    loginSecurityAssert(! $limiter->consume($scope, '203.0.113.1', 1, 600), '로그인 초기화가 다른 요청 보호를 해제하면 안 됩니다.');
}
loginSecurityAssert($settings->read('registration')->value() === ['enabled' => true], '가입 설정을 변경하면 안 됩니다.');
$limiter->save(false, 1);
$before = $transients;
for ($i = 0; $i < 50; ++$i) {
    loginSecurityAssert($limiter->consume('login', '203.0.113.1|student-a', 10, 600), '꺼진 상태에서는 로그인 횟수를 제한하면 안 됩니다.');
}
loginSecurityAssert($before === $transients, '꺼진 상태에서 로그인 카운터를 쌓으면 안 됩니다.');
loginSecurityAssert(! $limiter->consume('lost_password', '203.0.113.1', 1, 600), '로그인 제한을 꺼도 비밀번호 복구 제한은 유지해야 합니다.');
$limiter->reset(2);
loginSecurityAssert($limiter->snapshot()->value()['enabled'] === false, '초기화가 사용 여부를 바꾸면 안 됩니다.');
$limiter->save(true, 3);
$nextRequestLimiter = new LoginThrottleService(new WordPressAttemptLimiter(), $settings);
loginSecurityAssert($nextRequestLimiter->consume('login', '203.0.113.1|student-a', 1, 600), '다시 켜도 오래된 차단 기록이 살아나면 안 됩니다.');
loginSecurityAssert(! $nextRequestLimiter->consume('login', '203.0.113.1|student-a', 1, 600), '새 요청에서도 저장된 설정을 적용해야 합니다.');
$before = $limiter->snapshot();
try { $limiter->save(false, 0); throw new LogicException('오래된 저장 허용'); }
catch (RuntimeException) { loginSecurityAssert($before === $limiter->snapshot(), '오래된 화면이 설정을 덮어쓰면 안 됩니다.'); }
$settings->failWrites = true;
try { $limiter->reset(4); throw new LogicException('실패한 초기화 허용'); }
catch (RuntimeException) { loginSecurityAssert($before === $limiter->snapshot(), '저장 실패 시 기존 보호를 유지해야 합니다.'); }
$settings->failWrites = false;

$security = new class implements ActionSecurity {
    public bool $valid = true;
    public function verify(string $actionKey, HttpRequest $request): bool { return $this->valid; }
    public function hiddenFields(string $actionKey): string
    {
        return '<input type="hidden" name="action" value="' . $actionKey . '"><input type="hidden" name="_nonce" value="test">';
    }
};
$accounts = new class implements CurrentAccountProvider {
    public function current(): CurrentAccount
    {
        return new CurrentAccount(new UserIdentity(1, 'admin', '', '관리자', ['administrator']), new UserProfile(1, null, null, AccessStatus::Active, false));
    }
};
$permissions = new class implements Authorization {
    public bool $allowed = true;
    public function can(int $userId, string $capability, ?PermissionContext $context = null): bool
    {
        return $this->allowed && $userId === 1 && $capability === 'oj_manage_system';
    }
    public function canAny(int $userId, array $capabilities): bool { return $this->allowed; }
    public function isSuperAdmin(int $userId): bool { return $this->allowed; }
};
$responses = new class implements ActionResponse {
    public ?UiMessage $message = null;
    public function redirect(string $path, UiMessage $message, int $userId = 0): HttpResponse
    {
        $this->message = $message;
        return HttpResponse::redirect($path);
    }
};
$errors = new class implements ErrorReporter {
    public function report(Throwable $error): void {}
};
$guard = new ActionGuard($security, $accounts, $permissions, $limiter);
$system = new SystemSettingsService($settings);
$site = new SiteSettingsService($settings);
$action = new SaveSystemSettingsActionHandler($system, $site, $guard, $responses, $errors, $limiter);
$adminRequest = static fn (string $operation, array $extra = []): HttpRequest => new HttpRequest('POST', '/oj-action', [], array_merge([
    'operation' => $operation, 'expected_revision' => $limiter->snapshot()->revision(),
], $extra));
foreach (['save_login_throttle', 'reset_login_throttle'] as $operation) {
    $before = $limiter->snapshot();
    $permissions->allowed = false;
    $action->handle($adminRequest($operation));
    loginSecurityAssert($limiter->snapshot() === $before && $responses->message?->code() === 'system.failed', '운영 권한 없는 변경은 거부해야 합니다.');
    $permissions->allowed = true;
    $security->valid = false;
    $action->handle($adminRequest($operation));
    loginSecurityAssert($limiter->snapshot() === $before && $responses->message?->code() === 'system.failed', 'nonce 검증 전에는 설정을 변경하면 안 됩니다.');
    $security->valid = true;
}
$action->handle($adminRequest('save_login_throttle'));
loginSecurityAssert($limiter->snapshot()->value()['enabled'] === false && $responses->message?->code() === 'system.login_throttle_saved', '관리자 화면에서 제한을 끌 수 있어야 합니다.');
$action->handle($adminRequest('save_login_throttle', ['login_throttle_enabled' => '1']));
loginSecurityAssert($limiter->snapshot()->value()['enabled'] === true, '관리자 화면에서 제한을 켤 수 있어야 합니다.');
$action->handle($adminRequest('reset_login_throttle'));
loginSecurityAssert($responses->message?->code() === 'system.login_throttle_reset', '관리자 초기화 성공을 알려야 합니다.');
loginSecurityAssert($limiter->consume('login', '203.0.113.1|student-a', 1, 600), '관리자 초기화가 실제 로그인에 적용되어야 합니다.');
$before = $limiter->snapshot();
$action->handle($adminRequest('reset_login_throttle', ['expected_revision' => 0]));
loginSecurityAssert($before === $limiter->snapshot() && $responses->message?->code() === 'system.failed', '오래된 초기화 요청도 거부해야 합니다.');

$pages = new class implements ConsoleResponder {
    public function respond(string $title, string $content, string $currentMenu, HttpRequest $request, int $status = 200): HttpResponse
    {
        return HttpResponse::html($content, $status);
    }
};
$urls = new class implements ActionUrlProvider {
    public function actionUrl(): string { return '/oj-action'; }
    public function ajaxUrl(): string { return '/oj-ajax'; }
};
$page = new SystemSettingsPageHandler($pages, $system, $site, $urls, $security, $limiter);
$route = new RouteMatch(new RouteDefinition('console.settings.system', new ModuleId('settings'), 'settings', '/oj-console/settings/system', RouteArea::Console, ['GET'], AuthenticationRequirement::Authenticated, 'html'), []);
$html = $page->handle($route, new HttpRequest('GET', '/oj-console/settings/system'))->body();
$dom = new DOMDocument();
@$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
$xpath = new DOMXPath($dom);
loginSecurityAssert($xpath->query('//input[@name="login_throttle_enabled"][@checked]')->length === 1, '저장된 켜짐 상태를 표시해야 합니다.');
loginSecurityAssert($xpath->query('//section[@id="login-security"]//form[@method="post"]')->length === 2, '설정과 초기화는 POST 양식이어야 합니다.');
loginSecurityAssert($xpath->query('//section[@id="login-security"]//input[@name="expected_revision"]')->length === 2, '두 요청 모두 현재 revision을 포함해야 합니다.');
loginSecurityAssert(! str_contains($html, '모든 요청 제한 즉시 초기화'), '로그인 외 제한까지 초기화한다고 안내하면 안 됩니다.');
$limiter->save(false, $limiter->snapshot()->revision());
$offHtml = $page->handle($route, new HttpRequest('GET', '/oj-console/settings/system'))->body();
loginSecurityAssert(str_contains($offHtml, '로그인 횟수 제한이 꺼져 있습니다.'), '꺼진 상태의 경고를 표시해야 합니다.');
$definitions = require $pluginRoot . '/config/settings.php';
$definition = array_values(array_filter($definitions, static fn (array $row): bool => $row['key'] === 'login_security'));
loginSecurityAssert(count($definition) === 1 && $definition[0]['owner_module'] === 'auth', '로그인 설정은 Auth 소유로 등록해야 합니다.');

if (($argv[1] ?? '') === '--render' && isset($argv[2])) {
    $css = file_get_contents($pluginRoot . '/resources/ui/css/oj-ui-system.css');
    file_put_contents($argv[2], '<!doctype html><html lang="ko"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>운영 설정</title><style>' . $css . '</style></head><body class="ojp-app"><div class="ojp-console-shell"><div class="ojp-console-layout"><aside class="ojp-console-sidebar">관리자 메뉴</aside><main class="ojp-console-main"><div class="ojp-shell">' . $offHtml . '</div></main></div></div></body></html>');
}
fwrite(STDOUT, "Login security: {$assertions} assertions passed.\n");
