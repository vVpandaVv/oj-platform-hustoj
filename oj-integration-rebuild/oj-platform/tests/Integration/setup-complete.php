<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\Modules\PlatformDb\Application\SchemaOwnershipMarker;
use CodingPanda\OjPlatform\Modules\PlatformDb\Contract\SchemaTableDefinition;

$pluginRoot = dirname(__DIR__, 2);
$GLOBALS['wp_version'] = '6.6';

if (! defined('ABSPATH')) {
    define('ABSPATH', $pluginRoot . DIRECTORY_SEPARATOR);
}

require $pluginRoot . '/tests/Support/wordpress-stubs.php';
require_once $pluginRoot . '/src/Kernel/Autoloader.php';
\CodingPanda\OjPlatform\Kernel\Autoloader::register($pluginRoot);

function wp_is_recovery_mode(): bool
{
    return (bool) ($GLOBALS['oj_platform_test_recovery_mode'] ?? false);
}

function assertNativeLoginProtection(bool $expected, string $context): void
{
    $expectedCount = $expected ? 1 : 0;
    if (count(ojPlatformTestActions('login_init')) !== $expectedCount) {
        throw new RuntimeException($context . ': WordPress 로그인 보호 Hook 수가 올바르지 않습니다.');
    }
    if (count(ojPlatformTestFilters('wp_auth_check_same_domain')) !== $expectedCount) {
        throw new RuntimeException($context . ': 관리자 재인증 안내 정책이 올바르지 않습니다.');
    }
    foreach (['login_url', 'lostpassword_url', 'register_url'] as $hook) {
        if (ojPlatformTestFilters($hook) !== []) {
            throw new RuntimeException($context . ': 코어 로그인 URL을 변경하면 안 됩니다: ' . $hook);
        }
    }
}

final class CompletedInstallationDatabase
{
    public string $dbname = 'wordpress';

    /** @var list<array{Name:string, Comment:string, Engine:string, Collation:string}> */
    public array $tableRows = [];
    public int $tableStatusReads = 0;
    public string $serverInfo = '8.0.42';
    public bool $failVersionRead = false;
    public bool $failTableRead = false;

    public function db_server_info(): string
    {
        if ($this->failVersionRead) {
            throw new RuntimeException('테스트 데이터베이스 버전 조회 실패');
        }

        return $this->serverInfo;
    }

    /** @return list<array{Name:string, Comment:string, Engine:string, Collation:string}> */
    public function get_results(string $statement, mixed $output = null): array
    {
        if ($this->failTableRead) {
            throw new RuntimeException('테스트 데이터베이스 테이블 조회 실패');
        }
        if (trim($statement) === 'SHOW TABLE STATUS') { ++$this->tableStatusReads; }
        return trim($statement) === 'SHOW TABLE STATUS' ? $this->tableRows : [];
    }

    public function query(string $statement): int
    {
        return 1;
    }
}

$installationId = '0123456789abcdef0123456789abcdef';
$ownership = new SchemaOwnershipMarker();
$database = new CompletedInstallationDatabase();
$schemaFiles = glob($pluginRoot . '/config/*-schema.php');
if (! is_array($schemaFiles)) {
    fwrite(STDERR, "설치 완료 스키마 픽스처를 찾지 못했습니다.\n");
    exit(1);
}
foreach ($schemaFiles as $schemaFile) {
    foreach ((array) require $schemaFile as $definition) {
        $schema = SchemaTableDefinition::fromArray((array) $definition);
        $database->tableRows[] = [
            'Name' => $schema->key(),
            'Comment' => $ownership->create($installationId, $schema),
            'Engine' => 'InnoDB',
            'Collation' => 'utf8mb4_unicode_ci',
        ];
    }
}
if (count($database->tableRows) !== count(require $pluginRoot . '/config/platform-tables.php')) {
    fwrite(STDERR, "설치 완료 스키마와 목표 테이블 수가 다릅니다.\n");
    exit(1);
}

$GLOBALS['wpdb'] = $database;
$GLOBALS['oj_platform_test_options']['oj_platform_installation_id'] = [
    'revision' => 1,
    'value' => $installationId,
];
$GLOBALS['oj_platform_test_options']['oj_platform_install_state'] = [
    'revision' => 1,
    'value' => ['status' => 'complete'],
];

require $pluginRoot . '/oj-platform.php';
$bootActions = ojPlatformTestActions('plugins_loaded');
$completedOptions = $GLOBALS['oj_platform_test_options'];
$completedTables = $database->tableRows;
ojPlatformTestRunActions('plugins_loaded');
if ($database->tableStatusReads !== 1) {
    throw new RuntimeException('정상 부팅은 테이블 상태를 한 번만 읽어야 합니다: ' . $database->tableStatusReads);
}

$actualHooks = array_keys($GLOBALS['oj_platform_test_actions']);
sort($actualHooks);

$expectedHooks = [
    'admin_menu',
    'admin_notices',
    'admin_enqueue_scripts',
    'admin_post_nopriv_oj_check_guardian_consent_request',
    'admin_post_nopriv_oj_guardian_consent',
    'admin_post_nopriv_oj_login',
    'admin_post_nopriv_oj_lost_password',
    'admin_post_nopriv_oj_register_user',
    'admin_post_nopriv_oj_send_guardian_consent_request',
    'admin_post_oj_accept_user_agreements',
    'admin_post_oj_ai_persona_delete',
    'admin_post_oj_ai_persona_duplicate',
    'admin_post_oj_ai_persona_restore',
    'admin_post_oj_ai_persona_save',
    'admin_post_oj_ai_guide_validate',
    'admin_post_oj_board_add_comment',
    'admin_post_oj_board_add_thread',
    'admin_post_oj_board_create_thread',
    'admin_post_oj_board_delete_comment',
    'admin_post_oj_board_restore_thread',
    'admin_post_oj_board_toggle_comment_hidden',
    'admin_post_oj_board_toggle_thread_hidden',
    'admin_post_oj_board_toggle_thread_pinned',
    'admin_post_oj_board_trash_thread',
    'admin_post_oj_board_update_thread',
    'admin_post_oj_board_update_thread_public',
    'admin_post_oj_change_password',
    'admin_post_oj_check_guardian_consent_request',
    'admin_post_oj_class_export_progress',
    'admin_post_oj_class_lifecycle_command',
    'admin_post_oj_class_membership_command',
    'admin_post_oj_class_save',
    'admin_post_oj_console_approve_user_registration',
    'admin_post_oj_console_bulk_execute_users',
    'admin_post_oj_console_bulk_preview_users',
    'admin_post_oj_console_create_user',
    'admin_post_oj_console_sync_role_presets',
    'admin_post_oj_console_toggle_user_active',
    'admin_post_oj_console_update_user',
    'admin_post_oj_console_update_user_roles',
    'admin_post_oj_contest_admin_allowed_ip',
    'admin_post_oj_contest_allowed_ip',
    'admin_post_oj_contest_export',
    'admin_post_oj_contest_lifecycle_command',
    'admin_post_oj_contest_participation_command',
    'admin_post_oj_contest_public_admin_allowed_ip',
    'admin_post_oj_contest_save',
    'admin_post_oj_delete_account',
    'admin_post_oj_save_addon_capability_assignments',
    'admin_post_oj_group_add_member',
    'admin_post_oj_group_approve_membership',
    'admin_post_oj_group_archive',
    'admin_post_oj_group_bulk_members',
    'admin_post_oj_group_cancel_request',
    'admin_post_oj_group_grant_manager',
    'admin_post_oj_group_join',
    'admin_post_oj_group_leave',
    'admin_post_oj_group_public_approve_membership',
    'admin_post_oj_group_public_reject_membership',
    'admin_post_oj_group_public_remove_membership',
    'admin_post_oj_group_reject_membership',
    'admin_post_oj_group_remove_membership',
    'admin_post_oj_group_reset_member_password',
    'admin_post_oj_group_restore',
    'admin_post_oj_group_revoke_manager',
    'admin_post_oj_group_save',
    'admin_post_oj_group_save_member_names',
    'admin_post_oj_guardian_consent',
    'admin_post_oj_job_cancel',
    'admin_post_oj_job_retry_failed',
    'admin_post_oj_login',
    'admin_post_oj_logout',
    'admin_post_oj_lost_password',
    'admin_post_oj_message_admin_hide',
    'admin_post_oj_message_delete',
    'admin_post_oj_message_report',
    'admin_post_oj_message_report_resolve',
    'admin_post_oj_message_send',
    'admin_post_oj_save_messaging_settings',
    'admin_post_oj_migration_cleanup_report',
    'admin_post_oj_migration_deactivate_legacy_plugins',
    'admin_post_oj_migration_dry_run',
    'admin_post_oj_migration_run',
    'admin_post_oj_migration_scan',
    'admin_post_oj_migration_validate',
    'admin_post_oj_save_migration_settings',
    'admin_post_oj_notice_add_comment',
    'admin_post_oj_notice_create',
    'admin_post_oj_notice_create_console',
    'admin_post_oj_notice_delete_comment',
    'admin_post_oj_notice_restore',
    'admin_post_oj_notice_trash',
    'admin_post_oj_notice_update',
    'admin_post_oj_problem_book_delete_category',
    'admin_post_oj_private_execution_control',
    'admin_post_oj_problem_book_personal_add',
    'admin_post_oj_problem_book_personal_remove',
    'admin_post_oj_problem_book_save_category',
    'admin_post_oj_problem_bulk_apply_metadata_draft',
    'admin_post_oj_problem_bulk_visibility',
    'admin_post_oj_problem_change_id',
    'admin_post_oj_problem_delete',
    'admin_post_oj_problem_generate_metadata',
    'admin_post_oj_problem_generate_metadata_filtered',
    'admin_post_oj_problem_save',
    'admin_post_oj_problem_test_data_action',
    'admin_post_oj_save_problem_test_data_settings',
    'admin_post_oj_problem_toggle_visibility',
    'admin_post_oj_problem_topic_create',
    'admin_post_oj_problem_xml_export',
    'admin_post_oj_problem_xml_import',
    'admin_post_oj_quiz_bank_delete',
    'admin_post_oj_quiz_bank_save',
    'admin_post_oj_quiz_create',
    'admin_post_oj_quiz_export_to_bank',
    'admin_post_oj_quiz_import_bank_question',
    'admin_post_oj_quiz_save',
    'admin_post_oj_quiz_start_attempt',
    'admin_post_oj_quiz_submit_attempt',
    'admin_post_oj_register_user',
    'admin_post_oj_save_agreement_document',
    'admin_post_oj_save_ai_feedback_settings',
    'admin_post_oj_save_blockly_settings',
    'admin_post_oj_save_board_settings',
    'admin_post_oj_save_registration_settings',
    'admin_post_oj_save_submission_display_settings',
    'admin_post_oj_save_submission_settings',
    'admin_post_oj_save_user_settings',
    'admin_post_oj_send_guardian_consent_request',
    'admin_post_oj_status_delete',
    'admin_post_oj_status_rejudge',
    'admin_post_oj_submission_delete',
    'admin_post_oj_submission_rejudge',
    'admin_post_oj_submission_restore',
    'admin_post_oj_submit_submission',
    'admin_post_oj_user_password_reset',
    'admin_post_oj_platform_clear_last_fatal',
    'admin_post_oj_reset_public_menu_settings',
    'admin_post_oj_save_ai_provider_settings',
    'admin_post_oj_save_ai_task_routing',
    'admin_post_oj_save_feature_flags',
    'admin_post_oj_save_home_settings',
    'admin_post_oj_save_judge_db_settings',
    'admin_post_oj_save_public_menu_settings',
    'admin_post_oj_save_site_settings',
    'admin_post_oj_save_smtp_settings',
    'admin_post_oj_save_system_settings',
    'admin_post_oj_send_smtp_test_mail',
    'init',
    'login_init',
    'oj_platform_candidate_proof_cleanup',
    'oj_platform_jobs_tick',
    'oj_platform_private_execution_cleanup',
    'admin_post_oj_platform_run_jobs',
    'admin_post_nopriv_oj_platform_run_jobs',
    'phpmailer_init',
    'plugins_loaded',
    'template_redirect',
    'wp_ajax_oj_ai_feedback_generate',
    'wp_ajax_oj_job_status',
    'wp_ajax_oj_problem_ai_generate',
    'wp_ajax_oj_problem_upload_image',
    'wp_ajax_oj_submission_poll',
];
sort($expectedHooks);

if ($actualHooks !== $expectedHooks) {
    fwrite(
        STDERR,
        "설치 완료 뒤 Hook 구성이 올바르지 않습니다.\n"
        . var_export($actualHooks, true)
        . "\n"
    );
    exit(1);
}

if ($GLOBALS['oj_platform_test_option_writes'] !== []) {
    fwrite(STDERR, "설치 완료 상태의 일반 부팅 중 옵션 쓰기가 발생했습니다.\n");
    exit(1);
}

assertNativeLoginProtection(true, '설치 완료');
foreach (['add_user_metadata', 'update_user_metadata', 'delete_user_metadata', 'wp_pre_insert_user_data'] as $nameFilter) {
    if (count(ojPlatformTestFilters($nameFilter)) !== 1) {
        throw new RuntimeException('설치 완료 후 관리용 이름 저장 경계가 등록되지 않았습니다: ' . $nameFilter);
    }
}
foreach ([
    'login_url' => 'https://example.test/wp-login.php',
    'lostpassword_url' => 'https://example.test/wp-login.php?action=lostpassword',
    'register_url' => 'https://example.test/wp-login.php?action=register',
] as $filter => $nativeUrl) {
    if (ojPlatformTestApplyFilters($filter, $nativeUrl) !== $nativeUrl) {
        throw new RuntimeException('복구 및 개인정보 확인 메일의 코어 주소를 유지해야 합니다: ' . $filter);
    }
}
if (ojPlatformTestApplyFilters('wp_auth_check_same_domain', true) !== false) {
    throw new RuntimeException('관리자 재인증은 새 탭 로그인 안내를 사용해야 합니다.');
}

$resetFixture = static function () use ($bootActions, $completedOptions, $completedTables, $database): void {
    $GLOBALS['oj_platform_test_actions'] = ['plugins_loaded' => $bootActions];
    $GLOBALS['oj_platform_test_filters'] = [];
    $GLOBALS['oj_platform_test_options'] = $completedOptions;
    $GLOBALS['oj_platform_test_option_writes'] = [];
    $GLOBALS['oj_platform_test_recovery_mode'] = false;
    $database->tableRows = $completedTables;
    $database->tableStatusReads = 0;
    $database->serverInfo = '8.0.42';
    $database->failVersionRead = false;
    $database->failTableRead = false;
};

$scenarios = [
    '인증 기능 꺼짐' => static function (): void {
        $GLOBALS['oj_platform_test_options']['oj_platform_feature_flags'] = [
            'revision' => 1,
            'value' => ['auth' => false],
        ];
    },
    '스키마 업그레이드 대기' => static function () use ($database): void {
        $database->tableRows[0]['Comment'] = (string) preg_replace(
            '/fingerprint=[^|]+$/',
            'fingerprint=' . str_repeat('0', 64),
            $database->tableRows[0]['Comment']
        );
    },
    '지원하지 않는 데이터베이스 버전' => static function () use ($database): void {
        $database->serverInfo = '5.5.62';
    },
    '데이터베이스 버전 조회 오류' => static function () use ($database): void {
        $database->failVersionRead = true;
    },
    '데이터베이스 테이블 조회 오류' => static function () use ($database): void {
        $database->failTableRead = true;
    },
    '검증된 WordPress 복구 모드' => static function (): void {
        $GLOBALS['oj_platform_test_recovery_mode'] = true;
    },
];
foreach ($scenarios as $context => $prepare) {
    $resetFixture();
    $prepare();
    ojPlatformTestRunActions('plugins_loaded');
    assertNativeLoginProtection(false, $context);
    if ($GLOBALS['oj_platform_test_option_writes'] !== []) {
        throw new RuntimeException($context . ': 잠금 방지 부팅 중 옵션 쓰기가 발생했습니다.');
    }
    if ($context === '스키마 업그레이드 대기'
        && ojPlatformTestActions('admin_post_oj_platform_setup') === []) {
        throw new RuntimeException('스키마 업그레이드 대기 중 설치 복구 진입점이 보존되지 않았습니다.');
    }
}

$resetFixture();
define('OJ_PLATFORM_ALLOW_WORDPRESS_LOGIN', true);
ojPlatformTestRunActions('plugins_loaded');
assertNativeLoginProtection(false, '서버 비상 해제 상수');
if ($GLOBALS['oj_platform_test_option_writes'] !== []) {
    throw new RuntimeException('서버 비상 해제 부팅 중 옵션 쓰기가 발생했습니다.');
}

fwrite(
    STDOUT,
    sprintf(
        "R-10 설치 완료 통합 검사 통과: 운영 Hook %d개, 로그인 보호 등록, 잠금 방지 7개 상황, 부팅 쓰기 0개\n",
        count($actualHooks)
    )
);
