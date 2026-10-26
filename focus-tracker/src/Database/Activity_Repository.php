<?php
declare(strict_types=1);

namespace FocusTracker\Database;

/**
 * Storage for teacher-created "활동" (activity) rows — see docs/DESIGN.md
 * §4. Each activity pins a class/group context, a time window, and the
 * per-activity scoring weights used by Scoring\Activity_Score_Service.
 */
final class Activity_Repository {
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_DELETED = 'deleted';

    /**
     * @param array{
     *   name: string, owner_user_id: int, context_type: string, context_id: string,
     *   problem_ids: ?list<int>, starts_at: string, ends_at: string,
     *   weight_focus: float, weight_selfreg: float, weight_activity: float
     * } $data
     */
    public static function create(array $data): ?int {
        global $wpdb;

        $now = current_time('mysql', true);
        $encoded_problem_ids = null === $data['problem_ids'] ? null : wp_json_encode(array_values($data['problem_ids']));

        $inserted = $wpdb->insert(
            Installer::activities_table(),
            array(
                'name' => $data['name'],
                'owner_user_id' => $data['owner_user_id'],
                'context_type' => $data['context_type'],
                'context_id' => $data['context_id'],
                'problem_ids' => $encoded_problem_ids,
                'starts_at' => $data['starts_at'],
                'ends_at' => $data['ends_at'],
                'weight_focus' => $data['weight_focus'],
                'weight_selfreg' => $data['weight_selfreg'],
                'weight_activity' => $data['weight_activity'],
                'status' => self::STATUS_SCHEDULED,
                'created_at' => $now,
                'updated_at' => $now,
            ),
            array('%s', '%d', '%s', '%s', '%s', '%s', '%s', '%f', '%f', '%f', '%s', '%s', '%s')
        );

        return false !== $inserted ? (int) $wpdb->insert_id : null;
    }

    /** @return array<string, mixed>|null */
    public static function find(int $id, bool $lock = false): ?array {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . Installer::activities_table() . ' WHERE id = %d' . ($lock ? ' FOR UPDATE' : ''), $id),
            ARRAY_A
        );

        return is_array($row) ? self::decode($row) : null;
    }

    /**
     * @param array{name?: string, starts_at?: string, ends_at?: string, status?: string,
     *   weight_focus?: float, weight_selfreg?: float, weight_activity?: float, problem_ids?: ?list<int>} $data
     */
    public static function update(int $id, array $data): bool {
        global $wpdb;

        $fields = array();
        $formats = array();

        foreach (array('name' => '%s', 'starts_at' => '%s', 'ends_at' => '%s', 'status' => '%s') as $key => $format) {
            if (array_key_exists($key, $data)) {
                $fields[$key] = $data[$key];
                $formats[] = $format;
            }
        }

        foreach (array('weight_focus', 'weight_selfreg', 'weight_activity') as $key) {
            if (array_key_exists($key, $data)) {
                $fields[$key] = (float) $data[$key];
                $formats[] = '%f';
            }
        }

        if (array_key_exists('problem_ids', $data)) {
            $fields['problem_ids'] = null === $data['problem_ids'] ? null : wp_json_encode(array_values($data['problem_ids']));
            $formats[] = '%s';
        }

        if (empty($fields)) {
            return true;
        }

        $fields['updated_at'] = current_time('mysql', true);
        $formats[] = '%s';

        return false !== $wpdb->update(Installer::activities_table(), $fields, array('id' => $id), $formats, array('%d'));
    }

    /** @return list<array<string, mixed>> */
    public static function list_for_owner(int $owner_user_id, ?string $status = null, ?string $context_type = null, ?string $context_id = null): array {
        global $wpdb;

        $where = 'owner_user_id = %d AND status != %s';
        $params = array($owner_user_id, self::STATUS_DELETED);
        if (null !== $context_type) { $where .= ' AND context_type = %s'; $params[] = $context_type; }
        if (null !== $context_id) { $where .= ' AND context_id = %s'; $params[] = $context_id; }
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . Installer::activities_table() . ' WHERE ' . $where . ' ORDER BY starts_at DESC, id DESC', $params
        ), ARRAY_A);
        $activities = array_map(array(__CLASS__, 'decode'), is_array($rows) ? $rows : array());
        return null === $status ? $activities : array_values(array_filter($activities,
            static fn (array $activity): bool => self::effective_status($activity) === $status));
    }

    /** The stored status records an explicit close; the UTC window controls progression. */
    public static function effective_status(array $activity, ?string $now_utc = null): string {
        $now_utc = $now_utc ?? current_time('mysql', true);
        if ($activity['status'] === self::STATUS_DELETED) { return self::STATUS_DELETED; }
        if ($activity['status'] === self::STATUS_CLOSED || $now_utc >= $activity['ends_at']) { return self::STATUS_CLOSED; }
        return $now_utc < $activity['starts_at'] ? self::STATUS_SCHEDULED : self::STATUS_ACTIVE;
    }

    public static function has_archived_scores(int $activity_id): bool {
        global $wpdb;
        $count = $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . Installer::scores_table() . ' WHERE activity_id = %d AND raw_events_expired = 1', $activity_id
        ));
        return null === $count || '' !== (string) $wpdb->last_error || (int) $count > 0;
    }

    /**
     * Candidate activities for one class/group context whose window covers
     * $at_mysql. The caller still narrows by problem_id in PHP (JSON
     * problem_ids isn't portably filterable in SQL) — see
     * Rest\Controllers\Events_Controller.
     *
     * @return list<array<string, mixed>>
     */
    public static function find_active_for_context(string $context_type, string $context_id, string $at_mysql): array {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . Installer::activities_table() . "
                 WHERE context_type = %s AND context_id = %s
                   AND starts_at <= %s AND ends_at >= %s
                   AND status NOT IN ('closed', 'deleted')
                 ORDER BY starts_at DESC",
                $context_type,
                $context_id,
                $at_mysql,
                $at_mysql
            ),
            ARRAY_A
        );

        return array_map(array(__CLASS__, 'decode'), is_array($rows) ? $rows : array());
    }

    /** @return list<array<string, mixed>> Closed activities still accept delayed observations within their recorded window. */
    public static function find_overlapping_for_context(string $context_type, string $context_id, string $from_mysql, string $to_mysql, bool $lock = false, bool $include_deleted = false): array {
        return self::find_overlapping_for_contexts(array(array('type' => $context_type, 'id' => $context_id)), $from_mysql, $to_mysql, $lock, $include_deleted);
    }

    /** One indexed schedule query for all freshly authorized contexts. */
    public static function find_overlapping_for_contexts(array $contexts, string $from_mysql, string $to_mysql, bool $lock = false, bool $include_deleted = false): array {
        global $wpdb;
        $groups = array();
        foreach ($contexts as $context) { $groups[$context['type']][(string) $context['id']] = (string) $context['id']; }
        if (empty($groups)) { return array(); }
        ksort($groups);
        $conditions = array();
        $values = array();
        foreach ($groups as $type => $ids) {
            sort($ids, SORT_STRING);
            $conditions[] = '(context_type = %s AND context_id IN (' . implode(',', array_fill(0, count($ids), '%s')) . '))';
            array_push($values, $type, ...$ids);
        }
        array_push($values, $to_mysql, $from_mysql);
        // Concurrent learners only read shared schedules. Shared locks still
        // exclude a teacher's UPDATE/DELETE until ingestion/checks commit.
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . Installer::activities_table() . '
             WHERE (' . implode(' OR ', $conditions) . ') AND starts_at <= %s AND ends_at >= %s
             ' . ($include_deleted ? '' : "AND status != 'deleted' ") . 'ORDER BY starts_at ASC, id ASC' . ($lock ? ' LOCK IN SHARE MODE' : ''),
            $values
        ), ARRAY_A);

        return array_map(array(__CLASS__, 'decode'), is_array($rows) ? $rows : array());
    }

    /** @param array<string, mixed> $row */
    private static function decode(array $row): array {
        $problem_ids = null;
        if (! empty($row['problem_ids'])) {
            $decoded = json_decode((string) $row['problem_ids'], true);
            $problem_ids = is_array($decoded) ? array_map('intval', $decoded) : null;
        }

        $row['problem_ids'] = $problem_ids;
        $row['id'] = (int) $row['id'];
        $row['owner_user_id'] = (int) $row['owner_user_id'];
        $row['weight_focus'] = (float) $row['weight_focus'];
        $row['weight_selfreg'] = (float) $row['weight_selfreg'];
        $row['weight_activity'] = (float) $row['weight_activity'];

        return $row;
    }
}
