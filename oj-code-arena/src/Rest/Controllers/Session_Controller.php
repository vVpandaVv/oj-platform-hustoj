<?php
declare(strict_types=1);

namespace OjCodeArena\Rest\Controllers;

use OjCodeArena\Addon\Context_Resolver;
use OjCodeArena\Database\Problem_Repository;
use OjCodeArena\Database\Round_Repository;
use OjCodeArena\Database\Session_Repository;
use OjCodeArena\Rest\Api;
use OjCodeArena\Support\Capabilities;
use OjCodeArena\Support\Game_Server;
use OjCodeArena\Support\Game_Settings;
use OjCodeArena\Support\Settings;

/** Teacher endpoints for class games. */
final class Session_Controller {
    public static function register(): void {
        $can = array(Capabilities::class, 'can_teach');
        register_rest_route(Api::NS, '/contexts', array('methods' => 'GET', 'callback' => array(__CLASS__, 'contexts'), 'permission_callback' => $can));
        register_rest_route(Api::NS, '/sessions', array(
            array('methods' => 'GET', 'callback' => array(__CLASS__, 'index'), 'permission_callback' => $can),
            array('methods' => 'POST', 'callback' => array(__CLASS__, 'create'), 'permission_callback' => $can),
        ));
        register_rest_route(Api::NS, '/sessions/(?P<id>\d+)', array(
            array('methods' => 'PUT', 'callback' => array(__CLASS__, 'update'), 'permission_callback' => $can),
            array('methods' => 'DELETE', 'callback' => array(__CLASS__, 'close'), 'permission_callback' => $can),
        ));
        register_rest_route(Api::NS, '/sessions/(?P<id>\d+)/ticket', array('methods' => 'POST', 'callback' => array(__CLASS__, 'ticket'), 'permission_callback' => $can));
        register_rest_route(Api::NS, '/sessions/(?P<id>\d+)/records', array('methods' => 'GET', 'callback' => array(__CLASS__, 'records'), 'permission_callback' => $can));
    }

    public static function contexts(): \WP_REST_Response {
        return new \WP_REST_Response(array('contexts' => Context_Resolver::teaching_contexts(get_current_user_id())));
    }

    public static function index(): \WP_REST_Response {
        return new \WP_REST_Response(array(
            'sessions' => (new Session_Repository())->open_for_owner(get_current_user_id(), Capabilities::is_operator()),
            'defaults' => Game_Settings::defaults(),
            'tags' => (new Problem_Repository())->tags(),
            'configured' => Settings::configured(),
        ));
    }

    /** @return \WP_REST_Response|\WP_Error */
    public static function create(\WP_REST_Request $request) {
        $uid = get_current_user_id();
        $title = trim(sanitize_text_field((string) $request->get_param('title')));
        if ('' === $title) {
            return Api::error('title', '제목을 입력해 주세요.', 400);
        }
        $type = (string) $request->get_param('context_type');
        $id = sanitize_text_field((string) $request->get_param('context_id'));
        $label = '';
        if ('' !== $id) {
            if (! in_array($type, array('class', 'group'), true)
                || null === Context_Resolver::authorize($uid, $type, $id, Context_Resolver::OPERATION_MANAGE, $label)) {
                return Api::error('forbidden', '이 반(그룹)을 관리할 권한이 없어요.', 403);
            }
        } else {
            $type = '';
        }
        $session = (new Session_Repository())->create(array(
            'title' => $title,
            'owner_id' => $uid,
            'context_type' => $type,
            'context_id' => $id,
            'context_label' => (string) $label,
            'settings' => (array) $request->get_param('settings'),
        ));
        if (! $session) {
            return Api::error('db', '저장하지 못했어요.', 500);
        }
        return new \WP_REST_Response(array('session' => $session), 201);
    }

    /** @return \WP_REST_Response|\WP_Error */
    public static function update(\WP_REST_Request $request) {
        $session = self::owned((int) $request['id']);
        if (is_wp_error($session)) {
            return $session;
        }
        $fields = array();
        $title = trim(sanitize_text_field((string) $request->get_param('title')));
        if ('' !== $title) {
            $fields['title'] = $title;
        }
        if (is_array($request->get_param('settings'))) {
            $fields['settings'] = $request->get_param('settings');
        }
        $session = (new Session_Repository())->update($session['id'], $fields);
        if ($session && Settings::configured()) {
            // Best effort: the next ticket pushes again if the server is down.
            Game_Server::ensure($session, true);
        }
        return new \WP_REST_Response(array('session' => $session));
    }

    /** @return \WP_REST_Response|\WP_Error */
    public static function close(\WP_REST_Request $request) {
        $session = self::owned((int) $request['id']);
        if (is_wp_error($session)) {
            return $session;
        }
        (new Session_Repository())->update($session['id'], array('status' => 'archived'));
        $remote = Settings::configured() ? Game_Server::close($session['id']) : true;
        return new \WP_REST_Response(array('ok' => true, 'remote' => ! is_wp_error($remote)));
    }

    /** @return \WP_REST_Response|\WP_Error */
    public static function ticket(\WP_REST_Request $request) {
        $session = self::owned((int) $request['id']);
        if (is_wp_error($session)) {
            return $session;
        }
        if ('open' !== $session['status']) {
            return Api::error('closed', '닫힌 수업 게임이에요.', 409);
        }
        if (! Settings::configured()) {
            return Api::error('not_configured', '게임 서버가 설정되지 않았어요. (교사 메뉴 › 코드 아레나 › 설정)', 503);
        }
        $pushed = Game_Server::ensure($session, true);
        if (is_wp_error($pushed)) {
            return $pushed;
        }
        $role = 'board' === $request->get_param('role') ? 'board' : 'teacher';
        return new \WP_REST_Response(Play_Controller::issue(get_current_user_id(), $role, $session['id']));
    }

    /** @return \WP_REST_Response|\WP_Error */
    public static function records(\WP_REST_Request $request) {
        $session = self::owned((int) $request['id']);
        if (is_wp_error($session)) {
            return $session;
        }
        return new \WP_REST_Response(array('rounds' => (new Round_Repository())->for_sessions(array($session['id']), 50)));
    }

    /** @return array<string,mixed>|\WP_Error */
    private static function owned(int $id) {
        $session = (new Session_Repository())->find($id);
        if (! $session) {
            return Api::error('not_found', '수업 게임이 없어요.', 404);
        }
        if ($session['owner_id'] !== get_current_user_id() && ! Capabilities::is_operator()) {
            return Api::error('forbidden', '다른 선생님의 수업 게임이에요.', 403);
        }
        return $session;
    }
}
