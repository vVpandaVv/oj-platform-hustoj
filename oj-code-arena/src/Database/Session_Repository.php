<?php
declare(strict_types=1);

namespace OjCodeArena\Database;

use OjCodeArena\Support\Game_Settings;

/** Class games ("sessions") that teachers open from the console. */
final class Session_Repository {
    /**
     * @param array<string,mixed> $data title, owner_id, context_type, context_id, context_label, settings
     * @return array<string,mixed>|null
     */
    public function create(array $data): ?array {
        global $wpdb;
        $now = Tables::now();
        $ok = $wpdb->insert(Tables::sessions(), array(
            'title' => mb_substr((string) $data['title'], 0, 120),
            'owner_id' => (int) $data['owner_id'],
            'context_type' => (string) ($data['context_type'] ?? ''),
            'context_id' => (string) ($data['context_id'] ?? ''),
            'context_label' => mb_substr((string) ($data['context_label'] ?? ''), 0, 120),
            'settings' => (string) wp_json_encode(Game_Settings::clean((array) ($data['settings'] ?? array())), JSON_UNESCAPED_UNICODE),
            'status' => 'open',
            'created_at' => $now,
            'updated_at' => $now,
        ));
        return $ok ? $this->find((int) $wpdb->insert_id) : null;
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Tables::sessions() . ' WHERE id = %d', $id), ARRAY_A);
        return is_array($row) ? self::shape($row) : null;
    }

    /** @return list<array<string,mixed>> */
    public function open_for_owner(int $owner_id, bool $all_owners): array {
        global $wpdb;
        $sql = 'SELECT * FROM ' . Tables::sessions() . " WHERE status = 'open'";
        if (! $all_owners) {
            $sql .= $wpdb->prepare(' AND owner_id = %d', $owner_id);
        }
        $rows = $wpdb->get_results($sql . ' ORDER BY updated_at DESC LIMIT 100', ARRAY_A);
        return array_map(array(self::class, 'shape'), is_array($rows) ? $rows : array());
    }

    /**
     * Open sessions a learner may see: those without a class/group, and
     * those whose class/group is in $contexts (list of "type|id").
     *
     * @param list<string> $contexts
     * @return list<array<string,mixed>>
     */
    public function open_for_learner(array $contexts): array {
        global $wpdb;
        $rows = $wpdb->get_results('SELECT * FROM ' . Tables::sessions() . " WHERE status = 'open' ORDER BY updated_at DESC LIMIT 200", ARRAY_A);
        $allowed = array_flip($contexts);
        $out = array();
        foreach (is_array($rows) ? $rows : array() as $row) {
            if ('' === $row['context_id'] || isset($allowed[$row['context_type'] . '|' . $row['context_id']])) {
                $out[] = self::shape($row);
            }
        }
        return $out;
    }

    /** @param array<string,mixed> $fields title, settings, status */
    public function update(int $id, array $fields): ?array {
        global $wpdb;
        $set = array('updated_at' => Tables::now());
        if (isset($fields['title'])) {
            $set['title'] = mb_substr((string) $fields['title'], 0, 120);
        }
        if (isset($fields['settings'])) {
            $set['settings'] = (string) wp_json_encode(Game_Settings::clean((array) $fields['settings']), JSON_UNESCAPED_UNICODE);
        }
        if (isset($fields['status'])) {
            $set['status'] = 'archived' === $fields['status'] ? 'archived' : 'open';
        }
        $wpdb->update(Tables::sessions(), $set, array('id' => $id));
        return $this->find($id);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function shape(array $row): array {
        $settings = json_decode((string) $row['settings'], true);
        return array(
            'id' => (int) $row['id'],
            'title' => (string) $row['title'],
            'owner_id' => (int) $row['owner_id'],
            'context_type' => (string) $row['context_type'],
            'context_id' => (string) $row['context_id'],
            'context' => (string) $row['context_label'],
            'settings' => Game_Settings::clean(is_array($settings) ? $settings : array()),
            'status' => (string) $row['status'],
            'updated_at' => (string) $row['updated_at'],
        );
    }
}
