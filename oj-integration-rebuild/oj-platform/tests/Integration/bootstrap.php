<?php

declare(strict_types=1);

$pluginRoot = dirname(__DIR__, 2);
$GLOBALS['wp_version'] = '6.6';

if (! defined('ABSPATH')) {
    define('ABSPATH', $pluginRoot . DIRECTORY_SEPARATOR);
}

require $pluginRoot . '/tests/Support/wordpress-stubs.php';
require $pluginRoot . '/oj-platform.php';

preg_match('/^\s*\*\s*Version:\s*(\S+)/m', (string) file_get_contents($pluginRoot . '/oj-platform.php'), $headerVersion);
if (! defined('OJ_PLATFORM_VERSION') || OJ_PLATFORM_VERSION !== ($headerVersion[1] ?? null)) {
    fwrite(STDERR, "플러그인 버전 상수가 올바르지 않습니다.\n");
    exit(1);
}

$pluginsLoaded = ojPlatformTestActions('plugins_loaded');
if (count($pluginsLoaded) !== 1) {
    fwrite(STDERR, "plugins_loaded 부팅 Hook은 정확히 1개여야 합니다.\n");
    exit(1);
}

if (($GLOBALS['oj_platform_test_actions']['admin_notices'] ?? []) !== []) {
    fwrite(STDERR, "요구 버전을 충족한 부팅에서 오류 알림이 등록됐습니다.\n");
    exit(1);
}

ojPlatformTestRunActions('plugins_loaded');

if (ojPlatformTestActions('login_init') !== []) {
    throw new RuntimeException('설치 전에는 WordPress 로그인을 차단하면 안 됩니다.');
}
foreach (['add_user_metadata', 'update_user_metadata', 'delete_user_metadata', 'wp_pre_insert_user_data'] as $nameFilter) {
    if (ojPlatformTestFilters($nameFilter) !== []) {
        throw new RuntimeException('설치 전에는 관리용 이름 저장 경계를 적용하면 안 됩니다: ' . $nameFilter);
    }
}
foreach (['login_url', 'lostpassword_url', 'register_url', 'wp_auth_check_same_domain'] as $loginFilter) {
    if (ojPlatformTestFilters($loginFilter) !== []) {
        throw new RuntimeException('설치 전에 로그인 URL 정책이 적용됐습니다: ' . $loginFilter);
    }
}

if (count(ojPlatformTestActions('admin_notices')) !== 2) {
    fwrite(STDERR, "신규 설치 상태에서 설치 안내 알림이 등록되지 않았습니다.\n");
    exit(1);
}

ob_start();
ojPlatformTestRunActions('admin_notices');
$setupNotice = (string) ob_get_clean();
if (
    ! str_contains($setupNotice, 'OJ Platform 설치를 완료해 주세요.')
    || ! str_contains($setupNotice, 'admin.php?page=oj-platform-setup')
    || ! str_contains($setupNotice, '설정 시작')
) {
    fwrite(STDERR, "신규 설치 안내의 문구나 진입 주소가 올바르지 않습니다.\n");
    exit(1);
}

ojPlatformTestRunActions('admin_menu');
$serverMenus = $GLOBALS['oj_platform_test_submenu_pages'] ?? [];
if (count($serverMenus) !== 1 || $serverMenus[0]['slug'] !== 'oj-server-install'
    || $serverMenus[0]['capability'] !== 'install_plugins' || $serverMenus[0]['parent'] !== '') {
    fwrite(STDERR, "숨겨진 서버 복구 화면의 등록이나 설치 권한이 올바르지 않습니다.\n");
    exit(1);
}
$setupMenus = $GLOBALS['oj_platform_test_menu_pages'] ?? [];
if (count($setupMenus) !== 1) {
    fwrite(STDERR, "신규 설치 메뉴가 정확히 한 개 등록되지 않았습니다.\n");
    exit(1);
}
$setupMenu = $setupMenus[0];
if (
    ($setupMenu['menu_title'] ?? '') !== 'OJ Platform 설치'
    || ($setupMenu['capability'] ?? '') !== 'manage_options'
    || ($setupMenu['menu_slug'] ?? '') !== 'oj-platform-setup'
    || ($setupMenu['icon_url'] ?? '') !== 'dashicons-admin-tools'
) {
    fwrite(STDERR, "신규 설치 메뉴 구성이 올바르지 않습니다.\n");
    exit(1);
}
$expectedHooks = [
    'plugins_loaded',
    'admin_menu',
    'admin_notices',
    'admin_post_oj_platform_setup',
    'admin_enqueue_scripts',
];
$actualHooks = array_keys($GLOBALS['oj_platform_test_actions']);
sort($expectedHooks);
sort($actualHooks);

if ($actualHooks !== $expectedHooks) {
    fwrite(STDERR, "신규 설치 상태의 임시 Hook 구성이 올바르지 않습니다.\n");
    exit(1);
}

if ($GLOBALS['oj_platform_test_option_writes'] !== []) {
    fwrite(STDERR, "일반 부팅 중 WordPress 옵션 쓰기가 발생했습니다.\n");
    exit(1);
}

fwrite(STDOUT, "R-03 WordPress 진입 통합 검사 통과: 부팅 Hook 1개, 설치 안내 1개, 임시 설치 화면 1개, 임시 액션 1개, 부팅 쓰기 0개\n");
