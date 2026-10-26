<?php
declare(strict_types=1);
namespace OjPrimm\Application;

use DomainException;
use OjPrimm\Generation\LessonSchema;
use OjPrimm\Storage\ManagementStore;
use OjPrimm\Storage\GenerationStore;
use OjPrimm\Storage\Repository;

/** Teacher operations create immutable content versions; learner attempts keep their activity ID. */
final class ActivityManagement
{
    public function __construct(private readonly Repository $records, private readonly ManagementStore $store,
        private readonly PlatformAccess $platform, private readonly ?GenerationStore $generations = null) {}

    public function manage(int $id): array { return $this->view($this->authorized($id)); }
    public function edit(int $id, array $input): array
    {
        $base = $this->authorized($id);
        $this->revision($base, $input);
        $patch = $input['patch'] ?? null;
        if (! is_array($patch) || array_diff(array_keys($patch), ['title', 'lesson']) !== []) { throw new DomainException('수정할 제목과 수업 자료를 확인해 주세요.'); }
        $title = LessonSchema::text($patch['title'] ?? $base['title'], 191, '수업 제목');
        $lesson = $patch['lesson'] ?? ($base['lesson'] ?? null);
        if (! is_array($lesson)) { throw new DomainException('기존 수동 활동은 복제할 수 있으며 AI 자료 편집은 생성된 수업에서 사용합니다.'); }
        // A browser may edit teaching content, never supply a successful execution certificate.
        $lesson['verification'] = ['status' => 'not_executed', 'note' => LessonSchema::NOTE];
        $lesson = LessonSchema::validateNormalized($lesson, $base['lesson']['language'], $base['support_kind']);
        $data = array_replace($base, ['title' => $title, 'lesson' => $lesson, 'status' => 'draft',
            'creator_user_id' => $this->platform->actorId(), 'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'), 'quality' => ['status' => 'pending', 'notes' => ['편집한 내용은 전체 재검증 후 배포합니다.']]]);
        unset($data['id'], $data['generation_id']);
        $data['quality'] = GenerationService::qualityForOrigin($data['quality'], $data['template_origin']);
        $this->fresh($base);
        $key = $this->requestKey($input, ['edit', $id, $input['expected_revision'] ?? 1, $patch]);
        $version = $this->store->version($base, $data, $key);
        $this->audit('version_created', $version);
        return $this->view($version);
    }
    public function publish(int $id, array $input): array
    {
        $activity = $this->authorized($id);
        $this->revision($activity, $input);
        if (isset($activity['lesson']) && (($activity['lesson']['verification']['status'] ?? '') !== 'verified'
            || ($activity['lesson']['verification']['engine'] ?? '') !== 'oj')) { throw new DomainException('OJ 검증을 통과한 수업만 공개할 수 있습니다. 먼저 전체 검증을 실행해 주세요.'); }
        if (in_array($activity['quality']['status'] ?? '', ['review_required', 'needs_review'], true) && ($input['acknowledge_review'] ?? false) !== true) {
            throw new DomainException('품질 검토 사유를 확인한 뒤 검토 완료를 선택해 주세요.');
        }
        $this->fresh($activity);
        $saved = $this->store->update($id, (int) ($activity['revision'] ?? 1), ['status' => 'active',
            'template_origin' => $activity['template_origin'], 'generation_kind' => $activity['generation_kind'] ?? '', 'quality' => $activity['quality'] ?? [],
            'reviewed_by' => $this->platform->actorId(), 'reviewed_at' => gmdate('Y-m-d H:i:s')]);
        $this->audit('published', $saved);
        return $this->view($saved);
    }
    public function withdraw(int $id, array $input): array
    {
        $activity = $this->authorized($id); $this->revision($activity, $input);
        $this->fresh($activity);
        $saved = $this->store->update($id, (int) ($activity['revision'] ?? 1), ['status' => 'withdrawn']);
        $this->audit('withdrawn', $saved);
        return $this->view($saved);
    }
    public function cloneActivity(int $id, array $input): array
    {
        $base = $this->authorized($id);
        [$type, $contextId] = $this->context($input);
        $context = $this->platform->authorize($type, $contextId, 'assign');
        $problem = $this->platform->problem($base['target_problem_id'], $type, $contextId)['problem'];
        if (! hash_equals($base['target_hash'], $problem['content_hash'])) { throw new DomainException('목표 문제가 변경되었습니다. 바뀐 문제로 새 자료를 생성해 주세요.'); }
        if ($base['support_problem_id'] !== null) { $this->freshSupport($base, $type, $contextId); }
        $data = array_replace($base, ['title' => LessonSchema::text($input['title'] ?? $base['title'], 191, '활동 제목'),
            'creator_user_id' => $this->platform->actorId(), 'context_type' => $type, 'context_id' => $contextId,
            'context_revision' => $context['revision'], 'status' => 'draft', 'reused_from' => $id,
            'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')]);
        unset($data['id'], $data['generation_id']);
        // A new assignment reuses immutable verified content, never other learners' attempts.
        $this->platform->authorize($type, $contextId, 'assign');
        $copy = $this->store->version($base, $data, $this->requestKey($input, ['clone', $id, $type, $contextId, $data['title']]));
        $this->audit('reused', $copy);
        return $this->view($copy);
    }

    /** Invoked inside GenerationStore::complete's transaction and lease fence. */
    public function acceptGenerated(array $job, array $lesson, array $quality): array
    {
        $this->platform->assertRole('teach');
        if ($this->platform->actorId() !== $job['creator_user_id']) { throw new DomainException('생성 요청자가 변경되었습니다.'); }
        $context = $this->platform->authorize($job['context_type'], $job['context_id'], 'assign');
        $problem = $this->platform->problem($job['target_problem_id'], $job['context_type'], $job['context_id'])['problem'];
        if (! hash_equals($job['target_hash'], $problem['content_hash'])) { throw new DomainException('목표 문제가 변경되어 자료를 배포하지 않았습니다.'); }
        $templateOrigin = GenerationService::templateOriginForJob($job, $this->records, $this->generations);
        $quality = GenerationService::qualityForOrigin($quality, $templateOrigin);
        $review = in_array($quality['status'] ?? '', ['review_required', 'needs_review'], true);
        $profile = $job['profile'] ?? [];
        $publish = $profile['auto_publish'] ?? true;
        $status = $review ? 'review' : ($publish ? 'active' : 'draft');
        $changes = ['lesson' => $lesson, 'quality' => $quality, 'status' => $status,
            'generation_id' => $job['id'], 'profile' => $profile, 'practice_required' => true,
            'template_origin' => $templateOrigin, 'generation_kind' => $templateOrigin ? 'template' : 'ai'];
        if (! empty($job['draft_activity_id'])) {
            $draft = $this->authorized((int) $job['draft_activity_id']);
            if (! in_array($draft['status'], ['draft', 'review'], true)
                || (int) ($draft['revision'] ?? 1) !== (int) ($job['draft_revision'] ?? 0)) { throw new DomainException('검증 중 편집 버전이 변경되었습니다. 새 버전을 검증해 주세요.'); }
            return $this->store->update($draft['id'], (int) $job['draft_revision'], $changes);
        }
        $parentId = (int) ($job['parent_activity_id'] ?? 0);
        $parent = $parentId > 0 ? $this->authorized($parentId) : null;
        $now = gmdate('Y-m-d H:i:s');
        $data = $changes + ['creator_user_id' => $job['creator_user_id'],
            'title' => $job['title'] !== '' ? $job['title'] : $lesson['title'], 'context_type' => $job['context_type'],
            'context_id' => $job['context_id'], 'context_revision' => $context['revision'],
            'target_problem_id' => $job['target_problem_id'], 'target_title' => $problem['title'], 'target_hash' => $problem['content_hash'],
            'support_kind' => $job['support_kind'], 'support_problem_id' => null, 'support_title' => 'AI 생성 수정 도움 활동',
            'support_hash' => '', 'support_is_target' => false, 'demo_code' => 'ai_generated',
            'created_at' => $now, 'updated_at' => $now];
        return $this->store->version($parent, $data, 'generation:' . $job['id'], true);
    }

    public function activities(array $input): array
    {
        [$type, $id] = $this->context($input); $this->platform->assertRole('teach');
        $this->platform->authorize($type, $id, 'report');
        $limit = max(1, min(100, (int) ($input['limit'] ?? 25)));
        $cursor = (int) ($input['cursor'] ?? 0);
        $items = $this->store->page($type, $id, $cursor, $limit);
        return ['items' => array_map($this->summary(...), $items), 'next_cursor' => count($items) === $limit ? (string) end($items)['id'] : ''];
    }
    public function report(array $input): array
    {
        [$type, $contextId] = $this->context($input); $this->platform->assertRole('teach');
        $this->platform->authorize($type, $contextId, 'report');
        if (! $this->platform instanceof RosterAccess) { throw new DomainException('전체 학급 명단 조회를 사용할 수 없습니다.'); }
        $activityId = (int) ($input['activity_id'] ?? 0);
        if ($activityId < 1) { $page = $this->store->page($type, $contextId, 0, 1); $activityId = (int) ($page[0]['id'] ?? 0); }
        $activity = $activityId > 0 ? $this->authorized($activityId, 'report') : null;
        if ($activity !== null && ($activity['context_type'] !== $type || $activity['context_id'] !== $contextId)) { throw new DomainException('보고서의 수업 범위가 다릅니다.'); }
        $roster = $this->platform->roster($type, $contextId);
        $attempts = $activity === null ? [] : array_column($this->store->attempts($activityId), null, 'user_id');
        $items = []; $counts = ['not_started' => 0, 'in_progress' => 0, 'process_complete' => 0, 'accepted' => 0];
        $search = LessonSchema::text($input['search'] ?? '', 120, '검색어', true);
        foreach ($roster as $learner) {
            $actor = (int) $learner['user_id'];
            $attempt = $attempts[$actor] ?? null;
            if ($attempt !== null) { unset($attempt['last_request_hash'], $attempt['make_submission_watch'], $attempt['make_submission_linked'], $attempt['make_submission_recent']); }
            $status = $attempt === null ? 'not_started' : (($attempt['status'] ?? '') === 'process_complete' ? 'process_complete' : 'in_progress');
            ++$counts[$status]; if ($attempt['is_accepted'] ?? false) { ++$counts['accepted']; }
            $item = $learner + ['attempt' => $attempt, 'status' => $status, 'current_stage' => $attempt['current_stage'] ?? '',
                'process_complete' => $status === 'process_complete', 'is_accepted' => $attempt['is_accepted'] ?? false,
                'verified_at' => $attempt['verified_at'] ?? null];
            if ($search === '' || stripos($actor . ' ' . $learner['display_name'] . ' ' . ($learner['management_name'] ?? ''), $search) !== false) { $items[] = $item; }
        }
        usort($items, static fn (array $a, array $b): int => $a['user_id'] <=> $b['user_id']);
        $binding = hash('sha256', json_encode([$type, $contextId, $activityId, $search, array_column($roster, 'user_id')], JSON_THROW_ON_ERROR));
        $offset = 0;
        if (($input['cursor'] ?? '') !== '') {
            $cursor = json_decode(base64_decode((string) $input['cursor'], true) ?: '', true);
            if (! is_array($cursor) || ($cursor['binding'] ?? '') !== $binding || ! is_int($cursor['offset'] ?? null) || $cursor['offset'] < 0) { throw new DomainException('명단 또는 보고서가 변경되었습니다. 첫 페이지를 다시 열어 주세요.'); }
            $offset = $cursor['offset'];
        }
        $limit = max(1, min(100, (int) ($input['limit'] ?? 50))); $total = count($items);
        $page = array_slice($items, $offset, $limit);
        $next = $offset + $limit < $total ? base64_encode(json_encode(['binding' => $binding, 'offset' => $offset + $limit], JSON_THROW_ON_ERROR)) : '';
        $this->platform->authorize($type, $contextId, 'report');
        return ['items' => $page, 'total' => $total, 'next_cursor' => $next, 'counts' => $counts, 'activity_id' => $activityId];
    }
    public function exportCsv(array $input): string
    {
        $input['cursor'] = ''; $input['limit'] = 100;
        $rows = [['학습자 번호', '표시 이름', '관리용 이름', '진행 상태', '현재 단계', '과정 완료', '최종 정답', '정답 확인 시각']];
        do {
            $page = $this->report($input);
            foreach ($page['items'] as $item) { $rows[] = [$item['user_id'], $item['display_name'], $item['management_name'] ?? '',
                $item['status'], $item['current_stage'], $item['process_complete'] ? '예' : '아니오', $item['is_accepted'] ? '예' : '아니오', $item['verified_at'] ?? '']; }
            $input['cursor'] = $page['next_cursor'];
        } while ($input['cursor'] !== '');
        $stream = fopen('php://temp', 'w+');
        foreach ($rows as $row) {
            $safe = array_map(static function ($value): string { $value = (string) $value; return preg_match('/^[\s]*[=+@-]/u', $value) ? "'" . $value : $value; }, $row);
            fputcsv($stream, $safe, ',', '"', '', "\r\n");
        }
        rewind($stream); $csv = stream_get_contents($stream); fclose($stream); return $csv;
    }
    private function view(array $activity): array
    {
        $family = (int) (($activity['family_id'] ?? 0) ?: $activity['id']); $versions = [];
        foreach ($this->store->versions($family) as $version) {
            // A reused family can span classes: never reveal another class's private metadata.
            try { $this->platform->authorize($version['context_type'], $version['context_id'], 'report'); }
            catch (DomainException) { continue; }
            $versions[] = $this->summary($version);
        }
        return ['activity' => $this->summary($activity), 'lesson' => $activity['lesson'] ?? null,
            'revision' => (int) ($activity['revision'] ?? 1), 'state' => $activity['status'], 'versions' => $versions];
    }
    private function authorized(int $id, string $operation = 'assign'): array
    {
        $this->platform->assertRole('teach');
        $activity = $this->records->findActivity($id) ?? throw new DomainException('수업 자료를 찾을 수 없습니다.');
        $this->platform->authorize($activity['context_type'], $activity['context_id'], $operation);
        return $this->provenance($activity);
    }
    private function fresh(array $activity): void
    {
        $this->platform->authorize($activity['context_type'], $activity['context_id'], 'assign');
        $problem = $this->platform->problem($activity['target_problem_id'], $activity['context_type'], $activity['context_id'])['problem'];
        if (! hash_equals($activity['target_hash'], $problem['content_hash'])) { throw new DomainException('목표 문제가 변경되었습니다. 새 자료를 생성해 주세요.'); }
        if ($activity['support_problem_id'] !== null) { $this->freshSupport($activity, $activity['context_type'], $activity['context_id']); }
    }
    private function freshSupport(array $activity, string $type, string $contextId): void
    {
        $this->platform->assertSupport($activity['support_problem_id'], $activity['support_kind'], $type, $contextId);
        $support = $this->platform->problem($activity['support_problem_id'], $type, $contextId)['problem'];
        if (! hash_equals($activity['support_hash'], $support['content_hash'])) { throw new DomainException('지원 문제가 변경되었습니다. 바뀐 템플릿으로 새 활동을 만들어 주세요.'); }
    }
    private function revision(array $activity, array $input): void
    {
        if ((int) ($input['expected_revision'] ?? 0) !== (int) ($activity['revision'] ?? 1)) { throw new DomainException('수업 버전이 변경되었습니다. 다시 불러와 주세요.'); }
    }
    private function context(array $input): array
    {
        $type = $input['context_type'] ?? ''; $id = (string) ($input['context_id'] ?? '');
        if (! in_array($type, ['class', 'group'], true)) { throw new DomainException('수업·모둠을 선택해 주세요.'); }
        $id = str_starts_with($id, $type . ':') ? substr($id, strlen($type) + 1) : $id;
        if (preg_match('/^[1-9][0-9]{0,17}$/D', $id) !== 1) { throw new DomainException('수업·모둠 번호를 확인해 주세요.'); }
        return [$type, $type . ':' . $id];
    }
    private function requestKey(array $input, array $fallback): string
    {
        $key = $input['request_key'] ?? hash('sha256', json_encode($fallback, JSON_THROW_ON_ERROR));
        if (! is_string($key) || preg_match('/^[a-zA-Z0-9_.:-]{8,160}$/D', $key) !== 1) { throw new DomainException('작업 요청 키가 올바르지 않습니다.'); }
        return $this->platform->actorId() . ':' . $fallback[0] . ':' . $fallback[1] . ':' . $key;
    }
    private function summary(array $activity): array
    {
        $activity = $this->provenance($activity);
        unset($activity['lesson'], $activity['request_key_hash'], $activity['request_payload_hash']); return $activity;
    }
    private function provenance(array $activity): array
    {
        $activity['template_origin'] = GenerationService::templateOriginForActivity($activity, $this->records, $this->generations);
        if ($activity['template_origin']) { $activity['generation_kind'] = 'template'; }
        $activity['quality'] = GenerationService::qualityForOrigin($activity['quality'] ?? [], $activity['template_origin']);
        return $activity;
    }
    private function audit(string $operation, array $activity): void
    {
        if (function_exists('do_action')) { do_action('oj_primm_activity_management', $operation, $activity['id'], $this->platform->actorId()); }
    }
}
