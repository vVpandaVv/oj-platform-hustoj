<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Kernel\Hook\HookRegistrar;
use CodingPanda\OjPlatform\Kernel\Registry\DefinitionRegistry;
use CodingPanda\OjPlatform\Modules\Permission\Contract\CapabilityDefinition;
use CodingPanda\OjPlatform\Modules\Permission\Contract\PermissionSetup;
use CodingPanda\OjPlatform\Modules\Permission\Contract\RoleDefinition;
use CodingPanda\OjPlatform\Modules\Permission\Contract\RoleSyncReport;
use CodingPanda\OjPlatform\Modules\Permission\Infrastructure\WordPress\WordPressPermissionManager;

$pluginRoot = dirname(__DIR__, 2);
require_once $pluginRoot . '/src/Kernel/Autoloader.php';
\CodingPanda\OjPlatform\Kernel\Autoloader::register($pluginRoot);

$permissionRuntimeAssertions = 0;

function permissionRuntimeAssert(bool $condition, string $message): void
{
    global $permissionRuntimeAssertions;
    ++$permissionRuntimeAssertions;

    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function permissionRuntimeSame(mixed $expected, mixed $actual, string $message): void
{
    permissionRuntimeAssert(
        $expected === $actual,
        $message
        . PHP_EOL
        . 'Expected: ' . var_export($expected, true)
        . PHP_EOL
        . 'Actual: ' . var_export($actual, true)
    );
}

$GLOBALS['permission_runtime_options'] = [];

function get_option(string $name, mixed $default = false): mixed
{
    return $GLOBALS['permission_runtime_options'][$name] ?? $default;
}

function update_option(string $name, mixed $value, mixed $autoload = null): bool
{
    unset($autoload);
    $GLOBALS['permission_runtime_options'][$name] = $value;

    return true;
}

function add_option(
    string $name,
    mixed $value = '',
    string $deprecated = '',
    mixed $autoload = 'yes'
): bool {
    unset($deprecated, $autoload);
    if (array_key_exists($name, $GLOBALS['permission_runtime_options'])) {
        return false;
    }

    $GLOBALS['permission_runtime_options'][$name] = $value;

    return true;
}

function delete_option(string $name): bool
{
    $existed = array_key_exists($name, $GLOBALS['permission_runtime_options']);
    unset($GLOBALS['permission_runtime_options'][$name]);

    return $existed;
}

final class PermissionRuntimeSetup implements PermissionSetup
{
    public int $calls = 0;
    public bool $fail = false;

    public function synchronizeRoles(): RoleSyncReport
    {
        ++$this->calls;
        if ($this->fail) {
            throw new RuntimeException('강제 권한 동기화 실패');
        }

        return new RoleSyncReport([], ['administrator']);
    }
}

final class PermissionRuntimeReporter implements ErrorReporter
{
    /** @var list<Throwable> */
    public array $errors = [];

    public function report(Throwable $error): void
    {
        $this->errors[] = $error;
    }
}

final class PermissionRuntimeHooks implements HookRegistrar
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

$capabilities = new DefinitionRegistry(
    'permission-runtime-capabilities',
    CapabilityDefinition::class
);
$capabilities->register(CapabilityDefinition::fromArray([
    'key' => 'oj_access_console',
    'owner_module' => 'permission',
    'feature_key' => 'permission',
    'scope' => 'global',
    'label' => '관리 화면 접근',
    'description' => '관리 화면에 접근합니다.',
]));
$roles = new DefinitionRegistry('permission-runtime-roles', RoleDefinition::class);
$roles->register(new RoleDefinition(
    'oj_runtime_manager',
    '운영자',
    ['oj_access_console']
));
$setup = new PermissionRuntimeSetup();
$reporter = new PermissionRuntimeReporter();
$manager = new WordPressPermissionManager(
    $setup,
    $capabilities,
    $roles,
    $reporter
);
$hooks = new PermissionRuntimeHooks();
$manager->registerHooks($hooks);
permissionRuntimeSame(1, count($hooks->actions['init'] ?? []), '권한 동기화 Hook은 init에 한 번 등록해야 합니다.');

($hooks->actions['init'][0])();
permissionRuntimeSame(1, $setup->calls, '서명이 없는 기존 설치는 권한을 한 번 동기화해야 합니다.');
permissionRuntimeAssert(
    is_string(get_option('oj_platform_permission_signature', null)),
    '성공한 권한 동기화의 Registry 서명을 기록해야 합니다.'
);
permissionRuntimeAssert(
    get_option('oj_platform_permission_lock', null) === null,
    '성공한 권한 동기화 뒤 잠금을 해제해야 합니다.'
);

($hooks->actions['init'][0])();
permissionRuntimeSame(1, $setup->calls, '같은 Registry 서명에서 권한을 반복 쓰면 안 됩니다.');

$capabilities->register(CapabilityDefinition::fromArray([
    'key' => 'oj_manage_runtime_probe',
    'owner_module' => 'permission',
    'feature_key' => 'permission',
    'scope' => 'global',
    'label' => '검사 권한',
    'description' => 'Registry 서명 변경을 검사합니다.',
]));
($hooks->actions['init'][0])();
permissionRuntimeSame(2, $setup->calls, '권한 Registry가 바뀌면 다시 한 번 동기화해야 합니다.');

unset($GLOBALS['permission_runtime_options']['oj_platform_permission_signature']);
$setup->fail = true;
($hooks->actions['init'][0])();
permissionRuntimeSame(3, $setup->calls, '실패한 권한 동기화도 한 번만 실행해야 합니다.');
permissionRuntimeSame(1, count($reporter->errors), '권한 동기화 실패를 시스템 기록에 전달해야 합니다.');
permissionRuntimeAssert(
    get_option('oj_platform_permission_signature', null) === null,
    '실패한 동기화의 Registry 서명을 완료로 기록하면 안 됩니다.'
);
permissionRuntimeAssert(
    get_option('oj_platform_permission_lock', null) === null,
    '실패한 권한 동기화 뒤에도 잠금을 해제해야 합니다.'
);

fwrite(
    STDOUT,
    sprintf(
        "권한 업그레이드 단위 검사 통과: assertion %d개\n",
        $permissionRuntimeAssertions
    )
);
