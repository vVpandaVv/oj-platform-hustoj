<?php
declare(strict_types=1);

namespace TeachingTools\Sessions;

use TeachingTools\Database\Installer;

final class Lesson_Template_Repository {
    /** @return list<array<string, mixed>> */
    public static function all(int $owner_id): array {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . Installer::lesson_templates_table() . ' WHERE owner_user_id = %d ORDER BY updated_at DESC, id DESC',
            $owner_id
        ), ARRAY_A);

        return is_array($rows) ? $rows : array();
    }

    /** @param list<array<string, mixed>> $steps */
    public static function save(int $owner_id, string $name, array $steps): ?array {
        global $wpdb;

        $encoded = wp_json_encode($steps);
        if (false === $encoded || strlen($encoded) > 128 * 1024) {
            return null;
        }
        $now = current_time('mysql', true);
        $inserted = $wpdb->insert(
            Installer::lesson_templates_table(),
            array(
                'owner_user_id' => $owner_id,
                'name' => $name,
                'steps' => $encoded,
                'created_at' => $now,
                'updated_at' => $now,
            ),
            array('%d', '%s', '%s', '%s', '%s')
        );

        return false === $inserted ? null : self::find((int) $wpdb->insert_id, $owner_id);
    }

    public static function find(int $id, int $owner_id): ?array {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . Installer::lesson_templates_table() . ' WHERE id = %d AND owner_user_id = %d',
            $id,
            $owner_id
        ), ARRAY_A);

        return is_array($row) ? $row : null;
    }

    public static function delete(int $id, int $owner_id): bool {
        global $wpdb;

        return false !== $wpdb->delete(
            Installer::lesson_templates_table(),
            array('id' => $id, 'owner_user_id' => $owner_id),
            array('%d', '%d')
        );
    }
}
