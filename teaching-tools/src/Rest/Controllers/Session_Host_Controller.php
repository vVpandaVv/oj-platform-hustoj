<?php
declare(strict_types=1);

namespace TeachingTools\Rest\Controllers;

use TeachingTools\Sessions\Session_Code_Guard;
use TeachingTools\Sessions\Tool_Session_Repository;
use TeachingTools\Support\Capabilities;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

final class Session_Host_Controller {
    private const CODE_PATTERN = '(?P<code>[A-Za-z0-9]{6})';

    public static function register_routes(): void {
        register_rest_route('teaching-tools/v1', '/sessions/' . self::CODE_PATTERN . '/hosts', array(
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array(__CLASS__, 'index'),
                'permission_callback' => array(Capabilities::class, 'can_host'),
            ),
            array(
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => array(__CLASS__, 'save'),
                'permission_callback' => array(Capabilities::class, 'can_host'),
            ),
            array(
                'methods' => WP_REST_Server::DELETABLE,
                'callback' => array(__CLASS__, 'delete'),
                'permission_callback' => array(Capabilities::class, 'can_host'),
            ),
        ));
        register_rest_route('teaching-tools/v1', '/sessions/' . self::CODE_PATTERN . '/hosts/transfer', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array(__CLASS__, 'transfer'),
            'permission_callback' => array(Capabilities::class, 'can_host'),
        ));
    }

    public static function index(WP_REST_Request $request) {
        $session = self::host_session($request, false);
        if (is_wp_error($session)) {
            return $session;
        }

        return rest_ensure_response(array('hosts' => Tool_Session_Repository::hosts((int) $session['id'])));
    }

    public static function save(WP_REST_Request $request) {
        $session = self::host_session($request, true);
        if (is_wp_error($session)) {
            return $session;
        }
        $params = $request->get_json_params();
        $params = is_array($params) ? $params : array();
        $id = absint($params['id'] ?? 0);
        $role = sanitize_key((string) ($params['role'] ?? 'observer'));
        if ($id < 1 || ! user_can($id, Capabilities::HOST) || ! Tool_Session_Repository::set_host((int) $session['id'], $id, $role)) {
            return new WP_Error('teaching_tools_invalid_session_host', __('공동 교사 ID와 역할을 확인해 주세요.', 'teaching-tools'), array('status' => 400));
        }

        return self::index($request);
    }

    public static function delete(WP_REST_Request $request) {
        $session = self::host_session($request, true);
        if (is_wp_error($session)) {
            return $session;
        }
        $params = $request->get_json_params();
        $id = absint(is_array($params) ? ($params['id'] ?? 0) : 0);
        if ($id < 1 || 'owner' === Tool_Session_Repository::host_role((int) $session['id'], $id)) {
            return new WP_Error('teaching_tools_invalid_session_host', __('공동 교사를 확인해 주세요.', 'teaching-tools'), array('status' => 400));
        }
        Tool_Session_Repository::remove_host((int) $session['id'], $id);

        return self::index($request);
    }

    public static function transfer(WP_REST_Request $request) {
        $session = self::host_session($request, true);
        if (is_wp_error($session)) {
            return $session;
        }
        $params = $request->get_json_params();
        $id = absint(is_array($params) ? ($params['id'] ?? 0) : 0);
        if (! Tool_Session_Repository::transfer_ownership((int) $session['id'], get_current_user_id(), $id)) {
            return new WP_Error('teaching_tools_session_transfer_failed', __('진행자 권한을 넘기지 못했습니다.', 'teaching-tools'), array('status' => 409));
        }

        return rest_ensure_response(array('transferred' => true, 'id' => $id));
    }

    /** @return array<string,mixed>|WP_Error */
    private static function host_session(WP_REST_Request $request, bool $owner_only) {
        $actor_id = get_current_user_id();
        $guard = Session_Code_Guard::check($actor_id, (string) $request['code']);
        if (true !== $guard) {
            return $guard;
        }
        $session = Tool_Session_Repository::find_active_by_code((string) $request['code']);
        $role = null === $session ? null : Tool_Session_Repository::host_role((int) $session['id'], $actor_id);
        if (null === $session || null === $role || ($owner_only && 'owner' !== $role)) {
            Session_Code_Guard::record_failed_attempt($actor_id, (string) $request['code']);

            return new WP_Error('teaching_tools_session_not_found', __('수업 세션을 확인해 주세요.', 'teaching-tools'), array('status' => 404));
        }

        return $session;
    }
}
