<?php
declare(strict_types=1);
namespace OjPrimm\Application;

use DomainException;
use OjPrimm\Storage\Repository;
use OjPrimm\Generation\LessonSupport;
use OjPrimm\Storage\ManagementStore;
use OjPrimm\Storage\PracticeStore;
use OjPrimm\Storage\GenerationStore;

final class PrimmService
{
    public const SUBMISSION_WATCH_SECONDS = 720;
    public const STAGES = [
        ['key' => 'predict', 'label' => '예측', 'prompt' => '예제 코드를 읽고 실행 전 예상 출력과 이유를 적으세요.'],
        ['key' => 'run', 'label' => '실행 확인', 'prompt' => '검증된 예제 실행 기록을 확인하고 최초 예측과 비교하세요.'],
        ['key' => 'investigate', 'label' => '탐구', 'prompt' => '입력·변수 변화·조건·반복의 역할을 설명하고 짝과 비교하세요.'],
        ['key' => 'modify', 'label' => '수정', 'prompt' => '지원 활동을 풀거나 예제의 조건·값을 바꾸어 보고 변경 이유를 기록하세요.'],
        ['key' => 'make', 'label' => '제작', 'prompt' => '목표 문제를 독립적으로 풀고 본인 제출의 채점 결과와 성찰을 확인하세요.'],
    ];

    public function __construct(private readonly Repository $records, private readonly PlatformAccess $platform,
        private readonly ?ManagementStore $management = null, private readonly ?PracticeStore $practices = null,
        private readonly ?GenerationStore $generations = null) {}

    public function teachingContexts(): array { $this->platform->assertRole('teach'); return $this->platform->contexts(true); }
    public function learningContexts(): array { $this->platform->assertRole('learn'); return $this->platform->contexts(false); }
    public function searchProblems(string $search = ''): array
    {
        $this->platform->assertRole('teach');
        return $this->platform->searchProblems($this->text($search, 120, '검색어'));
    }

    public function saveActivity(array $input): array
    {
        $this->platform->assertRole('teach');
        [$type, $contextId] = $this->context($input['context_type'] ?? '', $input['context_id'] ?? '');
        $context = $this->platform->authorize($type, $contextId, 'assign');
        $targetId = $this->positive($input['target_problem_id'] ?? null, '목표 문제 번호');
        $target = $this->platform->problem($targetId, $type, $contextId)['problem'];
        $kind = $input['support_kind'] ?? 'none';
        if (! is_string($kind) || ! in_array($kind, ['none', 'code_ordering', 'code_completion'], true)) {
            throw new DomainException('지원 활동 방식을 선택해 주세요.');
        }
        $supportId = null;
        $support = null;
        if ($kind !== 'none') {
            $supportId = $this->positive($input['support_problem_id'] ?? $targetId, '지원 문제 번호');
            $support = $this->platform->problem($supportId, $type, $contextId)['problem'];
            $this->platform->assertSupport($supportId, $kind, $type, $contextId);
        }
        $demo = $input['demo_code'] ?? 'sum';
        if (! is_string($demo) || ! in_array($demo, ['sum', 'condition'], true)) { throw new DomainException('합계·조건 중 기초 도입 예제를 선택해 주세요.'); }
        $title = trim($this->text($input['title'] ?? '', 191, '활동 제목'));
        if ($title === '') { $title = $this->text('PRIMM · ' . $target['title'], 191, '활동 제목'); }
        $now = gmdate('Y-m-d H:i:s');
        $data = [
            'creator_user_id' => $this->platform->actorId(), 'title' => $title,
            'context_type' => $type, 'context_id' => $contextId, 'context_revision' => $context['revision'],
            'target_problem_id' => $targetId, 'target_title' => $target['title'], 'target_hash' => $target['content_hash'],
            'support_kind' => $kind, 'support_problem_id' => $supportId,
            'support_title' => $support['title'] ?? '', 'support_hash' => $support['content_hash'] ?? '',
            'support_is_target' => $supportId !== null && $supportId === $targetId,
            'demo_code' => $demo, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
        ];
        $this->platform->authorize($type, $contextId, 'assign');
        $activity = $this->records->createActivity($data);
        if (function_exists('do_action')) { do_action('oj_primm_activity_created', $activity['id'], $this->platform->actorId()); }
        return $activity;
    }

    public function teacherActivities(string $contextType, string $contextId, string $cursor = '', int $limit = 25): array
    {
        $this->platform->assertRole('teach');
        [$type, $id] = $this->context($contextType, $contextId);
        $this->platform->authorize($type, $id, 'report');
        if ($cursor !== '' && preg_match('/^[1-9][0-9]{0,17}$/D', $cursor) !== 1) { throw new DomainException('목록 페이지 번호가 올바르지 않습니다.'); }
        $limit = max(1, min(100, $limit));
        $activities = $this->management === null ? $this->records->activitiesForContext($type, $id)
            : $this->management->page($type, $id, (int) $cursor, $limit);
        $attempts = [];
        foreach ($this->records->attemptsForActivities(array_column($activities, 'id')) as $attempt) {
            try { $this->platform->authorize($type, $id, 'report', (int) $attempt['user_id']); }
            catch (DomainException) { continue; }
            $attempts[] = $this->publicAttempt($attempt);
        }
        return ['activities' => array_map($this->publicActivity(...), $activities), 'attempts' => $attempts, 'report_limit' => 200,
            'next_cursor' => $this->management !== null && count($activities) === $limit ? (string) end($activities)['id'] : ''];
    }

    public function studentActivities(): array
    {
        $this->platform->assertRole('learn');
        $items = [];
        foreach ($this->platform->contexts(false) as $context) {
            try { $this->platform->authorize($context['type'], $context['id'], 'participate', $this->platform->actorId()); }
            catch (DomainException) { continue; }
            foreach ($this->records->activitiesForContext($context['type'], $context['id']) as $activity) {
                if (($activity['status'] ?? 'active') !== 'active') { continue; }
                $activity['attempt'] = $this->publicAttempt($this->records->findAttempt((int) $activity['id'], $this->platform->actorId()) ?? $this->emptyAttempt((int) $activity['id']));
                $items[] = $this->publicActivity($activity);
            }
        }
        return $items;
    }

    public function activity(int $id, ?string $reviewStage = null): array
    {
        $activity = $this->requireActivity($id);
        $readOnly = false;
        try {
            $this->platform->assertRole('teach');
            $this->platform->authorize($activity['context_type'], $activity['context_id'], 'report');
            $readOnly = true;
        } catch (DomainException) {
            $this->platform->assertRole('learn');
            $this->platform->authorize($activity['context_type'], $activity['context_id'], 'participate', $this->platform->actorId());
        }
        $attempt = $readOnly ? $this->emptyAttempt($id) : ($this->records->findAttempt($id, $this->platform->actorId()) ?? $this->emptyAttempt($id));
        $withdrawn = false;
        if (! $readOnly && ($activity['status'] ?? 'active') !== 'active') {
            if (($activity['status'] ?? '') !== 'withdrawn' || $attempt['id'] === 0) { throw new DomainException('아직 공개되지 않았거나 회수된 수업 자료입니다.'); }
            $withdrawn = true;
        }
        if ($reviewStage !== null && (! in_array($reviewStage, array_column(self::STAGES, 'key'), true)
            || (! $readOnly && ! in_array($reviewStage, $attempt['completed_stages'], true)))) {
            throw new DomainException('완료한 단계만 다시 볼 수 있습니다.');
        }
        $view = $this->view($activity, $attempt, $readOnly);
        $view['display_stage'] = $reviewStage ?? $attempt['current_stage'];
        $view['review_only'] = $reviewStage !== null || $withdrawn;
        $view['withdrawn'] = $withdrawn;
        if ($view['review_only']) { $view['make_submission'] = $this->makeSubmission($activity, $attempt, true, (bool) $view['stale']); }
        return $view;
    }

    public function progress(int $id, array $input): array
    {
        $this->platform->assertRole('learn');
        $activity = $this->requireActivity($id);
        if (($activity['status'] ?? 'active') !== 'active') { throw new DomainException('공개된 수업에서만 진행 내용을 저장할 수 있습니다.'); }
        $actor = $this->platform->actorId();
        $this->platform->authorize($activity['context_type'], $activity['context_id'], 'participate', $actor);
        $attempt = $this->records->findAttempt($id, $actor) ?? $this->emptyAttempt($id);
        $view = $this->view($activity, $attempt, false);
        if ($view['stale']) { throw new DomainException('목표·지원 문제의 내용이 바뀌었습니다. 교사에게 새 활동을 요청해 주세요.'); }
        $stage = $input['stage'] ?? '';
        if (! is_string($stage) || ! in_array($stage, array_column(self::STAGES, 'key'), true)) { throw new DomainException('활동 단계를 확인해 주세요.'); }
        $version = $this->nonNegative($input['expected_version'] ?? null);
        $responses = $input['responses'] ?? [];
        if (! is_array($responses) || array_diff(array_keys($responses), [$stage]) !== []) { throw new DomainException('현재 단계의 응답만 저장할 수 있습니다.'); }
        $response = $this->text($responses[$stage] ?? ($attempt['responses'][$stage] ?? ''), 12000, '단계 응답');
        $draft = $this->text($input['draft_code'] ?? $attempt['draft_code'], 65536, '코드 초안');
        $supportState = $this->text($input['support_state'] ?? $attempt['support_state'], 65536, '지원 활동 상태');
        if (array_key_exists('support_state', $input) && $stage !== 'modify') { throw new DomainException('지원 활동 상태는 수정 단계에서 저장할 수 있습니다.'); }
        $advance = $input['advance'] ?? false;
        if (! is_bool($advance)) { throw new DomainException('단계 이동 값을 확인해 주세요.'); }
        $submissionId = array_key_exists('submission_id', $input) && $input['submission_id'] !== null && $input['submission_id'] !== ''
            ? $this->positive($input['submission_id'], '제출 번호') : ($attempt['submission_id'] ?? null);
        if ($submissionId !== null && $stage !== 'make' && array_key_exists('submission_id', $input)) { throw new DomainException('제출 연결은 제작 단계에서 할 수 있습니다.'); }
        $requestHash = hash('sha256', json_encode([$version, $stage, $response, $draft, $supportState, $advance, $submissionId], JSON_THROW_ON_ERROR));
        if (($attempt['last_request_hash'] ?? '') === $requestHash) { return $view; }
        if ($version !== $attempt['version'] || $stage !== $attempt['current_stage']) {
            throw new DomainException('현재 단계 또는 기록 버전이 바뀌었습니다. 새로고침해 주세요.');
        }
        if ($advance && trim($response) === '') { throw new DomainException('현재 단계의 응답을 기록한 뒤 다음 단계로 이동해 주세요.'); }
        $state = $this->state($attempt);
        $state['responses'][$stage] = $response;
        // The first prediction is evidence and stays immutable after advancing.
        if ($stage === 'predict' && $advance && $state['first_prediction'] === null) { $state['first_prediction'] = $response; }
        $state['draft_code'] = $draft;
        $state['support_state'] = $supportState;
        if ($stage === 'modify' && $this->practices !== null && ($activity['practice_required'] ?? false)) {
            $practice = $this->practices->latest($id, $actor);
            $passed = is_array($practice) && $practice['status'] === 'completed' && $practice['all_passed']
                && hash_equals($practice['source_hash'], hash('sha256', $draft));
            $state['practice_passed'] = $passed;
            $state['practice_checked_at'] = $passed ? $practice['checked_at'] : null;
            $state['practice_source_hash'] = $passed ? $practice['source_hash'] : null;
            if ($advance && ! $passed) { throw new DomainException('현재 수정 코드를 연습 테스트로 확인한 뒤 다음 단계로 이동해 주세요.'); }
        }
        if ($stage === 'make' && $submissionId !== null) {
            $verified = $this->platform->problem($activity['target_problem_id'], $activity['context_type'], $activity['context_id'], $submissionId)['submission'] ?? null;
            if (! is_array($verified) || (int) ($verified['id'] ?? 0) !== $submissionId
                || (int) ($verified['problem_id'] ?? 0) !== $activity['target_problem_id']) {
                throw new DomainException('목표 문제에 대한 본인의 실제 제출 번호를 입력해 주세요.');
            }
            $state['submission_id'] = $submissionId;
            $state['result_code'] = (int) $verified['result_code'];
            $state['is_accepted'] = ! $verified['processing'] && $state['result_code'] === 4;
            $state['verified_at'] = gmdate('Y-m-d H:i:s');
            $state['submission_provenance'] = 'verified_own_submission';
            $state['make_submission_linked'] = $this->submissionSnapshot($verified, $activity, $attempt['make_submission_linked'] ?? null);
            if (isset($state['make_submission_watch'])) { $state['make_submission_watch']['active'] = false; }
        }
        if ($advance) {
            $state['completed_stages'] = array_values(array_unique([...$state['completed_stages'], $stage]));
            $position = array_search($stage, array_column(self::STAGES, 'key'), true);
            if ($stage === 'make') { $state['status'] = 'process_complete'; }
            else { $state['current_stage'] = self::STAGES[$position + 1]['key']; }
        }
        // Recheck membership after reads and immediately before the write.
        $this->platform->authorize($activity['context_type'], $activity['context_id'], 'participate', $actor);
        $saved = $this->records->saveAttempt($id, $actor, $version, $state, [
            'stage' => $stage, 'type' => $advance ? 'stage_completed' : 'draft_saved',
            'payload' => ['response' => $response, 'draft_code' => $draft, 'support_state' => $supportState,
                'submission_id' => $state['submission_id'], 'result_code' => $state['result_code']],
        ], $requestHash);
        return $this->view($activity, $saved, false) + ['display_stage' => $saved['current_stage'], 'review_only' => false];
    }

    /** Hand the saved program to the platform editor; the learner still submits through the native form. */
    public function submissionLaunch(int $id, array $input): array
    {
        [$activity, $attempt, $actor] = $this->submissionContext($id, $input);
        if (! $this->platform instanceof SubmissionDraftAccess) { throw new DomainException('제출 편집기 연동을 사용할 수 없습니다. 플랫폼 설치 상태를 확인해 주세요.'); }
        $this->submissionDraft($activity, $attempt);
        if (($attempt['make_submission_linked']['processing'] ?? false) === true) { throw new DomainException('연결한 제출을 채점하고 있습니다. 결과를 확인한 뒤 새 초안을 보내 주세요.'); }
        $watched = $this->submissionWatch($id, $input);
        $version = (int) $watched['attempt']['version'];
        $this->recheckSubmission($activity, $actor, $version);
        $attempt = $this->records->findAttempt($id, $actor) ?? throw new DomainException('저장한 코드 초안을 확인할 수 없습니다.');
        $activity = $this->requireActivity($id);
        [$source, $language] = $this->submissionDraft($activity, $attempt);
        $view = $this->view($activity, $attempt, false);
        if ($view['stale']) { throw new DomainException('목표·지원 문제가 변경되었습니다. 새 활동에서 제출을 준비해 주세요.'); }
        $this->recheckSubmission($activity, $actor, $version);
        $launch = $this->platform->prepareSubmissionDraft($activity['target_problem_id'], $activity['context_type'], $activity['context_id'], $language, $source);
        $this->recheckSubmission($activity, $actor, $version);
        $currentActivity = $this->requireActivity($id);
        $currentAttempt = $this->records->findAttempt($id, $actor) ?? throw new DomainException('저장한 코드 초안을 확인할 수 없습니다.');
        [$currentSource, $currentLanguage] = $this->submissionDraft($currentActivity, $currentAttempt);
        if ($source !== $currentSource || $language !== $currentLanguage) { throw new DomainException('저장한 코드나 수업 언어가 변경되었습니다. 다시 준비해 주세요.'); }
        $view = $this->view($currentActivity, $currentAttempt, false);
        if ($view['stale']) { throw new DomainException('목표·지원 문제가 변경되었습니다. 새 활동에서 제출을 준비해 주세요.'); }
        $this->recheckSubmission($currentActivity, $actor, $version);
        if (! is_string($launch['url'] ?? null) || strlen($launch['url']) > 4096
            || str_contains($launch['url'], "\0") || str_contains($launch['url'], "\r") || str_contains($launch['url'], "\n")
            || ! str_starts_with($launch['url'], '/submit/' . $activity['target_problem_id'] . '?')
            || ($launch['language'] ?? null) !== $language || ! is_int($launch['expires_at'] ?? null)
            || $launch['expires_at'] <= time() || $launch['expires_at'] > time() + 305) {
            throw new DomainException('제출 편집기 연결 정보가 올바르지 않습니다. 다시 준비해 주세요.');
        }
        // The opaque URL is response-only: never persist it in attempts, events, reports, or polls.
        return $this->submissionView($view, $currentActivity, $currentAttempt)
            + ['submission_launch' => array_intersect_key($launch, array_flip(['url', 'language', 'expires_at']))];
    }

    private function submissionDraft(array $activity, array $attempt): array
    {
        $source = $attempt['draft_code'] ?? null;
        if (! is_string($source) || trim($source) === '' || strlen($source) > 65536 || str_contains($source, "\0") || preg_match('//u', $source) !== 1) {
            throw new DomainException('제작 코드 초안을 먼저 작성하고 저장해 주세요. 유효한 UTF-8 코드 64KiB까지 전달할 수 있습니다.');
        }
        $language = is_array($activity['lesson'] ?? null) ? ($activity['lesson']['language'] ?? null)
            : (in_array($activity['demo_code'] ?? null, ['sum', 'condition'], true) ? 'python' : null);
        if (! is_string($language) || ! in_array($language, ['python', 'c', 'cpp', 'java'], true)) {
            throw new DomainException('수업 언어를 확인할 수 없습니다. 교사에게 새 활동을 요청해 주세요.');
        }
        return [$source, $language];
    }

    /** Establish a server-side baseline immediately before opening the native submission form. */
    public function submissionWatch(int $id, array $input): array
    {
        [$activity, $attempt, $actor, $version] = $this->submissionContext($id, $input);
        $target = null; $cursor = null;
        $latest = $this->submissionLookup($activity, null, $target, $cursor);
        $view = $this->view($activity, $attempt, false, $target);
        if ($view['stale']) { throw new DomainException('목표·지원 문제가 변경되었습니다. 새 활동에서 제출을 확인해 주세요.'); }
        $this->recheckSubmission($activity, $actor, $version);
        if ($this->watchActive($attempt['make_submission_watch'] ?? [])) { return $this->submissionView($view, $activity, $attempt); }
        if ($cursor === null) {
            $view = $this->submissionView($view, $activity, $attempt);
            $view['make_submission']['status'] = 'unavailable';
            return $view;
        }
        $state = $this->state($attempt);
        $state['make_submission_watch'] = ['active' => $cursor !== null, 'started_at' => gmdate('Y-m-d H:i:s'), 'baseline_id' => $cursor];
        $state['make_submission_recent'] = $latest === null ? null : $this->submissionSnapshot($latest, $activity, $attempt['make_submission_recent'] ?? null);
        unset($state['make_submission_linked']);
        $state['submission_id'] = null; $state['result_code'] = null; $state['is_accepted'] = false; $state['verified_at'] = null;
        unset($state['submission_provenance']);
        return $this->saveSubmissionState($view, $activity, $attempt, $actor, $version, $state, 'submission_watch_started');
    }

    /** A watch links one new ID; later polls always query that exact ID until an explicit new request. */
    public function submissionCheck(int $id, array $input): array
    {
        [$activity, $attempt, $actor, $version] = $this->submissionContext($id, $input);
        $mode = $input['mode'] ?? 'watch';
        if (! is_string($mode) || ! in_array($mode, ['watch', 'latest', 'linked'], true)) { throw new DomainException('제출 확인 방식을 확인해 주세요.'); }
        $linkedId = (int) ($attempt['submission_id'] ?? 0);
        $explicit = $mode !== 'latest' && $linkedId > 0 ? $linkedId : null;
        $target = null;
        $submission = $this->submissionLookup($activity, $explicit, $target);
        $view = $this->view($activity, $attempt, false, $target);
        if ($view['stale']) { throw new DomainException('목표·지원 문제가 변경되었습니다. 새 활동에서 제출을 확인해 주세요.'); }
        $this->recheckSubmission($activity, $actor, $version);
        $state = $this->state($attempt);
        if ($submission === null) {
            $view = $this->submissionView($view, $activity, $attempt);
            if ($mode !== 'watch' || $explicit !== null) { $view['make_submission']['status'] = 'unavailable'; }
            return $view;
        }
        if ($explicit !== null && $submission['id'] !== $explicit) { throw new DomainException('연결한 본인 제출을 확인할 수 없습니다.'); }
        if ($mode === 'linked' && $explicit === null) { return $this->submissionView($view, $activity, $attempt); }
        if ($mode === 'latest' || $explicit === null) {
            $state['make_submission_recent'] = $this->submissionSnapshot($submission, $activity, $attempt['make_submission_recent'] ?? null);
        }
        $watch = $attempt['make_submission_watch'] ?? [];
        $adopt = $mode === 'latest' || $explicit !== null || ($this->watchActive($watch)
            && is_int($watch['baseline_id'] ?? null) && $submission['id'] > $watch['baseline_id']);
        if ($adopt) {
            $snapshot = $this->submissionSnapshot($submission, $activity, $attempt['make_submission_linked'] ?? null);
            $state['make_submission_linked'] = $snapshot;
            $state['submission_id'] = $snapshot['id']; $state['result_code'] = $snapshot['result_code'];
            $state['is_accepted'] = ! $snapshot['processing'] && $snapshot['result_code'] === 4;
            $state['verified_at'] = $snapshot['verified_at']; $state['submission_provenance'] = 'verified_own_submission';
            if (isset($state['make_submission_watch'])) { $state['make_submission_watch']['active'] = false; }
        }
        return $this->saveSubmissionState($view, $activity, $attempt, $actor, $version, $state, 'submission_result_checked');
    }

    private function submissionContext(int $id, array $input): array
    {
        $this->platform->assertRole('learn');
        $activity = $this->requireActivity($id); $actor = $this->platform->actorId();
        $teacherPreview = false;
        try { $this->platform->assertRole('teach'); $this->platform->authorize($activity['context_type'], $activity['context_id'], 'report'); $teacherPreview = true; }
        catch (DomainException) {}
        if ($teacherPreview) { throw new DomainException('교사 확인 화면에서는 학생 제출 연결을 변경할 수 없습니다.'); }
        if (($activity['status'] ?? 'active') !== 'active') { throw new DomainException('공개된 수업에서만 제출을 연결할 수 있습니다.'); }
        $this->platform->authorize($activity['context_type'], $activity['context_id'], 'participate', $actor);
        $attempt = $this->records->findAttempt($id, $actor);
        if ($attempt === null || $attempt['current_stage'] !== 'make') { throw new DomainException('제작 단계에서 본인 제출을 확인할 수 있습니다.'); }
        $version = $this->nonNegative($input['expected_version'] ?? null);
        if ($attempt['version'] !== $version) { throw new DomainException('다른 화면에서 기록 버전이 변경되었습니다. 새로고침해 주세요.'); }
        return [$activity, $attempt, $actor, $version];
    }

    private function submissionLookup(array $activity, ?int $submissionId, ?array &$target = null, ?int &$cursor = null): ?array
    {
        $result = $this->platform instanceof SubmissionLookupAccess
            ? $this->platform->submission($activity['target_problem_id'], $activity['context_type'], $activity['context_id'], $submissionId)
            : $this->platform->problem($activity['target_problem_id'], $activity['context_type'], $activity['context_id'], $submissionId);
        if (! hash_equals($activity['target_hash'], (string) ($result['problem']['content_hash'] ?? ''))) { throw new DomainException('목표 문제가 변경되었습니다. 새 활동에서 제출을 확인해 주세요.'); }
        $target = $result['problem'];
        $submission = $result['submission'] ?? null;
        if ($submissionId === null) {
            $provided = $result['latest_own_submission_id'] ?? null;
            if ($provided !== null && (! is_int($provided) || $provided < 0)) { throw new DomainException('본인 제출의 최신 기준선을 확인할 수 없습니다.'); }
            $cursor = $provided ?? (is_int($submission['id'] ?? null) ? $submission['id'] : null);
            if (is_int($submission['id'] ?? null) && $cursor !== null) { $cursor = max($cursor, $submission['id']); }
        }
        if ($submission === null) { return null; }
        if (! is_array($submission) || ! is_int($submission['id'] ?? null) || $submission['id'] < 1
            || ($submission['problem_id'] ?? null) !== $activity['target_problem_id']
            || ! is_int($submission['result_code'] ?? null) || ! is_bool($submission['processing'] ?? null)
            || ($submissionId !== null && $submission['id'] !== $submissionId)) { throw new DomainException('목표 문제의 본인 제출을 확인할 수 없습니다.'); }
        return $submission;
    }

    private function recheckSubmission(array $activity, int $actor, int $version): void
    {
        if ($this->platform instanceof ActorRevalidationAccess) { $this->platform->refreshActor(); }
        $this->platform->assertRole('learn');
        if ($this->platform->actorId() !== $actor) { throw new DomainException('제출을 확인한 학습자 계정이 변경되었습니다. 다시 로그인해 주세요.'); }
        $current = $this->requireActivity((int) $activity['id']);
        if (($current['status'] ?? 'active') !== 'active' || $current['target_problem_id'] !== $activity['target_problem_id']
            || ! hash_equals($current['target_hash'], $activity['target_hash']) || $current['context_type'] !== $activity['context_type']
            || $current['context_id'] !== $activity['context_id'] || $current['support_problem_id'] !== $activity['support_problem_id']
            || ! hash_equals($current['support_hash'], $activity['support_hash'])) { throw new DomainException('활동이 회수되었거나 변경되었습니다. 다시 확인해 주세요.'); }
        $this->platform->authorize($activity['context_type'], $activity['context_id'], 'participate', $actor);
        $attempt = $this->records->findAttempt((int) $activity['id'], $actor);
        if ($attempt === null || $attempt['version'] !== $version || $attempt['current_stage'] !== 'make') { throw new DomainException('다른 화면에서 기록 버전이 변경되었습니다. 새로고침해 주세요.'); }
    }

    private function submissionSnapshot(array $submission, array $activity, ?array $previous): array
    {
        $query = parse_url($this->platform->problemUrl($activity['target_problem_id'], $activity['context_type'], $activity['context_id']), PHP_URL_QUERY);
        $snapshot = ['id' => $submission['id'], 'result_code' => $submission['result_code'], 'processing' => $submission['processing'],
            'label' => $submission['processing'] ? '채점 중' : (match ($submission['result_code']) {
                4 => '정답', 5 => '출력 형식 오류', 6 => '오답', 7 => '시간 초과', 8 => '메모리 초과',
                9 => '출력 초과', 10 => '실행 오류', 11 => '컴파일 오류', default => '채점 결과 ' . $submission['result_code'],
            }), 'submitted_at' => is_string($submission['submitted_at'] ?? null) ? $submission['submitted_at'] : '',
            'language' => is_string($submission['language'] ?? null) ? $submission['language'] : 'unknown',
            'detail_url' => '/submissions/' . $submission['id'] . (is_string($query) && $query !== '' ? '?' . $query : ''),
            'source_hash' => is_string($submission['source'] ?? null) && $submission['source'] !== '' ? self::submissionSourceHash($submission['source']) : null];
        $previousValues = $previous ?? []; unset($previousValues['verified_at']);
        $snapshot['verified_at'] = $previousValues === $snapshot ? $previous['verified_at'] : gmdate('Y-m-d H:i:s');
        return $snapshot;
    }

    private function watchActive(array $watch): bool
    {
        $started = is_string($watch['started_at'] ?? null) ? strtotime($watch['started_at'] . ' UTC') : false;
        return ($watch['active'] ?? false) === true && $started !== false && $started <= time() + 5 && time() - $started < self::SUBMISSION_WATCH_SECONDS;
    }
    private static function submissionSourceHash(string $source): string { return hash('sha256', str_replace(["\r\n", "\r"], "\n", $source)); }
    private function saveSubmissionState(array $view, array $activity, array $attempt, int $actor, int $version, array $state, string $type): array
    {
        if ($state === $this->state($attempt)) { return $this->submissionView($view, $activity, $attempt); }
        $saved = $this->records->saveAttempt((int) $activity['id'], $actor, $version, $state,
            ['stage' => 'make', 'type' => $type, 'payload' => ['submission_id' => $state['submission_id'] ?? null, 'result_code' => $state['result_code'] ?? null]],
            hash('sha256', json_encode([$type, $version, $state], JSON_THROW_ON_ERROR)));
        return $this->submissionView($view, $activity, $saved);
    }
    private function submissionView(array $view, array $activity, array $attempt): array
    {
        $view['attempt'] = $this->publicAttempt($attempt);
        $view['make_submission'] = $this->makeSubmission($activity, $attempt, false, (bool) $view['stale']);
        $view['display_stage'] = 'make'; $view['review_only'] = false;
        return $view;
    }
    private function makeSubmission(array $activity, array $attempt, bool $readOnly, bool $stale): array
    {
        $available = ! $readOnly && ! $stale && ($activity['status'] ?? 'active') === 'active' && $attempt['current_stage'] === 'make';
        $project = static function (?array $snapshot) use ($attempt): ?array {
            if ($snapshot === null) { return null; }
            $snapshot['draft_matches'] = is_string($snapshot['source_hash'] ?? null) ? hash_equals($snapshot['source_hash'], self::submissionSourceHash($attempt['draft_code'])) : null;
            return array_intersect_key($snapshot, array_flip(['id', 'result_code', 'processing', 'label', 'submitted_at', 'verified_at', 'language', 'draft_matches', 'detail_url']));
        };
        $linked = $attempt['make_submission_linked'] ?? null;
        // Older versions stored only the verified ID/result; show that evidence until an explicit refresh enriches it.
        if ($linked === null && (int) ($attempt['submission_id'] ?? 0) > 0) {
            $code = (int) ($attempt['result_code'] ?? 0);
            $linked = $this->submissionSnapshot(['id' => (int) $attempt['submission_id'], 'result_code' => $code,
                'processing' => $code < 4, 'source' => ''], $activity, null);
            $linked['verified_at'] = $attempt['verified_at'] ?? null;
        }
        $submission = $project($linked);
        $watch = $attempt['make_submission_watch'] ?? [];
        $active = $available && $this->watchActive($watch);
        $status = ! $available ? 'unavailable' : ($submission !== null ? ($submission['processing'] ? 'processing' : ($submission['result_code'] === 4 ? 'accepted' : 'judged')) : ($active ? 'watching' : 'idle'));
        return ['available' => $available, 'status' => $status, 'watch' => ['active' => $active, 'started_at' => $watch['started_at'] ?? null],
            'submission' => $submission, 'recent' => $project($attempt['make_submission_recent'] ?? null)];
    }

    public function support(int $id): array
    {
        $activity = $this->supportActivity($id);
        if (isset($activity['lesson'])) { return LessonSupport::publicPayload($activity['lesson']); }
        if (! $this->platform instanceof ExerciseAccess) { throw new DomainException('도움 활동 연동을 사용할 수 없습니다.'); }
        return $this->platform->support((int) $activity['support_problem_id'], $activity['support_kind'], $activity['context_type'], $activity['context_id']);
    }
    public function assembleSupport(int $id, array $answer): array
    {
        $this->platform->assertRole('learn');
        $activity = $this->supportActivity($id);
        if (isset($activity['lesson'])) { return LessonSupport::assemble($activity['lesson'], $answer); }
        if (! $this->platform instanceof ExerciseAccess) { throw new DomainException('도움 활동 연동을 사용할 수 없습니다.'); }
        return $this->platform->assembleSupport((int) $activity['support_problem_id'], $activity['support_kind'], $activity['context_type'], $activity['context_id'], $answer);
    }
    private function supportActivity(int $id): array
    {
        $view = $this->activity($id);
        if ($view['read_only'] || ($view['review_only'] ?? false) || ! in_array($view['attempt']['current_stage'], ['modify', 'make'], true)) {
            throw new DomainException('예측·실행 비교·탐구를 마친 뒤 도움 활동을 열 수 있습니다.');
        }
        if ($view['stale']) { throw new DomainException('원문 문제가 변경되었습니다. 새 활동을 확인해 주세요.'); }
        $activity = $this->requireActivity($id);
        if (! in_array($activity['support_kind'], ['code_ordering', 'code_completion'], true)) { throw new DomainException('이 활동에는 도움 자료가 없습니다.'); }
        $this->platform->authorize($activity['context_type'], $activity['context_id'], 'participate', $this->platform->actorId());
        return $activity;
    }

    private function view(array $activity, array $attempt, bool $readOnly, ?array $target = null): array
    {
        if (! $readOnly && $this->practices !== null && ($activity['practice_required'] ?? false)) {
            $practice = $this->practices->latest((int) $activity['id'], (int) $attempt['user_id']);
            $passed = is_array($practice) && $practice['status'] === 'completed' && ($practice['all_passed'] ?? false)
                && hash_equals($practice['source_hash'], hash('sha256', $attempt['draft_code']));
            // Projection only: execution does not write the progress version or overwrite another tab's draft.
            $attempt['practice_passed'] = $passed;
            $attempt['practice_source_hash'] = $passed ? $practice['source_hash'] : null;
            $attempt['practice_checked_at'] = $passed ? $practice['checked_at'] : null;
        }
        $target ??= $this->platform->problem($activity['target_problem_id'], $activity['context_type'], $activity['context_id'])['problem'];
        $support = $activity['support_problem_id'] === $activity['target_problem_id'] ? $target : ($activity['support_problem_id'] !== null
            ? $this->platform->problem($activity['support_problem_id'], $activity['context_type'], $activity['context_id'])['problem'] : null);
        $stale = ! hash_equals($activity['target_hash'], $target['content_hash'])
            || ($support !== null && ! hash_equals($activity['support_hash'], $support['content_hash']));
        $lesson = $activity['lesson'] ?? null;
        $verification = is_array($lesson) ? array_intersect_key($lesson['verification'], array_flip(['status', 'engine', 'checked_at', 'case_count', 'repair_attempts', 'note'])) : null;
        $demo = is_array($lesson) ? $lesson['demo'] + ['language' => $lesson['language'], 'verification' => $verification,
            'verification_note' => $lesson['verification']['note'], 'key' => 'ai_generated'] : $this->demo($activity['demo_code']);
        if (! $readOnly && $attempt['current_stage'] === 'predict') { unset($demo['expected_output'], $demo['explanation']); }
        $stages = self::STAGES;
        if (is_array($lesson)) {
            $currentPosition = array_search($attempt['current_stage'], array_column(self::STAGES, 'key'), true);
            $stages = [];
            foreach ($lesson['stages'] as $position => $descriptor) {
                if (($descriptor['key'] ?? '') === 'modify' && ! $readOnly) {
                    if ($activity['support_kind'] !== 'none') { unset($descriptor['starter_code']); }
                    else { $descriptor['starter_code'] = $lesson['demo']['code']; }
                }
                $stages[] = $readOnly || $position <= $currentPosition ? $descriptor : array_intersect_key($descriptor, array_flip(['key', 'label']));
            }
        }
        return ['activity' => $this->publicActivity($activity), 'attempt' => $this->publicAttempt($attempt), 'stages' => $stages,
            'make_submission' => $this->makeSubmission($activity, $attempt, $readOnly, $stale),
            'practice_required' => (bool) ($activity['practice_required'] ?? false),
            'target' => $target, 'support' => $support, 'read_only' => $readOnly, 'stale' => $stale, 'demo' => $demo,
            'lesson_summary' => ! is_array($lesson) ? null : ['title' => $lesson['title'], 'objectives' => $lesson['objectives'],
                'language' => $lesson['language'], 'verification' => $verification, 'verification_note' => $lesson['verification']['note']],
            'target_url' => $this->platform->problemUrl($activity['target_problem_id'], $activity['context_type'], $activity['context_id']),
            'support_url' => $support === null ? '' : $this->platform->problemUrl($activity['support_problem_id'], $activity['context_type'], $activity['context_id'])];
    }

    private function emptyAttempt(int $activityId): array
    {
        return ['id' => 0, 'activity_id' => $activityId, 'user_id' => $this->platform->actorId(), 'version' => 0,
            'current_stage' => 'predict', 'responses' => [], 'first_prediction' => null, 'draft_code' => '', 'support_state' => '',
            'completed_stages' => [], 'submission_id' => null, 'result_code' => null, 'is_accepted' => false,
            'status' => 'in_progress', 'verified_at' => null, 'created_at' => null, 'updated_at' => null];
    }
    private function state(array $attempt): array
    {
        return array_diff_key($attempt, array_flip(['id', 'activity_id', 'user_id', 'version', 'created_at', 'updated_at', 'last_request_hash']));
    }
    private function publicAttempt(array $attempt): array
    {
        unset($attempt['last_request_hash'], $attempt['make_submission_watch'], $attempt['make_submission_linked'], $attempt['make_submission_recent']); return $attempt;
    }
    private function publicActivity(array $activity): array
    {
        // The execution certificate proves a run, never whether AI authored the teaching material.
        $activity['generation_kind'] = is_array($activity['lesson'] ?? null)
            && ! GenerationService::templateOriginForActivity($activity, $this->records, $this->generations) ? 'ai' : 'template';
        return array_intersect_key($activity, array_flip(['id', 'creator_user_id', 'title', 'context_type', 'context_id',
            'context_revision', 'target_problem_id', 'target_title', 'target_hash', 'support_kind', 'support_problem_id',
            'support_title', 'support_hash', 'support_is_target', 'demo_code', 'status', 'created_at', 'updated_at',
            'family_id', 'parent_activity_id', 'content_version', 'revision', 'practice_required', 'generation_kind']));
    }
    private function requireActivity(int $id): array
    {
        if ($id < 1 || ($activity = $this->records->findActivity($id)) === null) { throw new DomainException('활동을 찾을 수 없습니다.'); }
        return $activity;
    }
    private function context(mixed $type, mixed $id): array
    {
        if (! is_string($type) || ! in_array($type, ['class', 'group'], true) || (! is_string($id) && ! is_int($id))) { throw new DomainException('수업·그룹을 선택해 주세요.'); }
        $numeric = str_starts_with((string) $id, $type . ':') ? substr((string) $id, strlen($type) + 1) : (string) $id;
        return [$type, $type . ':' . $this->positive($numeric, '학습 공간 번호')];
    }
    private function positive(mixed $value, string $label): int
    {
        if ((! is_int($value) && ! is_string($value)) || preg_match('/^[1-9][0-9]{0,17}$/D', (string) $value) !== 1) { throw new DomainException($label . '를 확인해 주세요.'); }
        return (int) $value;
    }
    private function nonNegative(mixed $value): int
    {
        if ((! is_int($value) && ! is_string($value)) || preg_match('/^(?:0|[1-9][0-9]{0,17})$/D', (string) $value) !== 1) { throw new DomainException('저장 버전을 확인해 주세요.'); }
        return (int) $value;
    }
    private function text(mixed $value, int $maximum, string $label): string
    {
        if (! is_string($value) || strlen($value) > $maximum || str_contains($value, "\0") || preg_match('//u', $value) !== 1) { throw new DomainException($label . '의 형식이나 길이를 확인해 주세요.'); }
        return str_replace(["\r\n", "\r"], "\n", $value);
    }
    private function demo(string $key): array
    {
        return match ($key) {
            'sum' => ['key' => 'sum', 'code' => "numbers = [1, 2, 3]\ntotal = 0\nfor number in numbers:\n    total += number\nprint(total)", 'input' => '', 'expected_output' => "6\n"],
            'condition' => ['key' => 'condition', 'code' => "score = 60\nif score >= 60:\n    print(\"통과\")\nelse:\n    print(\"다시 도전\")", 'input' => '', 'expected_output' => "통과\n"],
            default => ['key' => 'none', 'code' => '', 'input' => ''],
        };
    }
}
