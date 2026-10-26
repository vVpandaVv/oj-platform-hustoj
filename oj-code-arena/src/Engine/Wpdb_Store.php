<?php
declare(strict_types=1);

namespace OjCodeArena\Engine;

/** MySQL storage through $wpdb (works under SHORTINIT). */
final class Wpdb_Store implements Store {
    private const LOCK_WAIT = 3;

    private \wpdb $db;
    private string $live;
    private string $events;
    private string $presence;

    public function __construct(\wpdb $db) {
        $this->db = $db;
        $this->live = $db->prefix . 'oj_code_arena_live';
        $this->events = $db->prefix . 'oj_code_arena_events';
        $this->presence = $db->prefix . 'oj_code_arena_presence';
    }

    public function row(string $key): ?array {
        $row = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->live} WHERE arena = %s", $key), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    public function head(string $key): ?array {
        $row = $this->db->get_row($this->db->prepare("SELECT arena, mode, state, next_tick, closed FROM {$this->live} WHERE arena = %s", $key), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    public function write(string $key, array $fields, bool $insert, array $events, int $now): void {
        if ($insert) {
            $this->db->replace($this->live, array('arena' => $key) + $fields);
        } else {
            $this->db->update($this->live, $fields, array('arena' => $key));
        }
        foreach ($events as list($scope, $payload)) {
            $this->db->insert($this->events, array('arena' => $key, 'scope' => $scope, 'payload' => $payload, 'at' => $now));
        }
    }

    public function max_event_id(string $key): int {
        return (int) $this->db->get_var($this->db->prepare("SELECT COALESCE(MAX(id), 0) FROM {$this->events} WHERE arena = %s", $key));
    }

    public function events_after(string $key, int $cursor, int $limit): array {
        $rows = $this->db->get_results($this->db->prepare(
            "SELECT id, scope, payload FROM {$this->events} WHERE arena = %s AND id > %d ORDER BY id ASC LIMIT %d",
            $key,
            $cursor,
            $limit
        ), ARRAY_A);
        $out = array();
        foreach ((array) $rows as $r) {
            $out[] = array('id' => (int) $r['id'], 'scope' => (string) $r['scope'], 'payload' => (string) $r['payload']);
        }
        return $out;
    }

    public function seen(string $key, int $uid, string $role, int $now): void {
        $this->db->query($this->db->prepare(
            "INSERT INTO {$this->presence} (arena, uid, role, seen) VALUES (%s, %d, %s, %d) ON DUPLICATE KEY UPDATE seen = VALUES(seen)",
            $key,
            $uid,
            $role,
            $now
        ));
    }

    public function presence(string $key): array {
        $rows = $this->db->get_results($this->db->prepare("SELECT uid, seen FROM {$this->presence} WHERE arena = %s AND role = 'learner'", $key), ARRAY_A);
        $out = array();
        foreach ((array) $rows as $r) {
            $out[(string) $r['uid']] = (int) $r['seen'];
        }
        return $out;
    }

    public function recent_free_arena(int $uid, int $since): ?string {
        $key = $this->db->get_var($this->db->prepare(
            "SELECT p.arena FROM {$this->presence} p JOIN {$this->live} l ON l.arena = p.arena WHERE p.uid = %d AND p.role = 'learner' AND l.mode = 'free' AND l.closed = 0 AND p.seen > %d ORDER BY p.seen DESC LIMIT 1",
            $uid,
            $since
        ));
        return $key ? (string) $key : null;
    }

    public function free_rooms(): array {
        return array_map('strval', (array) $this->db->get_col("SELECT summary FROM {$this->live} WHERE closed = 0 AND mode = 'free' ORDER BY created DESC LIMIT 60"));
    }

    public function last_room_created_by(int $uid): int {
        return (int) $this->db->get_var($this->db->prepare("SELECT COALESCE(MAX(created), 0) FROM {$this->live} WHERE host_uid = %d", $uid));
    }

    public function count_open(string $mode): int {
        return (int) $this->db->get_var($this->db->prepare("SELECT COUNT(*) FROM {$this->live} WHERE closed = 0 AND mode = %s", $mode));
    }

    public function count_players(int $since): int {
        return (int) $this->db->get_var($this->db->prepare("SELECT COUNT(DISTINCT uid) FROM {$this->presence} WHERE role = 'learner' AND seen > %d", $since));
    }

    public function prune(int $now): void {
        $this->db->query($this->db->prepare("DELETE FROM {$this->events} WHERE at < %d", $now - 180000));
        $this->db->query($this->db->prepare("DELETE FROM {$this->presence} WHERE seen < %d", $now - 3600000));
        $this->db->query($this->db->prepare("DELETE FROM {$this->live} WHERE (closed = 1 AND updated < %d) OR updated < %d", $now - 600000, $now - 6 * 3600000));
    }

    public function locked(string $key, callable $fn): void {
        $name = 'oca_' . $this->db->prefix . $key;
        $got = (int) $this->db->get_var($this->db->prepare('SELECT GET_LOCK(%s, %d)', $name, self::LOCK_WAIT));
        try {
            $fn();
        } finally {
            if ($got) {
                $this->db->query($this->db->prepare('SELECT RELEASE_LOCK(%s)', $name));
            }
        }
    }
}
