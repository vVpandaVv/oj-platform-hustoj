<?php
/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * Additional permission: see PLATFORM-LINKING-EXCEPTION.txt.
 */
declare(strict_types=1);

/**
 * Runs only when the plugin is removed via "삭제" in the Plugins screen —
 * never on deactivate/reactivate. WordPress includes this file standalone
 * (the main oj-code-lab.php bootstrap, its autoloader, and every
 * OJ_CODE_LAB_* constant are NOT loaded), so table names are hardcoded
 * here rather than reused from Installer.
 */

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

wp_clear_scheduled_hook('oj_code_lab_cache_cleanup');
wp_clear_scheduled_hook('oj_code_lab_summary_backfill');

if (! defined('OJ_CODE_LAB_DELETE_DATA') || true !== OJ_CODE_LAB_DELETE_DATA) {
    return;
}

global $wpdb;

$notebooks_table = $wpdb->prefix . 'oj_code_lab_notebooks';
$datasets_table = $wpdb->prefix . 'oj_code_lab_datasets';
$dataset_cache_table = $wpdb->prefix . 'oj_code_lab_dataset_cache';
$uploads_table = $wpdb->prefix . 'oj_code_lab_uploads';

$wpdb->query("DROP TABLE IF EXISTS {$notebooks_table}");
$wpdb->query("DROP TABLE IF EXISTS {$datasets_table}");
$wpdb->query("DROP TABLE IF EXISTS {$dataset_cache_table}");
$wpdb->query("DROP TABLE IF EXISTS {$uploads_table}");

delete_option('oj_code_lab_db_version');
delete_option('oj_code_lab_kaggle_username');
delete_option('oj_code_lab_kaggle_key');

delete_option('oj_code_lab_kaggle_credentials');
delete_option('oj_code_lab_install_error');
delete_option('oj_code_lab_last_settings_event');
