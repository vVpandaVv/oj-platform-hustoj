<?php
declare(strict_types=1);
namespace EduGames\Progress;

final class Store {
    public static function table(): string { global $wpdb; return $wpdb->prefix.'edu_games_progress'; }
    public static function install(): void {
        if (get_option('edu_games_progress_schema') === '1') return;
        global $wpdb; $table=self::table(); $charset=$wpdb->get_charset_collate();
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE {$table} (
            user_id bigint(20) unsigned NOT NULL,
            game_slug varchar(80) NOT NULL,
            record_key varchar(120) NOT NULL,
            best_value int(10) unsigned NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (user_id, game_slug, record_key)
        ) {$charset};");
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table)) === $table) update_option('edu_games_progress_schema','1',false);
    }
    public static function read(int $user, string $game): array|\WP_Error {
        global $wpdb;
        $rows=$wpdb->get_results($wpdb->prepare('SELECT record_key, best_value FROM '.self::table().' WHERE user_id=%d AND game_slug=%s',$user,$game),ARRAY_A);
        if (!is_array($rows)||!empty($wpdb->last_error)) return self::error();
        $records=array(); foreach($rows as $row) $records[$row['record_key']]=(int)$row['best_value'];
        $best=$wpdb->get_var($wpdb->prepare('SELECT MAX(score) FROM '.\EduGames\Database\Installer::scores_table().' WHERE user_id=%d AND game_slug=%s',$user,$game));
        if (!empty($wpdb->last_error)) return self::error();
        return array('records'=>(object)$records,'bestScore'=>(int)$best);
    }
    public static function put(int $user, string $game, string $key, int $value): bool {
        global $wpdb; $table=self::table(); $merge=$key==='mistakes'?'LEAST':'GREATEST';
        return false!==$wpdb->query($wpdb->prepare("INSERT INTO {$table} (user_id,game_slug,record_key,best_value,updated_at) VALUES (%d,%s,%s,%d,%s) ON DUPLICATE KEY UPDATE best_value={$merge}(best_value,VALUES(best_value)),updated_at=VALUES(updated_at)",$user,$game,$key,$value,gmdate('Y-m-d H:i:s')));
    }
    public static function error(): \WP_Error { return new \WP_Error('edu_games_progress','계정 기록을 저장하거나 읽지 못했습니다. 다시 시도하세요.',array('status'=>500)); }
}
