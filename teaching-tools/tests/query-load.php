<?php
declare(strict_types=1);

namespace TeachingTools\Support {
    final class Capabilities {
        public static function can_host(): bool { return $GLOBALS['actor'] === 1; }
        public static function can_join(): bool { return $GLOBALS['actor'] > 0; }
    }
}

namespace {
    define('ARRAY_A', 'ARRAY_A');
    define('HOUR_IN_SECONDS', 3600);
    define('DAY_IN_SECONDS', 86400);
    $actor = 2;
    function __(string $text, string $domain = ''): string { return $text; }
    function sanitize_text_field(string $value): string { return trim(strip_tags($value)); }
    function sanitize_key(string $value): string { return strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '', $value)); }
    function get_current_user_id(): int { return $GLOBALS['actor']; }
    function wp_get_current_user(): object { return (object) array('display_name' => '학생'); }
    function get_user_meta(int $id, string $key, bool $single): string { return '관리 이름'; }
    function current_time(string $format, bool $gmt = false): string { return gmdate('Y-m-d H:i:s'); }
    function wp_json_encode(mixed $value): string { return json_encode($value, JSON_UNESCAPED_UNICODE); }
    function rest_ensure_response(mixed $value): mixed { return $value; }
    function get_transient(string $key): mixed { return false; }
    function set_transient(string $key, mixed $value, int $ttl): void {}
    function get_option(string $key): string { return '2.1.0'; }
    function is_admin(): bool { return false; }
    function ensure(bool $value, string $message): void { if (!$value) { throw new RuntimeException($message); } }
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
    final class Query_Db {
        public string $prefix = 'wp_';
        public array $queries = array();
        public array $sessions;
        public array $participants;
        public array $hosts = array('1:1' => 'owner');
        public function __construct() {
            $this->sessions = array(1 => array('id' => 1, 'join_code' => 'CODE01', 'owner_user_id' => 1,
                'tool_type' => 'classroom', 'mode' => 'live', 'context_type' => 'class', 'context_id' => '5',
                'state' => '{"audience":{"label":"3반","autoOpen":true}}', 'status' => 'active',
                'updated_at' => '', 'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600)));
            $this->participants = array('1:2' => array('id' => 10, 'data' => '{"displayName":"학생 둘","managementName":"2번"}'));
        }
        public function prepare(string $sql, mixed ...$values): string {
            return preg_replace_callback('/%[ds]/', static function (array $match) use (&$values): string {
                $value = array_shift($values);
                return $match[0] === '%d' ? (string) (int) $value : "'" . str_replace("'", "''", (string) $value) . "'";
            }, $sql);
        }
        private function pair(string $sql): string {
            preg_match('/session_id = (\d+) AND user_id = (\d+)/', $sql, $match);
            return ($match[1] ?? '') . ':' . ($match[2] ?? '');
        }
        public function get_row(string $sql, string $output): ?array {
            $this->queries[] = $sql;
            if (str_contains($sql, 'session_participants')) { return $this->participants[$this->pair($sql)] ?? null; }
            if (preg_match('/join_code = \'([^\']+)\'/', $sql, $match)) {
                foreach ($this->sessions as $row) { if ($row['join_code'] === $match[1]) { return $row; } }
            }
            if (preg_match('/WHERE id = (\d+)/', $sql, $match)) { return $this->sessions[(int) $match[1]] ?? null; }
            return null;
        }
        public function get_var(string $sql): mixed {
            $this->queries[] = $sql;
            if (str_contains($sql, 'SELECT role')) { return $this->hosts[$this->pair($sql)] ?? null; }
            if (str_contains($sql, 'SELECT tool_type')) { return 'classroom'; }
            if (str_contains($sql, 'COUNT(*)')) { return count($this->participants); }
            throw new RuntimeException('Unexpected query: ' . $sql);
        }
        public function get_results(string $sql, string $output): array {
            $this->queries[] = $sql;
            preg_match('/(?:participants|hosts)\.user_id = (\d+)/', $sql, $match);
            $user = (int) ($match[1] ?? 0);
            $is_host = str_contains($sql, 'hosts ON');
            return array_values(array_filter($this->sessions, fn (array $row): bool => $row['status'] === 'active'
                && ($is_host ? isset($this->hosts[$row['id'] . ':' . $user]) : isset($this->participants[$row['id'] . ':' . $user]))));
        }
        public function update(string $table, array $data, array $where, array $formats, array $where_formats): int {
            $this->queries[] = 'UPDATE ' . $table;
            if (str_contains($table, 'session_participants')) {
                foreach ($this->participants as &$row) { if ($row['id'] === $where['id']) { $row = array_merge($row, $data); } }
                unset($row);
            } else { $this->sessions[$where['id']] = array_merge($this->sessions[$where['id']], $data); }
            return 1;
        }
        public function delete(string $table, array $where, array $formats): int {
            $this->queries[] = 'DELETE ' . $table;
            unset($this->participants[$where['session_id'] . ':' . $where['user_id']]);
            return 1;
        }
        public function query(string $sql): int { $this->queries[] = $sql; return 1; }
    }
    require __DIR__ . '/../src/Database/Installer.php';
    require __DIR__ . '/../src/Sessions/Session_State_Policy.php';
    require __DIR__ . '/../src/Sessions/Tool_Session_Repository.php';
    require __DIR__ . '/../src/Sessions/Session_Code_Guard.php';
    require __DIR__ . '/../src/Rest/Controllers/Sessions_Controller.php';
    require __DIR__ . '/../src/Rest/Controllers/Classroom_Controller.php';
    use TeachingTools\Database\Installer;
    use TeachingTools\Sessions\Tool_Session_Repository as Repository;
    use TeachingTools\Rest\Controllers\Sessions_Controller as Sessions;
    use TeachingTools\Rest\Controllers\Classroom_Controller as Classroom;

    $wpdb = new Query_Db();
    Installer::maybe_install();
    ensure($wpdb->queries === array(), 'A normal request with the installed schema must issue no metadata queries');
    $request = new WP_REST_Request(array('code' => 'CODE01'));
    $result = Sessions::get_session($request);
    ensure(is_array($result) && $result['role'] === 'participant', 'Authorized student must receive the classroom');
    ensure(count($wpdb->queries) === 3, 'Classroom read must use three queries instead of nine: session, host, participant');
    Sessions::get_session($request);
    ensure(count($wpdb->queries) === 3, 'Same-request guard/controller reads must be reused');
    // Simulate a separate PHP request so the join budget cannot accidentally
    // depend on values primed by the preceding controller call.
    foreach (array('session_reads', 'session_ids', 'host_reads', 'participant_reads') as $property) {
        (new ReflectionProperty(Repository::class, $property))->setValue(null, array());
    }
    $wpdb->queries = array();
    $joined = Sessions::join_session($request);
    ensure(is_array($joined) && count($wpdb->queries) === 4, 'A fresh join request uses three authorization reads and one participant write');
    ensure(Repository::participant_data(1, 2)['displayName'] === '학생 둘', 'Join must retain the verified roster identity');

    $actor = 9;
    ensure(Sessions::get_session($request) instanceof WP_Error, 'Cached access must never leak to another actor');
    ensure(Sessions::join_session($request) instanceof WP_Error, 'An outside student cannot enter a targeted lesson');
    ensure(Classroom::discover()['items'] === array(), 'Discovery is restricted to the current actor');
    $actor = 2;
    $discovery = Classroom::discover();
    ensure($discovery['classroom']['active'] && $discovery['items'][0]['autoOpen'], 'Combined discovery preserves active classroom and automatic activity opening');

    Repository::remove_participant(1, 2);
    ensure(Sessions::get_session($request) instanceof WP_Error, 'Membership deletion must invalidate the request cache');
    ensure(Classroom::discover()['items'] === array(), 'Revoked participants must disappear from discovery');
    $actor = 1;
    ensure(is_array(Sessions::get_session($request)), 'Owner remains authorized');
    Repository::end(1);
    ensure(Sessions::get_session($request) instanceof WP_Error, 'Ending a lesson must invalidate cached active state');
    echo "Query load tests passed: schema marker, 3-query reads, 4-query joins, actor isolation and mutation invalidation.\n";
}
