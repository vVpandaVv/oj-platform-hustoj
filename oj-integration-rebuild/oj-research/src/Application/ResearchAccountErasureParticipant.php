<?php
declare(strict_types=1);
namespace CodingPanda\OjResearch\Application;

use CodingPanda\OjPlatform\Modules\Auth\Contract\AccountErasureBlocker;
use CodingPanda\OjPlatform\Modules\Auth\Contract\AccountErasureParticipant;
use CodingPanda\OjPlatform\Modules\PlatformDb\Contract\ApplicationDatabase;

final class ResearchAccountErasureParticipant implements AccountErasureParticipant
{
    public function __construct(private readonly ApplicationDatabase $db) {}
    public function moduleKey(): string { return 'research'; }
    public function blockers(int $userId): array
    {
        $count = 0;
        foreach ($this->db->rows('SELECT owner_id, status, data FROM research_studies') as $row) {
            if (in_array($row['status'], ['cancelled', 'archived'], true)) { continue; }
            $data = json_decode($row['data'], true, 512, JSON_THROW_ON_ERROR);
            if ((int) $row['owner_id'] === $userId || in_array($userId, $data['protocol']['raters'], true)) { ++$count; }
        }
        return $count > 0 ? [new AccountErasureBlocker('research', 'research.active_staff', '담당 연구를 종료·보관한 뒤 계정을 삭제해 주세요.', $count)] : [];
    }
    public function anonymize(int $userId, string $currentUserLogin, string $anonymousUserLogin): void
    {
        $erasedByStudy = [];
        foreach ($this->db->rows('SELECT id, study_id FROM research_participants WHERE user_id = %d', [$userId]) as $row) {
            $studyId = (int) $row['study_id'];
            $erasedByStudy[$studyId] = ($erasedByStudy[$studyId] ?? 0) + 1;
            $this->db->delete('research_records', ['participant_id' => (int) $row['id']]);
        }
        $this->db->delete('research_participants', ['user_id' => $userId]);
        $erasedRaterSlots = [];
        foreach ($this->db->rows('SELECT id, owner_id, data FROM research_studies') as $row) {
            $studyId = (int) $row['id'];
            $data = json_decode($row['data'], true, 512, JSON_THROW_ON_ERROR);
            foreach ($data['protocol']['raters'] as $slot => $rater) {
                if ($rater === $userId) {
                    $erasedRaterSlots[$studyId] = -($slot + 1);
                    $data['protocol']['raters'][$slot] = $erasedRaterSlots[$studyId];
                }
            }
            foreach ($data['protocol']['materials'] ?? [] as $problemId => $material) {
                if (($material['reviewer'] ?? null) === $userId) { $data['protocol']['materials'][$problemId]['reviewer'] = 0; }
            }
            if (isset($erasedByStudy[$studyId])) { $data['erased_count'] = ($data['erased_count'] ?? 0) + $erasedByStudy[$studyId]; }
            $this->db->update('research_studies', ['owner_id' => (int) $row['owner_id'] === $userId ? 0 : (int) $row['owner_id'],
                'data' => json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)], ['id' => (int) $row['id']]);
        }
        foreach ($this->db->rows('SELECT id, study_id, data FROM research_records') as $row) {
            $data = json_decode($row['data'], true, 512, JSON_THROW_ON_ERROR);
            $changed = false;
            if (($data['actor'] ?? null) === $userId) { $data['actor'] = 0; $changed = true; }
            if (($data['rater_id'] ?? null) === $userId) {
                $data['rater_id'] = $erasedRaterSlots[(int) $row['study_id']] ?? throw new \RuntimeException('연구 평가자 연결을 확인하지 못했습니다.');
                $changed = true;
            }
            if ($changed) { $this->db->update('research_records', ['data' => json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)], ['id' => (int) $row['id']]); }
        }
    }
    public function verify(int $userId, string $anonymousUserLogin): bool
    {
        $count = $this->db->value('SELECT COUNT(*) FROM research_participants WHERE user_id = %d', [$userId]);
        if (! is_numeric($count) || (int) $count !== 0) { return false; }
        foreach ($this->db->rows('SELECT owner_id, data FROM research_studies') as $row) {
            $data = json_decode($row['data'], true, 512, JSON_THROW_ON_ERROR);
            if ((int) $row['owner_id'] === $userId || in_array($userId, $data['protocol']['raters'], true)) { return false; }
            foreach ($data['protocol']['materials'] ?? [] as $material) { if (($material['reviewer'] ?? null) === $userId) { return false; } }
        }
        foreach ($this->db->rows('SELECT data FROM research_records') as $row) {
            $data = json_decode($row['data'], true, 512, JSON_THROW_ON_ERROR);
            if (($data['actor'] ?? null) === $userId || ($data['rater_id'] ?? null) === $userId) { return false; }
        }
        return true;
    }
}
