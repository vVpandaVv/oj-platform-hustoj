<?php
declare(strict_types=1);

namespace FocusTracker\Rest\Controllers;

use FocusTracker\Support\Capabilities;
use FocusTracker\Support\Diagnostics;
use FocusTracker\Support\Request_Limiter;
use FocusTracker\Tracking\Activity_Window_Gate;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class Tracking_Controller {
    public static function register_routes(): void {
        register_rest_route('focus-tracker/v1', '/tracking/state', array('methods' => WP_REST_Server::CREATABLE,
            'callback' => array(__CLASS__, 'state'), 'permission_callback' => array(__CLASS__, 'authorize')));
    }

    public static function authorize(WP_REST_Request $request): bool|WP_Error {
        if (! is_user_logged_in() || ! Capabilities::can_participate()) { return new WP_Error('focus_tracker_forbidden', '추적 대상이 아닙니다.', array('status' => 403)); }
        $size = Request_Limiter::body_limit($request, 8192);
        if ($size !== null) { return $size; }
        $nonce = (string) $request->get_param('_wpnonce');
        if ($nonce === '' && method_exists($request, 'get_header')) { $nonce = (string) $request->get_header('X-WP-Nonce'); }
        if (false === wp_verify_nonce($nonce, 'wp_rest')) { return new WP_Error('focus_tracker_bad_nonce', '요청을 검증할 수 없습니다.', array('status' => 403)); }
        $body = $request->get_json_params();
        if (isset($body['user_id']) && (int) $body['user_id'] !== get_current_user_id()) { return new WP_Error('focus_tracker_account_changed', '로그인 계정이 변경되었습니다.', array('status' => 403)); }
        return true;
    }

    public static function state(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $size = Request_Limiter::body_limit($request, 8192);
        if ($size !== null) { Diagnostics::record('gate', 'invalid'); return $size; }
        $limit = Request_Limiter::check(get_current_user_id(), 'gate');
        if ($limit !== null) { Diagnostics::record('gate', $limit->get_error_code() === 'focus_tracker_rate_limited' ? 'limited' : 'store'); return $limit; }
        $body = $request->get_json_params();
        $body = is_array($body) ? $body : array();
        $problem = filter_var($body['problem_id'] ?? null, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1)));
        $type = $body['context_type'] ?? 'general';
        $id = $body['context_id'] ?? null;
        if ($problem === false || ! is_string($type) || ($id !== null && ! is_string($id) && ! is_int($id))) {
            Diagnostics::record('gate', 'invalid');
            return new WP_Error('focus_tracker_invalid_payload', '문제와 학습 문맥을 확인해 주세요.', array('status' => 400));
        }
        $state = Activity_Window_Gate::state(get_current_user_id(), $problem, $type, $id);
        Diagnostics::record('gate', $state instanceof WP_Error ? 'access' : (! empty($state['active']) ? 'success' : 'inactive'));
        return $state instanceof WP_Error ? $state : rest_ensure_response($state);
    }
}
