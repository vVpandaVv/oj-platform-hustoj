<?php
declare(strict_types=1);

namespace EduGames\Rest\Controllers;

use EduGames\Games\Game_Visibility;
use EduGames\Support\Capabilities;

/**
 * Operator-only game catalog management, used by the OJ Console addon
 * page instead of a standalone WordPress admin menu (C28-R01).
 */
final class Games_Admin_Controller {
    public static function register_routes(): void {
        register_rest_route(
            'edu-games/v1',
            '/admin/games',
            array(
                'methods' => \WP_REST_Server::READABLE,
                'callback' => array(__CLASS__, 'list_games'),
                'permission_callback' => array(Capabilities::class, 'can_manage'),
            )
        );

        register_rest_route(
            'edu-games/v1',
            '/admin/games/visibility',
            array(
                'methods' => \WP_REST_Server::CREATABLE,
                'callback' => array(__CLASS__, 'save_visibility'),
                'permission_callback' => array(Capabilities::class, 'can_manage'),
            )
        );
    }

    public static function list_games(\WP_REST_Request $request) {
        $games = function_exists('edu_games_registry') ? \edu_games_registry()->all(false) : array();
        $closed_ids = Game_Visibility::closed_ids();

        return rest_ensure_response(
            array_values(
                array_map(
                    static function (array $game) use ($closed_ids): array {
                        $game_id = sanitize_key((string) ($game['id'] ?? ''));

                        return array(
                            'id' => $game_id,
                            'title' => (string) ($game['title'] ?? $game_id),
                            'description' => (string) ($game['description'] ?? ''),
                            'category' => (string) ($game['category'] ?? ''),
                            'status' => (string) ($game['status'] ?? 'planned'),
                            'statusLabel' => (string) ($game['statusLabel'] ?? ''),
                            'block' => (string) ($game['block'] ?? ''),
                            'shortcode' => (string) ($game['shortcode'] ?? ''),
                            'isOpen' => ! in_array($game_id, $closed_ids, true),
                        );
                    },
                    $games
                )
            )
        );
    }

    public static function save_visibility(\WP_REST_Request $request) {
        $games = function_exists('edu_games_registry') ? \edu_games_registry()->all(false) : array();
        $all_ids = array_keys($games);

        $params = $request->get_json_params();
        $open_ids = is_array($params) && isset($params['openIds']) && is_array($params['openIds'])
            ? array_map('sanitize_key', $params['openIds'])
            : array();

        Game_Visibility::save_open_ids($open_ids, $all_ids);

        return rest_ensure_response(array('saved' => true));
    }
}
