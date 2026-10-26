<?php
declare(strict_types=1);

namespace FocusTracker\Rest\Controllers;

use FocusTracker\Addon\Context_Resolver;
use FocusTracker\Support\Capabilities;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Lets the console dashboard's "새 활동 만들기" form populate a class/group
 * picker without the addon touching oj-platform's internal repositories —
 * thin passthrough to Context_Resolver::list_teaching_contexts().
 */
final class Contexts_Controller {
    public static function register_routes(): void {
        register_rest_route('focus-tracker/v1', '/contexts', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array(__CLASS__, 'index'),
            'permission_callback' => array(__CLASS__, 'can_manage'),
        ));
    }

    public static function can_manage(): bool|WP_Error {
        return Capabilities::can_manage() ?: new WP_Error('focus_tracker_forbidden', __('관리 권한이 없습니다.', 'focus-tracker'), array('status' => 403));
    }

    public static function index(WP_REST_Request $request): WP_REST_Response {
        $contexts = Context_Resolver::list_teaching_contexts(get_current_user_id());

        return rest_ensure_response(array_map(static function (array $context): array {
            return array(
                'type' => $context['type'],
                'id' => $context['id'],
                'label' => $context['label'],
            );
        }, $contexts));
    }
}
