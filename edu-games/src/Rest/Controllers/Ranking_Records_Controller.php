<?php
declare(strict_types=1);
namespace EduGames\Rest\Controllers;

use EduGames\Database\Installer;
use EduGames\Rankings\Record_Moderation;

final class Ranking_Records_Controller {
    public static function register_routes(): void {
        register_rest_route('edu-games/v1', '/leaderboards/game/(?P<game>[a-z0-9-]+)/records/(?P<store>score|run)/(?P<id>[1-9][0-9]*)', array(
            'methods' => 'DELETE',
            'callback' => array(__CLASS__, 'delete'),
            'permission_callback' => array(__CLASS__, 'allowed'),
        ));
    }

    public static function allowed(\WP_REST_Request $request): bool|\WP_Error {
        if (!Record_Moderation::allowed()) return new \WP_Error('edu_games_admin_only', '사이트 관리자만 기록을 삭제할 수 있습니다.', array('status'=>403));
        if (!wp_verify_nonce($request->get_header('X-WP-Nonce'), 'wp_rest')) return new \WP_Error('edu_games_nonce', '페이지를 새로고침한 뒤 다시 시도하세요.', array('status'=>403));
        return true;
    }

    public static function delete(\WP_REST_Request $request) {
        $permission = self::allowed($request);
        if (is_wp_error($permission)) return $permission;
        $store = (string)$request['store'];
        $id = (int)$request['id'];
        $game = sanitize_key((string)$request['game']);
        if (!in_array($store, array('score','run'), true) || $id <= 0 || $game === '') return new \WP_Error('edu_games_record', '기록을 확인하세요.', array('status'=>400));
        global $wpdb;
        $table = $store === 'score' ? Installer::scores_table() : \EduGames\Learning\Installer::table();
        $finished = $store === 'run' ? ' AND completed_at IS NOT NULL' : '';
        // Bind both the ID and game; an unfinished run must never be removed here.
        $removed = $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE id=%d AND game_slug=%s{$finished}", $id, $game));
        if ($removed === false) return new \WP_Error('edu_games_delete_failed', '기록을 삭제하지 못했습니다. 잠시 후 다시 시도하세요.', array('status'=>500));
        if ($removed !== 1) return new \WP_Error('edu_games_record_missing', '이미 삭제되었거나 삭제할 수 없는 기록입니다. 순위를 새로고침하세요.', array('status'=>404));
        $audit = array('actor'=>get_current_user_id(), 'game'=>$game, 'store'=>$store, 'recordId'=>$id, 'at'=>gmdate('c'));
        error_log('[Edu Games] ranking_record_deleted '.wp_json_encode($audit));
        do_action('edu_games_ranking_record_deleted', $audit);
        return rest_ensure_response(array('deleted'=>true, 'recordId'=>$id));
    }
}
