<?php
declare(strict_types=1);

namespace OjCodeArena\Rest\Controllers;

use OjCodeArena\Database\Round_Repository;
use OjCodeArena\Database\Session_Repository;
use OjCodeArena\Rest\Api;
use OjCodeArena\Support\Capabilities;
use OjCodeArena\Support\Game_Server;
use OjCodeArena\Support\Settings;
use OjCodeArena\Support\Ticket;

/**
 * 교사 메뉴 › 코드 아레나 › 설정 → 부하 시험: a throw-away class game plus
 * tickets for virtual learners, driven from the browser of a site admin or
 * an educator with the "코드 아레나 설정" capability
 * (resources/js/admin-loadtest.js). Built-in engine only.
 */
final class Loadtest_Controller {
    public const UID_BASE = 2000000000;
    private const MAX = 60;

    public static function register(): void {
        $can = static fn (): bool => Capabilities::can_manage_settings();
        register_rest_route(Api::NS, '/admin/loadtest', array('methods' => 'POST', 'callback' => array(__CLASS__, 'start'), 'permission_callback' => $can));
        register_rest_route(Api::NS, '/admin/loadtest/(?P<id>\d+)', array('methods' => 'DELETE', 'callback' => array(__CLASS__, 'finish'), 'permission_callback' => $can));
    }

    /** @return \WP_REST_Response|\WP_Error */
    public static function start(\WP_REST_Request $request) {
        if (Settings::uses_node()) {
            return Api::error('node', '부하 시험은 내장 엔진에서만 할 수 있어요.', 409);
        }
        $count = max(1, min(self::MAX, (int) $request->get_param('count')));
        $minutes = max(1, min(5, (int) $request->get_param('minutes')));
        $session = (new Session_Repository())->create(array(
            'title' => '부하 시험 (지워짐)',
            'owner_id' => get_current_user_id(),
            'settings' => array('teams' => min(8, max(2, (int) ceil($count / 6))), 'limitMin' => $minutes, 'selfPick' => true, 'penalty' => false, 'bossEverySec' => 20),
        ));
        if (! $session) {
            return Api::error('db', '시험 게임을 만들지 못했어요.', 500);
        }
        $pushed = Game_Server::ensure($session, true);
        if (is_wp_error($pushed)) {
            return $pushed;
        }
        $tickets = array();
        for ($k = 1; $k <= $count; $k++) {
            $tickets[] = Ticket::issue(array('uid' => self::UID_BASE + $k, 'name' => '가상학생 ' . $k, 'role' => 'learner', 'sid' => $session['id'], 'free' => false), Settings::secret());
        }
        $teacher = Play_Controller::issue(get_current_user_id(), 'teacher', $session['id']);
        return new \WP_REST_Response(array(
            'session' => $session['id'],
            'teams' => $session['settings']['teams'],
            'relay' => $teacher['url'],
            'rest' => $teacher['rest'] ?? '',
            'teacher' => $teacher['ticket'],
            'tickets' => $tickets,
        ));
    }

    public static function finish(\WP_REST_Request $request): \WP_REST_Response {
        $id = (int) $request['id'];
        $session = (new Session_Repository())->find($id);
        if ($session && '부하 시험 (지워짐)' === $session['title']) {
            Game_Server::close($id);
            (new Session_Repository())->update($id, array('status' => 'archived'));
            (new Round_Repository())->delete_for_session($id);
        }
        return new \WP_REST_Response(array('ok' => true));
    }
}
