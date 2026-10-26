<?php
declare(strict_types=1);

namespace EduGames\Statistics;

use EduGames\Database\Installer;
use EduGames\Rankings\Leaderboard_Service;

final class Player_Statistics_Service {
    /**
     * @return array<string, mixed>
     */
    public static function for_user(int $user_id, string $game_slug = ''): array {
        if ($user_id <= 0) {
            return array(
                'summary' => self::empty_summary(),
                'games' => array(),
                'recent' => array(),
                'modeBreakdown' => array(),
                'myRank' => null,
            );
        }

        global $wpdb;

        $table = Installer::scores_table();
        $game_slug = sanitize_key($game_slug);
        $where = 'WHERE user_id = %d';
        $args = array($user_id);

        if ('' !== $game_slug) {
            $where .= ' AND game_slug = %s';
            $args[] = $game_slug;
        }

        $summary_sql = $wpdb->prepare(
            "SELECT COUNT(*) AS attempts,
                COALESCE(MAX(score), 0) AS best_score,
                COALESCE(AVG(score), 0) AS average_score,
                COALESCE(SUM(rounds_played), 0) AS rounds_played,
                COALESCE(SUM(correct_count), 0) AS correct_count,
                COALESCE(AVG(accuracy), 0) AS average_accuracy,
                COALESCE(MIN(NULLIF(duration_seconds, 0)), 0) AS best_duration,
                MAX(created_at) AS last_played_at
            FROM {$table}
            {$where}",
            $args
        );
        $summary = $wpdb->get_row($summary_sql, ARRAY_A);

        $recent_sql = $wpdb->prepare(
            "SELECT id, game_slug, score, rounds_played, correct_count, duration_seconds, accuracy, meta, created_at
            FROM {$table}
            {$where}
            ORDER BY created_at DESC
            LIMIT 10",
            $args
        );
        $recent_rows = $wpdb->get_results($recent_sql, ARRAY_A);

        return array(
            'summary' => self::normalize_summary(is_array($summary) ? $summary : array()),
            'games' => self::games_for_user($user_id),
            'recent' => self::normalize_recent(is_array($recent_rows) ? $recent_rows : array()),
            'modeBreakdown' => self::mode_breakdown(is_array($recent_rows) ? $recent_rows : array()),
            'myRank' => '' !== $game_slug ? Leaderboard_Service::rank_for_user($game_slug, $user_id) : null,
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function games_for_user(int $user_id): array {
        global $wpdb;

        $table = Installer::scores_table();
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT game_slug,
                    COUNT(*) AS attempts,
                    MAX(score) AS best_score,
                    AVG(score) AS average_score,
                    SUM(rounds_played) AS rounds_played,
                    SUM(correct_count) AS correct_count,
                    AVG(accuracy) AS average_accuracy,
                    MAX(created_at) AS last_played_at
                FROM {$table}
                WHERE user_id = %d
                GROUP BY game_slug
                ORDER BY best_score DESC, last_played_at DESC",
                $user_id
            ),
            ARRAY_A
        );

        if (! is_array($rows)) {
            return array();
        }

        return array_map(
            static function (array $row): array {
                return array(
                    'gameSlug' => (string) $row['game_slug'],
                    'attempts' => (int) $row['attempts'],
                    'bestScore' => (int) $row['best_score'],
                    'averageScore' => round((float) $row['average_score'], 1),
                    'roundsPlayed' => (int) $row['rounds_played'],
                    'correctCount' => (int) $row['correct_count'],
                    'averageAccuracy' => round((float) $row['average_accuracy'], 1),
                    'lastPlayedAt' => (string) $row['last_played_at'],
                );
            },
            $rows
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function normalize_summary(array $row): array {
        if (empty($row)) {
            return self::empty_summary();
        }

        return array(
            'attempts' => (int) $row['attempts'],
            'bestScore' => (int) $row['best_score'],
            'averageScore' => round((float) $row['average_score'], 1),
            'roundsPlayed' => (int) $row['rounds_played'],
            'correctCount' => (int) $row['correct_count'],
            'averageAccuracy' => round((float) $row['average_accuracy'], 1),
            'bestDuration' => (int) $row['best_duration'],
            'lastPlayedAt' => (string) ($row['last_played_at'] ?? ''),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function empty_summary(): array {
        return array(
            'attempts' => 0,
            'bestScore' => 0,
            'averageScore' => 0,
            'roundsPlayed' => 0,
            'correctCount' => 0,
            'averageAccuracy' => 0,
            'bestDuration' => 0,
            'lastPlayedAt' => '',
        );
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private static function normalize_recent(array $rows): array {
        return array_map(
            static function (array $row): array {
                return array(
                    'id' => (int) $row['id'],
                    'gameSlug' => (string) $row['game_slug'],
                    'score' => (int) $row['score'],
                    'roundsPlayed' => (int) $row['rounds_played'],
                    'correctCount' => (int) $row['correct_count'],
                    'durationSeconds' => (int) $row['duration_seconds'],
                    'accuracy' => (float) $row['accuracy'],
                    'createdAt' => (string) $row['created_at'],
                );
            },
            $rows
        );
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, int>
     */
    private static function mode_breakdown(array $rows): array {
        $breakdown = array();

        foreach ($rows as $row) {
            $meta = json_decode((string) ($row['meta'] ?? ''), true);
            if (! is_array($meta) || empty($meta['modeHistory']) || ! is_array($meta['modeHistory'])) {
                continue;
            }

            foreach ($meta['modeHistory'] as $mode) {
                $mode = sanitize_key((string) $mode);
                if ('' === $mode) {
                    continue;
                }

                $breakdown[$mode] = ($breakdown[$mode] ?? 0) + 1;
            }
        }

        return $breakdown;
    }
}
