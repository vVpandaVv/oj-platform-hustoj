<?php
declare(strict_types=1);
defined('WP_UNINSTALL_PLUGIN') || exit;
$settings = get_option('oj_microbit_settings', []);
if (!is_array($settings) || !in_array($settings['deleteOnUninstall'] ?? false, [true, 1, '1'], true)) { return; }
global $wpdb;
foreach (['lesson_results', 'lesson_projects', 'assignments', 'attempts', 'projects', 'exercise_classes', 'exercise_groups', 'exercises', 'limits'] as $name) {
    if ($wpdb->query('DROP TABLE IF EXISTS `' . str_replace('`', '``', $wpdb->prefix . 'oj_microbit_' . $name) . '`') === false) {
        update_option('oj_microbit_uninstall_error', true, false);
        throw new RuntimeException('마이크로비트 자료 삭제를 완료하지 못했습니다. DB 삭제 권한을 확인한 뒤 다시 시도해 주세요. 삭제 설정과 진단 정보는 보존했습니다.');
    }
}
foreach (['oj_microbit_settings', 'oj_microbit_schema_version', 'oj_microbit_cleanup_error', 'oj_microbit_privacy_cleanup_error', 'oj_microbit_cap_cleanup_error', 'oj_microbit_owned_learner_cap', 'oj_microbit_uninstall_error'] as $option) { delete_option($option); }
