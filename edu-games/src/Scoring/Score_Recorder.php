<?php
declare(strict_types=1);

namespace EduGames\Scoring;

use EduGames\Database\Installer;

final class Score_Recorder {
    public const MAX_SCORE = 999999;

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|\WP_Error
     */
    public static function record(string $game_slug, int $user_id, array $data) {
        if ($user_id <= 0) {
            return new \WP_Error(
                'edu_games_login_required',
                __('로그인한 사용자만 점수를 기록할 수 있습니다.', 'edu-games'),
                array('status' => 401)
            );
        }

        $game_slug = sanitize_key($game_slug);
        $game = self::game_definition($game_slug);

        if (null === $game) {
            return new \WP_Error(
                'edu_games_unknown_game',
                __('등록되지 않은 게임입니다.', 'edu-games'),
                array('status' => 404)
            );
        }

        if ('playable' !== (string) ($game['status'] ?? 'planned')) {
            return new \WP_Error(
                'edu_games_game_not_playable',
                __('아직 점수를 기록할 수 없는 게임입니다.', 'edu-games'),
                array('status' => 400)
            );
        }

        $score = max(0, min(self::MAX_SCORE, (int) ($data['score'] ?? 0)));
        if ($score <= 0) {
            return new \WP_Error(
                'edu_games_invalid_score',
                __('기록할 점수가 없습니다.', 'edu-games'),
                array('status' => 400)
            );
        }

        $rounds_played = max(0, min(65535, absint($data['roundsPlayed'] ?? 0)));
        $correct_count = max(0, min($rounds_played, absint($data['correctCount'] ?? 0)));
        $duration_seconds = max(0, min(86400, absint($data['durationSeconds'] ?? 0)));
        $accuracy = max(0, min(100, (float) ($data['accuracy'] ?? 0)));
        $user = get_userdata($user_id);
        $player_name = $user ? $user->display_name : __('플레이어', 'edu-games');
        $meta = isset($data['meta']) && is_array($data['meta']) ? $data['meta'] : array();

        global $wpdb;

        $inserted = $wpdb->insert(
            Installer::scores_table(),
            array(
                'game_slug' => $game_slug,
                'user_id' => $user_id,
                'player_name' => sanitize_text_field($player_name),
                'score' => $score,
                'rounds_played' => $rounds_played,
                'correct_count' => $correct_count,
                'duration_seconds' => $duration_seconds,
                'accuracy' => $accuracy,
                'meta' => wp_json_encode($meta),
                'created_at' => current_time('mysql'),
            ),
            array('%s', '%d', '%s', '%d', '%d', '%d', '%d', '%f', '%s', '%s')
        );

        if (false === $inserted) {
            return new \WP_Error(
                'edu_games_score_insert_failed',
                __('점수를 저장하지 못했습니다.', 'edu-games'),
                array('status' => 500)
            );
        }

        return array(
            'id' => (int) $wpdb->insert_id,
            'gameSlug' => $game_slug,
            'score' => $score,
            'roundsPlayed' => $rounds_played,
            'correctCount' => $correct_count,
            'durationSeconds' => $duration_seconds,
            'accuracy' => $accuracy,
            'playerName' => sanitize_text_field($player_name),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function game_definition(string $game_slug): ?array {
        if (! function_exists('edu_games_registry')) {
            return null;
        }

        $games = \edu_games_registry()->all();

        return isset($games[$game_slug]) && is_array($games[$game_slug]) ? $games[$game_slug] : null;
    }
}
