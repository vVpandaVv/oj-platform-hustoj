<?php
declare(strict_types=1);

namespace TeachingTools\Addon\Seating;

use TeachingTools\Database\Installer;

/**
 * Named seating layouts a teacher saves for reuse across class periods —
 * separate from Tool_Session_Repository's sessions, which are ephemeral
 * (4-hour TTL, cleaned up after a week) and never meant for "same seats as
 * last time" reuse. Saving under a name a teacher already used overwrites
 * that layout instead of creating a duplicate.
 */
final class Seating_Layout_Repository {
    /**
     * @param array<int, array<string, mixed>> $seats
     * @return array<string, mixed>|null
     */
    public static function save(int $owner_user_id, string $name, int $rows, int $cols, array $seats): ?array {
        global $wpdb;

        $encoded_seats = wp_json_encode($seats);
        if (false === $encoded_seats) {
            return null;
        }

        $now = current_time('mysql', true);
        $existing_id = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT id FROM ' . Installer::seating_layouts_table() . ' WHERE owner_user_id = %d AND name = %s',
                $owner_user_id,
                $name
            )
        );

        if (null !== $existing_id && '' !== $existing_id) {
            $wpdb->update(
                Installer::seating_layouts_table(),
                array(
                    'rows_count' => $rows,
                    'cols_count' => $cols,
                    'seats' => $encoded_seats,
                    'updated_at' => $now,
                ),
                array('id' => (int) $existing_id),
                array('%d', '%d', '%s', '%s'),
                array('%d')
            );

            return self::find((int) $existing_id, $owner_user_id);
        }

        $inserted = $wpdb->insert(
            Installer::seating_layouts_table(),
            array(
                'owner_user_id' => $owner_user_id,
                'name' => $name,
                'rows_count' => $rows,
                'cols_count' => $cols,
                'seats' => $encoded_seats,
                'created_at' => $now,
                'updated_at' => $now,
            ),
            array('%d', '%s', '%d', '%d', '%s', '%s', '%s')
        );

        return false !== $inserted ? self::find((int) $wpdb->insert_id, $owner_user_id) : null;
    }

    /** @return list<array<string, mixed>> */
    public static function list_for_owner(int $owner_user_id): array {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, name, rows_count, cols_count, updated_at FROM ' . Installer::seating_layouts_table()
                    . ' WHERE owner_user_id = %d ORDER BY updated_at DESC',
                $owner_user_id
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : array();
    }

    /** @return array<string, mixed>|null */
    public static function find(int $id, int $owner_user_id): ?array {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . Installer::seating_layouts_table() . ' WHERE id = %d AND owner_user_id = %d',
                $id,
                $owner_user_id
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    public static function delete(int $id, int $owner_user_id): bool {
        global $wpdb;

        return (bool) $wpdb->delete(
            Installer::seating_layouts_table(),
            array('id' => $id, 'owner_user_id' => $owner_user_id),
            array('%d', '%d')
        );
    }
}
