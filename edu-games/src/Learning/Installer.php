<?php
declare(strict_types=1);
namespace EduGames\Learning;

final class Installer {
    public static function table(): string { global $wpdb; return $wpdb->prefix.'edu_games_learning_runs'; }
    public static function maybe_install(): void {
        if(get_option('edu_games_learning_schema')==='2'){return;}
        global $wpdb; $table=self::table();$charset=$wpdb->get_charset_collate();
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            token varchar(64) NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            game_slug varchar(80) NOT NULL,
            content_version varchar(64) NOT NULL,
            mode varchar(20) NOT NULL,
            level varchar(20) NOT NULL,
            revision int(10) unsigned NOT NULL DEFAULT 0,
            state_json longtext NOT NULL,
            score decimal(7,2) NOT NULL DEFAULT 0,
            started_at datetime NOT NULL,
            expires_at datetime NOT NULL,
            completed_at datetime NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY token (token),
            KEY user_game (user_id, game_slug, started_at),
            KEY version_rank (game_slug, content_version, mode, level, score)
        ) {$charset};");
        $score=$wpdb->get_row("SHOW COLUMNS FROM {$table} LIKE 'score'",ARRAY_A);
        if(is_array($score)&&strtolower((string)($score['Type']??''))==='decimal(7,2)'){update_option('edu_games_learning_schema','2',false);}
    }
}
