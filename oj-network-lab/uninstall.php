<?php
declare(strict_types=1);
defined('WP_UNINSTALL_PLUGIN') || exit;
if (!get_option('oj_network_lab_delete_on_uninstall', false)) { return; }
global $wpdb;
$networkTable = $wpdb->prefix . 'oj_network_lab_projects';
$wpdb->query('DROP TABLE IF EXISTS `' . str_replace('`', '``', $networkTable) . '`');
foreach (['oj_network_lab_schema', 'oj_network_lab_install_retry', 'oj_network_lab_retention_days', 'oj_network_lab_delete_on_uninstall'] as $networkOption) { delete_option($networkOption); }
wp_clear_scheduled_hook('oj_network_lab_cleanup');
