<?php
declare(strict_types=1);

namespace {
    final class WP_Error {
        public function __construct(public string $code, public string $message, public array $data = array()) {}
    }
    final class WP_REST_Request implements ArrayAccess {
        public function __construct(private array $params) {}
        public function get_json_params(): array { return $this->params; }
        public function offsetExists(mixed $offset): bool { return isset($this->params[$offset]); }
        public function offsetGet(mixed $offset): mixed { return $this->params[$offset] ?? null; }
        public function offsetSet(mixed $offset, mixed $value): void { $this->params[$offset] = $value; }
        public function offsetUnset(mixed $offset): void { unset($this->params[$offset]); }
    }
    $transients = array();
    $actor = 1;
    function __(string $text, string $domain = ''): string { return $text; }
    function sanitize_text_field(string $value): string { return trim(strip_tags($value)); }
    function sanitize_key(string $value): string { return strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '', $value)); }
    function get_transient(string $key): mixed { return $GLOBALS['transients'][$key] ?? false; }
    function set_transient(string $key, mixed $value, int $ttl): void { $GLOBALS['transients'][$key] = $value; }
    function current_time(string $format, bool $gmt = false): string { return gmdate('Y-m-d H:i:s'); }
    function get_current_user_id(): int { return $GLOBALS['actor']; }
    function wp_get_current_user(): object { return (object) array('display_name' => '학생'); }
    function get_user_meta(int $id, string $key, bool $single): string { return '관리용 이름'; }
    function rest_ensure_response(mixed $value): mixed { return $value; }
    function is_wp_error(mixed $value): bool { return $value instanceof WP_Error; }
    function ensure(bool $value, string $message): void { if (!$value) { throw new RuntimeException($message); } }
}

namespace TeachingTools\Rest\Controllers {
    final class Roster_Controller {
        public static function students_for_context(string $type, string $id, string $revision, int $actor): array|\WP_Error {
            if ($actor !== 1 || $type !== 'group' || $id !== '5' || $revision !== 'r1') {
                return new \WP_Error('forbidden', 'permission denied');
            }
            return array(
                array('id' => 2, 'displayName' => '학생 둘', 'managementName' => '2번'),
                array('id' => 3, 'displayName' => '학생 셋', 'managementName' => '3번'),
            );
        }
    }
}

namespace TeachingTools\Sessions {
    final class Tool_Session_Repository {
        public const VALID_TOOL_TYPES = array('timer', 'seating', 'picker', 'ladder', 'whiteboard', 'quickboard', 'quiz', 'grouping', 'timeattack', 'classroom');
        public const VALID_MODES = array('solo', 'live');
        public static array $sessions = array();
        public static array $participants = array();
        public static function create(int $owner, string $tool, string $mode, array $state, ?string $type, ?string $context, array $participants): array {
            $id = count(self::$sessions) + 1;
            $code = 'CODE' . str_pad((string) $id, 2, '0', STR_PAD_LEFT);
            $session = array('id' => $id, 'join_code' => $code, 'owner_user_id' => $owner, 'tool_type' => $tool, 'mode' => $mode,
                'state' => json_encode($state), 'context_type' => $type, 'context_id' => $context,
                'status' => 'active', 'updated_at' => '', 'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600));
            self::$sessions[$code] = $session;
            foreach ($participants as $participant) { self::$participants[$id][$participant['id']] = $participant['data']; }
            return $session;
        }
        public static function find_by_code(string $code): ?array { return self::$sessions[strtoupper($code)] ?? null; }
        public static function find(int $id): ?array {
            foreach (self::$sessions as $session) { if ($session['id'] === $id) { return $session; } }
            return null;
        }
        public static function update_state(int $id, array $state): bool {
            foreach (self::$sessions as $code => $session) {
                if ($session['id'] === $id) { self::$sessions[$code]['state'] = json_encode($state); return true; }
            }
            return false;
        }
        public static function find_active_by_code(string $code): ?array {
            $session = self::find_by_code($code);
            return $session && $session['status'] === 'active' ? $session : null;
        }
        public static function host_role(int $id, int $actor): ?string { return $actor === 1 ? 'owner' : null; }
        public static function is_participant(int $id, int $actor): bool { return isset(self::$participants[$id][$actor]); }
        public static function count_participants(int $id): int { return count(self::$participants[$id] ?? array()); }
        public static function participant_data(int $id, int $actor): array { return self::$participants[$id][$actor] ?? array(); }
        public static function add_participant(int $id, int $actor, array $data): bool { self::$participants[$id][$actor] = $data; return true; }
        public static function assigned_sessions(int $actor): array {
            return array_values(array_filter(self::$sessions, static fn (array $session): bool => self::is_participant($session['id'], $actor)
                && $session['mode'] === 'live' && $session['status'] === 'active' && $session['context_type'] !== null));
        }
    }
}

namespace {
    require __DIR__ . '/../src/Sessions/Session_Code_Guard.php';
    require __DIR__ . '/../src/Rest/Controllers/Sessions_Controller.php';
    use TeachingTools\Sessions\Session_Code_Guard as Guard;
    use TeachingTools\Sessions\Tool_Session_Repository as Repository;
    use TeachingTools\Rest\Controllers\Sessions_Controller as Sessions;

    $context = array('type' => 'group', 'id' => '5', 'revision' => 'r1', 'label' => '3반');
    foreach (Repository::VALID_TOOL_TYPES as $tool) {
        $state = $tool === 'timeattack' ? array('roster' => array(array('id' => 2, 'name' => '학생 둘'))) : array();
        $auto_open = in_array($tool, array('whiteboard', 'quiz', 'classroom'), true);
        $result = Sessions::create_session(new WP_REST_Request(array('toolType' => $tool, 'mode' => 'live', 'context' => $context, 'state' => $state, 'autoOpen' => $auto_open)));
        ensure(!is_wp_error($result) && $result['participantCount'] === 2, $tool . ' must enroll the verified group');
        $updated = Sessions::update_state(new WP_REST_Request(array('code' => $result['joinCode'], 'state' => array('page' => 2, 'audience' => array('label' => 'forged')))));
        ensure(!is_wp_error($updated) && $updated['state']['context']['id'] === '5'
            && $updated['state']['audience']['label'] === '3반'
            && $updated['state']['audience']['autoOpen'] === $auto_open, $tool . ' must preserve verified group metadata on sync');
    }
    $actor = 2;
    $transients['httt_code_guard_2'] = 999;
    ensure(Guard::check(2, 'CODE01') === true, 'Old background lock must not block first login');
    for ($i = 0; $i < 50; ++$i) { Guard::record_failed_attempt(2, 'STALE1'); }
    ensure(Guard::check(2, 'GUESS1') === true, 'Polling one stale code must count once');
    for ($i = 0; $i < 10; ++$i) { Guard::record_failed_attempt(2, 'BAD' . $i); }
    ensure(is_wp_error(Guard::check(2, 'GUESS2')), 'Distinct code guessing remains limited');
    ensure(!is_wp_error(Sessions::join_session(new WP_REST_Request(array('code' => 'CODE01')))), 'Assigned student must join even when guessing is limited');
    ensure(Repository::$participants[1][2]['displayName'] === '학생 둘' && Repository::$participants[1][2]['managementName'] === '2번', 'Automatic entry must preserve the platform roster names');
    ensure(!is_wp_error(Sessions::get_session(new WP_REST_Request(array('code' => 'CODE01')))), 'Assigned student must poll normally');
    $actor = 1;
    for ($i = 0; $i < 10; ++$i) { Guard::record_failed_attempt(1, 'BAD' . $i); }
    ensure(!is_wp_error(Sessions::get_session(new WP_REST_Request(array('code' => 'CODE01')))), 'Teacher must access owned lesson');
    $actor = 9;
    ensure(is_wp_error(Sessions::join_session(new WP_REST_Request(array('code' => 'CODE05')))), 'Outside students cannot join group whiteboard');
    ensure(Sessions::assigned_sessions()['items'] === array(), 'Outside students cannot discover a group');
    $actor = 2;
    ensure(count(Sessions::assigned_sessions()['items']) === 10, 'All assigned activity types are discoverable');
    $automatic = array_filter(Sessions::assigned_sessions()['items'], static fn (array $item): bool => $item['autoOpen']);
    ensure(count($automatic) === 3, 'Only explicitly enabled group sessions may open the activity screen');
    $actor = 1;
    $public = Sessions::create_session(new WP_REST_Request(array('toolType' => 'whiteboard', 'mode' => 'live', 'autoOpen' => true, 'state' => array('audience' => array('autoOpen' => true)))));
    ensure(!isset($public['state']['audience']), 'Code-only sessions must not forge automatic navigation');
    Repository::$sessions['CODE01']['status'] = 'ended';
    for ($i = 0; $i < 20; ++$i) { Guard::record_failed_attempt(3, 'CODE01'); }
    ensure(Guard::check(3, 'NEW123') === true, 'Ended assigned lesson must never count as a guess');
    fwrite(STDOUT, "Session admission tests passed: all 10 tools, known-session bypass, stale-code deduplication and group permissions.\n");
}
