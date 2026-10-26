<?php
declare(strict_types=1);

namespace OjCodeArena\Database;

/** Finished rounds reported by the game server, and each student's line. */
final class Round_Repository {
    /**
     * Stores one round result (already validated by Rest\Server_Controller).
     * Returns the round id, or the existing id for a repeated delivery.
     *
     * @param array<string,mixed> $result
     */
    public function save(array $result, string $title): int {
        global $wpdb;
        $session_id = (int) $result['sessionId'];
        $dedupe = sha1($session_id . '|' . $result['mode'] . '|' . $result['round'] . '|' . $result['startedAt'] . '|' . self::player_key($result));
        $existing = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . Tables::rounds() . ' WHERE dedupe = %s', $dedupe));
        if ($existing) {
            return (int) $existing;
        }
        $ok = $wpdb->insert(Tables::rounds(), array(
            'session_id' => $session_id,
            'mode' => $result['mode'],
            'round_no' => (int) $result['round'],
            'title' => mb_substr($title, 0, 120),
            'reason' => (string) $result['reason'],
            'started_at' => gmdate('Y-m-d H:i:s', (int) $result['startedAt']),
            'ended_at' => gmdate('Y-m-d H:i:s', (int) $result['endedAt']),
            'result' => (string) json_encode($result, JSON_UNESCAPED_UNICODE),
            'dedupe' => $dedupe,
            'created_at' => Tables::now(),
        ));
        if (! $ok) {
            return 0;
        }
        $round_id = (int) $wpdb->insert_id;
        foreach ($result['teams'] as $team) {
            foreach ($team['players'] as $p) {
                if ($p['bot'] || $p['uid'] <= 0) {
                    continue;
                }
                $wpdb->insert(Tables::players(), array(
                    'round_id' => $round_id,
                    'user_id' => (int) $p['uid'],
                    'team_no' => (int) $team['team'],
                    'team_rank' => (int) $team['rank'],
                    'team_pct' => (int) $team['pct'],
                    'cleared' => $team['cleared'] ? 1 : 0,
                    'solved' => (int) $p['solved'],
                    'bosses' => (int) $p['bosses'],
                    'score' => (int) $p['solved'] + 3 * (int) $p['bosses'],
                ));
            }
        }
        return $round_id;
    }

    /**
     * Class-game rounds. $session_ids null = every session (operators).
     *
     * @param list<int>|null $session_ids
     * @return list<array<string,mixed>>
     */
    public function for_sessions(?array $session_ids, int $limit = 200): array {
        global $wpdb;
        $sql = 'SELECT r.*, s.title AS session_title FROM ' . Tables::rounds() . ' r LEFT JOIN ' . Tables::sessions() . " s ON s.id = r.session_id WHERE r.mode = 'class'";
        if (null !== $session_ids) {
            if (! $session_ids) {
                return array();
            }
            $sql .= ' AND r.session_id IN (' . implode(',', array_map('intval', $session_ids)) . ')';
        }
        $rows = $wpdb->get_results($sql . $wpdb->prepare(' ORDER BY r.ended_at DESC, r.id DESC LIMIT %d', $limit), ARRAY_A);
        return array_map(array(self::class, 'shape'), is_array($rows) ? $rows : array());
    }

    /** Retention: removes rounds that ended before $before (unix time). */
    public function delete_older_than(int $before): int {
        global $wpdb;
        $cut = gmdate('Y-m-d H:i:s', $before);
        $ids = array_map('intval', (array) $wpdb->get_col($wpdb->prepare('SELECT id FROM ' . Tables::rounds() . ' WHERE ended_at < %s LIMIT 5000', $cut)));
        if (! $ids) {
            return 0;
        }
        $in = implode(',', $ids);
        $wpdb->query('DELETE FROM ' . Tables::players() . " WHERE round_id IN ({$in})"); // ids are ints
        $wpdb->query('DELETE FROM ' . Tables::rounds() . " WHERE id IN ({$in})");
        return count($ids);
    }

    /** Removes every round of one class game (load-test clean-up). */
    public function delete_for_session(int $session_id): void {
        global $wpdb;
        $ids = array_map('intval', (array) $wpdb->get_col($wpdb->prepare('SELECT id FROM ' . Tables::rounds() . ' WHERE session_id = %d', $session_id)));
        if ($ids) {
            $in = implode(',', $ids);
            $wpdb->query('DELETE FROM ' . Tables::players() . " WHERE round_id IN ({$in})"); // ids are ints
            $wpdb->query('DELETE FROM ' . Tables::rounds() . " WHERE id IN ({$in})");
        }
    }

    /**
     * Personal data erasure: drops the learner's lines and replaces their
     * name inside the stored round results.
     */
    public function erase_user(int $user_id): int {
        global $wpdb;
        $ids = array_map('intval', (array) $wpdb->get_col($wpdb->prepare('SELECT DISTINCT round_id FROM ' . Tables::players() . ' WHERE user_id = %d', $user_id)));
        foreach ($ids as $id) {
            $raw = $wpdb->get_var($wpdb->prepare('SELECT result FROM ' . Tables::rounds() . ' WHERE id = %d', $id));
            $result = json_decode((string) $raw, true);
            if (! is_array($result)) {
                continue;
            }
            foreach ($result['teams'] as $t => $team) {
                foreach ($team['players'] as $p => $player) {
                    if ((int) ($player['uid'] ?? 0) === $user_id) {
                        $result['teams'][$t]['players'][$p]['uid'] = 0;
                        $result['teams'][$t]['players'][$p]['name'] = '삭제된 사용자';
                    }
                }
            }
            $wpdb->update(Tables::rounds(), array('result' => (string) json_encode($result, JSON_UNESCAPED_UNICODE)), array('id' => $id));
        }
        $wpdb->delete(Tables::players(), array('user_id' => $user_id));
        return count($ids);
    }

    /** @return list<array<string,mixed>> */
    public function for_user(int $user_id, int $limit = 50, int $offset = 0): array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT p.*, r.mode, r.title, r.ended_at FROM ' . Tables::players() . ' p JOIN ' . Tables::rounds() . ' r ON r.id = p.round_id WHERE p.user_id = %d ORDER BY r.ended_at DESC, r.id DESC LIMIT %d OFFSET %d',
            $user_id,
            $limit,
            $offset
        ), ARRAY_A);
        $out = array();
        foreach (is_array($rows) ? $rows : array() as $row) {
            $out[] = array(
                'date' => self::local((string) $row['ended_at']),
                'title' => '' !== $row['title'] ? (string) $row['title'] : '자유 대전',
                'mode' => (string) $row['mode'],
                'team_rank' => (int) $row['team_rank'],
                'team_pct' => (int) $row['team_pct'],
                'cleared' => (bool) $row['cleared'],
                'solved' => (int) $row['solved'],
                'bosses' => (int) $row['bosses'],
            );
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function shape(array $row): array {
        $result = json_decode((string) $row['result'], true);
        $result = is_array($result) ? $result : array();
        return array(
            'id' => (int) $row['id'],
            'session_id' => (int) $row['session_id'],
            'session_title' => (string) ($row['session_title'] ?? $row['title']),
            'title' => (string) $row['title'],
            'mode' => (string) $row['mode'],
            'round' => (int) $row['round_no'],
            'reason' => (string) $row['reason'],
            'ended_at' => strtotime((string) $row['ended_at'] . ' UTC'),
            'ended_label' => self::local((string) $row['ended_at']),
            'teams' => (array) ($result['teams'] ?? array()),
        );
    }

    private static function local(string $gmt): string {
        return get_date_from_gmt($gmt, 'Y-m-d H:i');
    }

    /** @param array<string,mixed> $result */
    private static function player_key(array $result): string {
        $ids = array();
        foreach ($result['teams'] as $team) {
            foreach ($team['players'] as $p) {
                $ids[] = (int) $p['uid'] . ':' . (int) $p['solved'];
            }
        }
        return implode(',', $ids);
    }
}
