<?php
declare(strict_types=1);
namespace OjPrimm\Storage;

use DomainException;
use RuntimeException;
use Throwable;

final class WpdbPracticeStore implements PracticeStore
{
    public function __construct(private readonly object $db) {}
    private function table(): string { return $this->db->prefix . 'oj_primm_practices'; }
    private function row(array $row): array
    {
        return ['id' => (int) $row['id'], 'activity_id' => (int) $row['activity_id'], 'user_id' => (int) $row['user_id'],
            'status' => $row['status'], 'lease_token' => $row['lease_token'], 'created_at' => $row['created_at'], 'updated_at' => $row['updated_at']]
            + json_decode($row['data_json'], true, 32, JSON_THROW_ON_ERROR);
    }
    public function find(int $id): ?array
    {
        $row = $this->db->get_row($this->db->prepare('SELECT * FROM `' . $this->table() . '` WHERE id=%d', $id), ARRAY_A);
        return is_array($row) ? $this->row($row) : null;
    }
    public function createOrFind(int $activity, int $actor, string $fingerprint, array $data): array
    {
        $find = fn () => $this->db->get_var($this->db->prepare('SELECT id FROM `' . $this->table() . '` WHERE user_id=%d AND fingerprint=%s', $actor, $fingerprint));
        $id = $find(); if ($id !== null) { return $this->find((int) $id) ?? throw new RuntimeException('기존 연습을 읽지 못했습니다.'); }
        // Seed outside the transaction, then serialize admission before taking a consistent snapshot.
        // One non-personal row avoids per-user lock rows and check/insert races across requests.
        $control = $this->db->prefix . 'oj_primm_generation_control';
        if ($this->db->query($this->db->prepare('INSERT IGNORE INTO `' . $control . '` (scope_key,data_json,updated_at) VALUES (%s,%s,%s)',
            'practice_admission', '{}', gmdate('Y-m-d H:i:s'))) === false
            || $this->db->query('START TRANSACTION') === false) { throw new RuntimeException('연습 실행 한도를 확인하지 못했습니다.'); }
        try {
            if ($this->db->get_var($this->db->prepare('SELECT scope_key FROM `' . $control . '` WHERE scope_key=%s FOR UPDATE', 'practice_admission')) !== 'practice_admission') {
                throw new RuntimeException('연습 실행 한도를 잠그지 못했습니다.');
            }
            $id = $find();
            if ($id !== null) { $result = $this->find((int) $id) ?? throw new RuntimeException('기존 연습을 읽지 못했습니다.'); }
            else { $result = $this->admit($activity, $actor, $fingerprint, $data); }
            if ($this->db->query('COMMIT') === false) { throw new RuntimeException('연습 요청을 확정하지 못했습니다.'); }
            return $result;
        } catch (Throwable $error) { $this->db->query('ROLLBACK'); throw $error; }
    }
    private function admit(int $activity, int $actor, string $fingerprint, array $data): array
    {
        // Cap learner work before making another native queue entry. Pending work does not consume an unlimited budget.
        $active = (int) $this->db->get_var($this->db->prepare('SELECT COUNT(*) FROM `' . $this->table() . '` WHERE user_id=%d AND status<>%s AND updated_at>=%s AND created_at>=%s',
            $actor, 'completed', gmdate('Y-m-d H:i:s', time() - 600), gmdate('Y-m-d H:i:s', time() - 86400)));
        if ($active >= 3) { throw new DomainException('진행 중인 연습 검증을 먼저 확인해 주세요. 잠시 후 다시 실행할 수 있습니다.'); }
        $recent = (int) $this->db->get_var($this->db->prepare('SELECT COUNT(*) FROM `' . $this->table() . '` WHERE user_id=%d AND created_at>=%s', $actor, gmdate('Y-m-d H:i:s', time() - 60)));
        if ($recent >= 10) { throw new DomainException('연습 실행 요청이 많습니다. 잠시 후 다시 시도해 주세요.'); }
        $now = gmdate('Y-m-d H:i:s');
        $ok = $this->db->insert($this->table(), ['activity_id' => $activity, 'user_id' => $actor, 'fingerprint' => $fingerprint,
            'status' => 'pending', 'lease_token' => '', 'lease_expires_at' => null,
            'data_json' => json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'created_at' => $now, 'updated_at' => $now]);
        if ($ok === false) { throw new RuntimeException('연습 요청을 저장하지 못했습니다.'); }
        return $this->find((int) $this->db->insert_id) ?? throw new RuntimeException('연습 요청을 읽지 못했습니다.');
    }
    public function claim(int $id, string $lease): bool
    {
        $now = gmdate('Y-m-d H:i:s');
        $changed = $this->db->query($this->db->prepare('UPDATE `' . $this->table() . '` SET lease_token=%s,lease_expires_at=%s WHERE id=%d AND status<>%s AND (lease_token=%s OR lease_expires_at<=%s)',
            $lease, gmdate('Y-m-d H:i:s', time() + 60), $id, 'completed', '', $now));
        if ($changed === false) { throw new RuntimeException('연습 처리 권한을 저장하지 못했습니다.'); } return $changed === 1;
    }
    public function save(int $id, string $lease, array $data, string $status): array
    {
        if (! in_array($status, ['pending', 'completed'], true)) { throw new DomainException('연습 상태가 올바르지 않습니다.'); }
        $changed = $this->db->update($this->table(), ['status' => $status, 'lease_token' => '', 'lease_expires_at' => null,
            'data_json' => json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'updated_at' => gmdate('Y-m-d H:i:s')],
            ['id' => $id, 'lease_token' => $lease]);
        if ($changed !== 1) { throw new DomainException('연습 처리 상태가 변경되었습니다. 다시 확인해 주세요.'); }
        return $this->find($id) ?? throw new RuntimeException('연습 결과를 읽지 못했습니다.');
    }
    public function latest(int $activity, int $actor): ?array
    {
        $row = $this->db->get_row($this->db->prepare('SELECT * FROM `' . $this->table() . '` WHERE activity_id=%d AND user_id=%d ORDER BY id DESC LIMIT 1', $activity, $actor), ARRAY_A);
        return is_array($row) ? $this->row($row) : null;
    }
}
