<?php
declare(strict_types=1);

namespace EduGames\Rest\Controllers;

use EduGames\Rankings\Leaderboard_Service;
use EduGames\Scoring\Score_Recorder;
use EduGames\Scoring\Score_Validator;
use EduGames\Sessions\Game_Session_Service;
use EduGames\Support\Capabilities;

final class Games_Controller {
    public static function register_routes(): void {
        register_rest_route(
            'edu-games/v1',
            '/games/(?P<game>[a-z0-9-]+)/scores',
            array(
                'methods' => \WP_REST_Server::CREATABLE,
                'callback' => array(__CLASS__, 'record_score'),
                'permission_callback' => array(__CLASS__, 'can_record_score'),
                'args' => array(
                    'game' => array(
                        'required' => true,
                        'sanitize_callback' => 'sanitize_key',
                    ),
                ),
            )
        );

        register_rest_route(
            'edu-games/v1',
            '/games/(?P<game>[a-z0-9-]+)/session',
            array(
                'methods' => \WP_REST_Server::CREATABLE,
                'callback' => array(__CLASS__, 'start_session'),
                'permission_callback' => array(__CLASS__, 'can_record_score'),
                'args' => array(
                    'game' => array(
                        'required' => true,
                        'sanitize_callback' => 'sanitize_key',
                    ),
                ),
            )
        );
    }

    public static function can_record_score(): bool {
        return Capabilities::can_play();
    }

    /**
     * Mints (or reuses, if the player hasn't yet consumed one) a fresh
     * anti-replay play-session token for this game. The token embedded in
     * the page's initial data-config is single-use — a player who starts a
     * second ranked round without reloading the page must call this first,
     * or their score submission will be rejected as edu_games_session_invalid
     * no matter how high it is.
     */
    public static function start_session(\WP_REST_Request $request) {
        $game = sanitize_key((string) $request['game']);
        $definition = Score_Validator::client_game($game);
        if (is_wp_error($definition)) {
            return $definition;
        }
        $user_id = get_current_user_id();

        if ($user_id <= 0) {
            return new \WP_Error(
                'edu_games_login_required',
                __('로그인한 사용자만 플레이 세션을 시작할 수 있습니다.', 'edu-games'),
                array('status' => 401)
            );
        }

        $token = Game_Session_Service::start($user_id, $game);
        if ('' === $token) {
            return new \WP_Error('edu_games_session_start_failed', __('플레이 세션을 시작하지 못했습니다. 다시 시도해 주세요.', 'edu-games'), array('status' => 500));
        }
        return rest_ensure_response(array('sessionToken' => $token));
    }

    public static function record_score(\WP_REST_Request $request) {
        $game = sanitize_key((string) $request['game']);
        $params = $request->get_json_params();
        $data = is_array($params) ? $params : array();
        $user_id = get_current_user_id();

        $session_token = is_string($data['sessionToken'] ?? null) ? $data['sessionToken'] : '';
        $session = Game_Session_Service::find_valid($session_token, $user_id, $game);
        if (null === $session) {
            return new \WP_Error(
                'edu_games_session_invalid',
                __('플레이 세션이 유효하지 않습니다. 게임을 새로 불러온 뒤 다시 시도해 주세요.', 'edu-games'),
                array('status' => 400)
            );
        }

        $elapsed = max(0, time() - (int) strtotime($session['started_at'] . ' UTC'));
        $validated = Score_Validator::validate($game, $data, $elapsed);
        if (is_wp_error($validated)) {
            return $validated;
        }

        // Claim before inserting: only one concurrent request may save this session.
        if (! Game_Session_Service::mark_consumed((int) $session['id'])) {
            return new \WP_Error('edu_games_session_already_used', __('이미 제출한 플레이 세션입니다.', 'edu-games'), array('status' => 409));
        }
        $recorded = Score_Recorder::record($game, $user_id, $validated);

        if (is_wp_error($recorded)) {
            // A definite insert failure can retry; successful writes remain single-use.
            Game_Session_Service::release_failed((int) $session['id']);
            return $recorded;
        }

        return rest_ensure_response(
            array(
                'score' => $recorded,
                'rank' => Leaderboard_Service::rank_for_user($game, get_current_user_id()),
                'leaderboard' => Leaderboard_Service::for_game($game, 20),
            )
        );
    }
}
