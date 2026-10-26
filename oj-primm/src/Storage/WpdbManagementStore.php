<?php
declare(strict_types=1);
namespace OjPrimm\Storage;

use DomainException;
use RuntimeException;
use Throwable;

final class WpdbManagementStore implements ManagementStore
{
    public function __construct(private readonly object $db, private readonly Repository $records) {}
    private function table(): string { return $this->db->prefix . 'oj_primm_activities'; }
    private function must(mixed $result): void { if ($result === false) { throw new RuntimeException('수업 버전을 저장하지 못했습니다.'); } }
    private function activity(array $row): array
    {
        return ['id' => (int) $row['id'], 'creator_user_id' => (int) $row['creator_user_id']]
            + json_decode((string) $row['data_json'], true, 32, JSON_THROW_ON_ERROR);
    }
    public function page(string $type, string $id, int $before, int $limit): array
    {
        $sql = 'SELECT * FROM `' . $this->table() . '` WHERE context_type=%s AND context_id=%s';
        $values = [$type, $id];
        if ($before > 0) { $sql .= ' AND id<%d'; $values[] = $before; }
        $sql .= ' ORDER BY id DESC LIMIT %d'; $values[] = max(1, min(100, $limit));
        $rows = $this->db->get_results($this->db->prepare($sql, ...$values), ARRAY_A);
        if (! is_array($rows)) { throw new RuntimeException('수업 목록을 읽지 못했습니다.'); }
        return array_map($this->activity(...), $rows);
    }
    public function update(int $id, int $revision, array $changes): array
    {
        $record = $this->records->findActivity($id) ?? throw new DomainException('수업 자료가 없습니다.');
        if (($record['revision'] ?? 1) !== $revision) { throw new DomainException('수업 버전이 변경되었습니다. 다시 불러와 주세요.'); }
        $data = array_replace($record, $changes, ['revision' => $revision + 1, 'updated_at' => gmdate('Y-m-d H:i:s')]);
        $changed = $this->db->update($this->table(), ['title' => $data['title'], 'revision' => $data['revision'], 'status' => $data['status'],
            'data_json' => json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'updated_at' => $data['updated_at']],
            ['id' => $id, 'revision' => $revision]);
        $this->must($changed);
        if ($changed !== 1) { throw new DomainException('다른 교사가 수업 버전을 변경했습니다. 새로고침해 주세요.'); }
        return $data;
    }
    public function version(?array $parent, array $data, string $key, bool $inTransaction = false): array
    {
        $hash = hash('sha256', $key);
        $binding = hash('sha256', json_encode(array_diff_key($data, array_flip(['id', 'created_at', 'updated_at', 'revision',
            'content_version', 'family_id', 'parent_activity_id', 'request_key_hash', 'request_payload_hash'])), JSON_THROW_ON_ERROR));
        if (! $inTransaction) { $this->must($this->db->query('START TRANSACTION')); }
        try {
            $family = $parent === null ? 0 : (int) (($parent['family_id'] ?? 0) ?: $parent['id']);
            if ($family > 0) {
                // Lock before looking for duplicate requests so simultaneous saves reuse one version.
                $root = $this->db->get_var($this->db->prepare('SELECT id FROM `' . $this->table() . '` WHERE id=%d FOR UPDATE', $family));
                if ($root === null) { throw new DomainException('원본 수업 버전을 확인할 수 없습니다.'); }
            }
            $existing = $this->db->get_var($this->db->prepare('SELECT id FROM `' . $this->table() . '` WHERE creator_user_id=%d AND request_key_hash=%s', $data['creator_user_id'], $hash));
            if ($existing !== null) {
                $result = $this->records->findActivity((int) $existing) ?? throw new RuntimeException('기존 수업 버전을 확인하지 못했습니다.');
                if (! hash_equals($result['request_payload_hash'] ?? '', $binding)) { throw new DomainException('같은 요청 키에 다른 수정 내용이 사용되었습니다. 다시 저장해 주세요.'); }
            }
            else {
                $number = 1;
                if ($family > 0) {
                    $number = 1 + (int) $this->db->get_var($this->db->prepare('SELECT MAX(content_version) FROM `' . $this->table() . '` WHERE id=%d OR family_id=%d', $family, $family));
                }
                $result = $this->records->createActivity(array_replace($data, ['family_id' => $family,
                    'parent_activity_id' => $parent['id'] ?? null, 'content_version' => $number, 'revision' => 1,
                    'request_key_hash' => $hash, 'request_payload_hash' => $binding]));
            }
            if (! $inTransaction) { $this->must($this->db->query('COMMIT')); }
            return $result;
        } catch (Throwable $error) { if (! $inTransaction) { $this->db->query('ROLLBACK'); } throw $error; }
    }
    public function versions(int $family): array
    {
        $rows = $this->db->get_results($this->db->prepare('SELECT * FROM `' . $this->table() . '` WHERE id=%d OR family_id=%d ORDER BY id DESC LIMIT 100', $family, $family), ARRAY_A);
        if (! is_array($rows)) { throw new RuntimeException('수업 버전 이력을 읽지 못했습니다.'); }
        return array_map($this->activity(...), $rows);
    }
    public function attempts(int $activityId): array
    {
        $rows = $this->db->get_results($this->db->prepare('SELECT * FROM `' . $this->db->prefix . 'oj_primm_attempts` WHERE activity_id=%d ORDER BY user_id ASC', $activityId), ARRAY_A);
        if (! is_array($rows)) { throw new RuntimeException('수업 보고서를 읽지 못했습니다.'); }
        return array_map(static fn (array $row): array => ['id' => (int) $row['id'], 'user_id' => (int) $row['user_id'],
            'activity_id' => (int) $row['activity_id'], 'version' => (int) $row['version'], 'updated_at' => $row['updated_at']]
            + json_decode((string) $row['state_json'], true, 32, JSON_THROW_ON_ERROR), $rows);
    }
}
