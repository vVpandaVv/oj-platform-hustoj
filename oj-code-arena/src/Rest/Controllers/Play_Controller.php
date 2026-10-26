<?php
declare(strict_types=1);

namespace OjCodeArena\Rest\Controllers;

use OjCodeArena\Addon\Context_Resolver;
use OjCodeArena\Database\Round_Repository;
use OjCodeArena\Database\Session_Repository;
use OjCodeArena\Engine\Factory;
use OjCodeArena\Rest\Api;
use OjCodeArena\Support\Capabilities;
use OjCodeArena\Support\Game_Server;
use OjCodeArena\Support\Settings;
use OjCodeArena\Support\Ticket;

/** Learner endpoints: open class games, tickets, own records. */
final class Play_Controller {
    public static function register(): void {
        $can = array(Capabilities::class, 'can_play');
        register_rest_route(Api::NS, '/play', array('methods' => 'GET', 'callback' => array(__CLASS__, 'index'), 'permission_callback' => $can));
        register_rest_route(Api::NS, '/play/ticket', array('methods' => 'POST', 'callback' => array(__CLASS__, 'ticket'), 'permission_callback' => $can));
        register_rest_route(Api::NS, '/me/records', array('methods' => 'GET', 'callback' => array(__CLASS__, 'records'), 'permission_callback' => $can));
        // Built-in mode polling when relay.php cannot run on this host. The
        // signed ticket in the body is the authentication.
        register_rest_route(Api::NS, '/relay', array('methods' => 'POST', 'callback' => array(__CLASS__, 'relay'), 'permission_callback' => '__return_true'));
    }

    public static function index(): \WP_REST_Response {
        $uid = get_current_user_id();
        $sessions = (new Session_Repository())->open_for_learner(Context_Resolver::learning_context_keys($uid));
        return new \WP_REST_Response(array(
            'sessions' => array_map(static fn (array $s): array => array('id' => $s['id'], 'title' => $s['title'], 'context' => $s['context']), $sessions),
            'free' => Settings::free_enabled(),
        ));
    }

    /** @return \WP_REST_Response|\WP_Error */
    public static function ticket(\WP_REST_Request $request) {
        if (! Settings::configured()) {
            return Api::error('not_configured', '게임 서버가 설정되지 않았어요.', 503);
        }
        $uid = get_current_user_id();
        $sid = absint($request->get_param('session'));
        if ($sid > 0) {
            $session = (new Session_Repository())->find($sid);
            if (! $session || 'open' !== $session['status']) {
                return Api::error('no_session', '열려 있지 않은 수업 게임이에요.', 404);
            }
            if ('' !== $session['context_id']
                && null === Context_Resolver::authorize($uid, $session['context_type'], $session['context_id'], Context_Resolver::OPERATION_PARTICIPATE)) {
                return Api::error('forbidden', '이 반(그룹)의 학생만 들어갈 수 있어요.', 403);
            }
            // A learner may ask for a fresh push (game server restarted), but
            // at most once per 30 s per class game.
            $force = (bool) $request->get_param('force');
            if ($force) {
                $key = 'oj_code_arena_force_' . $sid;
                $force = ! get_transient($key);
                set_transient($key, 1, 30);
            }
            $pushed = Game_Server::ensure($session, $force);
            if (is_wp_error($pushed)) {
                return $pushed;
            }
        } elseif (! Settings::free_enabled()) {
            return Api::error('free_off', '자유 대전이 꺼져 있어요.', 403);
        }
        return new \WP_REST_Response(self::issue($uid, 'learner', $sid));
    }

    public static function records(): \WP_REST_Response {
        return new \WP_REST_Response(array('rounds' => (new Round_Repository())->for_user(get_current_user_id())));
    }

    public static function relay(\WP_REST_Request $request): \WP_REST_Response {
        global $wpdb;
        $res = new \WP_REST_Response(json_decode(Factory::handle_raw($wpdb, (string) $request->get_body()), true));
        $res->header('Cache-Control', 'no-store');
        return $res;
    }

    /**
     * Ticket plus how to reach the game: "poll" (built-in, relay.php with a
     * REST fallback) or "ws" (Node game server).
     *
     * @return array<string,string>
     */
    public static function issue(int $uid, string $role, int $sid): array {
        $user = wp_get_current_user();
        $where = Settings::uses_node()
            ? array('mode' => 'ws', 'url' => Settings::public_url())
            : array('mode' => 'poll', 'url' => OJ_CODE_ARENA_URL . 'relay.php', 'rest' => esc_url_raw(rest_url('oj-code-arena/v1/relay')));
        return $where + array(
            'ticket' => Ticket::issue(array(
                'uid' => $uid,
                'name' => $user && $user->exists() ? mb_substr((string) $user->display_name, 0, 40) : '학생',
                'role' => $role,
                'sid' => $sid,
                'free' => Settings::free_enabled(),
            ), Settings::secret()),
        );
    }
}
