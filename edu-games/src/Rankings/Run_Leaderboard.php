<?php
declare(strict_types=1);
namespace EduGames\Rankings;
use EduGames\Learning\Installer;

final class Run_Leaderboard {
    public static function page(string $game, string $version, string $mode, string $level, $page = 1, $size = 20): array|\WP_Error {
        global $wpdb;
        $where = $wpdb->prepare('game_slug=%s AND content_version=%s AND mode=%s AND level=%s AND completed_at IS NOT NULL', $game,$version,$mode,$level);
        $table = Installer::table();
        $total = $wpdb->get_var("SELECT COUNT(DISTINCT user_id) FROM {$table} WHERE {$where}");
        if ($total === null || !empty($wpdb->last_error)) return Page::error();
        $meta = Page::meta((int)$total,$page,$size);
        $best = "SELECT user_id, MAX(score) AS score FROM {$table} WHERE {$where} GROUP BY user_id";
        // Count higher-scoring participants, not rows in this page, to preserve shared ranks.
        // Alias is "place": RANK is a reserved word since MySQL 8.0.2 (window functions).
        $record = "(SELECT MIN(record.id) FROM {$table} record WHERE record.user_id=best.user_id AND record.score=best.score AND {$where})";
        $rows = $wpdb->get_results($wpdb->prepare("SELECT best.user_id, best.score, {$record} AS record_id, 1 + (SELECT COUNT(*) FROM ({$best}) higher WHERE higher.score > best.score) AS place FROM ({$best}) best ORDER BY best.score DESC, best.user_id ASC LIMIT %d OFFSET %d",$meta['perPage'],$meta['offset']),ARRAY_A);
        if (!is_array($rows) || !empty($wpdb->last_error)) return Page::error();
        foreach ($rows as &$row) {
            $row['name'] = (int)$row['user_id'] === get_current_user_id() ? '나' : '도전자';
            if (Record_Moderation::allowed()) {
                $row['name'] = get_userdata((int)$row['user_id'])->display_name ?? '탈퇴한 사용자';
                $row += Record_Moderation::fields('run', (int)$row['record_id'], $game);
            }
            unset($row['record_id']);
            $row['rank'] = (int)$row['place']; unset($row['place']); $row['score'] = (float)$row['score']; unset($row['user_id']);
        }
        unset($row);
        return array('leaderboard'=>$rows,'pagination'=>$meta);
    }
}
