<?php
/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * Additional permission: see PLATFORM-LINKING-EXCEPTION.txt.
 */
declare(strict_types=1);

namespace OjCodeLab\Rest\Controllers;

use OjCodeLab\Addon\Context_Resolver;
use OjCodeLab\Support\Capabilities;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Lets the teacher console dashboard's class/group picker populate itself
 * without touching oj-platform's internal repositories — thin passthrough
 * to Context_Resolver::list_teaching_contexts().
 */
final class Contexts_Controller {
    public static function register_routes(): void {
        register_rest_route('oj-code-lab/v1', '/contexts', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array(__CLASS__, 'index'),
            'permission_callback' => array(__CLASS__, 'can_review'),
        ));
    }

    public static function can_review(): bool|WP_Error {
        return Capabilities::can_review() ?: new WP_Error('oj_code_lab_forbidden', __('열람 권한이 없습니다.', 'oj-code-lab'), array('status' => 403));
    }

    public static function index(WP_REST_Request $request): WP_REST_Response {
        return rest_ensure_response(Context_Resolver::list_teaching_contexts(get_current_user_id()));
    }
}
