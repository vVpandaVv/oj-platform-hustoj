<?php
declare(strict_types=1);
namespace OjPrimm\Storage;

use DomainException;
use RuntimeException;
use Throwable;

final class WpdbGenerationStore implements GenerationOperationsStore
{
    public function __construct(private readonly object $db) {}
    private function table(): string { return $this->db->prefix . 'oj_primm_generations'; }
    private function controlTable(): string { return $this->db->prefix . 'oj_primm_generation_control'; }
    private function must(mixed $result): void { if ($result === false) { throw new RuntimeException('PRIMM 생성 작업을 저장하지 못했습니다.'); } }
    private function row(array $row): array
    {
        $data = json_decode((string) $row['data_json'], true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($data)) { throw new RuntimeException('생성 작업의 저장 자료를 확인하지 못했습니다.'); }
        return ['id' => (int) $row['id'], 'creator_user_id' => (int) $row['creator_user_id'],
            'status' => (string) $row['status'], 'activity_id' => $row['activity_id'] === null ? null : (int) $row['activity_id'],
            'error' => (string) $row['error_message'], 'lease_token' => (string) $row['lease_token'],
            'next_attempt_at' => $row['next_attempt_at'] ?? null,
            'lease_expires_at' => $row['lease_expires_at'], 'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at']] + $data;
    }
    public function find(int $id): ?array
    {
        $row = $this->db->get_row($this->db->prepare('SELECT * FROM `' . $this->table() . '` WHERE id=%d', $id), ARRAY_A);
        return is_array($row) ? $this->row($row) : null;
    }
    public function createOrFind(array $data): array
    {
        $existing = $this->db->get_var($this->db->prepare('SELECT id FROM `' . $this->table()
            . '` WHERE creator_user_id=%d AND fingerprint=%s', $data['creator_user_id'], $data['fingerprint']));
        if ($existing !== null) { return $this->find((int) $existing) ?? throw new RuntimeException('기존 생성 작업을 읽지 못했습니다.'); }
        $now = gmdate('Y-m-d H:i:s');
        $inserted = $this->db->insert($this->table(), [
            'creator_user_id' => $data['creator_user_id'], 'fingerprint' => $data['fingerprint'],
            'status' => 'queued', 'activity_id' => null, 'error_message' => '', 'lease_token' => '',
            'lease_expires_at' => null, 'data_json' => json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'created_at' => $now, 'updated_at' => $now,
        ]);
        if ($inserted === false) {
            // A concurrent request may have inserted the unique fingerprint first.
            $existing = $this->db->get_var($this->db->prepare('SELECT id FROM `' . $this->table()
                . '` WHERE creator_user_id=%d AND fingerprint=%s', $data['creator_user_id'], $data['fingerprint']));
            if ($existing !== null) { return $this->find((int) $existing) ?? throw new RuntimeException('생성 작업을 읽지 못했습니다.'); }
            throw new RuntimeException('생성 작업을 등록하지 못했습니다.');
        }
        return $this->find((int) $this->db->insert_id) ?? throw new RuntimeException('등록한 생성 작업을 읽지 못했습니다.');
    }
    public function claim(int $id, string $lease, string $now, string $expires): bool
    {
        $updated = $this->db->query($this->db->prepare('UPDATE `' . $this->table()
            . '` SET status=%s,lease_token=%s,lease_expires_at=%s,error_message=%s,updated_at=%s'
            . ' WHERE id=%d AND (status=%s OR (status=%s AND next_attempt_at<=%s) OR (status=%s AND lease_expires_at<=%s))',
            'running', $lease, $expires, '', $now, $id, 'queued', 'retry_wait', $now, 'running', $now));
        $this->must($updated);
        return $updated === 1;
    }
    public function complete(int $id, string $lease, callable $createActivity): array
    {
        $this->must($this->db->query('START TRANSACTION'));
        try {
            $row = $this->db->get_row($this->db->prepare('SELECT * FROM `' . $this->table() . '` WHERE id=%d FOR UPDATE', $id), ARRAY_A);
            if (! is_array($row) || $row['status'] !== 'running' || ! hash_equals((string) $row['lease_token'], $lease)) {
                throw new DomainException('생성 작업의 처리 권한이 바뀌었습니다. 상태를 새로 확인해 주세요.');
            }
            $activity = $createActivity();
            $updated = $this->db->update($this->table(), ['status' => 'ready', 'activity_id' => $activity['id'],
                'lease_token' => '', 'lease_expires_at' => null, 'error_message' => '', 'updated_at' => gmdate('Y-m-d H:i:s')],
                ['id' => $id, 'status' => 'running', 'lease_token' => $lease]);
            $this->must($updated);
            if ($updated !== 1) { throw new RuntimeException('생성 작업의 완료 상태를 저장하지 못했습니다.'); }
            $this->must($this->db->query('COMMIT'));
            return $this->find($id) ?? throw new RuntimeException('완료한 생성 작업을 읽지 못했습니다.');
        } catch (Throwable $error) { $this->db->query('ROLLBACK'); throw $error; }
    }
    public function checkpoint(int $id, string $lease, array $pipeline): array
    {
        $this->must($this->db->query('START TRANSACTION'));
        try {
            $row = $this->db->get_row($this->db->prepare('SELECT * FROM `' . $this->table() . '` WHERE id=%d FOR UPDATE', $id), ARRAY_A);
            if (! is_array($row) || $row['status'] !== 'running' || ! hash_equals((string) $row['lease_token'], $lease)) {
                throw new DomainException('생성 작업의 처리 권한이 바뀌었습니다. 상태를 새로 확인해 주세요.');
            }
            $data = json_decode((string) $row['data_json'], true, 32, JSON_THROW_ON_ERROR);
            $data['pipeline'] = $pipeline;
            $updated = $this->db->update($this->table(), ['status' => 'queued', 'next_attempt_at' => null,
                'data_json' => json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'lease_token' => '', 'lease_expires_at' => null, 'updated_at' => gmdate('Y-m-d H:i:s')],
                ['id' => $id, 'status' => 'running', 'lease_token' => $lease]);
            $this->must($updated);
            if ($updated !== 1) { throw new RuntimeException('생성 작업의 중간 결과를 저장하지 못했습니다.'); }
            $this->must($this->db->query('COMMIT'));
            return $this->find($id) ?? throw new RuntimeException('저장한 생성 작업을 읽지 못했습니다.');
        } catch (Throwable $error) { $this->db->query('ROLLBACK'); throw $error; }
    }
    public function fail(int $id, string $lease, string $message): void
    {
        $this->must($this->db->update($this->table(), ['status' => 'failed', 'error_message' => $message,
            'lease_token' => '', 'lease_expires_at' => null, 'updated_at' => gmdate('Y-m-d H:i:s')],
            ['id' => $id, 'status' => 'running', 'lease_token' => $lease]));
    }
    public function retry(int $id): array
    {
        return $this->controlled(function (array &$control) use ($id): array {
            $row = $this->db->get_row($this->db->prepare('SELECT * FROM `' . $this->table() . '` WHERE id=%d FOR UPDATE', $id), ARRAY_A);
            if (! is_array($row)) { throw new DomainException('생성 작업을 찾을 수 없습니다.'); }
            if ($row['status'] === 'failed') {
                $this->assertActiveLimit((int) $row['creator_user_id'], \OjPrimm\Support\GenerationPolicy::get());
                $data = json_decode((string) $row['data_json'], true, 32, JSON_THROW_ON_ERROR);
                // A deliberate new run has fresh AI/execution idempotency keys and repair budget.
                $data['pipeline'] = []; $data['run_key'] = bin2hex(random_bytes(16)); $data['transient_failures'] = 0;
                $this->must($this->db->update($this->table(), ['status' => 'queued', 'error_message' => '',
                    'data_json' => json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'next_attempt_at' => null,
                    'lease_token' => '', 'lease_expires_at' => null, 'updated_at' => gmdate('Y-m-d H:i:s')], ['id' => $id, 'status' => 'failed']));
            }
            return $this->find($id) ?? throw new DomainException('생성 작업을 찾을 수 없습니다.');
        });
    }
    public function pending(int $limit = 3): array
    {
        $now = gmdate('Y-m-d H:i:s');
        $rows = $this->db->get_results($this->db->prepare('SELECT id,creator_user_id FROM `' . $this->table()
            . '` WHERE status=%s OR (status=%s AND lease_expires_at<=%s) OR (status=%s AND next_attempt_at<=%s) ORDER BY updated_at ASC,id ASC LIMIT 500',
            'queued', 'running', $now, 'retry_wait', $now), ARRAY_A);
        if (! is_array($rows)) { throw new RuntimeException('대기 중인 생성 작업을 확인하지 못했습니다.'); }
        // One oldest step per teacher first; subsequent turns remain round-robin within the bounded scan.
        $groups = []; foreach ($rows as $row) { $groups[(string) $row['creator_user_id']][] = $row; }
        $fair = []; $limit = max(1, min(10, $limit));
        while ($groups !== [] && count($fair) < $limit) {
            foreach (array_keys($groups) as $actor) { $fair[] = array_shift($groups[$actor]); if ($groups[$actor] === []) { unset($groups[$actor]); } if (count($fair) >= $limit) { break; } }
        }
        return $fair;
    }

    private function controlled(callable $work): mixed
    {
        $this->must($this->db->query('START TRANSACTION'));
        try {
            $row = $this->db->get_row($this->db->prepare('SELECT * FROM `' . $this->controlTable() . '` WHERE scope_key=%s FOR UPDATE', 'queue'), ARRAY_A);
            if (! is_array($row)) {
                $this->db->insert($this->controlTable(), ['scope_key' => 'queue', 'data_json' => '{}', 'updated_at' => gmdate('Y-m-d H:i:s')]);
                $row = $this->db->get_row($this->db->prepare('SELECT * FROM `' . $this->controlTable() . '` WHERE scope_key=%s FOR UPDATE', 'queue'), ARRAY_A);
                if (! is_array($row)) { throw new RuntimeException('생성 작업 한도 저장소를 잠그지 못했습니다.'); }
            }
            $control = json_decode((string) $row['data_json'], true, 32, JSON_THROW_ON_ERROR);
            if (($control['date'] ?? '') !== gmdate('Y-m-d')) { $control = ['date' => gmdate('Y-m-d'), 'ai_calls' => 0, 'teachers' => []]; }
            $result = $work($control);
            $this->must($this->db->update($this->controlTable(), ['data_json' => json_encode($control, JSON_THROW_ON_ERROR), 'updated_at' => gmdate('Y-m-d H:i:s')], ['scope_key' => 'queue']));
            $this->must($this->db->query('COMMIT')); return $result;
        } catch (Throwable $error) { $this->db->query('ROLLBACK'); throw $error; }
    }
    public function createLimited(array $data, array $limits): array
    {
        return $this->controlled(function (array &$control) use ($data, $limits): array {
            $existing = $this->db->get_var($this->db->prepare('SELECT id FROM `' . $this->table() . '` WHERE creator_user_id=%d AND fingerprint=%s', $data['creator_user_id'], $data['fingerprint']));
            if ($existing !== null) { return $this->find((int) $existing) ?? throw new RuntimeException('생성 작업을 읽지 못했습니다.'); }
            $this->assertActiveLimit((int) $data['creator_user_id'], $limits);
            return $this->createOrFind($data);
        });
    }
    private function assertActiveLimit(int $actor, array $limits): void
    {
        foreach ([['actor' => null, 'key' => 'site_active_limit'], ['actor' => $actor, 'key' => 'teacher_active_limit']] as $scope) {
            $sql = 'SELECT COUNT(*) FROM `' . $this->table() . "` WHERE status IN ('queued','running','retry_wait')"
                . $this->db->prepare(' AND created_at>%s', gmdate('Y-m-d H:i:s', time() - 86400));
            if ($scope['actor'] !== null) { $sql .= $this->db->prepare(' AND creator_user_id=%d', $scope['actor']); }
            if ((int) $this->db->get_var($sql) >= $limits[$scope['key']]) { throw new \OjPrimm\Generation\GenerationFailure($scope['actor'] === null
                ? '사이트의 활성 생성 작업 한도에 도달했습니다. 완료 후 다시 요청해 주세요.' : '교사의 활성 생성 작업 한도에 도달했습니다. 기존 작업을 완료하거나 취소해 주세요.',
                $scope['actor'] === null ? 'generation_site_active_limit' : 'generation_teacher_active_limit', true); }
        }
    }
    public function reserveAi(int $id, string $lease, array $limits): array
    {
        return $this->controlled(function (array &$control) use ($id, $lease, $limits): array {
            $job = $this->find($id);
            if ($job === null || $job['status'] !== 'running' || ! hash_equals($job['lease_token'], $lease)) { throw new DomainException('생성 작업의 처리 권한이 바뀌었습니다.'); }
            $actor = (string) $job['creator_user_id']; $usage = $job['usage'] ?? ['ai_calls' => 0, 'execution_count' => 0];
            if (($usage['ai_calls'] ?? 0) >= $limits['job_ai_limit']) { throw new DomainException('작업별 AI 호출 예산을 모두 사용했습니다. 자료를 검토한 뒤 새 작업을 시작해 주세요.'); }
            if (($control['ai_calls'] ?? 0) >= $limits['site_daily_ai_limit'] || ($control['teachers'][$actor] ?? 0) >= $limits['teacher_daily_ai_limit']) {
                throw new \OjPrimm\Generation\TransientGenerationException('오늘의 AI 호출 예산을 모두 사용했습니다. 다음 날 자동으로 다시 시도합니다.', max(60, strtotime('tomorrow UTC') - time()));
            }
            ++$control['ai_calls']; $control['teachers'][$actor] = ($control['teachers'][$actor] ?? 0) + 1; $usage['ai_calls'] = ($usage['ai_calls'] ?? 0) + 1;
            $raw = $this->db->get_row($this->db->prepare('SELECT * FROM `' . $this->table() . '` WHERE id=%d FOR UPDATE', $id), ARRAY_A);
            if (! is_array($raw) || $raw['status'] !== 'running' || ! hash_equals((string) $raw['lease_token'], $lease)) { throw new DomainException('AI 호출 전에 작업이 중지되었습니다.'); }
            $data = json_decode($raw['data_json'], true, 32, JSON_THROW_ON_ERROR); $data['usage'] = $usage;
            $this->must($this->db->update($this->table(), ['data_json' => json_encode($data, JSON_THROW_ON_ERROR)], ['id' => $id, 'lease_token' => $lease, 'status' => 'running']));
            return $usage;
        });
    }
    public function defer(int $id, string $lease, string $message, string $retryAt, array $details = []): array
    {
        return $this->mutate($id, function (array $row, array &$data) use ($lease, $message, $retryAt, $details): array {
            if ($row['status'] !== 'running' || ! hash_equals((string) $row['lease_token'], $lease)) { throw new DomainException('생성 작업의 처리 권한이 바뀌었습니다.'); }
            $data['transient_failures'] = ($data['transient_failures'] ?? 0) + 1;
            $data['last_failure'] = $details + ['at' => gmdate('Y-m-d H:i:s'), 'retryable' => true];
            return ['status' => 'retry_wait', 'error_message' => $message, 'next_attempt_at' => $retryAt, 'lease_token' => '', 'lease_expires_at' => null];
        });
    }
    public function failDetailed(int $id, string $lease, string $message, array $details): void
    {
        $this->mutate($id, static function (array $row, array &$data) use ($lease, $message, $details): array {
            if ($row['status'] !== 'running' || ! hash_equals((string) $row['lease_token'], $lease)) { return []; }
            $data['last_failure'] = $details + ['at' => gmdate('Y-m-d H:i:s'), 'retryable' => false];
            return ['status' => 'failed', 'error_message' => $message, 'next_attempt_at' => null, 'lease_token' => '', 'lease_expires_at' => null];
        });
    }
    public function expire(int $id, string $message): array
    {
        return $this->mutate($id, static function (array $row, array &$data) use ($message): array {
            if ($row['status'] === 'ready') { return []; }
            if (isset($data['last_failure'])) { $data['previous_failure'] = $data['last_failure']; }
            $data['last_failure'] = ['code' => 'generation_expired', 'retryable' => false, 'at' => gmdate('Y-m-d H:i:s')];
            return ['status' => 'failed', 'error_message' => $message, 'next_attempt_at' => null, 'lease_token' => '', 'lease_expires_at' => null];
        });
    }
    public function cancel(int $id, string $message, bool $administrative = false): array
    {
        return $this->mutate($id, static function (array $row, array &$data) use ($message, $administrative): array {
            if (! in_array($row['status'], ['queued', 'running', 'retry_wait'], true)) { return []; }
            return ['status' => $administrative ? 'stopped' : 'canceled', 'error_message' => $message, 'next_attempt_at' => null, 'lease_token' => '', 'lease_expires_at' => null];
        });
    }
    public function resume(int $id): array
    {
        return $this->controlled(function (array &$control) use ($id): array {
            $row = $this->db->get_row($this->db->prepare('SELECT * FROM `' . $this->table() . '` WHERE id=%d FOR UPDATE', $id), ARRAY_A);
            if (! is_array($row)) { throw new DomainException('생성 작업을 찾을 수 없습니다.'); }
            if (in_array($row['status'], ['canceled', 'stopped', 'retry_wait'], true)) {
                if ($row['status'] !== 'retry_wait') { $this->assertActiveLimit((int) $row['creator_user_id'], \OjPrimm\Support\GenerationPolicy::get()); }
                $this->must($this->db->update($this->table(), ['status' => 'queued', 'error_message' => '', 'next_attempt_at' => null,
                    'lease_token' => '', 'lease_expires_at' => null, 'updated_at' => gmdate('Y-m-d H:i:s')], ['id' => $id]));
            }
            return $this->find($id) ?? throw new RuntimeException('생성 작업을 읽지 못했습니다.');
        });
    }
    private function mutate(int $id, callable $change): array
    {
        $this->must($this->db->query('START TRANSACTION'));
        try {
            $row = $this->db->get_row($this->db->prepare('SELECT * FROM `' . $this->table() . '` WHERE id=%d FOR UPDATE', $id), ARRAY_A);
            if (! is_array($row)) { throw new DomainException('생성 작업을 찾을 수 없습니다.'); }
            $data = json_decode($row['data_json'], true, 32, JSON_THROW_ON_ERROR); $fields = $change($row, $data);
            if ($fields !== []) { $fields['data_json'] = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE); $fields['updated_at'] = gmdate('Y-m-d H:i:s'); $this->must($this->db->update($this->table(), $fields, ['id' => $id])); }
            $this->must($this->db->query('COMMIT'));
            return $this->find($id) ?? throw new RuntimeException('생성 작업을 읽지 못했습니다.');
        } catch (Throwable $error) { $this->db->query('ROLLBACK'); throw $error; }
    }
    public function overview(?int $actor = null): array
    {
        $sql = 'SELECT * FROM `' . $this->table() . '`';
        if ($actor !== null) { $sql .= $this->db->prepare(' WHERE creator_user_id=%d', $actor); }
        $rows = $this->db->get_results($sql . ' ORDER BY updated_at DESC,id DESC LIMIT 100', ARRAY_A);
        $jobs = array_map(fn (array $row): array => $this->row($row), is_array($rows) ? $rows : []);
        $usage = ['ai_calls' => 0, 'execution_count' => 0, 'jobs' => count($jobs)]; $statuses = [];
        foreach ($jobs as $job) {
            $usage['ai_calls'] += (int) ($job['usage']['ai_calls'] ?? 0);
            $usage['execution_count'] += count($job['pipeline']['executions'] ?? []) + count($job['pipeline']['baseline_executions'] ?? []);
            foreach ($job['pipeline']['history'] ?? [] as $round) { $usage['execution_count'] += count($round['results'] ?? []); }
            $statuses[$job['status']] = ($statuses[$job['status']] ?? 0) + 1;
        }
        $controlRaw = $this->db->get_var($this->db->prepare('SELECT data_json FROM `' . $this->controlTable() . '` WHERE scope_key=%s', 'queue'));
        $control = is_string($controlRaw) ? json_decode($controlRaw, true, 32, JSON_THROW_ON_ERROR) : [];
        $daily = ($control['date'] ?? '') === gmdate('Y-m-d') ? ($actor === null ? ($control['ai_calls'] ?? 0) : ($control['teachers'][(string) $actor] ?? 0)) : 0;
        return ['jobs' => $jobs, 'usage' => $usage, 'statuses' => $statuses, 'sample_limit' => 100,
            'daily_usage' => ['date' => gmdate('Y-m-d'), 'ai_calls' => $daily]];
    }
    public function cancelPending(int $id, string $message): void
    {
        $this->must($this->db->query($this->db->prepare('UPDATE `' . $this->table()
            . '` SET status=%s,error_message=%s,lease_token=%s,lease_expires_at=NULL,updated_at=%s'
            . ' WHERE id=%d AND (status=%s OR (status=%s AND lease_expires_at<=%s))',
            'failed', $message, '', gmdate('Y-m-d H:i:s'), $id, 'queued', 'running', gmdate('Y-m-d H:i:s'))));
    }
}
