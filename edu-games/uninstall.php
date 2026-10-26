<?php
declare(strict_types=1);

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

$tables = array(
    $wpdb->prefix . 'edu_games_scores',
    $wpdb->prefix . 'edu_games_sessions',
    $wpdb->prefix . 'edu_games_team_members',
    $wpdb->prefix . 'edu_games_teams',
    $wpdb->prefix . 'edu_games_ai_model_lab_attempts',
);

foreach ($tables as $table) {
    $wpdb->query('DROP TABLE IF EXISTS ' . $table);
}

$ranking_page_id = (int) get_option('edu_games_ranking_page_id');
if ($ranking_page_id > 0) {
    wp_delete_post($ranking_page_id, true);
}

delete_option('edu_games_db_version');
delete_option('edu_games_closed_games');
delete_option('edu_games_ranking_page_id');
delete_option('edu_games_ranking_page_cleanup_done');
delete_option('edu_games_ranking_page_removed');
