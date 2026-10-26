<?php

declare(strict_types=1);

$pluginRoot = dirname(__DIR__, 2);
$GLOBALS['wp_version'] = '6.5';

if (! defined('ABSPATH')) {
    define('ABSPATH', $pluginRoot . DIRECTORY_SEPARATOR);
}

require $pluginRoot . '/tests/Support/wordpress-stubs.php';
require $pluginRoot . '/oj-platform.php';

if (ojPlatformTestActions('plugins_loaded') !== []) {
    fwrite(STDERR, "요구 버전 실패 상태에서 Kernel 부팅 Hook을 등록하면 안 됩니다.\n");
    exit(1);
}

if (ojPlatformTestActions('admin_menu') !== [] || ojPlatformTestActions('admin_enqueue_scripts') !== []) {
    fwrite(STDERR, "요구 버전 실패 상태에서는 서버 설치 메뉴나 자산을 등록하면 안 됩니다.\n");
    exit(1);
}

$notices = ojPlatformTestActions('admin_notices');
if (count($notices) !== 1) {
    fwrite(STDERR, "요구 버전 실패 알림은 정확히 1개여야 합니다.\n");
    exit(1);
}

ob_start();
ojPlatformTestRunActions('admin_notices');
$noticeHtml = (string) ob_get_clean();

if (! str_contains($noticeHtml, 'WordPress 6.6 이상이 필요합니다.')) {
    fwrite(STDERR, "요구 버전 실패 알림 문구가 올바르지 않습니다.\n");
    exit(1);
}

fwrite(STDOUT, "R-03 요구 버전 통합 검사 통과: Kernel Hook 0개, 관리자 오류 알림 1개\n");
