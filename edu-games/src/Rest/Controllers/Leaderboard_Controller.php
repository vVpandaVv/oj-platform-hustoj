<?php
declare(strict_types=1);

namespace EduGames\Rest\Controllers;

use EduGames\Games\Game_Visibility;
use EduGames\Groups\Group_Ranking_Service;
use EduGames\Groups\Team_Repository;
use EduGames\Rankings\Leaderboard_Service;
use EduGames\Support\Capabilities;

final class Leaderboard_Controller {
    public static function register_routes(): void {
        register_rest_route(
            'edu-games/v1',
            '/leaderboards/game/(?P<game>[a-z0-9-]+)',
            array(
                'methods' => \WP_REST_Server::READABLE,
                'callback' => array(__CLASS__, 'get_game_leaderboard'),
                'permission_callback' => array(__CLASS__, 'can_read_leaderboard'),
                'args' => array(
                    'game' => array(
                        'required' => true,
                        'sanitize_callback' => 'sanitize_key',
                    ),
                    'limit' => array(
                        'required' => false,
                        'sanitize_callback' => 'absint',
                    ),
                ),
            )
        );

        register_rest_route(
            'edu-games/v1',
            '/leaderboards/teams',
            array(
                'methods' => \WP_REST_Server::READABLE,
                'callback' => array(__CLASS__, 'get_team_standings'),
                'permission_callback' => array(__CLASS__, 'can_read_leaderboard'),
                'args' => array(
                    'game' => array(
                        'required' => true,
                        'sanitize_callback' => 'sanitize_key',
                    ),
                    'limit' => array(
                        'required' => false,
                        'sanitize_callback' => 'absint',
                    ),
                ),
            )
        );

        register_rest_route(
            'edu-games/v1',
            '/leaderboards/team/(?P<team_id>\d+)',
            array(
                'methods' => \WP_REST_Server::READABLE,
                'callback' => array(__CLASS__, 'get_team_leaderboard'),
                'permission_callback' => array(__CLASS__, 'can_read_team_leaderboard'),
                'args' => array(
                    'team_id' => array(
                        'required' => true,
                        'sanitize_callback' => 'absint',
                    ),
                    'game' => array(
                        'required' => true,
                        'sanitize_callback' => 'sanitize_key',
                    ),
                ),
            )
        );
    }

    public static function can_read_team_leaderboard(\WP_REST_Request $request): bool {
        if (Capabilities::can_manage()) {
            return true;
        }

        if (! Capabilities::can_play()) {
            return false;
        }

        return Team_Repository::is_member(absint($request['team_id']), get_current_user_id());
    }

    public static function get_team_leaderboard(\WP_REST_Request $request) {
        $team_id = absint($request['team_id']);
        $game = sanitize_key((string) $request->get_param('game'));

        if (! Capabilities::can_preview_closed() && ! Game_Visibility::is_open($game)) {
            return new \WP_Error(
                'edu_games_closed',
                __('이 게임은 현재 공개되지 않았습니다.', 'edu-games'),
                array('status' => 404)
            );
        }

        if ($request->get_param('page') !== null) {
            $result = Leaderboard_Service::page($game,$request->get_param('page'),$request->get_param('perPage'),Team_Repository::member_user_ids($team_id));
            return is_wp_error($result) ? $result : rest_ensure_response($result + array('game'=>$game,'teamId'=>$team_id));
        }
        return rest_ensure_response(
            array(
                'teamId' => $team_id,
                'game' => $game,
                'leaderboard' => Group_Ranking_Service::leaderboard($team_id, $game),
            )
        );
    }

    // Viewing rankings is a legitimate oversight need for teachers/managers
    // even without the addon's own "play" capability explicitly assigned to
    // their role — matches the manage-bypass already used elsewhere (see
    // Capabilities::can_preview_closed()).
    public static function can_read_leaderboard(): bool {
        return Capabilities::can_play() || Capabilities::can_manage();
    }

    public static function get_team_standings(\WP_REST_Request $request) {
        $game = sanitize_key((string) $request->get_param('game'));

        if (! Capabilities::can_preview_closed() && ! Game_Visibility::is_open($game)) {
            return new \WP_Error(
                'edu_games_closed',
                __('이 게임은 현재 공개되지 않았습니다.', 'edu-games'),
                array('status' => 404)
            );
        }

        $limit = absint($request->get_param('limit') ?? 0);

        if ($request->get_param('page') !== null) {
            $result = Group_Ranking_Service::page($game,$request->get_param('page'),$request->get_param('perPage'));
            return is_wp_error($result) ? $result : rest_ensure_response($result + array('game'=>$game));
        }

        return rest_ensure_response(
            array(
                'game' => $game,
                'standings' => Group_Ranking_Service::standings($game, $limit),
            )
        );
    }

    public static function get_game_leaderboard(\WP_REST_Request $request) {
        $game = sanitize_key((string) $request['game']);

        if (! Capabilities::can_preview_closed() && ! Game_Visibility::is_open($game)) {
            return new \WP_Error(
                'edu_games_closed',
                __('이 게임은 현재 공개되지 않았습니다.', 'edu-games'),
                array('status' => 404)
            );
        }

        $limit = absint($request->get_param('limit') ?? 0);

        if ($request->get_param('page') !== null) {
            $result = Leaderboard_Service::page($game,$request->get_param('page'),$request->get_param('perPage'));
            return is_wp_error($result) ? $result : rest_ensure_response($result + array('game'=>$game,'myRank'=>Leaderboard_Service::rank_for_user($game,get_current_user_id())));
        }

        return rest_ensure_response(
            array(
                'game' => $game,
                'leaderboard' => Leaderboard_Service::for_game($game, $limit),
                'myRank' => Leaderboard_Service::rank_for_user($game, get_current_user_id()),
            )
        );
    }
}
