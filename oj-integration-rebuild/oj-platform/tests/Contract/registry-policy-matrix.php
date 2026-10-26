<?php

declare(strict_types=1);

$pluginRoot = dirname(__DIR__, 2);
$routes = require $pluginRoot . '/config/routes.php';
$actions = require $pluginRoot . '/config/actions.php';
$permissionConfig = require $pluginRoot . '/config/capabilities.php';
$menus = require $pluginRoot . '/config/menus.php';
$modules = require $pluginRoot . '/config/modules.php';
$features = require $pluginRoot . '/config/features.php';
$routeAccess = require $pluginRoot . '/config/route-access.php';
$errors = [];

$expectedCounts = [
    'Route' => [count($routes), 170],
    'Action' => [count($actions), 144],
    'capability' => [count($permissionConfig['capabilities'] ?? []), 62],
    'role' => [count($permissionConfig['roles'] ?? []), 17],
    'preset' => [count($permissionConfig['presets'] ?? []), 11],
    'menu' => [count($menus), 65],
];
foreach ($expectedCounts as $label => [$actual, $expected]) {
    if ($actual !== $expected) {
        $errors[] = sprintf(
            '%s 동결 수량이 다릅니다: 예상 %d, 실제 %d',
            $label,
            $expected,
            $actual
        );
    }
}

/**
 * @param list<array<string, mixed>> $items
 * @return array<string, array<string, mixed>>
 */
$indexBy = static function (
    array $items,
    string $key,
    string $label
) use (&$errors): array {
    $indexed = [];
    foreach ($items as $offset => $item) {
        $value = is_array($item) ? ($item[$key] ?? null) : null;
        if (! is_string($value) || trim($value) === '') {
            $errors[] = sprintf('%s %d번 항목의 %s가 올바르지 않습니다.', $label, $offset, $key);
            continue;
        }
        if (isset($indexed[$value])) {
            $errors[] = sprintf('%s 키가 중복되었습니다: %s', $label, $value);
            continue;
        }
        $indexed[$value] = $item;
    }

    return $indexed;
};

$moduleIndex = $indexBy($modules, 'key', 'module');
$featureIndex = $indexBy($features, 'key', 'feature');
$routeIndex = $indexBy($routes, 'name', 'Route');
$actionIndex = $indexBy($actions, 'key', 'Action');
$capabilityIndex = $indexBy(
    is_array($permissionConfig['capabilities'] ?? null)
        ? $permissionConfig['capabilities']
        : [],
    'key',
    'capability'
);
$roleIndex = $indexBy(
    is_array($permissionConfig['roles'] ?? null)
        ? $permissionConfig['roles']
        : [],
    'key',
    'role'
);
$presetIndex = $indexBy(
    is_array($permissionConfig['presets'] ?? null)
        ? $permissionConfig['presets']
        : [],
    'key',
    'preset'
);
$menuIndex = $indexBy($menus, 'key', 'menu');

foreach ($routes as $route) {
    $name = (string) ($route['name'] ?? '');
    $owner = (string) ($route['owner_module'] ?? '');
    $feature = (string) ($route['feature_key'] ?? '');
    $area = (string) ($route['area'] ?? '');
    $authentication = (string) ($route['authentication'] ?? '');
    if (! isset($moduleIndex[$owner])) {
        $errors[] = sprintf('Route %s의 소유 모듈이 없습니다: %s', $name, $owner);
    }
    if (! isset($featureIndex[$feature])) {
        $errors[] = sprintf('Route %s의 기능이 없습니다: %s', $name, $feature);
    }
    if (
        str_starts_with($name, 'console.')
        && ($area !== 'console' && $area !== 'response')
    ) {
        $errors[] = sprintf('관리자 Route %s의 영역이 올바르지 않습니다.', $name);
    }
    if (
        str_starts_with($name, 'console.')
        && $authentication !== 'authenticated'
    ) {
        $errors[] = sprintf('관리자 Route %s는 로그인이 필수여야 합니다.', $name);
    }
}

foreach ($actions as $action) {
    $key = (string) ($action['key'] ?? '');
    $owner = (string) ($action['owner_module'] ?? '');
    if (! isset($moduleIndex[$owner])) {
        $errors[] = sprintf('Action %s의 소유 모듈이 없습니다: %s', $key, $owner);
    }
    if (! in_array($action['transport'] ?? null, ['admin_post', 'ajax'], true)) {
        $errors[] = sprintf('Action %s의 전송 방식이 올바르지 않습니다.', $key);
    }
    if (! in_array($action['authentication'] ?? null, ['anonymous', 'authenticated'], true)) {
        $errors[] = sprintf('Action %s의 로그인 정책이 올바르지 않습니다.', $key);
    }
}

$wordpressCapabilities = ['read' => true, 'upload_files' => true];
$validateCapabilities = static function (
    array $capabilities,
    string $context
) use (&$errors, $capabilityIndex, $wordpressCapabilities): void {
    foreach ($capabilities as $capability) {
        if (
            ! is_string($capability)
            || (
                ! isset($capabilityIndex[$capability])
                && ! isset($wordpressCapabilities[$capability])
            )
        ) {
            $errors[] = sprintf(
                '%s에 등록되지 않은 권한이 있습니다: %s',
                $context,
                is_scalar($capability) ? (string) $capability : '(invalid)'
            );
        }
    }
};

foreach ($roleIndex as $key => $role) {
    $capabilities = is_array($role['capabilities'] ?? null)
        ? $role['capabilities']
        : [];
    $validateCapabilities($capabilities, 'role ' . $key);
    if (
        array_filter(
            $capabilities,
            static fn (mixed $capability): bool => is_string($capability)
                && str_starts_with($capability, 'oj_')
        ) !== []
        && ! in_array('oj_access_console', $capabilities, true)
    ) {
        $errors[] = sprintf('관리 역할 %s에 oj_access_console이 없습니다.', $key);
    }
}
foreach ($presetIndex as $key => $preset) {
    $validateCapabilities(
        is_array($preset['capabilities'] ?? null)
            ? $preset['capabilities']
            : [],
        'preset ' . $key
    );
}

foreach ($menuIndex as $key => $menu) {
    $route = (string) ($menu['route'] ?? '');
    $parent = $menu['parent'] ?? null;
    if ($route !== 'first_accessible_child' && ! isset($routeIndex[$route])) {
        $errors[] = sprintf('menu %s의 Route가 없습니다: %s', $key, $route);
    }
    if ($parent !== null && (! is_string($parent) || ! isset($menuIndex[$parent]))) {
        $errors[] = sprintf('menu %s의 상위 메뉴가 없습니다.', $key);
    }
    if (
        is_string($parent)
        && ($menuIndex[$parent]['parent'] ?? null) !== null
    ) {
        $errors[] = sprintf('menu %s는 3단계 이상으로 중첩될 수 없습니다.', $key);
    }
    $feature = $menu['feature_key'] ?? null;
    if (
        $feature !== null
        && (! is_string($feature) || ! isset($featureIndex[$feature]))
    ) {
        $errors[] = sprintf('menu %s의 기능이 없습니다: %s', $key, (string) $feature);
    }
    $validateCapabilities(
        is_array($menu['capabilities'] ?? null)
            ? $menu['capabilities']
            : [],
        'menu ' . $key
    );
}

foreach ($routeAccess as $routeName => $capabilities) {
    if (
        ! is_string($routeName)
        || ! isset($routeIndex[$routeName])
        || ! str_starts_with($routeName, 'console.')
    ) {
        $errors[] = sprintf('Route 접근 정책 대상이 올바르지 않습니다: %s', (string) $routeName);
        continue;
    }
    if (! is_array($capabilities) || $capabilities === []) {
        $errors[] = sprintf('Route %s의 접근 권한이 비어 있습니다.', $routeName);
        continue;
    }
    $validateCapabilities($capabilities, 'Route 접근 정책 ' . $routeName);
}

$testDataSettingsRoute = $routeIndex['console.problems.test_data_settings'] ?? null;
if (
    ! is_array($testDataSettingsRoute)
    || ($testDataSettingsRoute['owner_module'] ?? null) !== 'problems'
    || ($testDataSettingsRoute['authentication'] ?? null) !== 'authenticated'
    || ($routeAccess['console.problems.test_data_settings'] ?? null) !== ['oj_manage_system']
) {
    $errors[] = '테스트 환경 설정 Route는 Problems 소유의 로그인·시스템 관리 전용이어야 합니다.';
}

$testDataSettingsAction = $actionIndex['oj_save_problem_test_data_settings'] ?? null;
if (
    ! is_array($testDataSettingsAction)
    || ($testDataSettingsAction['owner_module'] ?? null) !== 'problems'
    || ($testDataSettingsAction['transport'] ?? null) !== 'admin_post'
    || ($testDataSettingsAction['authentication'] ?? null) !== 'authenticated'
) {
    $errors[] = '테스트 환경 저장 Action 계약이 올바르지 않습니다.';
}

$testDataSettingsMenu = $menuIndex['operations.test_data'] ?? null;
if (
    ! is_array($testDataSettingsMenu)
    || ($testDataSettingsMenu['parent'] ?? null) !== 'operations'
    || ($testDataSettingsMenu['route'] ?? null) !== 'console.problems.test_data_settings'
    || ($testDataSettingsMenu['feature_key'] ?? null) !== 'problems'
    || ($testDataSettingsMenu['capabilities'] ?? null) !== ['oj_manage_system']
) {
    $errors[] = '테스트 환경 메뉴는 운영 하위의 Problems·시스템 관리 전용이어야 합니다.';
}

if ($errors !== []) {
    fwrite(
        STDERR,
        "Registry·권한 행렬 검사 실패\n- "
        . implode("\n- ", array_values(array_unique($errors)))
        . "\n"
    );
    exit(1);
}

fwrite(
    STDOUT,
    sprintf(
        "Registry·권한 행렬 검사 통과: Route %d, Action %d, 권한 %d, 역할 %d, 메뉴 %d\n",
        count($routeIndex),
        count($actionIndex),
        count($capabilityIndex),
        count($roleIndex),
        count($menuIndex)
    )
);
