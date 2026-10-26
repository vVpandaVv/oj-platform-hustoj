<?php
declare(strict_types=1);

namespace OjCodeArena\Database;

use OjCodeArena\Support\Problem_Validator;

/**
 * The shared problem bank. Every change bumps a version number so the game
 * server copy of each session is refreshed (Support\Game_Server).
 */
final class Problem_Repository {
    public const VERSION_OPTION = 'oj_code_arena_bank_version';

    public function count(): int {
        global $wpdb;
        return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Tables::problems());
    }

    /** @return list<array<string,mixed>> */
    public function all(): array {
        global $wpdb;
        $rows = $wpdb->get_results('SELECT * FROM ' . Tables::problems() . ' ORDER BY kind DESC, id ASC', ARRAY_A);
        return array_map(array($this, 'shape'), is_array($rows) ? $rows : array());
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Tables::problems() . ' WHERE id = %d', $id), ARRAY_A);
        return is_array($row) ? $this->shape($row) : null;
    }

    /**
     * Inserts a validated problem. Returns the new id, or 0 when an equal
     * problem already exists. Throws \InvalidArgumentException on bad input.
     *
     * @param array<string,mixed> $input
     */
    public function insert(array $input, int $user_id): int {
        global $wpdb;
        $p = Problem_Validator::clean($input);
        $hash = self::hash($p);
        $exists = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . Tables::problems() . ' WHERE hash = %s', $hash));
        if ($exists) {
            return 0;
        }
        $ok = $wpdb->insert(Tables::problems(), array(
            'kind' => $p['kind'],
            'title' => $p['title'],
            'code' => $p['code'],
            'input' => $p['input'],
            'answers' => wp_json_encode($p['answers']),
            'tags' => wp_json_encode($p['tags'], JSON_UNESCAPED_UNICODE),
            'hash' => $hash,
            'enabled' => 1,
            'created_by' => $user_id,
            'updated_at' => Tables::now(),
        ));
        if (! $ok) {
            return 0;
        }
        self::bump();
        return (int) $wpdb->insert_id;
    }

    /** @param array<string,mixed> $input */
    public function update(int $id, array $input): ?array {
        global $wpdb;
        $current = $this->find($id);
        if (null === $current) {
            return null;
        }
        $fields = array('updated_at' => Tables::now());
        if (array_key_exists('enabled', $input)) {
            $fields['enabled'] = $input['enabled'] ? 1 : 0;
        }
        if (isset($input['code'])) {
            $p = Problem_Validator::clean(array_merge($current, $input, array('kind' => $current['kind'])));
            $hash = self::hash($p);
            $clash = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . Tables::problems() . ' WHERE hash = %s AND id <> %d', $hash, $id));
            if ($clash) {
                throw new \InvalidArgumentException('같은 문제가 이미 있어요.');
            }
            $fields += array(
                'title' => $p['title'],
                'code' => $p['code'],
                'input' => $p['input'],
                'answers' => wp_json_encode($p['answers']),
                'tags' => wp_json_encode($p['tags'], JSON_UNESCAPED_UNICODE),
                'hash' => $hash,
            );
        }
        $wpdb->update(Tables::problems(), $fields, array('id' => $id));
        self::bump();
        return $this->find($id);
    }

    public function delete(int $id): bool {
        global $wpdb;
        $ok = (bool) $wpdb->delete(Tables::problems(), array('id' => $id));
        self::bump();
        return $ok;
    }

    public function delete_all(): void {
        global $wpdb;
        $wpdb->query('DELETE FROM ' . Tables::problems());
        self::bump();
    }

    /** @return list<string> */
    public function tags(): array {
        $tags = array();
        foreach ($this->all() as $p) {
            if ($p['enabled']) {
                foreach ($p['tags'] as $t) {
                    $tags[$t] = true;
                }
            }
        }
        return array_keys($tags);
    }

    /**
     * Enabled problems in the shape the game server expects.
     *
     * @return array{normal: list<array<string,mixed>>, boss: list<array<string,mixed>>, tags: list<string>}
     */
    public function for_game(): array {
        $normal = array();
        $boss = array();
        $tags = array();
        foreach ($this->all() as $p) {
            if (! $p['enabled']) {
                continue;
            }
            foreach ($p['tags'] as $t) {
                $tags[$t] = true;
            }
            if ('boss' === $p['kind']) {
                $boss[] = array('title' => $p['title'], 'code' => $p['code'], 'input' => $p['input'], 'answers' => $p['answers'], 'tags' => $p['tags']);
            } else {
                $normal[] = array('code' => $p['code'], 'tags' => $p['tags']);
            }
        }
        return array('normal' => $normal, 'boss' => $boss, 'tags' => array_keys($tags));
    }

    public static function version(): int {
        return (int) get_option(self::VERSION_OPTION, 1);
    }

    private static function bump(): void {
        update_option(self::VERSION_OPTION, self::version() + 1, false);
    }

    /** @param array<string,mixed> $p */
    private static function hash(array $p): string {
        $key = $p['kind'] . '|' . preg_replace('/\s+/u', '', (string) $p['code']);
        if ('boss' === $p['kind']) {
            $key .= '|' . $p['title'];
        }
        return sha1($key);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function shape(array $row): array {
        $answers = json_decode((string) $row['answers'], true);
        $tags = json_decode((string) $row['tags'], true);
        return array(
            'id' => (int) $row['id'],
            'kind' => (string) $row['kind'],
            'title' => (string) $row['title'],
            'code' => (string) $row['code'],
            'input' => (string) $row['input'],
            'answers' => is_array($answers) ? array_values(array_map('strval', $answers)) : array(),
            'tags' => is_array($tags) ? array_values(array_map('strval', $tags)) : array(),
            'enabled' => (bool) $row['enabled'],
            'created_by' => (int) $row['created_by'],
        );
    }
}
