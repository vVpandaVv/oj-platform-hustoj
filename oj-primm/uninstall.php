<?php
declare(strict_types=1);
defined('WP_UNINSTALL_PLUGIN') || exit;

// Removing plugin files preserves learning evidence by default.
if (! in_array(get_option('oj_primm_delete_on_uninstall', false), [true, 1, '1'], true)) { return; }
global $wpdb;
foreach (['events', 'attempts', 'practices', 'generations', 'generation_control', 'activities'] as $name) {
    $table = $wpdb->prefix . 'oj_primm_' . $name;
    $wpdb->query('DROP TABLE IF EXISTS `' . str_replace('`', '``', $table) . '`');
}
foreach (['oj_primm_schema_version', 'oj_primm_schema_retry', 'oj_primm_retention_days', 'oj_primm_delete_on_uninstall', 'oj_primm_owned_learner_cap',
    'oj_primm_class_settings', 'oj_primm_generation_policy', 'oj_primm_last_background_error', 'oj_primm_auto_prepare_log'] as $option) { delete_option($option); }
