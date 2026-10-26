<?php
declare(strict_types=1);

namespace OjCodeArena\Support;

use OjCodeArena\Addon\Context_Resolver;
use OjCodeArena\Database\Problem_Repository;
use OjCodeArena\Engine\Factory;

/**
 * Hands class games to whichever engine runs them: the built-in engine
 * inside WordPress (default), or the separate Node game server over
 * signed HTTP calls. Push (create/update), close, status.
 */
final class Game_Server {
    private const PUSHED = 'oj_code_arena_pushed_';

    /**
     * Makes sure the game server has this class game with the current
     * settings, roster and problem bank. Skips the call when nothing changed
     * recently, unless $force.
     *
     * @param array<string,mixed> $session
     * @return true|\WP_Error
     */
    public static function ensure(array $session, bool $force = false) {
        $repo = new Problem_Repository();
        $stamp = $session['updated_at'] . '|' . Problem_Repository::version();
        $key = self::PUSHED . (int) $session['id'];
        if (! $force && get_transient($key) === $stamp) {
            return true;
        }
        $roster = null;
        if ('' !== $session['context_id']) {
            $roster = self::roster($session);
            if (null === $roster) {
                return new \WP_Error('oj_code_arena_roster', '반/그룹 명단을 읽지 못했어요. 수업 게임을 만든 선생님이 이 반을 관리할 수 있는지 확인해 주세요.', array('status' => 409));
            }
        }
        $payload = array(
            'id' => (int) $session['id'],
            'title' => $session['title'],
            'context' => $session['context'],
            'settings' => $session['settings'],
            'roster' => $roster,
            'problems' => $repo->for_game(),
        );
        if (! Settings::uses_node()) {
            global $wpdb;
            Factory::relay($wpdb)->upsert_session($payload);
            set_transient($key, $stamp, 10 * MINUTE_IN_SECONDS);
            return true;
        }
        $res = self::call('POST', '/api/sessions', $payload);
        if (is_wp_error($res)) {
            return $res;
        }
        if (empty($res['ok'])) {
            return new \WP_Error('oj_code_arena_push', '게임 서버가 수업 게임을 받지 않았어요: ' . (string) ($res['why'] ?? '?'), array('status' => 502));
        }
        set_transient($key, $stamp, 10 * MINUTE_IN_SECONDS);
        return true;
    }

    /** @return true|\WP_Error */
    public static function close(int $session_id) {
        delete_transient(self::PUSHED . $session_id);
        if (! Settings::uses_node()) {
            global $wpdb;
            Factory::relay($wpdb)->close_session($session_id);
            return true;
        }
        $res = self::call('POST', '/api/sessions/close', array('id' => $session_id));
        return is_wp_error($res) ? $res : true;
    }

    /** @return array<string,mixed>|\WP_Error */
    public static function status() {
        if (! Settings::uses_node()) {
            global $wpdb;
            return array('ok' => true, 'version' => '내장 ' . OJ_CODE_ARENA_VERSION) + Factory::relay($wpdb)->status();
        }
        $result = self::call('GET', '/status', null);
        if (! is_wp_error($result) && (empty($result['authenticated']) || 'oj-realtime-hub' !== ($result['service'] ?? '')
            || 'oj-code-arena' !== ($result['app'] ?? '') || 1 !== ($result['protocol'] ?? null))) {
            return new \WP_Error('oj_code_arena_hub_registration', '공통 서버의 아레나 등록·인증을 확인하지 못했습니다. 새 설치 명령으로 갱신하세요.', array('status' => 502));
        }
        return $result;
    }

    /**
     * Learners of the session's class/group, read with the session owner's
     * authority (the gateway only lets class managers list a roster).
     *
     * @param array<string,mixed> $session
     * @return list<array{uid:int, name:string}>|null
     */
    private static function roster(array $session): ?array {
        $owner = (int) $session['owner_id'];
        $revision = Context_Resolver::authorize($owner, $session['context_type'], $session['context_id'], Context_Resolver::OPERATION_MANAGE);
        if (null === $revision) {
            return null;
        }
        $out = array();
        foreach (Context_Resolver::list_all_learner_ids($owner, $session['context_type'], $session['context_id'], $revision) as $uid) {
            $user = get_userdata($uid);
            $out[] = array('uid' => (int) $uid, 'name' => $user ? (string) $user->display_name : '학생 ' . $uid);
        }
        return $out;
    }

    /**
     * @param array<string,mixed>|null $body
     * @return array<string,mixed>|\WP_Error
     */
    private static function call(string $method, string $route, ?array $body) {
        if (! Settings::configured()) {
            return new \WP_Error('oj_code_arena_not_configured', '게임 서버 주소와 비밀키가 설정되지 않았어요. (교사 메뉴 › 코드 아레나 › 설정)', array('status' => 503));
        }
        $raw = null === $body ? '' : (string) wp_json_encode($body, JSON_UNESCAPED_UNICODE);
        $sig = Ticket::sign($method, $route, $raw, Settings::secret());
        $args = array(
            'method' => $method,
            'timeout' => 8,
            'headers' => array(
                'Content-Type' => 'application/json',
                'X-Arena-Time' => $sig['time'],
                'X-Arena-Signature' => $sig['signature'],
                'X-OJ-Realtime-Time' => $sig['time'],
                'X-OJ-Realtime-Signature' => $sig['signature'],
            ),
        );
        if ('GET' !== $method) {
            $args['body'] = $raw;
        }
        $res = wp_remote_request(Settings::api_url() . $route, $args);
        if (is_wp_error($res)) {
            return new \WP_Error('oj_code_arena_unreachable', '게임 서버에 연결할 수 없어요: ' . $res->get_error_message(), array('status' => 502));
        }
        $code = (int) wp_remote_retrieve_response_code($res);
        $data = json_decode((string) wp_remote_retrieve_body($res), true);
        if (401 === $code) {
            return new \WP_Error('oj_code_arena_secret', '게임 서버가 서명을 거부했어요. 양쪽 비밀키가 같은지 확인해 주세요.', array('status' => 502));
        }
        if ($code < 200 || $code >= 300 || ! is_array($data)) {
            return new \WP_Error('oj_code_arena_bad_response', '게임 서버 응답이 올바르지 않아요 (HTTP ' . $code . ').', array('status' => 502));
        }
        return $data;
    }
}
