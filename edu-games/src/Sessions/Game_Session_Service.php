<?php
declare(strict_types=1);

namespace EduGames\Sessions;

use EduGames\Database\Installer;

/**
 * Minimal play-session lifecycle used to stop a logged-in user from
 * replaying the same score submission. A token is minted once per page
 * render or replay (see Abstract_Game_Renderer, refresh endpoint), replaces
 * any earlier unused token of the same user+game, and can be consumed once,
 * after a minimum elapsed time, by the matching user+game score submission.
 */
final class Game_Session_Service {
    private const TTL_SECONDS = 7200;
    private const MINIMUM_PLAY_SECONDS = 3;

    public static function start(int $user_id, string $game_slug): string {
        global $wpdb;

        $game_slug = sanitize_key($game_slug);
        $table = Installer::sessions_table();

        // Always a fresh token: handing back an older unused one made
        // started_at up to two hours old, so the "played no longer than the
        // session" check (Score_Validator) allowed almost any duration.
        // Earlier unused tokens of this user+game stop being valid.
        $now = current_time('mysql', true);
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table} SET expires_at = %s
                WHERE user_id = %d AND game_slug = %s AND consumed_at IS NULL AND expires_at > %s",
                $now,
                $user_id,
                $game_slug,
                $now
            )
        );

        $token = wp_generate_password(32, false, false);

        $inserted = $wpdb->insert(
            $table,
            array(
                'token' => $token,
                'game_slug' => $game_slug,
                'user_id' => $user_id,
                'started_at' => current_time('mysql', true),
                'expires_at' => gmdate('Y-m-d H:i:s', time() + self::TTL_SECONDS),
            ),
            array('%s', '%s', '%d', '%s', '%s')
        );

        return false === $inserted ? '' : $token;
    }

    /**
     * Read-only check: is this token currently valid for this user+game and
     * old enough to plausibly reflect real play time? Does NOT consume it —
     * callers validate first, then atomically claim it before inserting a
     * score. A definite save failure can release the claim for a retry.
     *
     * @return array<string, mixed>|null the session row (for mark_consumed), or null if invalid
     */
    public static function find_valid(string $token, int $user_id, string $game_slug): ?array {
        $token = sanitize_text_field($token);
        if ($token === '' || $user_id <= 0) {
            return null;
        }

        global $wpdb;

        $game_slug = sanitize_key($game_slug);
        $table = Installer::sessions_table();

        $session = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, started_at FROM {$table}
                WHERE token = %s AND user_id = %d AND game_slug = %s AND consumed_at IS NULL AND expires_at > %s
                LIMIT 1",
                $token,
                $user_id,
                $game_slug,
                current_time('mysql', true)
            ),
            ARRAY_A
        );

        if (! is_array($session)) {
            return null;
        }

        $started_at = strtotime((string) $session['started_at'] . ' UTC');
        if (false === $started_at || (time() - $started_at) < self::MINIMUM_PLAY_SECONDS) {
            return null;
        }

        return $session;
    }

    /**
     * Atomically marks a session (previously returned by find_valid()) as
     * consumed so it can never gate a second score submission.
     */
    public static function mark_consumed(int $session_id): bool {
        global $wpdb;

        $updated = $wpdb->query(
            $wpdb->prepare(
                'UPDATE ' . Installer::sessions_table() . ' SET consumed_at = %s WHERE id = %d AND consumed_at IS NULL AND expires_at > %s',
                current_time('mysql', true),
                $session_id,
                current_time('mysql', true)
            )
        );

        return 1 === (int) $updated;
    }

    public static function release_failed(int $session_id): void {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            'UPDATE ' . Installer::sessions_table() . ' SET consumed_at = NULL WHERE id = %d',
            $session_id
        ));
    }
}
