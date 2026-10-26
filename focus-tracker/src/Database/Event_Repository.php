<?php
declare(strict_types=1);

namespace FocusTracker\Database;

use FocusTracker\Support\Clock;

/**
 * Append-only raw event log (see docs/DESIGN.md §4). Rows older than the
 * configured retention window are purged by Jobs\Retention_Cleanup.
 */
final class Event_Repository {
    public const VALID_TYPES = array(
        'visible', 'hidden', 'focus', 'blur', 'activity', 'run', 'submit',
        'heartbeat', 'session_start', 'session_end',
    );

    /**
     * @param list<array{type: string, occurred_at: int, meta: ?array<string, mixed>}> $events
     */
    public static function insert_batch(int $participation_id, array $events): int|false {
        global $wpdb;

        $events = array_values(array_filter($events, static function (array $event): bool {
            return in_array($event['type'], self::VALID_TYPES, true);
        }));

        if (empty($events)) {
            return 0;
        }

        $table = Installer::events_table();
        $received_at = current_time('mysql', true);

        $rows = array();
        $placeholders = array();

        foreach ($events as $event) {
            $meta = empty($event['meta']) ? null : wp_json_encode($event['meta']);

            $sequence = isset($event['seq']) ? (int) $event['seq'] : null;
            $placeholders[] = null === $sequence ? '(%d, NULL, %s, %s, %d, %s, %s)' : '(%d, %d, %s, %s, %d, %s, %s)';
            $rows[] = $participation_id;
            if (null !== $sequence) {
                $rows[] = $sequence;
            }
            array_push(
                $rows,
                $event['type'],
                Clock::ms_to_mysql($event['occurred_at']),
                $event['occurred_at'],
                $received_at,
                $meta
            );
        }

        $sql = "INSERT INTO {$table} (participation_id, sequence_number, event_type, occurred_at, occurred_at_ms, received_at, meta) VALUES "
            . implode(', ', $placeholders) . ' ON DUPLICATE KEY UPDATE id = id';

        $inserted = $wpdb->query($wpdb->prepare($sql, $rows));
        if (false === $inserted) {
            return false;
        }

        return (int) $inserted;
    }

    /** Derive session state from persisted events, including out-of-order deliveries. */
    public static function session_summary(int $participation_id): ?array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT MAX(occurred_at_ms) AS latest_at,
                MAX(CASE WHEN event_type = %s THEN occurred_at_ms ELSE NULL END) AS ended_at,
                MAX(CASE WHEN meta LIKE %s THEN 1 ELSE 0 END) AS has_gaps
             FROM ' . Installer::events_table() . ' WHERE participation_id = %d',
            'session_end', '%"queue_dropped":1%', $participation_id
        ), ARRAY_A);

        return '' === (string) $wpdb->last_error && is_array($row) && null !== $row['latest_at'] ? $row : null;
    }

    /**
     * @param list<int> $participation_ids
     * @return array<int, list<array{type: string, occurred_at: int}>> keyed by participation_id
     */
    public static function list_for_participations(array $participation_ids): array {
        global $wpdb;

        $participation_ids = array_values(array_unique(array_map('intval', $participation_ids)));
        if (empty($participation_ids)) {
            return array();
        }

        $placeholders = implode(',', array_fill(0, count($participation_ids), '%d'));
        $table = Installer::events_table();
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT participation_id, event_type, occurred_at_ms, sequence_number, meta FROM {$table}
                 WHERE participation_id IN ({$placeholders}) ORDER BY occurred_at_ms ASC, sequence_number ASC, id ASC",
                $participation_ids
            ),
            ARRAY_A
        );

        $by_participation = array_fill_keys($participation_ids, array());
        foreach ((is_array($rows) ? $rows : array()) as $row) {
            $by_participation[(int) $row['participation_id']][] = array(
                'type' => (string) $row['event_type'],
                'occurred_at' => (int) $row['occurred_at_ms'],
                'seq' => null === $row['sequence_number'] ? null : (int) $row['sequence_number'],
                'meta' => is_string($row['meta']) ? (json_decode($row['meta'], true) ?: array()) : array(),
            );
        }

        return $by_participation;
    }
}
