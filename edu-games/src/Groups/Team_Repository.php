<?php
declare(strict_types=1);

namespace EduGames\Groups;

use EduGames\Database\Installer;

/**
 * Storage for the addon's own lightweight "game team" concept — not the
 * platform's real class/group roster. A student belongs to at most one
 * team at a time; teachers manage teams via invite codes.
 */
final class Team_Repository {
    private const INVITE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    /** @return array<string, mixed>|null */
    public static function find(int $team_id): ?array {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . Installer::teams_table() . ' WHERE id = %d', $team_id),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    public static function find_by_invite_code(string $code): ?array {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . Installer::teams_table() . ' WHERE invite_code = %s', sanitize_text_field($code)),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    /** @return array<int, array<string, mixed>> */
    public static function all(): array {
        global $wpdb;

        $teams_table = Installer::teams_table();
        $members_table = Installer::team_members_table();

        $rows = $wpdb->get_results(
            "SELECT teams.*, COUNT(members.id) AS member_count
            FROM {$teams_table} teams
            LEFT JOIN {$members_table} members ON members.team_id = teams.id
            GROUP BY teams.id
            ORDER BY teams.name ASC",
            ARRAY_A
        );

        return is_array($rows) ? $rows : array();
    }

    public static function create(string $name, int $created_by): int {
        global $wpdb;

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $inserted = $wpdb->insert(
                Installer::teams_table(),
                array(
                    'name' => sanitize_text_field($name),
                    'invite_code' => self::generate_invite_code(),
                    'created_by' => $created_by,
                    'created_at' => current_time('mysql', true),
                ),
                array('%s', '%s', '%d', '%s')
            );

            if (false !== $inserted) {
                return (int) $wpdb->insert_id;
            }
        }

        return 0;
    }

    public static function rename(int $team_id, string $name): bool {
        global $wpdb;

        return false !== $wpdb->update(
            Installer::teams_table(),
            array('name' => sanitize_text_field($name)),
            array('id' => $team_id),
            array('%s'),
            array('%d')
        );
    }

    public static function reset_invite_code(int $team_id): ?string {
        global $wpdb;

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $code = self::generate_invite_code();
            $updated = $wpdb->update(
                Installer::teams_table(),
                array('invite_code' => $code),
                array('id' => $team_id),
                array('%s'),
                array('%d')
            );

            if (false !== $updated) {
                return $code;
            }
        }

        return null;
    }

    public static function delete(int $team_id): void {
        global $wpdb;

        $wpdb->delete(Installer::team_members_table(), array('team_id' => $team_id), array('%d'));
        $wpdb->delete(Installer::teams_table(), array('id' => $team_id), array('%d'));
    }

    /** @return array<string, mixed>|null */
    public static function team_for_user(int $user_id): ?array {
        global $wpdb;

        $teams_table = Installer::teams_table();
        $members_table = Installer::team_members_table();

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT teams.* FROM {$teams_table} teams
                INNER JOIN {$members_table} members ON members.team_id = teams.id
                WHERE members.user_id = %d
                LIMIT 1",
                $user_id
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    public static function join(int $team_id, int $user_id): void {
        global $wpdb;

        $members_table = Installer::team_members_table();
        $wpdb->delete($members_table, array('user_id' => $user_id), array('%d'));
        $wpdb->insert(
            $members_table,
            array(
                'team_id' => $team_id,
                'user_id' => $user_id,
                'joined_at' => current_time('mysql', true),
            ),
            array('%d', '%d', '%s')
        );
    }

    public static function leave(int $user_id): void {
        global $wpdb;

        $wpdb->delete(Installer::team_members_table(), array('user_id' => $user_id), array('%d'));
    }

    /** @return array<int, int> */
    public static function member_user_ids(int $team_id): array {
        global $wpdb;

        $ids = $wpdb->get_col(
            $wpdb->prepare('SELECT user_id FROM ' . Installer::team_members_table() . ' WHERE team_id = %d', $team_id)
        );

        return array_map('intval', is_array($ids) ? $ids : array());
    }

    public static function is_member(int $team_id, int $user_id): bool {
        return in_array($user_id, self::member_user_ids($team_id), true);
    }

    private static function generate_invite_code(): string {
        $code = '';
        $alphabet_length = strlen(self::INVITE_ALPHABET);

        for ($i = 0; $i < 6; $i++) {
            $code .= self::INVITE_ALPHABET[random_int(0, $alphabet_length - 1)];
        }

        return $code;
    }
}
