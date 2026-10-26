<?php
/**
 * Runs only when the plugin is deleted (not on deactivation): drops the
 * plugin tables and options. Names are hard-coded because the plugin's
 * classes are not loaded here.
 */
declare(strict_types=1);

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

foreach (array('oj_code_arena_round_players', 'oj_code_arena_rounds', 'oj_code_arena_problems', 'oj_code_arena_sessions', 'oj_code_arena_live', 'oj_code_arena_events', 'oj_code_arena_presence') as $table) {
    $wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . $table); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
}

foreach (array('oj_code_arena_db_version', 'oj_code_arena_settings', 'oj_code_arena_bank_version', 'oj_code_arena_defaults_applied', 'oj_code_arena_welcome') as $option) {
    delete_option($option);
}

$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_oj\\_code\\_arena\\_%' OR option_name LIKE '\\_transient\\_timeout\\_oj\\_code\\_arena\\_%'");
