<?php
declare(strict_types=1);
namespace OjPrimm\Storage;

use DomainException;
use RuntimeException;
use Throwable;

final class WpdbRepository implements Repository
{
    public function __construct(private readonly object $db, private readonly bool $extended = false) {}
    private function table(string $name): string { return $this->db->prefix . 'oj_primm_' . $name; }
    private function encode(array $data): string { return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
    private function decode(string $value): array
    {
        $data = json_decode($value, true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($data)) { throw new RuntimeException('PRIMM 저장 자료를 읽을 수 없습니다.'); }
        return $data;
    }
    private function must(mixed $result): void { if ($result === false) { throw new RuntimeException('PRIMM 자료 저장에 실패했습니다. 다시 시도해 주세요.'); } }

    public function createActivity(array $data): array
    {
        $columns = [
            'creator_user_id' => $data['creator_user_id'], 'context_type' => $data['context_type'],
            'context_id' => $data['context_id'], 'title' => $data['title'], 'data_json' => $this->encode($data),
            'created_at' => $data['created_at'], 'updated_at' => $data['updated_at'],
        ];
        if (array_key_exists('content_version', $data)) {
            foreach (['family_id', 'parent_activity_id', 'content_version', 'revision', 'status', 'request_key_hash'] as $key) {
                $columns[$key] = $data[$key] ?? null;
            }
        }
        $this->must($this->db->insert($this->table('activities'), $columns));
        $data['id'] = (int) $this->db->insert_id;
        if ($data['id'] < 1) { throw new RuntimeException('활동 번호를 확인하지 못했습니다.'); }
        return $data;
    }

    public function findActivity(int $id): ?array
    {
        $row = $this->db->get_row($this->db->prepare('SELECT * FROM `' . $this->table('activities') . '` WHERE id=%d', $id), ARRAY_A);
        return is_array($row) ? $this->activity($row) : null;
    }

    public function activitiesForContext(string $type, string $id): array
    {
        $rows = $this->db->get_results($this->db->prepare('SELECT * FROM `' . $this->table('activities')
            . '` WHERE context_type=%s AND context_id=%s ORDER BY id DESC LIMIT 100', $type, $id), ARRAY_A);
        if (! is_array($rows)) { throw new RuntimeException('활동 목록을 읽지 못했습니다.'); }
        return array_map(fn (array $row): array => $this->activity($row), $rows);
    }

    private function activity(array $row): array
    {
        return ['id' => (int) $row['id'], 'creator_user_id' => (int) $row['creator_user_id']]
            + $this->decode((string) $row['data_json']);
    }

    public function findAttempt(int $activityId, int $userId): ?array
    {
        $row = $this->db->get_row($this->db->prepare('SELECT * FROM `' . $this->table('attempts')
            . '` WHERE activity_id=%d AND user_id=%d', $activityId, $userId), ARRAY_A);
        return is_array($row) ? $this->attempt($row) : null;
    }

    private function attempt(array $row): array
    {
        return ['id' => (int) $row['id'], 'activity_id' => (int) $row['activity_id'], 'user_id' => (int) $row['user_id'],
            'version' => (int) $row['version'], 'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'], 'last_request_hash' => (string) $row['last_request_hash']]
            + $this->decode((string) $row['state_json']);
    }

    public function saveAttempt(int $activityId, int $userId, int $expectedVersion, array $state, array $event, string $requestHash): array
    {
        $this->must($this->db->query('START TRANSACTION'));
        try {
            if ($this->extended) {
                // Serialize progress with publish/withdraw updates on the same activity row.
                $activity = $this->db->get_row($this->db->prepare('SELECT * FROM `' . $this->table('activities') . '` WHERE id=%d FOR UPDATE', $activityId), ARRAY_A);
                if (! is_array($activity) || ($this->decode((string) $activity['data_json'])['status'] ?? 'active') !== 'active') {
                    throw new DomainException('회수되었거나 공개되지 않은 수업에는 새 진행 기록을 저장할 수 없습니다.');
                }
            }
            $row = $this->db->get_row($this->db->prepare('SELECT * FROM `' . $this->table('attempts')
                . '` WHERE activity_id=%d AND user_id=%d FOR UPDATE', $activityId, $userId), ARRAY_A);
            $actual = is_array($row) ? (int) $row['version'] : 0;
            if ($actual !== $expectedVersion) {
                if (is_array($row) && hash_equals((string) $row['last_request_hash'], $requestHash)) {
                    $this->must($this->db->query('COMMIT'));
                    return $this->attempt($row);
                }
                throw new DomainException('다른 화면에서 기록이 변경되었습니다. 새로고침한 뒤 이어서 작성해 주세요.');
            }
            $now = gmdate('Y-m-d H:i:s');
            $data = ['version' => $actual + 1, 'state_json' => $this->encode($state),
                'last_request_hash' => $requestHash, 'updated_at' => $now];
            if (is_array($row)) {
                $id = (int) $row['id'];
                $changed = $this->db->update($this->table('attempts'), $data, ['id' => $id, 'version' => $actual]);
                $this->must($changed);
                if ($changed !== 1) { throw new DomainException('저장 기록의 버전이 바뀌었습니다. 새로고침해 주세요.'); }
            } else {
                $this->must($this->db->insert($this->table('attempts'), $data + [
                    'activity_id' => $activityId, 'user_id' => $userId, 'created_at' => $now,
                ]));
                $id = (int) $this->db->insert_id;
            }
            $this->must($this->db->insert($this->table('events'), [
                'attempt_id' => $id, 'user_id' => $userId, 'stage' => $event['stage'],
                'event_type' => $event['type'], 'payload_json' => $this->encode($event['payload']), 'created_at' => $now,
            ]));
            $this->must($this->db->query('COMMIT'));
            return $this->findAttempt($activityId, $userId) ?? throw new RuntimeException('저장한 기록을 확인하지 못했습니다.');
        } catch (Throwable $error) {
            $this->db->query('ROLLBACK');
            throw $error;
        }
    }

    public function attemptsForActivities(array $activityIds): array
    {
        if ($activityIds === []) { return []; }
        $ids = array_values(array_unique(array_map('intval', $activityIds)));
        $rows = $this->db->get_results($this->db->prepare('SELECT * FROM `' . $this->table('attempts')
            . '` WHERE activity_id IN (' . implode(',', array_fill(0, count($ids), '%d')) . ') ORDER BY updated_at DESC,id DESC LIMIT 200', ...$ids), ARRAY_A);
        if (! is_array($rows)) { throw new RuntimeException('학생 기록을 읽지 못했습니다.'); }
        return array_map(fn (array $row): array => $this->attempt($row), $rows);
    }

    public function exportUser(int $userId, int $page = 1): array
    {
        $offset = (max(1, $page) - 1) * 50;
        $result = ['done' => true];
        $tables = ['activities' => 'creator_user_id', 'generations' => 'creator_user_id', 'attempts' => 'user_id', 'events' => 'user_id'];
        if ($this->extended) { $tables['practices'] = 'user_id'; }
        foreach ($tables as $table => $column) {
            $rows = $this->db->get_results($this->db->prepare('SELECT * FROM `' . $this->table($table)
                . '` WHERE `' . $column . '`=%d ORDER BY id ASC LIMIT 50 OFFSET %d', $userId, $offset), ARRAY_A);
            if (! is_array($rows)) { throw new RuntimeException('개인 기록을 내보내지 못했습니다.'); }
            $result[$table] = $rows;
            $result['done'] = $result['done'] && count($rows) < 50;
        }
        if ($this->extended && $page <= 1) {
            $raw = $this->db->get_var($this->db->prepare('SELECT data_json FROM `' . $this->table('generation_control') . '` WHERE scope_key=%s', 'queue'));
            $control = is_string($raw) ? $this->decode($raw) : [];
            $result['usage'] = isset($control['teachers'][(string) $userId])
                ? [['id' => 'daily-ai', 'date' => $control['date'] ?? '', 'ai_calls' => $control['teachers'][(string) $userId]]] : [];
        }
        return $result;
    }

    public function eraseUser(int $userId): bool
    {
        $this->must($this->db->query('START TRANSACTION'));
        try {
            if ($this->extended) {
                // Same queue lock as admission/accounting, so erasure cannot lose another teacher's usage.
                $raw = $this->db->get_var($this->db->prepare('SELECT data_json FROM `' . $this->table('generation_control') . '` WHERE scope_key=%s FOR UPDATE', 'queue'));
                if (is_string($raw)) {
                    $control = $this->decode($raw); unset($control['teachers'][(string) $userId]);
                    $this->must($this->db->update($this->table('generation_control'), ['data_json' => $this->encode($control)], ['scope_key' => 'queue']));
                }
            }
            foreach (['events', 'attempts'] as $table) {
                $this->must($this->db->delete($this->table($table), ['user_id' => $userId]));
            }
            if ($this->extended) { $this->must($this->db->delete($this->table('practices'), ['user_id' => $userId])); }
            $this->must($this->db->delete($this->table('generations'), ['creator_user_id' => $userId]));
            $rows = $this->db->get_results($this->db->prepare('SELECT id,data_json FROM `' . $this->table('activities')
                . '` WHERE creator_user_id=%d FOR UPDATE', $userId), ARRAY_A);
            if (! is_array($rows)) { throw new RuntimeException('활동 작성자 정보를 확인하지 못했습니다.'); }
            foreach ($rows as $row) {
                $data = $this->decode((string) $row['data_json']);
                $data['creator_user_id'] = 0;
                if ($this->extended) { unset($data['request_key_hash'], $data['request_payload_hash']); }
                $fields = ['creator_user_id' => 0, 'data_json' => $this->encode($data)];
                if ($this->extended) { $fields['request_key_hash'] = null; }
                $this->must($this->db->update($this->table('activities'), $fields, ['id' => (int) $row['id']]));
            }
            $this->must($this->db->query('COMMIT'));
            return true;
        } catch (Throwable $error) { $this->db->query('ROLLBACK'); throw $error; }
    }

    public function purgeBefore(string $cutoff): int
    {
        $events = $this->db->query($this->db->prepare('DELETE FROM `' . $this->table('events') . '` WHERE created_at < %s', $cutoff));
        $this->must($events);
        $attempts = $this->db->query($this->db->prepare('DELETE FROM `' . $this->table('attempts') . '` WHERE updated_at < %s', $cutoff));
        $this->must($attempts);
        $generations = $this->db->query($this->db->prepare('DELETE FROM `' . $this->table('generations') . '` WHERE status IN (%s,%s,%s,%s) AND updated_at < %s', 'failed', 'ready', 'canceled', 'stopped', $cutoff));
        $this->must($generations);
        $practices = 0;
        if ($this->extended) {
            $practices = $this->db->query($this->db->prepare('DELETE FROM `' . $this->table('practices') . '` WHERE updated_at < %s', $cutoff));
            $this->must($practices);
        }
        return (int) $events + (int) $attempts + (int) $generations + (int) $practices;
    }
}
