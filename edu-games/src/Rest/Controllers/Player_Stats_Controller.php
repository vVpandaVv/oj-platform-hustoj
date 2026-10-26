<?php
declare(strict_types=1);

namespace EduGames\Rest\Controllers;

use EduGames\Statistics\Player_Statistics_Service;
use EduGames\Support\Capabilities;

final class Player_Stats_Controller {
    public static function register_routes(): void {
        register_rest_route(
            'edu-games/v1',
            '/players/me/stats',
            array(
                'methods' => \WP_REST_Server::READABLE,
                'callback' => array(__CLASS__, 'get_my_stats'),
                'permission_callback' => array(__CLASS__, 'can_read_stats'),
            )
        );

        register_rest_route(
            'edu-games/v1',
            '/players/me/games/(?P<game>[a-z0-9-]+)/stats',
            array(
                'methods' => \WP_REST_Server::READABLE,
                'callback' => array(__CLASS__, 'get_my_game_stats'),
                'permission_callback' => array(__CLASS__, 'can_read_stats'),
                'args' => array(
                    'game' => array(
                        'required' => true,
                        'sanitize_callback' => 'sanitize_key',
                    ),
                ),
            )
        );
    }

    public static function can_read_stats(): bool {
        return Capabilities::can_play();
    }

    public static function get_my_stats(\WP_REST_Request $request): \WP_REST_Response {
        return rest_ensure_response(Player_Statistics_Service::for_user(get_current_user_id()));
    }

    public static function get_my_game_stats(\WP_REST_Request $request): \WP_REST_Response {
        return rest_ensure_response(
            Player_Statistics_Service::for_user(
                get_current_user_id(),
                sanitize_key((string) $request['game'])
            )
        );
    }
}
