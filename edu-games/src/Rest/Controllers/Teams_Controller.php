<?php
declare(strict_types=1);

namespace EduGames\Rest\Controllers;

use EduGames\Groups\Team_Repository;
use EduGames\Support\Capabilities;

final class Teams_Controller {
    public static function register_routes(): void {
        register_rest_route(
            'edu-games/v1',
            '/teams/mine',
            array(
                'methods' => \WP_REST_Server::READABLE,
                'callback' => array(__CLASS__, 'get_my_team'),
                'permission_callback' => array(Capabilities::class, 'can_play'),
            )
        );

        register_rest_route(
            'edu-games/v1',
            '/teams/join',
            array(
                'methods' => \WP_REST_Server::CREATABLE,
                'callback' => array(__CLASS__, 'join_team'),
                'permission_callback' => array(Capabilities::class, 'can_play'),
            )
        );

        register_rest_route(
            'edu-games/v1',
            '/teams/leave',
            array(
                'methods' => \WP_REST_Server::CREATABLE,
                'callback' => array(__CLASS__, 'leave_team'),
                'permission_callback' => array(Capabilities::class, 'can_play'),
            )
        );

        register_rest_route(
            'edu-games/v1',
            '/teams',
            array(
                array(
                    'methods' => \WP_REST_Server::READABLE,
                    'callback' => array(__CLASS__, 'list_teams'),
                    'permission_callback' => array(Capabilities::class, 'can_manage'),
                ),
                array(
                    'methods' => \WP_REST_Server::CREATABLE,
                    'callback' => array(__CLASS__, 'create_team'),
                    'permission_callback' => array(Capabilities::class, 'can_manage'),
                ),
            )
        );

        register_rest_route(
            'edu-games/v1',
            '/teams/(?P<id>\d+)/rename',
            array(
                'methods' => \WP_REST_Server::CREATABLE,
                'callback' => array(__CLASS__, 'rename_team'),
                'permission_callback' => array(Capabilities::class, 'can_manage'),
                'args' => array(
                    'id' => array('required' => true, 'sanitize_callback' => 'absint'),
                ),
            )
        );

        register_rest_route(
            'edu-games/v1',
            '/teams/(?P<id>\d+)/reset-invite-code',
            array(
                'methods' => \WP_REST_Server::CREATABLE,
                'callback' => array(__CLASS__, 'reset_invite_code'),
                'permission_callback' => array(Capabilities::class, 'can_manage'),
                'args' => array(
                    'id' => array('required' => true, 'sanitize_callback' => 'absint'),
                ),
            )
        );

        register_rest_route(
            'edu-games/v1',
            '/teams/(?P<id>\d+)',
            array(
                'methods' => \WP_REST_Server::DELETABLE,
                'callback' => array(__CLASS__, 'delete_team'),
                'permission_callback' => array(Capabilities::class, 'can_manage'),
                'args' => array(
                    'id' => array('required' => true, 'sanitize_callback' => 'absint'),
                ),
            )
        );
    }

    public static function get_my_team(\WP_REST_Request $request) {
        $team = Team_Repository::team_for_user(get_current_user_id());

        return rest_ensure_response(null === $team ? array('team' => null) : array('team' => self::present($team, true)));
    }

    public static function join_team(\WP_REST_Request $request) {
        $params = $request->get_json_params();
        $code = is_array($params) && isset($params['inviteCode']) ? sanitize_text_field((string) $params['inviteCode']) : '';
        $team = '' === $code ? null : Team_Repository::find_by_invite_code($code);

        if (null === $team) {
            return new \WP_Error(
                'edu_games_team_not_found',
                __('초대 코드를 확인해 주세요.', 'edu-games'),
                array('status' => 404)
            );
        }

        Team_Repository::join((int) $team['id'], get_current_user_id());

        return rest_ensure_response(array('team' => self::present($team, true)));
    }

    public static function leave_team(\WP_REST_Request $request) {
        Team_Repository::leave(get_current_user_id());

        return rest_ensure_response(array('team' => null));
    }

    public static function list_teams(\WP_REST_Request $request) {
        return rest_ensure_response(
            array_map(
                static fn(array $team): array => self::present($team, true),
                Team_Repository::all()
            )
        );
    }

    public static function create_team(\WP_REST_Request $request) {
        $params = $request->get_json_params();
        $name = is_array($params) && isset($params['name']) ? sanitize_text_field((string) $params['name']) : '';

        if ('' === $name) {
            return new \WP_Error(
                'edu_games_team_name_required',
                __('팀 이름을 입력해 주세요.', 'edu-games'),
                array('status' => 400)
            );
        }

        $team_id = Team_Repository::create($name, get_current_user_id());
        $team = Team_Repository::find($team_id);

        if (null === $team) {
            return new \WP_Error(
                'edu_games_team_create_failed',
                __('팀을 만들지 못했습니다.', 'edu-games'),
                array('status' => 500)
            );
        }

        return rest_ensure_response(array('team' => self::present($team, true)));
    }

    public static function rename_team(\WP_REST_Request $request) {
        $team_id = absint($request['id']);
        $params = $request->get_json_params();
        $name = is_array($params) && isset($params['name']) ? sanitize_text_field((string) $params['name']) : '';

        if ('' === $name || null === Team_Repository::find($team_id)) {
            return new \WP_Error(
                'edu_games_team_invalid',
                __('팀 이름 또는 팀을 확인해 주세요.', 'edu-games'),
                array('status' => 400)
            );
        }

        Team_Repository::rename($team_id, $name);

        return rest_ensure_response(array('team' => self::present((array) Team_Repository::find($team_id), true)));
    }

    public static function reset_invite_code(\WP_REST_Request $request) {
        $team_id = absint($request['id']);

        if (null === Team_Repository::find($team_id)) {
            return new \WP_Error(
                'edu_games_team_not_found',
                __('팀을 찾을 수 없습니다.', 'edu-games'),
                array('status' => 404)
            );
        }

        Team_Repository::reset_invite_code($team_id);

        return rest_ensure_response(array('team' => self::present((array) Team_Repository::find($team_id), true)));
    }

    public static function delete_team(\WP_REST_Request $request) {
        $team_id = absint($request['id']);
        Team_Repository::delete($team_id);

        return rest_ensure_response(array('deleted' => true));
    }

    /**
     * @param array<string, mixed> $team
     * @return array<string, mixed>
     */
    private static function present(array $team, bool $include_invite_code): array {
        $presented = array(
            'id' => (int) $team['id'],
            'name' => (string) $team['name'],
            'memberCount' => isset($team['member_count']) ? (int) $team['member_count'] : count(Team_Repository::member_user_ids((int) $team['id'])),
        );

        if ($include_invite_code) {
            $presented['inviteCode'] = (string) $team['invite_code'];
        }

        return $presented;
    }
}
