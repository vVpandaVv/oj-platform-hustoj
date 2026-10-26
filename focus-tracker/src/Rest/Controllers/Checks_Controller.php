<?php
declare(strict_types=1);

namespace FocusTracker\Rest\Controllers;

use FocusTracker\Checks\Check_Service;
use FocusTracker\Support\Capabilities;
use FocusTracker\Support\Request_Limiter;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class Checks_Controller {
    public static function register_routes(): void {
        register_rest_route('focus-tracker/v1', '/checks/next', array(
            'methods' => WP_REST_Server::CREATABLE, 'callback' => array(__CLASS__, 'next'), 'permission_callback' => array(__CLASS__, 'authorize'),
        ));
        register_rest_route('focus-tracker/v1', '/checks/(?P<id>\d+)/response', array(
            'methods' => WP_REST_Server::CREATABLE, 'callback' => array(__CLASS__, 'respond'), 'permission_callback' => array(__CLASS__, 'authorize'),
        ));
        register_rest_route('focus-tracker/v1', '/checks/summary', array(
            'methods' => WP_REST_Server::READABLE, 'callback' => array(__CLASS__, 'summary'), 'permission_callback' => array(__CLASS__, 'authorize_summary'),
        ));
    }

    public static function authorize(WP_REST_Request $request): bool|WP_Error {
        return self::permission($request, Capabilities::can_participate());
    }

    public static function authorize_summary(WP_REST_Request $request): bool|WP_Error {
        return self::permission($request, Capabilities::can_participate() || Capabilities::can_view_self());
    }

    private static function permission(WP_REST_Request $request, bool $allowed): bool|WP_Error {
        if (! is_user_logged_in() || ! $allowed) { return new WP_Error('focus_tracker_forbidden', '학습 확인 권한이 없습니다.', array('status' => 403)); }
        $size = Request_Limiter::body_limit($request, 524288);
        if ($size !== null) { return $size; }
        $nonce = (string) $request->get_param('_wpnonce');
        if ('' === $nonce && method_exists($request, 'get_header')) { $nonce = (string) $request->get_header('X-WP-Nonce'); }
        if (false === wp_verify_nonce($nonce, 'wp_rest')) { return new WP_Error('focus_tracker_bad_nonce', '요청을 검증할 수 없습니다.', array('status' => 403)); }
        $params = $request->get_json_params();
        if (is_array($params) && isset($params['user_id']) && (int) $params['user_id'] !== get_current_user_id()) {
            return new WP_Error('focus_tracker_account_changed', '로그인 계정이 변경되었습니다.', array('status' => 403));
        }
        return true;
    }

    public static function next(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $size = Request_Limiter::body_limit($request, 524288);
        if ($size !== null) { return $size; }
        $result = Check_Service::next(get_current_user_id(), self::body($request));
        return $result instanceof WP_Error ? $result : rest_ensure_response($result);
    }

    public static function respond(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $size = Request_Limiter::body_limit($request, 8192);
        if ($size !== null) { return $size; }
        $result = Check_Service::respond(get_current_user_id(), (int) $request->get_param('id'), self::body($request));
        return $result instanceof WP_Error ? $result : rest_ensure_response($result);
    }

    public static function summary(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $limit = Request_Limiter::check(get_current_user_id(), 'check_summary');
        if ($limit !== null) { return $limit; }
        return rest_ensure_response(Check_Service::summary(get_current_user_id()));
    }

    private static function body(WP_REST_Request $request): array {
        $body = $request->get_json_params();
        return is_array($body) ? $body : array();
    }
}
