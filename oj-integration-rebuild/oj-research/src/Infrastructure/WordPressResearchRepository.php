<?php
declare(strict_types=1);

namespace CodingPanda\OjResearch\Infrastructure;

use CodingPanda\OjPlatform\Modules\PlatformDb\Contract\ApplicationDatabase;
use CodingPanda\OjResearch\Contract\ResearchRepository;
use DomainException;
use RuntimeException;
use Throwable;

final class WordPressResearchRepository implements ResearchRepository
{
    public function __construct(private readonly ApplicationDatabase $db) {}

    public function transaction(callable $work): mixed
    {
        $this->db->beginTransaction();
        try {
            $result = $work();
            $this->db->commit();
            return $result;
        } catch (Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
    }

    public function create(array $study): int
    {
        return $this->db->insert('research_studies', $this->encode($study));
    }

    public function study(int $id, bool $lock = false): ?array
    {
        $row = $this->db->row('SELECT * FROM research_studies WHERE id = %d' . ($lock ? ' FOR UPDATE' : ''), [$id]);
        return $row === null ? null : $this->decode($row);
    }

    public function studies(): array
    {
        return $this->readRows('research_studies', '', [], 'id DESC');
    }

    public function updateStudy(array $study, int $expectedRevision): void
    {
        $id = (int) $study['id'];
        unset($study['id']);
        $study['revision'] = $expectedRevision + 1;
        if ($this->db->update('research_studies', $this->encode($study), ['id' => $id, 'revision' => $expectedRevision]) !== 1) {
            throw new DomainException('연구가 변경되었습니다. 화면을 새로고침해 주세요.');
        }
    }

    public function participants(int $studyId): array
    {
        return $this->readRows('research_participants', ' WHERE study_id = %d', [$studyId]);
    }

    public function addParticipant(array $participant): int
    {
        return $this->db->insert('research_participants', $this->encode($participant));
    }

    public function updateParticipant(int $id, array $changes): void
    {
        $this->db->update('research_participants', $this->encode($changes), ['id' => $id]);
    }

    public function activeForUser(int $userId): ?array
    {
        // ApplicationDatabase read failures can look like an empty result. Never treat an unavailable policy store as ordinary access.
        $count = $this->db->value('SELECT COUNT(*) FROM research_participants WHERE active_user_id = %d', [$userId]);
        if (! is_numeric($count)) {
            throw new RuntimeException('연구 제한 상태를 확인할 수 없습니다.');
        }
        if ((int) $count === 0) { return null; }
        $row = $this->db->row('SELECT * FROM research_participants WHERE active_user_id = %d', [$userId]);
        if ($row === null) { throw new RuntimeException('연구 참여 상태를 확인할 수 없습니다.'); }
        return $this->decode($row);
    }

    public function append(int $studyId, int $participantId, string $key, string $kind, array $data, string $at): void
    {
        $digest = hash('sha256', $key);
        $existing = $this->db->row('SELECT data FROM research_records WHERE study_id = %d AND event_key = %s', [$studyId, $digest]);
        $json = $this->json($data);
        if ($existing !== null) {
            if (! hash_equals((string) $existing['data'], $json)) {
                throw new DomainException('같은 연구 작업에 다른 내용이 전달되었습니다.');
            }
            return;
        }
        $this->db->insert('research_records', [
            'study_id' => $studyId, 'participant_id' => $participantId,
            'event_key' => $digest, 'kind' => $kind, 'data' => $json, 'created_at' => $at,
        ]);
    }

    public function records(int $studyId): array
    {
        return $this->readRows('research_records', ' WHERE study_id = %d', [$studyId]);
    }

    private function readRows(string $table, string $where, array $values, string $order = 'id'): array
    {
        $count = $this->db->value('SELECT COUNT(*) FROM ' . $table . $where, $values);
        if (! is_numeric($count)) { throw new RuntimeException('연구 자료를 확인할 수 없습니다.'); }
        $rows = $this->db->rows('SELECT * FROM ' . $table . $where . ' ORDER BY ' . $order, $values);
        if (count($rows) !== (int) $count) { throw new RuntimeException('연구 자료가 변경되었거나 조회하지 못했습니다. 다시 시도해 주세요.'); }
        return array_map($this->decode(...), $rows);
    }

    private function encode(array $data): array
    {
        foreach (['data', 'baseline'] as $key) {
            if (isset($data[$key]) && is_array($data[$key])) { $data[$key] = $this->json($data[$key]); }
        }
        return $data;
    }

    private function decode(array $data): array
    {
        foreach (['data', 'baseline'] as $key) {
            if (isset($data[$key]) && is_string($data[$key])) { $data[$key] = json_decode($data[$key], true, 512, JSON_THROW_ON_ERROR); }
        }
        foreach (['id', 'study_id', 'user_id', 'group_id', 'owner_id', 'revision', 'participant_id'] as $key) {
            if (isset($data[$key])) { $data[$key] = (int) $data[$key]; }
        }
        return $data;
    }

    private function json(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
