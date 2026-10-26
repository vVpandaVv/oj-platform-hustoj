<?php

declare(strict_types=1);

$pluginRoot = dirname(__DIR__, 2);
$GLOBALS['wp_version'] = '6.6';

if (! defined('ABSPATH')) {
    define('ABSPATH', $pluginRoot . DIRECTORY_SEPARATOR);
}

require $pluginRoot . '/tests/Support/wordpress-stubs.php';

final class MigrationRuntimeDatabase
{
    public string $dbname = 'wordpress';
    public bool $failVersionRead = false;

    public function db_server_info(): string
    {
        if ($this->failVersionRead) {
            throw new RuntimeException('테스트 이전 데이터베이스 버전 조회 실패');
        }

        return '8.0.42';
    }

    public function get_results(string $statement, mixed $output = null): array
    {
        return [];
    }

    public function query(string $statement): int
    {
        return 1;
    }
}

$GLOBALS['wpdb'] = new MigrationRuntimeDatabase();
$GLOBALS['oj_platform_test_options']['oj_platform_install_state'] = [
    'revision' => 1,
    'value' => [
        'status' => 'migration_ready',
        'legacy_candidate' => true,
    ],
];

require $pluginRoot . '/oj-platform.php';
ojPlatformTestRunActions('plugins_loaded');

$actualHooks = array_keys($GLOBALS['oj_platform_test_actions']);
sort($actualHooks);
$expectedHooks = [
    'admin_menu',
    'admin_notices',
    'admin_enqueue_scripts',
    'admin_post_nopriv_oj_login',
    'admin_post_nopriv_oj_lost_password',
    'admin_post_oj_accept_user_agreements',
    'admin_post_oj_change_password',
    'admin_post_oj_job_cancel',
    'admin_post_oj_login',
    'admin_post_oj_logout',
    'admin_post_oj_lost_password',
    'admin_post_oj_migration_cleanup_report',
    'admin_post_oj_migration_deactivate_legacy_plugins',
    'admin_post_oj_migration_dry_run',
    'admin_post_oj_migration_run',
    'admin_post_oj_migration_scan',
    'admin_post_oj_migration_validate',
    'admin_post_oj_save_migration_settings',
    'admin_post_oj_save_user_settings',
    'init',
    'login_init',
    'oj_platform_jobs_tick',
    'admin_post_oj_platform_run_jobs',
    'admin_post_nopriv_oj_platform_run_jobs',
    'plugins_loaded',
    'template_redirect',
    'wp_ajax_oj_job_status',
];
sort($expectedHooks);

if ($actualHooks !== $expectedHooks) {
    fwrite(
        STDERR,
        "데이터 이전 제한 런타임 Hook 구성이 올바르지 않습니다.\n"
        . var_export($actualHooks, true)
        . "\n"
    );
    exit(1);
}

foreach ([
    'admin_post_oj_submit_submission',
    'admin_post_oj_problem_save',
    'admin_post_oj_save_feature_flags',
    'phpmailer_init',
] as $forbiddenHook) {
    if (isset($GLOBALS['oj_platform_test_actions'][$forbiddenHook])) {
        fwrite(
            STDERR,
            '데이터 이전 중 금지된 Hook이 열렸습니다: ' . $forbiddenHook . "\n"
        );
        exit(1);
    }
}

if ($GLOBALS['oj_platform_test_option_writes'] !== []) {
    fwrite(
        STDERR,
        "데이터 이전 제한 런타임 부팅 중 옵션 쓰기가 발생했습니다.\n"
    );
    exit(1);
}

foreach (['login_url', 'lostpassword_url', 'register_url'] as $loginFilter) {
    if (ojPlatformTestFilters($loginFilter) !== []) {
        throw new RuntimeException('이전 제한 모드에서 코어 로그인 주소를 변경하면 안 됩니다: ' . $loginFilter);
    }
}
foreach (['add_user_metadata', 'update_user_metadata', 'delete_user_metadata', 'wp_pre_insert_user_data'] as $nameFilter) {
    if (ojPlatformTestFilters($nameFilter) !== []) {
        throw new RuntimeException('이전 제한 모드에서 관리용 이름 데이터 이전을 막으면 안 됩니다: ' . $nameFilter);
    }
}
if (ojPlatformTestApplyFilters('register_url', 'https://example.test/wp-login.php?action=register')
    !== 'https://example.test/wp-login.php?action=register') {
    throw new RuntimeException('이전 제한 모드에서도 코어 회원가입 주소를 보존해야 합니다.');
}
if (count(ojPlatformTestFilters('wp_auth_check_same_domain')) !== 1) {
    throw new RuntimeException('이전 제한 모드의 관리자 재인증 안내 정책이 누락됐습니다.');
}
if (ojPlatformTestApplyFilters('wp_auth_check_same_domain', true) !== false) {
    throw new RuntimeException('이전 제한 모드의 관리자 재인증 새 탭 안내가 누락됐습니다.');
}

$bootActions = ojPlatformTestActions('plugins_loaded');
$GLOBALS['oj_platform_test_actions'] = ['plugins_loaded' => $bootActions];
$GLOBALS['oj_platform_test_filters'] = [];
$GLOBALS['wpdb']->failVersionRead = true;
ojPlatformTestRunActions('plugins_loaded');
if (ojPlatformTestActions('login_init') !== []) {
    throw new RuntimeException('이전 중 데이터베이스 점검이 실패하면 WordPress 로그인을 유지해야 합니다.');
}
foreach (['login_url', 'lostpassword_url', 'register_url', 'wp_auth_check_same_domain'] as $loginFilter) {
    if (ojPlatformTestFilters($loginFilter) !== []) {
        throw new RuntimeException('이전 중 준비 상태 실패에도 로그인 정책이 남았습니다: ' . $loginFilter);
    }
}
if (ojPlatformTestActions('admin_post_nopriv_oj_login') === []
    || ojPlatformTestActions('template_redirect') === []) {
    throw new RuntimeException('이전 중 로그인 보호를 해제해도 OJ 런타임은 보존해야 합니다.');
}
if ($GLOBALS['oj_platform_test_option_writes'] !== []) {
    throw new RuntimeException('이전 중 준비 상태 실패 부팅에서 옵션 쓰기가 발생했습니다.');
}

fwrite(
    STDOUT,
    sprintf(
        "R-11 데이터 이전 제한 런타임 검사 통과: 허용 Hook %d개, 로그인 보호와 DB 오류 잠금 방지, 부팅 쓰기 0개\n",
        count($actualHooks)
    )
);
