<?php
declare(strict_types=1);

namespace TeachingTools\Sessions;

use TeachingTools\Database\Installer;

/**
 * Storage for a running tool "session": one host (the teacher) plus,
 * in live mode, any number of joined participants polling the same state
 * by join code. A session always belongs to exactly one owner and expires
 * on its own even if the teacher never explicitly ends it.
 */
final class Tool_Session_Repository {
    private const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    private const DEFAULT_TTL_SECONDS = 12 * HOUR_IN_SECONDS;
    private const MAX_PARTICIPANT_DATA_BYTES = 256 * 1024;

    /** Request-local reads only; never reused by another HTTP request. */
    private static array $session_reads = array();
    private static array $session_ids = array();
    private static array $host_reads = array();
    private static array $participant_reads = array();

    public const VALID_TOOL_TYPES = array('timer', 'seating', 'picker', 'ladder', 'whiteboard', 'quickboard', 'quiz', 'grouping', 'timeattack', 'classroom');
    public const VALID_MODES = array('solo', 'live');

    /** @param array<string, mixed> $state */
    public static function create(
        int $owner_user_id,
        string $tool_type,
        string $mode,
        array $state,
        ?string $context_type = null,
        ?string $context_id = null,
        array $participants = array()
    ): ?array {
        global $wpdb;

        if (! in_array($tool_type, self::VALID_TOOL_TYPES, true) || ! in_array($mode, self::VALID_MODES, true)) {
            return null;
        }

        if (! Session_State_Policy::accepts($tool_type, $state)) {
            return null;
        }
        $encoded_state = wp_json_encode($state);

        $now = current_time('mysql', true);
        $configured_ttl = (int) get_option('teaching_tools_active_session_hours', 12) * HOUR_IN_SECONDS;
        $expires_at = gmdate('Y-m-d H:i:s', time() + max(HOUR_IN_SECONDS, min(7 * DAY_IN_SECONDS, $configured_ttl ?: self::DEFAULT_TTL_SECONDS)));

        for ($attempt = 0; $attempt < 5; $attempt++) {
            if (false === $wpdb->query('START TRANSACTION')) {
                return null;
            }
            $inserted = $wpdb->insert(
                Installer::sessions_table(),
                array(
                    'join_code' => self::generate_code(),
                    'tool_type' => $tool_type,
                    'mode' => $mode,
                    'owner_user_id' => $owner_user_id,
                    'context_type' => $context_type,
                    'context_id' => $context_id,
                    'state' => $encoded_state,
                    'status' => 'active',
                    'created_at' => $now,
                    'updated_at' => $now,
                    'expires_at' => $expires_at,
                ),
                array('%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
            );

            if (false !== $inserted) {
                $session_id = (int) $wpdb->insert_id;
                $host_inserted = $wpdb->insert(
                    Installer::session_hosts_table(),
                    array(
                        'session_id' => $session_id,
                        'user_id' => $owner_user_id,
                        'role' => 'owner',
                        'created_at' => $now,
                    ),
                    array('%d', '%d', '%s', '%s')
                );
                if (false === $host_inserted || ! self::insert_participants($session_id, $participants, $now)) {
                    $wpdb->query('ROLLBACK');

                    return null;
                }
                if (false === $wpdb->query('COMMIT')) {
                    $wpdb->query('ROLLBACK');

                    return null;
                }

                return self::find($session_id);
            }
            $wpdb->query('ROLLBACK');
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    public static function find(int $id): ?array {
        global $wpdb;

        if (isset(self::$session_ids[$id])) {
            return self::$session_reads[self::$session_ids[$id]];
        }

        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . Installer::sessions_table() . ' WHERE id = %d', $id),
            ARRAY_A
        );

        return self::remember_session($row);
    }

    /** @return array<string, mixed>|null */
    public static function find_active_by_code(string $join_code): ?array {
        $row = self::find_by_code($join_code);

        return null !== $row && 'active' === (string) $row['status']
            && (string) $row['expires_at'] > current_time('mysql', true) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    public static function find_by_code(string $join_code): ?array {
        global $wpdb;

        $join_code = strtoupper(sanitize_text_field($join_code));
        if (array_key_exists($join_code, self::$session_reads)) {
            return self::$session_reads[$join_code];
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . Installer::sessions_table() . ' WHERE join_code = %s',
                $join_code
            ),
            ARRAY_A
        );

        self::$session_reads[$join_code] = self::remember_session($row);

        return self::$session_reads[$join_code];
    }

    /** @return array<string, mixed>|null */
    public static function find_active_classroom_for_owner(int $user_id): ?array {
        return self::active_classrooms_for_host($user_id)[0] ?? null;
    }

    /** @return array<string, mixed>|null */
    public static function find_active_classroom_for_participant(int $user_id): ?array {
        return self::active_classrooms_for_participant($user_id)[0] ?? null;
    }

    /** @return list<array<string, mixed>> */
    public static function active_classrooms_for_host(int $user_id): array {
        global $wpdb;

        $sessions = Installer::sessions_table();
        $hosts = Installer::session_hosts_table();
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT sessions.* FROM {$sessions} sessions"
                    . " INNER JOIN {$hosts} hosts ON hosts.session_id = sessions.id"
                    . " WHERE hosts.user_id = %d AND sessions.tool_type = 'classroom'"
                    . " AND sessions.status = 'active' AND sessions.expires_at > %s"
                    . ' ORDER BY sessions.updated_at DESC, sessions.id DESC LIMIT 20',
                $user_id,
                current_time('mysql', true)
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : array();
    }

    /** @return list<array<string, mixed>> */
    public static function active_classrooms_for_participant(int $user_id): array {
        global $wpdb;

        $sessions = Installer::sessions_table();
        $participants = Installer::participants_table();
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT sessions.* FROM {$sessions} sessions"
                    . " INNER JOIN {$participants} participants ON participants.session_id = sessions.id"
                    . " WHERE participants.user_id = %d AND sessions.tool_type = 'classroom'"
                    . " AND sessions.status = 'active' AND sessions.expires_at > %s"
                    . ' ORDER BY sessions.updated_at DESC, sessions.id DESC LIMIT 20',
                $user_id,
                current_time('mysql', true)
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : array();
    }

    /** @return list<array<string, mixed>> */
    public static function assigned_sessions(int $user_id): array {
        global $wpdb;
        $sessions = Installer::sessions_table();
        $participants = Installer::participants_table();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT sessions.* FROM {$sessions} sessions"
                . " INNER JOIN {$participants} participants ON participants.session_id = sessions.id"
                . " WHERE participants.user_id = %d AND sessions.mode = 'live'"
                . " AND sessions.context_type IS NOT NULL AND sessions.context_type <> ''"
                . " AND sessions.status = 'active' AND sessions.expires_at > %s"
                . ' ORDER BY sessions.created_at DESC, sessions.id DESC LIMIT 30',
            $user_id,
            current_time('mysql', true)
        ), ARRAY_A);

        return is_array($rows) ? $rows : array();
    }

    /** @param array<string, mixed> $state */
    public static function update_state(int $id, array $state): bool {
        global $wpdb;

        self::forget_session($id);

        $tool_type = (string) $wpdb->get_var($wpdb->prepare(
            'SELECT tool_type FROM ' . Installer::sessions_table() . ' WHERE id = %d',
            $id
        ));
        if ('' === $tool_type || ! Session_State_Policy::accepts($tool_type, $state)) {
            return false;
        }
        $encoded_state = wp_json_encode($state);
        if (false === $encoded_state) {
            return false;
        }

        return false !== $wpdb->update(
            Installer::sessions_table(),
            array('state' => $encoded_state, 'updated_at' => current_time('mysql', true)),
            array('id' => $id),
            array('%s', '%s'),
            array('%d')
        );
    }

    public static function end(int $id): bool {
        global $wpdb;

        self::forget_session($id);

        return false !== $wpdb->update(
            Installer::sessions_table(),
            array('status' => 'ended', 'updated_at' => current_time('mysql', true)),
            array('id' => $id),
            array('%s', '%s'),
            array('%d')
        );
    }

    public static function end_other_active_classrooms_for_owner(int $owner_user_id, int $keep_session_id): bool {
        global $wpdb;

        self::$session_reads = self::$session_ids = array();

        return false !== $wpdb->query(
            $wpdb->prepare(
                'UPDATE ' . Installer::sessions_table()
                    . " SET status = 'ended', updated_at = %s"
                    . " WHERE owner_user_id = %d AND tool_type = 'classroom' AND status = 'active' AND id <> %d",
                current_time('mysql', true),
                $owner_user_id,
                $keep_session_id
            )
        );
    }

    /** @param array<string, mixed> $data */
    public static function add_participant(int $session_id, int $user_id, array $data = array()): bool {
        global $wpdb;

        $existing = self::participant_row($session_id, $user_id);
        unset(self::$participant_reads[$session_id . ':' . $user_id]);

        if (is_array($existing)) {
            if (empty($data)) {
                return true;
            }

            $current = json_decode((string) ($existing['data'] ?? '{}'), true);
            $current = is_array($current) ? $current : array();
            $encoded = wp_json_encode(array_merge($current, $data));
            if (false === $encoded || strlen($encoded) > self::MAX_PARTICIPANT_DATA_BYTES) {
                return false;
            }

            $updated = $wpdb->update(
                Installer::participants_table(),
                array('data' => $encoded),
                array('id' => (int) $existing['id']),
                array('%s'),
                array('%d')
            );
            if (false !== $updated) {
                $existing['data'] = $encoded;
                self::$participant_reads[$session_id . ':' . $user_id] = $existing;
            }

            return false !== $updated;
        }

        $encoded = empty($data) ? null : wp_json_encode($data);
        if (false === $encoded || (is_string($encoded) && strlen($encoded) > self::MAX_PARTICIPANT_DATA_BYTES)) {
            return false;
        }

        return false !== $wpdb->insert(
            Installer::participants_table(),
            array(
                'session_id' => $session_id,
                'user_id' => $user_id,
                'joined_at' => current_time('mysql', true),
                'data' => $encoded,
            ),
            array('%d', '%d', '%s', '%s')
        );
    }

    /** @param array<string, mixed> $patch */
    public static function update_participant_data(int $session_id, int $user_id, array $patch): bool {
        global $wpdb;

        unset(self::$participant_reads[$session_id . ':' . $user_id]);

        $wpdb->query('START TRANSACTION');
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT id, data FROM ' . Installer::participants_table() . ' WHERE session_id = %d AND user_id = %d FOR UPDATE',
                $session_id,
                $user_id
            ),
            ARRAY_A
        );

        if (! is_array($row)) {
            $wpdb->query('ROLLBACK');

            return false;
        }

        $data = json_decode((string) ($row['data'] ?? '{}'), true);
        $data = is_array($data) ? $data : array();
        $encoded = wp_json_encode(array_merge($data, $patch));
        if (false === $encoded || strlen($encoded) > self::MAX_PARTICIPANT_DATA_BYTES) {
            $wpdb->query('ROLLBACK');

            return false;
        }

        $updated = $wpdb->update(
            Installer::participants_table(),
            array('data' => $encoded),
            array('id' => (int) $row['id']),
            array('%s'),
            array('%d')
        );

        if (false === $updated) {
            $wpdb->query('ROLLBACK');

            return false;
        }

        if (false === $wpdb->query('COMMIT')) {
            $wpdb->query('ROLLBACK');

            return false;
        }

        return true;
    }

    /** @return list<array<string, mixed>> */
    public static function participants(int $session_id): array {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT user_id, joined_at, data FROM ' . Installer::participants_table() . ' WHERE session_id = %d ORDER BY joined_at ASC, id ASC',
                $session_id
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : array();
    }

    /** @return array<string, mixed> */
    public static function participant_data(int $session_id, int $user_id): array {
        $row = self::participant_row($session_id, $user_id);
        $encoded = $row['data'] ?? null;
        $data = json_decode(is_string($encoded) ? $encoded : '{}', true);

        return is_array($data) ? $data : array();
    }

    /**
     * Read-modify-write under a row lock, for state mutations that must not
     * race — e.g. two students claiming the same ladder column at once.
     * $mutator receives the decoded state and returns [success, newState,
     * errorCode]; on success the row is updated inside the same
     * transaction, so a concurrent claim always sees the committed result.
     *
     * @param callable(array<string, mixed> $state): array{0:bool,1:?array<string, mixed>,2:string} $mutator
     * @return array{ok: bool, session: array<string, mixed>|null, error: string}
     */
    public static function atomic_update_by_code(string $join_code, callable $mutator): array {
        global $wpdb;

        $join_code = strtoupper(sanitize_text_field($join_code));
        if (isset(self::$session_reads[$join_code])) {
            self::forget_session((int) self::$session_reads[$join_code]['id']);
        }
        $wpdb->query('START TRANSACTION');

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . Installer::sessions_table() . " WHERE join_code = %s AND status = 'active' AND expires_at > %s FOR UPDATE",
                $join_code,
                current_time('mysql', true)
            ),
            ARRAY_A
        );

        if (! is_array($row)) {
            $wpdb->query('ROLLBACK');

            return array('ok' => false, 'session' => null, 'error' => 'not_found');
        }

        $state = json_decode((string) ($row['state'] ?? '{}'), true);
        $state = is_array($state) ? $state : array();

        [$success, $new_state, $error] = $mutator($state);

        if (! $success || null === $new_state) {
            $wpdb->query('ROLLBACK');

            return array('ok' => false, 'session' => $row, 'error' => $error);
        }

        $encoded = wp_json_encode($new_state);
        if (false === $encoded || ! Session_State_Policy::accepts((string) $row['tool_type'], $new_state)) {
            $wpdb->query('ROLLBACK');

            return array('ok' => false, 'session' => $row, 'error' => 'encode_failed');
        }

        $updated = $wpdb->update(
            Installer::sessions_table(),
            array('state' => $encoded, 'updated_at' => current_time('mysql', true)),
            array('id' => (int) $row['id']),
            array('%s', '%s'),
            array('%d')
        );
        if (false === $updated || false === $wpdb->query('COMMIT')) {
            $wpdb->query('ROLLBACK');

            return array('ok' => false, 'session' => $row, 'error' => 'write_failed');
        }

        $row['state'] = $encoded;

        return array('ok' => true, 'session' => $row, 'error' => '');
    }

    public static function is_participant(int $session_id, int $user_id): bool {
        return null !== self::participant_row($session_id, $user_id);
    }

    public static function remove_participant(int $session_id, int $user_id): bool {
        global $wpdb;

        unset(self::$participant_reads[$session_id . ':' . $user_id]);

        return false !== $wpdb->delete(
            Installer::participants_table(),
            array('session_id' => $session_id, 'user_id' => $user_id),
            array('%d', '%d')
        );
    }

    public static function count_participants(int $session_id): int {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . Installer::participants_table() . ' WHERE session_id = %d',
                $session_id
            )
        );
    }

    public static function host_role(int $session_id, int $user_id): ?string {
        global $wpdb;

        $key = $session_id . ':' . $user_id;
        if (array_key_exists($key, self::$host_reads)) {
            return self::$host_reads[$key];
        }

        $role = $wpdb->get_var($wpdb->prepare(
            'SELECT role FROM ' . Installer::session_hosts_table() . ' WHERE session_id = %d AND user_id = %d',
            $session_id,
            $user_id
        ));

        if (is_string($role) && in_array($role, array('owner', 'cohost', 'observer'), true)) {
            return self::$host_reads[$key] = $role;
        }

        $session = self::find($session_id);
        $owner_id = (int) ($session['owner_user_id'] ?? 0);
        if ($owner_id !== $user_id || $user_id < 1) {
            return self::$host_reads[$key] = null;
        }
        $wpdb->replace(
            Installer::session_hosts_table(),
            array(
                'session_id' => $session_id,
                'user_id' => $user_id,
                'role' => 'owner',
                'created_at' => current_time('mysql', true),
            ),
            array('%d', '%d', '%s', '%s')
        );

        return self::$host_reads[$key] = 'owner';
    }

    /** @return list<array{id:int,role:string,name:string}> */
    public static function hosts(int $session_id): array {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT user_id, role FROM ' . Installer::session_hosts_table() . ' WHERE session_id = %d ORDER BY FIELD(role, \'owner\', \'cohost\', \'observer\'), id ASC',
            $session_id
        ), ARRAY_A);
        $result = array();
        foreach (is_array($rows) ? $rows : array() as $row) {
            $id = (int) $row['user_id'];
            $user = get_userdata($id);
            $result[] = array(
                'id' => $id,
                'role' => (string) $row['role'],
                'name' => $user ? (string) $user->display_name : sprintf(__('사용자 #%d', 'teaching-tools'), $id),
            );
        }

        return $result;
    }

    public static function set_host(int $session_id, int $user_id, string $role): bool {
        global $wpdb;

        unset(self::$host_reads[$session_id . ':' . $user_id]);

        if ($user_id < 1 || ! in_array($role, array('cohost', 'observer'), true)) {
            return false;
        }

        return false !== $wpdb->replace(
            Installer::session_hosts_table(),
            array(
                'session_id' => $session_id,
                'user_id' => $user_id,
                'role' => $role,
                'created_at' => current_time('mysql', true),
            ),
            array('%d', '%d', '%s', '%s')
        );
    }

    public static function remove_host(int $session_id, int $user_id): bool {
        global $wpdb;

        unset(self::$host_reads[$session_id . ':' . $user_id]);

        return false !== $wpdb->delete(
            Installer::session_hosts_table(),
            array('session_id' => $session_id, 'user_id' => $user_id),
            array('%d', '%d')
        );
    }

    public static function transfer_ownership(int $session_id, int $from_user_id, int $to_user_id): bool {
        global $wpdb;

        if ('owner' !== self::host_role($session_id, $from_user_id) || ! in_array(self::host_role($session_id, $to_user_id), array('cohost', 'observer'), true)) {
            return false;
        }
        self::forget_session($session_id);
        unset(self::$host_reads[$session_id . ':' . $from_user_id], self::$host_reads[$session_id . ':' . $to_user_id]);
        if (false === $wpdb->query('START TRANSACTION')) {
            return false;
        }
        $updated_session = $wpdb->update(
            Installer::sessions_table(),
            array('owner_user_id' => $to_user_id, 'updated_at' => current_time('mysql', true)),
            array('id' => $session_id, 'owner_user_id' => $from_user_id),
            array('%d', '%s'),
            array('%d', '%d')
        );
        $old_role = $wpdb->update(Installer::session_hosts_table(), array('role' => 'cohost'), array('session_id' => $session_id, 'user_id' => $from_user_id), array('%s'), array('%d', '%d'));
        $new_role = $wpdb->update(Installer::session_hosts_table(), array('role' => 'owner'), array('session_id' => $session_id, 'user_id' => $to_user_id), array('%s'), array('%d', '%d'));
        if (1 !== $updated_session || false === $old_role || false === $new_role || false === $wpdb->query('COMMIT')) {
            $wpdb->query('ROLLBACK');

            return false;
        }

        return true;
    }

    /**
     * @param list<array{id:int,data?:array<string,mixed>}> $participants
     */
    private static function insert_participants(int $session_id, array $participants, string $joined_at): bool {
        global $wpdb;

        $seen = array();
        foreach ($participants as $participant) {
            if (! is_array($participant)) {
                return false;
            }
            $id = (int) ($participant['id'] ?? 0);
            if ($id < 1 || isset($seen[$id])) {
                return false;
            }
            $seen[$id] = true;
            $data = is_array($participant['data'] ?? null) ? $participant['data'] : array();
            $encoded = [] === $data ? null : wp_json_encode($data);
            if (false === $encoded || (is_string($encoded) && strlen($encoded) > self::MAX_PARTICIPANT_DATA_BYTES)) {
                return false;
            }
            if (false === $wpdb->insert(
                Installer::participants_table(),
                array(
                    'session_id' => $session_id,
                    'user_id' => $id,
                    'joined_at' => $joined_at,
                    'data' => $encoded,
                ),
                array('%d', '%d', '%s', '%s')
            )) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, mixed>|null */
    private static function remember_session(?array $row): ?array {
        if (null !== $row) {
            $code = (string) $row['join_code'];
            self::$session_reads[$code] = $row;
            self::$session_ids[(int) $row['id']] = $code;
        }

        return $row;
    }

    private static function forget_session(int $id): void {
        if (isset(self::$session_ids[$id])) {
            unset(self::$session_reads[self::$session_ids[$id]], self::$session_ids[$id]);
        }
    }

    /** @return array<string, mixed>|null */
    private static function participant_row(int $session_id, int $user_id): ?array {
        global $wpdb;

        $key = $session_id . ':' . $user_id;
        if (! array_key_exists($key, self::$participant_reads)) {
            $row = $wpdb->get_row($wpdb->prepare(
                'SELECT id, data FROM ' . Installer::participants_table() . ' WHERE session_id = %d AND user_id = %d',
                $session_id,
                $user_id
            ), ARRAY_A);
            self::$participant_reads[$key] = is_array($row) ? $row : null;
        }

        return self::$participant_reads[$key];
    }

    private static function generate_code(): string {
        $code = '';
        $length = strlen(self::CODE_ALPHABET);

        for ($i = 0; $i < 6; $i++) {
            $code .= self::CODE_ALPHABET[random_int(0, $length - 1)];
        }

        return $code;
    }
}
