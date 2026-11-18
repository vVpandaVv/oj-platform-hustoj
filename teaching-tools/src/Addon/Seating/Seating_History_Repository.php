<?php
declare(strict_types=1);

namespace TeachingTools\Addon\Seating;

use TeachingTools\Database\Installer;

final class Seating_History_Repository {
    public const LIMIT = 5;

    /** @return list<array<string,mixed>>|null */
    public static function recent(int $owner, string $scope): ?array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT id, rows_count, cols_count, positions, created_at FROM ' . Installer::seating_history_table()
                . ' WHERE owner_user_id = %d AND scope_key = %s ORDER BY id DESC LIMIT %d',
            $owner, $scope, self::LIMIT
        ), ARRAY_A);
        if (! is_array($rows) || '' !== $wpdb->last_error) {
            return null;
        }

        return array_map(static fn (array $row): array => array(
            'rows' => (int) $row['rows_count'], 'cols' => (int) $row['cols_count'],
            'positions' => json_decode((string) $row['positions'], true) ?: array(),
            'confirmedAt' => (string) $row['created_at'],
        ), $rows);
    }

    /** A retry or an edit of the same draft updates one round, never adds another. */
    public static function confirm(int $owner, string $scope, string $key, int $rows, int $cols, array $positions): bool {
        global $wpdb;
        $table = Installer::seating_history_table();
        $encoded = wp_json_encode($positions);
        if (false === $encoded || false === $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table} (owner_user_id, scope_key, confirmation_key, rows_count, cols_count, positions, created_at)"
                . ' VALUES (%d, %s, %s, %d, %d, %s, %s) ON DUPLICATE KEY UPDATE'
                . ' rows_count = IF(scope_key = VALUES(scope_key), VALUES(rows_count), rows_count),'
                . ' cols_count = IF(scope_key = VALUES(scope_key), VALUES(cols_count), cols_count),'
                . ' positions = IF(scope_key = VALUES(scope_key), VALUES(positions), positions)',
            $owner, $scope, $key, $rows, $cols, $encoded, current_time('mysql', true)
        ))) {
            return false;
        }
        $stored_scope = $wpdb->get_var($wpdb->prepare(
            "SELECT scope_key FROM {$table} WHERE owner_user_id = %d AND confirmation_key = %s", $owner, $key
        ));
        if ($stored_scope !== $scope) {
            return false;
        }
        // Read the cutoff after the upsert so simultaneous confirmations converge on the newest five.
        $cutoff = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE owner_user_id = %d AND scope_key = %s ORDER BY id DESC LIMIT 1 OFFSET %d",
            $owner, $scope, self::LIMIT - 1
        ));
        if ('' !== $wpdb->last_error) {
            return false;
        }

        return null === $cutoff || false !== $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE owner_user_id = %d AND scope_key = %s AND id < %d", $owner, $scope, (int) $cutoff
        ));
    }

    /** @return list<array<string,mixed>> */
    public static function for_user(int $user_id): array {
        global $wpdb;
        $needle = '%' . $wpdb->esc_like('"id":"u:' . $user_id . '"') . '%';
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . Installer::seating_history_table() . ' WHERE owner_user_id = %d OR positions LIKE %s',
            $user_id, $needle
        ), ARRAY_A);

        return is_array($rows) ? $rows : array();
    }

    /** Runs within Privacy_Tools' transaction. false signals an error; an integer is the changed row count. */
    public static function erase_user(int $user_id): int|false {
        global $wpdb;
        $table = Installer::seating_history_table();
        $rows = self::for_user($user_id);
        if ('' !== $wpdb->last_error) { return false; }
        $removed = $wpdb->delete($table, array('owner_user_id' => $user_id), array('%d'));
        if (false === $removed) { return false; }
        foreach ($rows as $row) {
            if ((int) $row['owner_user_id'] === $user_id) { continue; }
            $positions = json_decode((string) $row['positions'], true) ?: array();
            $filtered = array_values(array_filter($positions, static fn (array $position): bool => $position['id'] !== 'u:' . $user_id));
            if (count($filtered) === count($positions)) { continue; }
            if (false === $wpdb->update($table, array('positions' => wp_json_encode($filtered)), array('id' => (int) $row['id']), array('%s'), array('%d'))) {
                return false;
            }
            $removed++;
        }

        return $removed;
    }
}
