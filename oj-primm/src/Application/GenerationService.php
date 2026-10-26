<?php
declare(strict_types=1);
namespace OjPrimm\Application;

use DomainException;
use OjPrimm\Generation\LessonGenerator;
use OjPrimm\Generation\LessonSchema;
use OjPrimm\Generation\CurriculumLessonGenerator;
use OjPrimm\Generation\LessonQuality;
use OjPrimm\Generation\LessonTemplate;
use OjPrimm\Generation\TransientGenerationException;
use OjPrimm\Generation\GenerationFailure;
use OjPrimm\Generation\LessonValidationFailure;
use OjPrimm\Generation\ValidationRepairGenerator;
use OjPrimm\Storage\GenerationStore;
use OjPrimm\Storage\GenerationOperationsStore;
use OjPrimm\Storage\Repository;
use OjPrimm\Support\GenerationPolicy;
use Throwable;

final class GenerationService
{
    public const MAX_REPAIRS = 2;
    public const MAX_AGE_SECONDS = 86400;
    public function __construct(private readonly GenerationStore $jobs, private readonly Repository $records,
        private readonly PlatformAccess $platform, private readonly LessonGenerator $generator,
        private readonly LessonExecutor $executor, private readonly ?ClassSettingsService $classSettings = null,
        private readonly ?\Closure $activityFactory = null) {}

    public function availability(): array
    {
        $this->platform->assertRole('teach');
        return $this->generationAvailability();
    }
    public function start(array $input): array
    {
        return $this->startConfigured($input, []);
    }
    private function startConfigured(array $input, array $options): array
    {
        $this->platform->assertRole('teach');
        $actor = $this->platform->actorId();
        $type = $input['context_type'] ?? '';
        if (! is_string($type) || ! in_array($type, ['class', 'group'], true)) { throw new DomainException('배포할 수업·모둠을 선택해 주세요.'); }
        $rawId = $input['context_id'] ?? '';
        if (! is_string($rawId) && ! is_int($rawId)) { throw new DomainException('수업·모둠 번호를 확인해 주세요.'); }
        $numeric = str_starts_with((string) $rawId, $type . ':') ? substr((string) $rawId, strlen($type) + 1) : (string) $rawId;
        $contextId = $type . ':' . $this->positive($numeric);
        $context = $this->platform->authorize($type, $contextId, 'assign');
        $problemId = $this->positive($input['target_problem_id'] ?? null);
        $problem = $this->platform->problem($problemId, $type, $contextId)['problem'];
        $profile = ($this->classSettings ?? new ClassSettingsService($this->platform))->get($type, $contextId);
        $language = $input['language'] ?? $profile['default_language'];
        $support = $input['support_kind'] ?? $profile['support_kind'];
        if (! is_string($language) || ! in_array($language, ['python', 'c', 'cpp', 'java'], true)
            || ! is_string($support) || ! in_array($support, ['none', 'code_ordering', 'code_completion'], true)) {
            throw new DomainException('생성 언어와 도움 방식을 확인해 주세요.');
        }
        $key = $input['request_key'] ?? '';
        if (! is_string($key) || preg_match('/^[a-zA-Z0-9_-][a-zA-Z0-9_.:-]{7,127}$/D', $key) !== 1) { throw new DomainException('생성 요청 키를 확인해 주세요. 화면을 새로고침해 주세요.'); }
        $title = $input['title'] ?? '';
        if (! is_string($title) || strlen($title) > 191 || preg_match('//u', $title) !== 1 || str_contains($title, "\0")) { throw new DomainException('활동 이름을 확인해 주세요.'); }
        $limits = GenerationPolicy::get();
        if ($limits['paused']) { throw new DomainException('관리자가 새 PRIMM 생성 작업을 일시 중지했습니다.'); }
        if (($input['use_template'] ?? false) === true) { $options['template_only'] = true; }
        // Provenance is server metadata. A browser cannot clear it through lesson content.
        $options['template_origin'] = ($options['template_only'] ?? false) || ($options['template_origin'] ?? false);
        $available = (($options['validation_only'] ?? false) || ($options['template_only'] ?? false)) ? $this->executor->availability() : $this->generationAvailability($language);
        if (! ($available['available'] ?? false)) { throw new DomainException($available['message'] ?? 'AI 생성 경로를 설정해 주세요.'); }
        if (! in_array($language, $available['languages'] ?? [], true)) { throw new DomainException('선택한 언어를 OJ 채점기에서 실행할 수 없습니다.'); }
        $fingerprint = hash('sha256', json_encode([$actor, $type, $contextId, $problemId, $problem['content_hash'],
            $language, $support, trim($title), $profile, $options['parent_activity_id'] ?? null, $options['parent_revision'] ?? null,
            $options['scope'] ?? 'new', $options['template_only'] ?? false, ($input['force'] ?? false) === true ? hash('sha256', $key) : '', 'primm_lesson.v1:quality.v1'], JSON_THROW_ON_ERROR));
        $this->platform->authorize($type, $contextId, 'assign');
        $data = ['creator_user_id' => $actor, 'fingerprint' => $fingerprint,
            'request_key_hash' => hash('sha256', $key), 'context_type' => $type, 'context_id' => $contextId,
            'context_revision' => $context['revision'], 'target_problem_id' => $problemId, 'target_hash' => $problem['content_hash'],
            'language' => $language, 'support_kind' => $support, 'title' => trim($title),
            'run_key' => bin2hex(random_bytes(16)), 'key_version' => 2, 'pipeline' => [], 'profile' => $profile,
            'usage' => ['ai_calls' => 0, 'execution_count' => 0], 'events' => []] + $options;
        $job = $this->jobs instanceof GenerationOperationsStore ? $this->jobs->createLimited($data, $limits) : $this->jobs->createOrFind($data);
        // A deliberate new creation may replace expired work; retries never rotate an expired remote key.
        for ($replacement = 0; $job['status'] !== 'ready' && $this->expired($job); ++$replacement) {
            if ($replacement >= 100 || ! $this->jobs instanceof GenerationOperationsStore) { throw new DomainException('만료된 생성 이력을 다시 사용할 수 없습니다. 새 요청으로 생성해 주세요.'); }
            $this->jobs->expire($job['id'], $this->expiryMessage());
            $data['fingerprint'] = hash('sha256', $data['fingerprint'] . ':replaces:' . $job['id']);
            $data['supersedes_job_id'] = $job['id'];
            $job = $this->jobs->createLimited($data, $limits);
        }
        if ($job['status'] === 'queued') { $this->schedule(); }
        return $this->publicJob($job);
    }
    public function job(int $id): array { return $this->publicJob($this->authorized($id, true)); }
    public function validateActivity(int $id, array $input): array { return $this->fromActivity($id, $input, true); }
    public function regenerate(int $id, array $input): array { return $this->fromActivity($id, $input, false); }
    private function fromActivity(int $id, array $input, bool $validationOnly): array
    {
        $this->platform->assertRole('teach');
        $activity = $this->records->findActivity($id);
        if ($activity === null || ! is_array($activity['lesson'] ?? null)) { throw new DomainException('다시 준비할 수업 자료를 찾을 수 없습니다.'); }
        $this->platform->authorize($activity['context_type'], $activity['context_id'], 'assign');
        if (($activity['creator_user_id'] ?? 0) !== $this->platform->actorId() && ! $this->isAdmin()) { throw new DomainException('다른 교사의 수업 자료를 변경할 수 없습니다.'); }
        $scope = $validationOnly ? 'all' : ($input['scope'] ?? 'all');
        if (! in_array($scope, ['all', 'predict', 'run', 'investigate', 'modify', 'make'], true)) { throw new DomainException('다시 생성할 단계를 확인해 주세요.'); }
        $current = $this->platform->problem($activity['target_problem_id'], $activity['context_type'], $activity['context_id'])['problem'];
        if (($validationOnly || $scope !== 'all') && ! hash_equals($activity['target_hash'], $current['content_hash'])) { throw new DomainException('원문 문제가 변경되었습니다. 바뀐 문제로 전체 자료를 새로 생성해 주세요.'); }
        $lesson = $activity['lesson']; $lesson['verification'] = ['status' => 'not_executed', 'note' => LessonSchema::NOTE];
        $lesson = LessonSchema::validateNormalized($lesson, $lesson['language'], $lesson['support']['kind']);
        return $this->startConfigured(['context_type' => $activity['context_type'], 'context_id' => $activity['context_id'],
            'target_problem_id' => $activity['target_problem_id'], 'language' => $lesson['language'], 'support_kind' => $lesson['support']['kind'],
            'title' => $activity['title'], 'request_key' => $input['request_key'] ?? ('validation:' . $id . ':' . ($activity['revision'] ?? 1)), 'force' => true],
            ['seed_lesson' => $lesson, 'scope' => $scope, 'validation_only' => $validationOnly, 'parent_activity_id' => $id,
                'template_origin' => ($validationOnly || $scope !== 'all') && self::templateOriginForActivity($activity, $this->records, $this->jobs),
                'parent_revision' => $activity['revision'] ?? 1] + ($validationOnly && in_array($activity['status'] ?? '', ['draft', 'review'], true)
                    ? ['draft_activity_id' => $id, 'draft_revision' => $activity['revision'] ?? 1] : []));
    }
    public function cancel(int $id): array
    {
        $this->authorized($id);
        if (! $this->jobs instanceof GenerationOperationsStore) { throw new DomainException('작업 취소 기능을 사용할 수 없습니다.'); }
        return $this->publicJob($this->jobs->cancel($id, '교사가 생성을 취소했습니다.'));
    }
    public function stop(int $id): array
    {
        $this->platform->assertRole('teach');
        if (! $this->isAdmin() || ! $this->jobs instanceof GenerationOperationsStore) { throw new DomainException('관리자만 생성 작업을 중지할 수 있습니다.'); }
        return $this->publicJob($this->jobs->cancel($id, '관리자가 생성 작업을 중지했습니다.', true));
    }
    public function resume(int $id): array
    {
        if (GenerationPolicy::get()['paused']) { throw new DomainException('관리자가 생성 작업을 일시 중지했습니다.'); }
        if ($this->isAdmin()) {
            $this->platform->assertRole('teach'); $job = $this->jobs->find($id) ?? throw new DomainException('생성 작업을 찾을 수 없습니다.');
            $this->platform->authorize($job['context_type'], $job['context_id'], 'assign');
            $problem = $this->platform->problem($job['target_problem_id'], $job['context_type'], $job['context_id'])['problem'];
            if (! hash_equals($job['target_hash'], $problem['content_hash'])) { throw new DomainException('원문 문제가 변경되어 새 생성이 필요합니다.'); }
        } else { $job = $this->authorized($id); $this->currentProblem($job); }
        $this->assertLifetime($job);
        if ($job['status'] === 'stopped' && ! $this->isAdmin()) { throw new DomainException('관리자가 중지한 작업은 관리자만 다시 시작할 수 있습니다.'); }
        if (! $this->jobs instanceof GenerationOperationsStore) { return $this->retry($id); }
        $resumed = $this->jobs->resume($id); $this->schedule(); return $this->publicJob($resumed);
    }
    public function overview(bool $admin = false): array
    {
        $this->platform->assertRole('teach');
        if ($admin && ! $this->isAdmin()) { throw new DomainException('전체 생성 현황은 관리자만 볼 수 있습니다.'); }
        $result = $this->jobs instanceof GenerationOperationsStore ? $this->jobs->overview($admin ? null : $this->platform->actorId()) : ['jobs' => [], 'usage' => [], 'statuses' => []];
        $result['jobs'] = array_map(fn (array $job): array => $this->publicJob($job), $result['jobs']); $result['limits'] = GenerationPolicy::get();
        if ($admin && function_exists('get_option')) { $result['background_error'] = get_option('oj_primm_last_background_error', null); }
        if ($admin && function_exists('get_option')) { $result['automatic_preparation'] = get_option(ProblemPreparationAutomation::LOG_OPTION, []); }
        return $result;
    }
    public function retry(int $id): array
    {
        $job = $this->authorized($id);
        if ($job['status'] !== 'failed') { return $this->publicJob($job); }
        $this->assertLifetime($job);
        if (GenerationPolicy::get()['paused']) { throw new DomainException('관리자가 생성 작업을 일시 중지했습니다.'); }
        $this->currentProblem($job);
        $available = (($job['validation_only'] ?? false) || ($job['template_only'] ?? false)) ? $this->executor->availability() : $this->generationAvailability($job['language']);
        if (! ($available['available'] ?? false)) { throw new DomainException($available['message'] ?? 'AI 경로를 확인해 주세요.'); }
        $retried = $this->jobs->retry($id);
        $this->schedule();
        return $this->publicJob($retried);
    }
    public function processForTeacher(int $id): array
    {
        $job = $this->authorized($id);
        if ($job['status'] !== 'ready' && $this->expired($job)) {
            if ($this->jobs instanceof GenerationOperationsStore) { return $this->publicJob($this->jobs->expire($id, $this->expiryMessage())); }
            $this->assertLifetime($job);
        }
        if (in_array($job['status'], ['ready', 'failed', 'canceled', 'stopped'], true) || GenerationPolicy::get()['paused']) { return $this->publicJob($job); }
        if ($job['status'] === 'retry_wait' && ($job['next_attempt_at'] ?? '') > gmdate('Y-m-d H:i:s')) { return $this->publicJob($job); }
        $lease = bin2hex(random_bytes(24));
        if (! $this->jobs->claim($id, $lease, gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s', time() + 300))) {
            return $this->job($id);
        }
        try {
            // A recovered worker reads the latest durable state only after acquiring its lease.
            $job = $this->jobs->find($id) ?? throw new DomainException('생성 작업을 찾을 수 없습니다.');
            $problem = $this->currentProblem($job);
            $pipeline = $job['pipeline'] ?? [];
            $key = ($job['key_version'] ?? 1) === 2
                ? 'primm:' . $id . ':' . hash('sha256', $job['fingerprint'] . ':' . ($job['run_key'] ?? 'initial'))
                : 'primm:' . $id . ':' . $job['fingerprint'] . ':' . ($job['run_key'] ?? 'initial');
            $profile = $job['profile'] ?? ClassSettingsService::defaults();
            if ($pipeline === [] || ($pipeline['phase'] ?? '') === 'validation_repair') {
                $validationAttempt = (int) ($pipeline['validation_attempt'] ?? 0);
                if (($job['validation_only'] ?? false) && isset($job['seed_lesson'])) { $lesson = $job['seed_lesson']; }
                elseif ($job['template_only'] ?? false) { $lesson = LessonTemplate::create($job['language'], $job['support_kind']); }
                else {
                    $this->reserveAi($job, $lease);
                    try {
                        if (($pipeline['phase'] ?? '') === 'validation_repair') {
                            if ($validationAttempt !== 1 || ! $this->generator instanceof ValidationRepairGenerator) { throw new DomainException('수업 형식 복구 단계를 확인하지 못했습니다.'); }
                            $lesson = $this->generator->repairValidation($this->platform->actorId(), $problem, $job['language'], $job['support_kind'],
                                $pipeline['candidate'], $pipeline['validation_message'], $profile, $key . ':validation:1');
                        } elseif (isset($job['seed_lesson']) && $this->generator instanceof CurriculumLessonGenerator) {
                            $lesson = $this->generator->regenerate($this->platform->actorId(), $problem, $job['seed_lesson'], $job['scope'] ?? 'all', $profile, $key . ':generate');
                        } elseif ($this->generator instanceof CurriculumLessonGenerator) {
                            $lesson = $this->generator->generateForProfile($this->platform->actorId(), $problem, $job['language'], $job['support_kind'], $profile, $key . ':generate');
                        } else { $lesson = $this->generator->generate($this->platform->actorId(), $problem, $job['language'], $job['support_kind'], $key . ':generate'); }
                    } catch (LessonValidationFailure $error) {
                        // Only a full AI-authored draft may be replaced; edited/partial lessons keep their scope boundary.
                        if ($validationAttempt >= 1 || ! $this->generator instanceof ValidationRepairGenerator
                            || (isset($job['seed_lesson']) && ($job['scope'] ?? 'all') !== 'all')) { throw $error; }
                        $this->revalidate($job);
                        return $this->checkpoint($id, $lease, ['phase' => 'validation_repair', 'validation_attempt' => 1,
                            'candidate' => $error->candidate, 'validation_message' => $error->getMessage(), 'repair_attempt' => 0]);
                    }
                }
                $lesson = LessonSchema::validateNormalized($lesson, $job['language'], $job['support_kind']);
                $this->revalidate($job);
                return $this->checkpoint($id, $lease, ['phase' => 'verifying', 'lesson' => $lesson, 'repair_attempt' => 0,
                    'validation_attempt' => $validationAttempt, 'log' => $pipeline['log'] ?? [],
                    'round_started_at' => time(), 'executions' => [], 'results' => [], 'history' => []]);
            }
            $lesson = LessonSchema::validateNormalized($pipeline['lesson'], $job['language'], $job['support_kind']);
            if ($pipeline['phase'] === 'repairing') {
                $round = $pipeline['repair_attempt'] + 1;
                if ($round > self::MAX_REPAIRS) { throw new DomainException('자동 수정 횟수를 초과했습니다. 검증을 통과하지 못한 수업은 배포하지 않았습니다.'); }
                $this->reserveAi($job, $lease);
                $feedback = LessonVerification::feedback($lesson, $pipeline['results'], $round);
                $lesson = $this->generator instanceof CurriculumLessonGenerator
                    ? $this->generator->repairForProfile($this->platform->actorId(), $problem, $lesson, $feedback, $profile, $key . ':repair:' . $round)
                    : $this->generator->repair($this->platform->actorId(), $problem, $lesson, $feedback, $key . ':repair:' . $round);
                $lesson = LessonSchema::validateNormalized($lesson, $job['language'], $job['support_kind']);
                $this->revalidate($job);
                $pipeline['history'][] = ['repair_attempt' => $pipeline['repair_attempt'], 'source_hash' => LessonVerification::lessonHash($pipeline['lesson']),
                    'results' => $pipeline['results']];
                $pipeline = array_replace($pipeline, ['phase' => 'verifying', 'lesson' => $lesson, 'repair_attempt' => $round,
                    'round_started_at' => time(), 'executions' => [], 'results' => []]);
                return $this->checkpoint($id, $lease, $pipeline);
            }
            if ($pipeline['phase'] === 'verifying') {
                // The native OJ queue is asynchronous. Each HTTP/cron call performs a bounded unit of work.
                if (time() - $pipeline['round_started_at'] > 1200) { throw new DomainException('OJ 검증 대기 시간이 초과되었습니다. 채점기 상태를 확인한 뒤 다시 시도해 주세요.'); }
                foreach (LessonVerification::cases($lesson) as $case) {
                    $caseId = $case['case_id'];
                    if (isset($pipeline['results'][$caseId])) { continue; }
                    $ticket = $pipeline['executions'][$caseId] ?? null;
                    $result = $this->executor->execute($this->platform->actorId(), $lesson['language'], $case['source'], $case['input'],
                        $key . ':run:' . $pipeline['repair_attempt'] . ':' . $caseId . ':' . LessonVerification::lessonHash($lesson), $ticket);
                    $this->revalidate($job);
                    if (! is_string($result['ticket'] ?? null) || $result['ticket'] === '') { throw new DomainException('OJ 실행 요청 번호를 확인하지 못했습니다.'); }
                    $pipeline['executions'][$caseId] = $result['ticket'];
                    if (($result['status'] ?? '') === 'completed') { $pipeline['results'][$caseId] = LessonVerification::compare($result, $case); }
                    elseif (($result['status'] ?? '') !== 'pending') { throw new DomainException('OJ 예제 실행 결과를 확인하지 못했습니다.'); }
                    // Save every ticket immediately; a later case failure never loses earlier submissions.
                    return $this->checkpoint($id, $lease, $pipeline);
                }
                $feedback = LessonVerification::feedback($lesson, $pipeline['results'], $pipeline['repair_attempt'] + 1);
                if ($feedback['failures'] !== []) {
                    if (($job['validation_only'] ?? false) || ($job['template_only'] ?? false)) { throw new DomainException('예제 검증에 실패했습니다. 교사가 코드와 시험 사례를 수정한 뒤 다시 검증해 주세요. 이 검증 작업에서는 AI를 호출하지 않습니다.'); }
                    if ($pipeline['repair_attempt'] >= self::MAX_REPAIRS) {
                        throw new DomainException('도입 예제·수정 과제가 OJ 검증을 통과하지 못해 자동 수정 2회 후 생성을 중단했습니다. 수업은 배포하지 않았습니다. 다시 생성해 주세요.');
                    }
                    $pipeline['phase'] = 'repairing';
                } else {
                    $pipeline['phase'] = 'quality';
                    $pipeline['verification'] = LessonVerification::metadata($lesson, $pipeline['results'], $pipeline['repair_attempt']);
                }
                return $this->checkpoint($id, $lease, $pipeline);
            }
            if ($pipeline['phase'] === 'quality') {
                if (time() - $pipeline['round_started_at'] > 1200) { throw new DomainException('수정 전후 비교 실행 대기 시간이 초과되었습니다. 채점기 상태를 확인한 뒤 다시 시도해 주세요.'); }
                foreach (LessonQuality::baselineCases($lesson) as $case) {
                    $caseId = $case['case_id'];
                    if (isset($pipeline['baseline_results'][$caseId])) { continue; }
                    $result = $this->executor->execute($this->platform->actorId(), $lesson['language'], $case['source'], $case['input'],
                        $key . ':baseline:' . $pipeline['repair_attempt'] . ':' . $caseId . ':' . LessonVerification::lessonHash($lesson), $pipeline['baseline_executions'][$caseId] ?? null);
                    $this->revalidate($job);
                    if (! is_string($result['ticket'] ?? null) || $result['ticket'] === '') { throw new DomainException('수정 과제 비교 실행 번호를 확인하지 못했습니다.'); }
                    $pipeline['baseline_executions'][$caseId] = $result['ticket'];
                    if (($result['status'] ?? '') === 'completed') { $pipeline['baseline_results'][$caseId] = LessonVerification::compare($result, $case); }
                    elseif (($result['status'] ?? '') !== 'pending') { throw new DomainException('수정 과제 비교 결과를 확인하지 못했습니다.'); }
                    return $this->checkpoint($id, $lease, $pipeline);
                }
                $pipeline['quality'] = LessonQuality::assess($lesson, $profile, $pipeline['baseline_results'] ?? []);
                $pipeline['quality'] = self::qualityForOrigin($pipeline['quality'], self::templateOriginForJob($job, $this->records, $this->jobs));
                $pipeline['phase'] = 'publishing'; return $this->checkpoint($id, $lease, $pipeline);
            }
            if ($pipeline['phase'] !== 'publishing') { throw new DomainException('생성 작업의 검증 단계를 확인하지 못했습니다.'); }
            // Rebuild proof from server case hashes rather than trusting any AI status or checkpoint label.
            $lesson['verification'] = LessonVerification::metadata($lesson, $pipeline['results'], $pipeline['repair_attempt']);
            $this->revalidate($job);
            $quality = $pipeline['quality'] ?? ['status' => 'needs_review', 'reasons' => ['수정 과제의 차이 검사를 다시 확인해 주세요.']];
            // Also fence old publishing checkpoints that predate the provenance gate.
            $templateOrigin = self::templateOriginForJob($job, $this->records, $this->jobs);
            $quality = self::qualityForOrigin($quality, $templateOrigin);
            $result = $this->jobs->complete($id, $lease, function () use ($job, $lesson, $quality, $templateOrigin): array {
                if ($this->activityFactory !== null) { return ($this->activityFactory)($job, $lesson, $quality); }
                $context = $this->platform->authorize($job['context_type'], $job['context_id'], 'assign');
                $problem = $this->currentProblem($job);
                $now = gmdate('Y-m-d H:i:s');
                $title = $job['title'] !== '' ? $job['title'] : $lesson['title'];
                // Storage title is bytes bounded; do not cut a UTF-8 sequence.
                while (strlen($title) > 191) { $title = preg_replace('/.$/us', '', $title) ?? 'PRIMM 활동'; }
                return $this->records->createActivity([
                    'creator_user_id' => $this->platform->actorId(), 'title' => $title,
                    'context_type' => $job['context_type'], 'context_id' => $job['context_id'], 'context_revision' => $context['revision'],
                    'target_problem_id' => $job['target_problem_id'], 'target_title' => $problem['title'], 'target_hash' => $problem['content_hash'],
                    'support_kind' => $job['support_kind'], 'support_problem_id' => null,
                    'support_title' => 'AI 생성 수정 도움 활동', 'support_hash' => '', 'support_is_target' => false,
                    'demo_code' => 'ai_generated', 'lesson' => $lesson, 'generation_id' => $job['id'],
                    'generation_kind' => $templateOrigin ? 'template' : 'ai', 'template_origin' => $templateOrigin, 'quality' => $quality,
                    'status' => ($quality['status'] ?? '') === 'passed' && ($job['profile']['auto_publish'] ?? true) ? 'active' : 'review',
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            });
            if (function_exists('do_action')) { do_action('oj_primm_lesson_generated', $id, $result['activity_id'], $job['creator_user_id']); }
            return $this->publicJob($result);
        } catch (Throwable $error) {
            $message = $error instanceof DomainException ? $error->getMessage() : 'AI 생성 작업을 완료하지 못했습니다. 연결과 저장소를 확인한 뒤 다시 시도해 주세요.';
            $message = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $message) ?? 'AI 생성에 실패했습니다.';
            $message = function_exists('mb_strcut') ? mb_strcut($message, 0, 500, 'UTF-8') : 'AI 생성에 실패했습니다. 설정을 확인해 주세요.';
            $details = ['code' => $error instanceof GenerationFailure ? $error->failureCode : ($error instanceof DomainException ? 'validation_error' : 'storage_error'),
                'retryable' => $error instanceof GenerationFailure && $error->retryable];
            if ($error instanceof TransientGenerationException && $this->jobs instanceof GenerationOperationsStore) {
                $current = $this->jobs->find($id) ?? $job;
                $attempt = (int) ($current['transient_failures'] ?? 0);
                if ($attempt < GenerationPolicy::get()['transient_retry_limit']) {
                    $delay = max($error->retryAfterSeconds, min(900, 15 * (2 ** $attempt)));
                    $deferred = $this->jobs->defer($id, $lease, $message, gmdate('Y-m-d H:i:s', time() + $delay), $details);
                    $this->schedule($delay); return $this->publicJob($deferred);
                }
            }
            if ($this->jobs instanceof GenerationOperationsStore) { $this->jobs->failDetailed($id, $lease, $message, $details); }
            else { $this->jobs->fail($id, $lease, $message); }
            return $this->publicJob($this->jobs->find($id) ?? $job);
        }
    }
    /** Restore pre-1.0.1 provenance from our generation ledger and immutable activity ancestry only. */
    public static function templateOriginForActivity(array $activity, Repository $records, ?GenerationStore $jobs): bool
    {
        $seen = []; $unknown = false;
        for ($depth = 0; $depth < 1000; ++$depth) {
            if (is_bool($activity['template_origin'] ?? null)) { return $activity['template_origin']; }
            if (($activity['generation_kind'] ?? '') === 'template') { return true; }
            $id = (int) ($activity['id'] ?? 0);
            if ($id > 0 && isset($seen[$id])) { return true; }
            if ($id > 0) { $seen[$id] = true; }
            $parentId = (int) ($activity['parent_activity_id'] ?? $activity['reused_from'] ?? 0);
            $generationId = (int) ($activity['generation_id'] ?? 0);
            if ($generationId > 0) {
                $job = $jobs?->find($generationId);
                if ($job !== null) {
                    if (($job['template_only'] ?? false) || ($job['template_origin'] ?? false)) { return true; }
                    if (is_bool($job['template_origin'] ?? null)) { return false; }
                    // A known complete AI generation replaces every template-derived teaching stage.
                    if (! ($job['validation_only'] ?? false) && (! isset($job['seed_lesson']) || ($job['scope'] ?? 'all') === 'all')) { return false; }
                    if ($parentId < 1) { $parentId = (int) ($job['parent_activity_id'] ?? 0); }
                    $unknown = true;
                } else { $unknown = true; }
            } elseif (($activity['generation_kind'] ?? '') === 'ai') { $unknown = true; }
            if ($parentId < 1) { return $unknown; }
            $parent = $records->findActivity($parentId);
            if ($parent === null) { return true; }
            $activity = $parent;
        }
        return true;
    }
    public static function templateOriginForJob(array $job, Repository $records, ?GenerationStore $jobs): bool
    {
        if ($job['template_only'] ?? false) { return true; }
        if (is_bool($job['template_origin'] ?? null)) { return $job['template_origin']; }
        if (! ($job['validation_only'] ?? false) && ($job['scope'] ?? 'all') === 'all') { return false; }
        $parentId = (int) ($job['parent_activity_id'] ?? $job['draft_activity_id'] ?? 0);
        $parent = $parentId > 0 ? $records->findActivity($parentId) : null;
        return $parent === null || self::templateOriginForActivity($parent, $records, $jobs);
    }
    public static function qualityForOrigin(array $quality, bool $templateOrigin): array
    {
        if ($templateOrigin) {
            $quality['status'] = 'needs_review';
            $quality['reasons'] = array_values(array_unique(array_merge($quality['reasons'] ?? [],
                ['안전한 기본 예제와 원래 문제의 학습 연관성을 확인해 주세요.'])));
        }
        return $quality;
    }
    private function authorized(int $id, bool $allowAdmin = false): array
    {
        $this->platform->assertRole('teach');
        $job = $id > 0 ? $this->jobs->find($id) : null;
        if ($job === null || ($job['creator_user_id'] !== $this->platform->actorId() && ! ($allowAdmin && $this->isAdmin()))) { throw new DomainException('생성 작업을 조회할 권한이 없습니다.'); }
        $this->platform->authorize($job['context_type'], $job['context_id'], 'assign');
        return $job;
    }
    private function currentProblem(array $job): array
    {
        $this->platform->assertRole('teach');
        if ($job['creator_user_id'] !== $this->platform->actorId()) { throw new DomainException('생성 요청을 시작한 교사 계정이 변경되었습니다. 다시 로그인해 주세요.'); }
        $this->platform->authorize($job['context_type'], $job['context_id'], 'assign');
        $problem = $this->platform->problem($job['target_problem_id'], $job['context_type'], $job['context_id'])['problem'];
        if (! hash_equals($job['target_hash'], $problem['content_hash'])) { throw new DomainException('원문 문제가 변경되었습니다. 바뀐 문제로 새 생성을 시작해 주세요.'); }
        return $problem;
    }
    private function publicJob(array $job): array
    {
        $stalled = ($job['status'] === 'running' && ($job['lease_expires_at'] ?? '') < gmdate('Y-m-d H:i:s'))
            || (($job['status'] === 'queued' || ($job['status'] === 'retry_wait' && ($job['next_attempt_at'] ?? '') <= gmdate('Y-m-d H:i:s')))
                && strtotime($job['updated_at']) < time() - 1800);
        // A lost worker can be claimed again; the old lease cannot publish afterward.
        if ($job['status'] === 'running' && is_string($job['lease_expires_at'] ?? null)
            && $job['lease_expires_at'] <= gmdate('Y-m-d H:i:s')) { $job['status'] = 'queued'; }
        if ($job['status'] === 'retry_wait' && ($job['next_attempt_at'] ?? '') <= gmdate('Y-m-d H:i:s')) { $job['status'] = 'queued'; }
        $public = array_intersect_key($job, array_flip(['id', 'status', 'activity_id', 'error', 'updated_at', 'language', 'support_kind', 'target_problem_id']));
        $pipeline = $job['pipeline'] ?? [];
        $public += ['phase' => $pipeline['phase'] ?? 'generating', 'repair_attempt' => $pipeline['repair_attempt'] ?? 0, 'max_repairs' => self::MAX_REPAIRS];
        $public['message'] = match ($public['phase']) {
            'validation_repair' => 'AI 응답의 자료 형식을 한 번 복구합니다. 복구 후 모든 코드를 OJ에서 검증합니다.',
            'verifying' => 'OJ에서 도입 예제와 수정 과제의 코드·예상 출력을 검증하고 있습니다.',
            'repairing' => '검증 오류를 AI가 수정합니다. 수정 후 모든 예제를 다시 검증합니다.',
            'publishing' => '검증을 통과했습니다. 수업 자료를 저장하고 있습니다.',
            'quality' => '수정 전 코드와 수정 후 코드를 비교하고 학급 수준에 맞는지 확인합니다.',
            default => 'AI가 PRIMM 수업 자료를 생성하고 있습니다.',
        };
        if ($job['status'] === 'ready' && isset($pipeline['verification'])) { $public['verification'] = $pipeline['verification']; }
        if ($job['status'] === 'ready' && is_int($job['activity_id'])) {
            $activity = $this->records->findActivity($job['activity_id']);
            if ($activity !== null) {
                $public['activity_status'] = $activity['status'] ?? 'draft'; $public['activity_state'] = $public['activity_status'];
                $pipeline['quality'] = self::qualityForOrigin($activity['quality'] ?? $pipeline['quality'] ?? [],
                    self::templateOriginForActivity($activity, $this->records, $this->jobs));
            }
        }
        $owner = $job['creator_user_id'] === $this->platform->actorId();
        $public['can_process'] = $owner;
        $public['can_cancel'] = $owner && in_array($job['status'], ['queued', 'running', 'retry_wait'], true);
        $public['can_resume'] = ($owner && in_array($job['status'], ['canceled', 'retry_wait'], true)) || ($job['status'] === 'stopped' && $this->isAdmin());
        $public['is_stalled'] = $stalled;
        $public['next_attempt_at'] = $job['next_attempt_at'] ?? null;
        $public['usage'] = ($job['usage'] ?? ['ai_calls' => 0, 'execution_count' => 0]) + ['repair_attempts' => $pipeline['repair_attempt'] ?? 0];
        $public['usage']['execution_count'] = count($pipeline['executions'] ?? []) + count($pipeline['baseline_executions'] ?? []);
        foreach ($pipeline['history'] ?? [] as $round) { $public['usage']['execution_count'] += count($round['results'] ?? []); }
        if ($job['status'] === 'retry_wait') { $public['message'] = '연결 오류 또는 호출 예산으로 잠시 대기합니다. 저장한 진행 상태에서 자동으로 다시 시도합니다.'; }
        if (isset($pipeline['quality'])) { $public['quality'] = $pipeline['quality']; }
        $public['quality_summary'] = ['status' => $pipeline['quality']['status'] ?? 'pending', 'reasons' => $pipeline['quality']['reasons'] ?? []];
        $public['logs'] = $pipeline['log'] ?? [];
        $public['site_paused'] = GenerationPolicy::get()['paused'];
        $public['error_code'] = $job['last_failure']['code'] ?? '';
        $public['retryable'] = $job['last_failure']['retryable'] ?? false;
        $public['expired'] = $job['status'] !== 'ready' && $this->expired($job);
        $public['is_expired'] = $public['expired'];
        $public['can_retry'] = $job['status'] === 'failed' && !$public['expired'];
        if ($public['expired']) { $public['can_resume'] = false; $public['retryable'] = false; $public['message'] = $this->expiryMessage(); }
        if ($owner && $public['expired']) {
            $public['new_request'] = ['context_type' => $job['context_type'], 'context_id' => $job['context_id'],
                'target_problem_id' => $job['target_problem_id'], 'language' => $job['language'], 'support_kind' => $job['support_kind'],
                'title' => $job['title'], 'use_template' => (bool) ($job['template_only'] ?? false),
                'kind' => ($job['validation_only'] ?? false) ? 'validate' : (isset($job['parent_activity_id']) ? 'regenerate' : 'create'),
                'activity_id' => $job['draft_activity_id'] ?? $job['parent_activity_id'] ?? null, 'scope' => $job['scope'] ?? 'all'];
        }
        return $public;
    }
    private function generationAvailability(?string $language = null): array
    {
        $ai = $this->generator->availability();
        $oj = $this->executor->availability();
        $metadata = ['execution_available' => (bool) ($oj['available'] ?? false),
            'execution_languages' => $oj['languages'] ?? [], 'languages' => $oj['languages'] ?? []];
        if (! ($ai['available'] ?? false)) { return array_replace($ai, $metadata); }
        if (! ($oj['available'] ?? false)) { return array_replace($oj, $metadata); }
        if ($language !== null && ! in_array($language, $oj['languages'] ?? [], true)) {
            return ['available' => false, 'message' => '선택한 언어를 OJ 채점기에서 실행할 수 없습니다. 채점기 언어 설정을 확인해 주세요.'] + $metadata;
        }
        return array_replace($ai, $metadata);
    }
    private function checkpoint(int $id, string $lease, array $pipeline): array
    {
        $pipeline['log'] = $pipeline['log'] ?? [];
        $last = $pipeline['log'] === [] ? '' : ($pipeline['log'][array_key_last($pipeline['log'])]['phase'] ?? '');
        if ($last !== ($pipeline['phase'] ?? '')) { $pipeline['log'][] = ['phase' => $pipeline['phase'], 'at' => gmdate('Y-m-d H:i:s')]; }
        $pipeline['log'] = array_slice($pipeline['log'] ?? [], -30);
        $job = $this->jobs->checkpoint($id, $lease, $pipeline);
        $this->schedule();
        return $this->publicJob($job);
    }
    private function revalidate(array $job): void
    {
        $this->assertLifetime($job);
        if ($this->platform instanceof ActorRevalidationAccess) { $this->platform->refreshActor(); }
        $this->currentProblem($job);
    }
    private function positive(mixed $value): int
    {
        if ((! is_string($value) && ! is_int($value)) || preg_match('/^[1-9][0-9]{0,17}$/D', (string) $value) !== 1) { throw new DomainException('문제·수업 번호를 확인해 주세요.'); }
        return (int) $value;
    }
    private function reserveAi(array $job, string $lease): void
    {
        $this->assertLifetime($job);
        if ($this->jobs instanceof GenerationOperationsStore) { $this->jobs->reserveAi($job['id'], $lease, GenerationPolicy::get()); }
    }
    private function expired(array $job): bool
    {
        $value = $job['created_at'] ?? null;
        if (! is_string($value)) { return true; }
        $created = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new \DateTimeZone('UTC'));
        if ($created === false || $created->format('Y-m-d H:i:s') !== $value) { return true; }
        $age = time() - $created->getTimestamp();
        return $age >= self::MAX_AGE_SECONDS || $age < -300;
    }
    private function assertLifetime(array $job): void
    {
        if ($this->expired($job)) { throw new GenerationFailure($this->expiryMessage(), 'generation_expired', false); }
    }
    private function expiryMessage(): string { return '생성 작업의 유효 시간 24시간이 지났습니다. 이전 요청을 재시도하지 않고 새 생성 작업을 시작해 주세요.'; }
    private function isAdmin(): bool { return function_exists('current_user_can') && current_user_can('manage_options'); }
    private function schedule(int $delay = 1): void
    {
        if (function_exists('wp_schedule_single_event') && ! wp_next_scheduled('oj_primm_generation_tick')) {
            wp_schedule_single_event(time() + max(1, $delay), 'oj_primm_generation_tick');
        }
    }
}
