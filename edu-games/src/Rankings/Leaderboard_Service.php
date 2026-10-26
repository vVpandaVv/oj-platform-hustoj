<?php
declare(strict_types=1);

namespace EduGames\Rankings;

use EduGames\Database\Installer;

final class Leaderboard_Service {
    /**
     * Correlated subquery selecting the id of a user's single canonical
     * best attempt for a game, using the shared 4-key tie-break order
     * (score, accuracy, duration, created_at, id). Shared between
     * for_game() and rank_for_user() so the tie-break rule only lives here.
     */
    private static function best_row_id_subquery(string $table, string $alias, string $outer_alias, bool $match_score): string {
        $conditions = "{$alias}.game_slug = {$outer_alias}.game_slug AND {$alias}.user_id = {$outer_alias}.user_id";
        if ($match_score) {
            $conditions .= " AND {$alias}.score = {$outer_alias}.score";
        }

        return "SELECT {$alias}.id
                        FROM {$table} {$alias}
                        WHERE {$conditions}
                        ORDER BY {$alias}.score DESC, {$alias}.accuracy DESC, {$alias}.duration_seconds ASC, {$alias}.created_at ASC, {$alias}.id ASC
                        LIMIT 1";
    }

    /**
     * Zero (the default) includes every participant with a recorded score.
     * @return array<int, array<string, mixed>>
     */
    public static function for_game(string $game_slug, int $limit = 0, int $offset = 0): array {
        global $wpdb;

        $game_slug = sanitize_key($game_slug);
        $limit = max(0, $limit);
        $limit_sql = $limit > 0 ? ' LIMIT %d' : '';
        $offset = $limit > 0 ? max(0, $offset) : 0;
        if ($offset) $limit_sql .= ' OFFSET %d';
        $table = Installer::scores_table();

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT scores.id, scores.user_id, scores.player_name, scores.score, scores.rounds_played, scores.correct_count, scores.duration_seconds, scores.accuracy, user_attempts.attempts, scores.created_at
                FROM {$table} scores
                INNER JOIN (
                    SELECT user_id, MAX(score) AS best_score, COUNT(*) AS attempts
                    FROM {$table}
                    WHERE game_slug = %s
                    GROUP BY user_id
                ) user_attempts ON user_attempts.user_id = scores.user_id AND user_attempts.best_score = scores.score
                WHERE scores.game_slug = %s
                    AND scores.id = (
                        " . self::best_row_id_subquery($table, 'tied_scores', 'scores', true) . "
                    )
                ORDER BY scores.score DESC, scores.accuracy DESC, scores.duration_seconds ASC, scores.created_at ASC, scores.id ASC
                " . $limit_sql,
                ...array_merge(array($game_slug, $game_slug), $limit > 0 ? array($limit) : array(), $offset ? array($offset) : array())
            ),
            ARRAY_A
        );

        if (! is_array($rows)) {
            return array();
        }

        return array_map(
            static function (array $row, int $index) use ($offset, $game_slug): array {
                return Record_Moderation::fields('score', (int)($row['id'] ?? 0), $game_slug) + array(
                    'rank' => $offset + $index + 1,
                    'userId' => (int) $row['user_id'],
                    'playerName' => (string) $row['player_name'],
                    'score' => (int) $row['score'],
                    'roundsPlayed' => (int) $row['rounds_played'],
                    'correctCount' => (int) $row['correct_count'],
                    'durationSeconds' => (int) $row['duration_seconds'],
                    'accuracy' => (float) $row['accuracy'],
                    'attempts' => (int) $row['attempts'],
                    'createdAt' => (string) $row['created_at'],
                );
            },
            $rows,
            array_keys($rows)
        );
    }

    /**
     * Same per-user-best ranking as for_game(), scoped to an explicit set
     * of user ids (used for team leaderboards instead of the whole game).
     *
     * @param array<int, int> $user_ids
     * @return array<int, array<string, mixed>>
     */
    public static function for_game_among_users(string $game_slug, array $user_ids, int $limit = 0, int $offset = 0): array {
        global $wpdb;

        $game_slug = sanitize_key($game_slug);
        $user_ids = array_values(array_unique(array_filter(array_map('intval', $user_ids), static fn(int $id): bool => $id > 0)));
        if ($user_ids === array()) {
            return array();
        }

        $limit = max(0, $limit);
        $limit_sql = $limit > 0 ? ' LIMIT %d' : '';
        $offset = $limit > 0 ? max(0, $offset) : 0;
        if ($offset) $limit_sql .= ' OFFSET %d';
        $table = Installer::scores_table();
        $placeholders = implode(',', array_fill(0, count($user_ids), '%d'));

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT scores.id, scores.user_id, scores.player_name, scores.score, scores.rounds_played, scores.correct_count, scores.duration_seconds, scores.accuracy, scores.created_at
                FROM {$table} scores
                WHERE scores.game_slug = %s
                    AND scores.user_id IN ({$placeholders})
                    AND scores.id = (
                        " . self::best_row_id_subquery($table, 'tied_scores', 'scores', false) . "
                    )
                ORDER BY scores.score DESC, scores.accuracy DESC, scores.duration_seconds ASC, scores.created_at ASC, scores.id ASC
                " . $limit_sql,
                array_merge(array($game_slug), $user_ids, $limit > 0 ? array($limit) : array(), $offset ? array($offset) : array())
            ),
            ARRAY_A
        );

        if (! is_array($rows)) {
            return array();
        }

        return array_map(
            static function (array $row, int $index) use ($offset, $game_slug): array {
                return Record_Moderation::fields('score', (int)($row['id'] ?? 0), $game_slug) + array(
                    'rank' => $offset + $index + 1,
                    'userId' => (int) $row['user_id'],
                    'playerName' => (string) $row['player_name'],
                    'score' => (int) $row['score'],
                    'roundsPlayed' => (int) $row['rounds_played'],
                    'correctCount' => (int) $row['correct_count'],
                    'durationSeconds' => (int) $row['duration_seconds'],
                    'accuracy' => (float) $row['accuracy'],
                    'createdAt' => (string) $row['created_at'],
                );
            },
            $rows,
            array_keys($rows)
        );
    }

    public static function page(string $game, $page = 1, $size = 20, ?array $users = null): array|\WP_Error {
        global $wpdb;
        $game = sanitize_key($game);
        $args = array($game); $scope = '';
        if ($users !== null) {
            $users = array_values(array_unique(array_filter(array_map('intval', $users), static fn($id) => $id > 0)));
            if (!$users) return array('leaderboard'=>array(), 'pagination'=>Page::meta(0,$page,$size));
            $scope = ' AND user_id IN ('.implode(',',array_fill(0,count($users),'%d')).')';
            $args = array_merge($args,$users);
        }
        $total = $wpdb->get_var($wpdb->prepare('SELECT COUNT(DISTINCT user_id) FROM '.Installer::scores_table().' WHERE game_slug=%s'.$scope, ...$args));
        if ($total === null || !empty($wpdb->last_error)) return Page::error();
        $meta = Page::meta((int)$total,$page,$size);
        $rows = $users === null ? self::for_game($game,$meta['perPage'],$meta['offset']) : self::for_game_among_users($game,$users,$meta['perPage'],$meta['offset']);
        if (!empty($wpdb->last_error)) return Page::error();
        return array('leaderboard'=>$rows,'pagination'=>$meta);
    }

    public static function rank_for_user(string $game_slug, int $user_id): ?int {
        if ($user_id <= 0) {
            return null;
        }

        global $wpdb;

        $game_slug = sanitize_key($game_slug);
        $table = Installer::scores_table();
        $best_attempt = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, score, accuracy, duration_seconds, created_at
                FROM {$table}
                WHERE game_slug = %s AND user_id = %d
                ORDER BY score DESC, accuracy DESC, duration_seconds ASC, created_at ASC, id ASC
                LIMIT 1",
                $game_slug,
                $user_id
            ),
            ARRAY_A
        );

        if (! is_array($best_attempt)) {
            return null;
        }

        $higher_count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*)
                FROM {$table} scores
                WHERE scores.game_slug = %s
                    AND scores.id = (
                        " . self::best_row_id_subquery($table, 'best_scores', 'scores', false) . "
                    )
                    AND (
                        scores.score > %d
                        OR (scores.score = %d AND scores.accuracy > %f)
                        OR (scores.score = %d AND scores.accuracy = %f AND scores.duration_seconds < %d)
                        OR (scores.score = %d AND scores.accuracy = %f AND scores.duration_seconds = %d AND scores.created_at < %s)
                        OR (scores.score = %d AND scores.accuracy = %f AND scores.duration_seconds = %d AND scores.created_at = %s AND scores.id < %d)
                    )",
                $game_slug,
                (int) $best_attempt['score'],
                (int) $best_attempt['score'],
                (float) $best_attempt['accuracy'],
                (int) $best_attempt['score'],
                (float) $best_attempt['accuracy'],
                (int) $best_attempt['duration_seconds'],
                (int) $best_attempt['score'],
                (float) $best_attempt['accuracy'],
                (int) $best_attempt['duration_seconds'],
                (string) $best_attempt['created_at'],
                (int) $best_attempt['score'],
                (float) $best_attempt['accuracy'],
                (int) $best_attempt['duration_seconds'],
                (string) $best_attempt['created_at'],
                (int) $best_attempt['id']
            )
        );

        return ((int) $higher_count) + 1;
    }
}
