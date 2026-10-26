<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Core/Autoloader.php';
\OjPrimm\Core\Autoloader::register();

use OjPrimm\Application\GenerationService;
use OjPrimm\Application\PlatformAccess;
use OjPrimm\Application\PrimmService;
use OjPrimm\Application\LessonExecutor;
use OjPrimm\Generation\LessonGenerator;
use OjPrimm\Generation\LessonSchema;
use OjPrimm\Storage\GenerationStore;
use OjPrimm\Storage\GenerationOperationsStore;
use OjPrimm\Storage\Repository;

$checks = 0;
function verify(bool $condition, string $label): void { global $checks; ++$checks; if (! $condition) { throw new RuntimeException($label); } }
function deny(callable $work, string $label): void { try { $work(); } catch (DomainException) { verify(true, $label); return; } verify(false, $label); }
final class CurriculumPlatform implements PlatformAccess
{
    public int $actor = 10;
    public bool $allowed = true;
    public string $hash = 'problem-v1';
    public function actorId(): int { return $this->actor; }
    public function assertRole(string $role): void
    {
        if (! $this->allowed || ($role === 'teach' ? ! in_array($this->actor, [10, 11], true) : $this->actor !== 20)) { throw new DomainException('역할 권한 없음'); }
    }
    public function contexts(bool $teaching): array { return [['type' => 'class', 'id' => 'class:1', 'label' => '수업', 'revision' => 'rev1']]; }
    public function authorize(string $type, string $id, string $operation, ?int $learnerId = null): array
    {
        if (! $this->allowed || $type !== 'class' || $id !== 'class:1'
            || ($operation === 'participate' && ($this->actor !== 20 || $learnerId !== 20))
            || ($operation !== 'participate' && ! in_array($this->actor, [10, 11], true))) { throw new DomainException('현재 수업 소속 권한 없음'); }
        return ['type' => $type, 'id' => $id, 'revision' => 'rev1'];
    }
    public function searchProblems(string $search): array { return []; }
    public function assertSupport(int $problemId, string $kind, string $contextType, string $contextId): void { throw new LogicException('Generated support needs no authored problem'); }
    public function problem(int $problemId, string $contextType, string $contextId, ?int $submissionId = null): array
    {
        if ($problemId !== 100) { throw new DomainException('문제 없음'); }
        return ['problem' => ['id' => 100, 'title' => '문제', 'description' => '정수를 입력받아 수를 계산하세요.',
            'input' => '정수 한 개', 'output' => '계산한 수', 'sample_input' => '2', 'sample_output' => '3', 'content_hash' => $this->hash], 'submission' => null];
    }
    public function problemUrl(int $problemId, string $contextType, string $contextId): string { return '/problems/100?class_id=1'; }
}
final class CurriculumRecords implements Repository
{
    public array $activities = [];
    public array $attempts = [];
    public function createActivity(array $data): array { $data['id'] = count($this->activities) + 1; return $this->activities[$data['id']] = $data; }
    public function findActivity(int $id): ?array { return $this->activities[$id] ?? null; }
    public function activitiesForContext(string $type, string $id): array { return array_values($this->activities); }
    public function findAttempt(int $activityId, int $userId): ?array { return $this->attempts[$activityId . ':' . $userId] ?? null; }
    public function saveAttempt(int $activityId, int $userId, int $expectedVersion, array $state, array $event, string $requestHash): array
    {
        $key = $activityId . ':' . $userId;
        if (($this->attempts[$key]['version'] ?? 0) !== $expectedVersion) { throw new DomainException('version conflict'); }
        return $this->attempts[$key] = ['id' => 1, 'activity_id' => $activityId, 'user_id' => $userId,
            'version' => $expectedVersion + 1, 'last_request_hash' => $requestHash] + $state;
    }
    public function attemptsForActivities(array $activityIds): array { return array_values($this->attempts); }
    public function exportUser(int $userId, int $page = 1): array { return []; }
    public function eraseUser(int $userId): bool { return true; }
    public function purgeBefore(string $cutoff): int { return 0; }
}
final class CurriculumJobs implements GenerationOperationsStore
{
    public array $items = [];
    public ?int $aiLimit = null;
    public function createOrFind(array $data): array
    {
        foreach ($this->items as $job) { if ($job['creator_user_id'] === $data['creator_user_id'] && $job['fingerprint'] === $data['fingerprint']) { return $job; } }
        $id = count($this->items) + 1;
        return $this->items[$id] = ['id' => $id, 'status' => 'queued', 'activity_id' => null, 'error' => '',
            'lease_token' => '', 'lease_expires_at' => null, 'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')] + $data;
    }
    public function find(int $id): ?array { return $this->items[$id] ?? null; }
    public function claim(int $id, string $lease, string $now, string $expires): bool
    {
        $job = $this->items[$id];
        if ($job['status'] !== 'queued' && ! ($job['status'] === 'retry_wait' && $job['next_attempt_at'] <= $now)
            && ! ($job['status'] === 'running' && $job['lease_expires_at'] <= $now)) { return false; }
        $this->items[$id]['status'] = 'running'; $this->items[$id]['lease_token'] = $lease; $this->items[$id]['lease_expires_at'] = $expires;
        return true;
    }
    public function complete(int $id, string $lease, callable $createActivity): array
    {
        if (($this->items[$id]['status'] ?? '') !== 'running' || $this->items[$id]['lease_token'] !== $lease) { throw new DomainException('lost lease'); }
        $activity = $createActivity();
        $this->items[$id]['status'] = 'ready'; $this->items[$id]['activity_id'] = $activity['id'];
        return $this->items[$id];
    }
    public function checkpoint(int $id, string $lease, array $pipeline): array
    {
        if ($this->items[$id]['status'] !== 'running' || $this->items[$id]['lease_token'] !== $lease) { throw new DomainException('lost lease'); }
        $this->items[$id]['pipeline'] = $pipeline; $this->items[$id]['status'] = 'queued';
        $this->items[$id]['lease_token'] = ''; $this->items[$id]['lease_expires_at'] = null;
        return $this->items[$id];
    }
    public function fail(int $id, string $lease, string $message): void
    {
        if (($this->items[$id]['status'] ?? '') === 'running' && $this->items[$id]['lease_token'] === $lease) {
            $this->items[$id]['status'] = 'failed'; $this->items[$id]['error'] = $message;
        }
    }
    public function retry(int $id): array { if ($this->items[$id]['status'] === 'failed') { $this->items[$id]['status'] = 'queued'; $this->items[$id]['error'] = ''; $this->items[$id]['pipeline'] = []; $this->items[$id]['run_key'] = bin2hex(random_bytes(16)); } return $this->items[$id]; }
    public function pending(int $limit = 3): array { return []; }
    public function cancelPending(int $id, string $message): void { $this->items[$id]['status'] = 'failed'; $this->items[$id]['error'] = $message; }
    public function createLimited(array $data, array $limits): array { return $this->createOrFind($data); }
    public function reserveAi(int $id, string $lease, array $limits): array
    {
        if ($this->items[$id]['status'] !== 'running' || $this->items[$id]['lease_token'] !== $lease) { throw new DomainException('lost lease'); }
        if ($this->aiLimit !== null && ($this->items[$id]['usage']['ai_calls'] ?? 0) >= $this->aiLimit) { throw new DomainException('작업별 AI 호출 예산 소진'); }
        $this->items[$id]['usage']['ai_calls'] = ($this->items[$id]['usage']['ai_calls'] ?? 0) + 1;
        return $this->items[$id]['usage'];
    }
    public function defer(int $id, string $lease, string $message, string $retryAt, array $details = []): array
    {
        $this->items[$id]['status'] = 'retry_wait'; $this->items[$id]['next_attempt_at'] = $retryAt; $this->items[$id]['error'] = $message;
        $this->items[$id]['lease_token'] = ''; $this->items[$id]['lease_expires_at'] = null;
        $this->items[$id]['transient_failures'] = ($this->items[$id]['transient_failures'] ?? 0) + 1; $this->items[$id]['last_failure'] = $details;
        return $this->items[$id];
    }
    public function failDetailed(int $id, string $lease, string $message, array $details): void { $this->fail($id, $lease, $message); $this->items[$id]['last_failure'] = $details; }
    public function expire(int $id, string $message): array { $this->items[$id]['status'] = 'failed'; $this->items[$id]['error'] = $message; $this->items[$id]['last_failure'] = ['code' => 'generation_expired', 'retryable' => false]; return $this->items[$id]; }
    public function cancel(int $id, string $message, bool $administrative = false): array
    {
        $this->items[$id]['status'] = $administrative ? 'stopped' : 'canceled'; $this->items[$id]['error'] = $message;
        $this->items[$id]['lease_token'] = ''; $this->items[$id]['lease_expires_at'] = null; return $this->items[$id];
    }
    public function resume(int $id): array { $this->items[$id]['status'] = 'queued'; $this->items[$id]['error'] = ''; $this->items[$id]['next_attempt_at'] = null; return $this->items[$id]; }
    public function overview(?int $actor = null): array { return ['jobs' => array_values(array_filter($this->items, static fn (array $job): bool => $actor === null || $job['creator_user_id'] === $actor)), 'usage' => [], 'statuses' => []]; }
}
function rawCurriculum(string $language, string $kind): array
{
    $source = "value = \"HIDDEN_CODE_VALUE\"\nprint(len(value))\n";
    $stages = [];
    foreach (['predict', 'run', 'investigate', 'modify', 'make'] as $key) {
        $stages[] = ['key' => $key, 'label' => $key, 'prompt' => $key . ' 단계의 구체적인 안내', 'questions' => [$key . ' 단계에서 이 변수는 어떤 역할을 할까요?']];
    }
    $stages[3] += ['task' => '문자열의 길이를 출력하도록 수정하세요.', 'starter_code' => $source,
        'tests' => [['input' => '', 'expected_output' => "17\n"]], 'hints' => ['문자열 길이를 구하는 함수를 생각하세요.']];
    $stages[4]['task'] = 'FUTURE_MAKE_TASK';
    $support = match ($kind) {
        'code_ordering' => ['kind' => $kind, 'ordering_blocks' => ["value = \"HIDDEN_CODE_VALUE\"\n", "print(len(value))\n"]],
        'code_completion' => ['kind' => $kind, 'template' => "value = {{OJ_BLANK:value}}\nprint(len(value))\n",
            'blanks' => [['id' => 'value', 'label' => '문자열 값', 'kind' => 'inline', 'max_bytes' => 100, 'answer' => '"HIDDEN_CODE_VALUE"']]],
        default => ['kind' => 'none'],
    };
    return ['schema_version' => 1, 'language' => $language, 'title' => '문자열 길이와 계산', 'objectives' => ['입력과 변수의 역할을 설명한다.'],
        'demo' => ['code' => "x = 2\nprint(x + 1)\n", 'input' => '', 'expected_output' => "3\n", 'explanation' => 'EXPLANATION_ONLY_AFTER_PREDICT'],
        'stages' => $stages, 'support' => $support, 'verification' => ['status' => 'not_executed', 'note' => 'AI 초안']];
}
final class CurriculumGenerator implements LessonGenerator, \OjPrimm\Generation\ValidationRepairGenerator
{
    public bool $available = true;
    public int $calls = 0;
    public int $repairs = 0;
    public mixed $hook = null;
    public bool $failure = false;
    public bool $transient = false;
    public array $keys = [];
    public bool $invalidDraft = false;
    public bool $invalidRecovery = false;
    public bool $recoveryTransient = false;
    public int $validationRepairs = 0;
    public function availability(): array { return ['available' => $this->available, 'message' => 'AI 모델을 설정하세요.']; }
    public function generate(int $actor, array $publicProblem, string $language, string $supportKind, string $idempotencyKey): array
    {
        ++$this->calls;
        $this->keys[] = $idempotencyKey;
        verify($actor === 10 && $publicProblem['id'] === 100 && str_starts_with($idempotencyKey, 'primm:'), 'AI 요청은 원래 교사·문제·고정 작업 키에 연결');
        if ($this->transient) { throw new \OjPrimm\Generation\TransientGenerationException('AI 요청이 아직 처리 중입니다.', 0, 'addon_ai_request_pending'); }
        if ($this->failure) { throw new DomainException('AI 연결 실패'); }
        if (is_callable($this->hook)) { ($this->hook)(); }
        if ($this->invalidDraft) { throw new \OjPrimm\Generation\LessonValidationFailure('배열 조각 정합 오류', rawCurriculum($language, $supportKind)); }
        return LessonSchema::normalize(rawCurriculum($language, $supportKind), $language, $supportKind);
    }
    public function repairValidation(int $actor, array $publicProblem, string $language, string $kind,
        array $candidate, string $message, array $profile, string $key): array
    {
        ++$this->validationRepairs; $this->keys[] = $key;
        verify($actor === 10 && $publicProblem['id'] === 100 && str_ends_with($key, ':validation:1'), '형식 복구도 교사·문제·독립된 단일 라운드 키에 연결');
        verify($candidate['schema_version'] === 1 && $message === '배열 조각 정합 오류', '저장된 제한 초안과 구조 오류 진단을 복원');
        if ($this->recoveryTransient) { throw new \OjPrimm\Generation\TransientGenerationException('구조 복구 연결 대기'); }
        if ($this->invalidRecovery) { throw new \OjPrimm\Generation\LessonValidationFailure('복구 응답도 배열 조각 불일치', rawCurriculum($language, $kind)); }
        if (is_callable($this->hook)) { ($this->hook)(); }
        return LessonSchema::normalize(rawCurriculum($language, $kind), $language, $kind);
    }
    public function repair(int $actor, array $publicProblem, array $lesson, array $verificationReport, string $idempotencyKey): array
    {
        ++$this->repairs;
        verify($actor === 10 && $publicProblem['id'] === 100 && str_contains($idempotencyKey, ':repair:'), '자동 수정은 원래 교사·문제·라운드 키에 연결');
        verify($verificationReport['failures'] !== [] && ! isset($verificationReport['source']), '수정에는 실제 실패 사례를 전달');
        if ($this->failure) { throw new DomainException('AI 수정 연결 실패'); }
        if (is_callable($this->hook)) { ($this->hook)(); }
        $raw = rawCurriculum($lesson['language'], $lesson['support']['kind']);
        $raw['demo']['code'] = "x = 3\nprint(x)\n";
        return LessonSchema::normalize($raw, $lesson['language'], $lesson['support']['kind']);
    }
}
final class CurriculumExecutor implements LessonExecutor
{
    public bool $available = true;
    public bool $infrastructureFailure = false;
    public bool $pending = false;
    public array $badRounds = [];
    public array $requests = [];
    public mixed $hook = null;
    public function availability(): array { return ['available' => $this->available, 'languages' => ['python'], 'message' => 'OJ 채점기 미연결']; }
    public function execute(int $actor, string $language, string $source, string $input, string $key, ?string $ticket): array
    {
        if ($this->infrastructureFailure) { throw new DomainException('OJ 채점 서버 연결 실패'); }
        verify($actor === 10 && $language === 'python', '검증은 원래 교사의 선택 언어 실행');
        $id = hash('sha256', $key);
        if ($ticket === null) { $this->requests[$id] = ['key' => $key, 'source' => $source, 'input' => $input]; }
        else { verify($ticket === $id && isset($this->requests[$id]), '재조회는 저장한 개인 실행 ticket 재사용'); }
        if (is_callable($this->hook)) { ($this->hook)(); }
        if ($this->pending) { return ['status' => 'pending', 'ticket' => $id]; }
        preg_match('/:run:([0-9]+):/', $key, $match);
        $bad = in_array((int) ($match[1] ?? -1), $this->badRounds, true);
        return ['status' => 'completed', 'ticket' => $id, 'verdict' => $bad ? 'compile_error' : 'executed',
            'actual_output' => $bad ? '' : (str_contains($source, 'len(value)') ? "17\n" : "3\n"),
            'diagnostics' => $bad ? 'SyntaxError: fixture' : ''];
    }
}
function finishJob(GenerationService $service, int $id): array
{
    for ($step = 0; $step < 60; ++$step) {
        $job = $service->processForTeacher($id);
        if (in_array($job['status'], ['ready', 'failed'], true)) { return $job; }
    }
    throw new RuntimeException('Bounded fixture pipeline did not finish.');
}
$platform = new CurriculumPlatform(); $records = new CurriculumRecords(); $jobs = new CurriculumJobs(); $generator = new CurriculumGenerator();
$executor = new CurriculumExecutor();
$generation = new GenerationService($jobs, $records, $platform, $generator, $executor);
$input = ['context_type' => 'class', 'context_id' => 'class:1', 'target_problem_id' => 100, 'language' => 'python',
    'support_kind' => 'code_completion', 'request_key' => 'request-key-0001'];
$generator->available = false;
deny(fn () => $generation->start($input), 'AI 미설정은 자료 없이 실패하고 빈 활동을 만들지 않음');
verify($jobs->items === [] && $records->activities === [], '사용 불가 경로는 저장 부작용 없음');
$generator->available = true;
$executor->available = false;
deny(fn () => $generation->start($input), 'OJ 미설정은 생성 비용과 활동 저장 전에 차단');
$executor->available = true;
deny(fn () => $generation->start(array_replace($input, ['language' => 'java'])), '실행 불가한 언어의 생성 차단');
deny(fn () => $generation->start(array_replace($input, ['language' => 'invalid'])), '지원하지 않는 언어 거부');
deny(fn () => $generation->start(array_replace($input, ['request_key' => 'bad'])), '잘못된 요청 키 거부');
$job = $generation->start($input);
verify($job['status'] === 'queued' && $records->activities === [], '생성 대기와 실제 활동 생성 분리');
verify(! isset($job['fingerprint'], $job['lease_token'], $job['request_key_hash']), '교사 응답에서 내부 작업 자료를 제외');
$same = $generation->start(array_replace($input, ['request_key' => 'different-click-key']));
verify($same['id'] === $job['id'] && count($jobs->items) === 1, '내용이 같은 연속 클릭은 같은 작업으로 합침');
$platform->actor = 11;
deny(fn () => $generation->job($job['id']), '다른 담당 교사도 요청자 개인 작업을 볼 수 없음');
$platform->actor = 20;
deny(fn () => $generation->start($input), '학생은 AI 생성을 시작할 수 없음');
$platform->actor = 10;
$generator->hook = function () use ($generation, $job): void { verify($generation->processForTeacher($job['id'])['status'] === 'running', '진행 중 같은 작업의 중복 실행을 막음'); };
$generated = $generation->processForTeacher($job['id']);
verify($generated['status'] === 'queued' && $generated['phase'] === 'verifying' && $records->activities === [], 'AI 생성만으로 배포하지 않고 OJ 검증 대기');
verify(! isset($generated['lesson'], $generated['pipeline'], $generated['results']), '교사 작업 상태도 비공개 코드·실행증거를 제외');
$generator->hook = null;
$ready = finishJob($generation, $job['id']);
verify($ready['status'] === 'ready' && count($records->activities) === 1 && $generator->calls === 1, '생성 성공 시 자료와 활동을 한 번 연결');
verify($records->activities[1]['status'] === 'review' && $records->activities[1]['quality']['status'] === 'needs_review', '시험 사례가 하나인 생성 자료는 자동 게시 전 교사 검토로 보류');
$records->activities[1]['status'] = 'active'; // The teacher reviewed this fixture before learner access.
verify($records->activities[1]['support_problem_id'] === null && $records->activities[1]['demo_code'] === 'ai_generated', '문제·입출력 외에 별도 예제 문제를 요구하지 않음');
verify($ready['verification']['status'] === 'verified' && $ready['verification']['engine'] === 'oj'
    && $ready['verification']['case_count'] === 2 && $ready['verification']['repair_attempts'] === 0, '모든 도입·수정 사례를 통과한 서버 검증 메타데이터');
$generation->processForTeacher($job['id']);
verify($generator->calls === 1 && count($records->activities) === 1, '완료 작업의 재요청은 AI 비용과 중복 활동을 만들지 않음');
$generator->hook = null;
$service = new PrimmService($records, $platform);
verify(! str_contains(json_encode($service->teacherActivities('class', 'class:1')), 'HIDDEN_CODE_VALUE'), '교사 목록에도 완성 빈칸 응답과 내부 수업 스키마를 보내지 않음');
$platform->actor = 20;
$view = $service->activity(1);
$encoded = json_encode($view);
verify(! str_contains($encoded, 'HIDDEN_CODE_VALUE') && ! str_contains($encoded, 'FUTURE_MAKE_TASK'), '예측 단계에 숨은 도움 정답·미래 제작 자료를 노출하지 않음');
verify(! isset($view['demo']['expected_output'], $view['demo']['explanation']), '예측 저장 전에 결과와 설명을 제외');
verify($view['stages'][1] === ['key' => 'run', 'label' => 'run'], '미래 단계는 이름만 공개');
verify(! str_contains(json_encode($service->studentActivities()), 'HIDDEN_CODE_VALUE'), '학생 목록에서 완성 수업 자료를 제거');
deny(fn () => $service->support(1), '예측 단계의 직접 도움 API 접근 차단');
foreach (['predict', 'run', 'investigate'] as $version => $stage) {
    $view = $service->progress(1, ['expected_version' => $version, 'stage' => $stage, 'responses' => [$stage => '생각과 근거'], 'advance' => true]);
}
verify($view['demo']['expected_output'] === "3\n" && $view['demo']['explanation'] === 'EXPLANATION_ONLY_AFTER_PREDICT', '예측 이후 AI 제안 결과와 설명 공개');
verify(! isset($view['stages'][3]['starter_code']) && ! str_contains(json_encode($view), 'HIDDEN_CODE_VALUE'), '수정 단계도 완성 코드 대신 빈칸 템플릿만 제공');
$support = $service->support(1);
verify($support['blanks'][0]['id'] === 'value' && ! isset($support['blanks'][0]['answer']), '생성된 빈칸 자료의 공개 투영');
$assembled = $service->assembleSupport(1, ['template_hash' => $support['template_hash'], 'answers' => ['value' => '"student"']]);
verify(str_contains($assembled['source'], '"student"') && ! str_contains($assembled['source'], 'HIDDEN_CODE_VALUE'), '사용자 응답으로 복원하고 기준 정답을 돌려주지 않음');
verify(! isset($assembled['accepted']) && $assembled['verification']['status'] === 'not_executed', '복원 성공은 실행·채점 성공이 아님');
$platform->allowed = false;
deny(fn () => $service->assembleSupport(1, ['template_hash' => $support['template_hash'], 'answers' => ['value' => '"student"']]), '소속 해제 뒤 생성 도움 API를 차단');
$platform->allowed = true; $platform->actor = 10;
$failureJob = $generation->start(array_replace($input, ['support_kind' => 'none']));
$generator->failure = true;
verify($generation->processForTeacher($failureJob['id'])['status'] === 'failed' && count($records->activities) === 1, '실패한 AI 응답은 활동을 배정하지 않음');
$generator->failure = false;
verify($generation->retry($failureJob['id'])['status'] === 'queued', '명시적 재시도로 실패 상태 복구');
verify(finishJob($generation, $failureJob['id'])['status'] === 'ready', '재시도한 생성 결과를 검증 후 정상 저장');
$records->activities[2]['status'] = 'active';
$platform->actor = 20;
foreach (['predict', 'run', 'investigate'] as $version => $stage) {
    $noHelp = $service->progress(2, ['expected_version' => $version, 'stage' => $stage, 'responses' => [$stage => '근거'], 'advance' => true]);
}
verify($noHelp['stages'][3]['starter_code'] === $noHelp['demo']['code'], '도움 없는 수정은 도입 코드에서 시작');
verify(! str_contains(json_encode($noHelp), 'HIDDEN_CODE_VALUE'), '수정 완성 답안을 기본 초안으로 제공하지 않음');
$platform->actor = 10;
$changedJob = $generation->start(array_replace($input, ['support_kind' => 'code_ordering']));
$generator->hook = function () use ($platform): void { $platform->hash = 'problem-v2'; };
verify($generation->processForTeacher($changedJob['id'])['status'] === 'failed' && count($records->activities) === 2, 'AI 호출 중 원문이 바뀌면 낡은 활동을 저장하지 않음');
deny(fn () => $generation->retry($changedJob['id']), '바뀐 문제에는 이전 작업 재시도를 허용하지 않음');
$generator->hook = null;
$changedJob = $generation->start(array_replace($input, ['support_kind' => 'code_ordering']));
verify($changedJob['id'] !== 3, '바뀐 문제 해시로 새 생성 작업을 구분');
$generator->hook = function () use ($platform): void { $platform->allowed = false; };
verify($generation->processForTeacher($changedJob['id'])['status'] === 'failed' && count($records->activities) === 2, 'AI 응답 이후에도 교사 권한을 다시 확인');
$platform->allowed = true; $generator->hook = null;
$generation->retry($changedJob['id']);
$generator->hook = function () use ($platform): void { $platform->actor = 11; };
verify($generation->processForTeacher($changedJob['id'])['status'] === 'failed' && count($records->activities) === 2, 'AI 호출 중 계정 변경은 원래 교사의 활동으로 저장되지 않음');
$platform->actor = 10; $generator->hook = null;
$generation->retry($changedJob['id']);
$readyOrdering = finishJob($generation, $changedJob['id']);
verify($readyOrdering['status'] === 'ready' && count($records->activities) === 3, '배열 도움 옵션도 자동 자료와 함께 저장');
$old = $jobs->items[$changedJob['id']];
$jobs->items[$changedJob['id']]['status'] = 'running';
$jobs->items[$changedJob['id']]['lease_expires_at'] = '2000-01-01 00:00:00';
verify($generation->job($changedJob['id'])['status'] === 'queued', '유실된 작업 잠금 만료를 클라이언트 복구 가능 상태로 제공');
$jobs->items[$changedJob['id']] = $old;

$platform->actor = 10;
$beforeActivities = count($records->activities);
$beforeRepairs = $generator->repairs;
$beforeExecutions = count($executor->requests);
$executor->badRounds = [0];
$repairJob = $generation->start(array_replace($input, ['title' => '한 번 자동 수정']));
$repaired = finishJob($generation, $repairJob['id']);
verify($repaired['status'] === 'ready' && $generator->repairs === $beforeRepairs + 1, '실제 OJ 오류는 AI 자동 수정 후 재검증');
verify($repaired['verification']['repair_attempts'] === 1 && count($executor->requests) === $beforeExecutions + 5,
    '수정 후 도입·수정 전체 재실행과 수정 전 원본의 차이 검사까지 수행');
verify($records->activities[$repaired['activity_id']]['lesson']['demo']['code'] === "x = 3\nprint(x)\n", '실제 수정된 코드를 검증해서 저장');
verify(count($records->activities) === $beforeActivities + 1 && count($jobs->items[$repairJob['id']]['pipeline']['history']) === 1, '초안·실패 라운드는 배포 없이 비공개 이력으로 보관');

$executor->badRounds = [0, 1];
$twice = $generation->start(array_replace($input, ['title' => '두 번 자동 수정']));
$twiceReady = finishJob($generation, $twice['id']);
verify($twiceReady['status'] === 'ready' && $twiceReady['verification']['repair_attempts'] === 2, '자동 수정 최대 두 번 범위에서 성공');

$executor->badRounds = [0, 1, 2];
$exhausted = $generation->start(array_replace($input, ['title' => '계속 잘못된 코드']));
$beforeActivities = count($records->activities); $beforeRepairs = $generator->repairs;
$failed = finishJob($generation, $exhausted['id']);
verify($failed['status'] === 'failed' && $failed['activity_id'] === null && str_contains($failed['error'], '자동 수정 2회'), '최대 수정 소진은 명확히 실패하고 학생 배포 금지');
verify($generator->repairs === $beforeRepairs + 2 && count($records->activities) === $beforeActivities, '실패 반복에도 비용과 활동 생성은 상한 유지');
$executor->badRounds = [];

$infra = $generation->start(array_replace($input, ['title' => '채점기 장애']));
$generation->processForTeacher($infra['id']);
$beforeRepairs = $generator->repairs; $executor->infrastructureFailure = true;
$infraFailed = $generation->processForTeacher($infra['id']);
verify($infraFailed['status'] === 'failed' && $generator->repairs === $beforeRepairs && count($records->activities) === $beforeActivities,
    '채점기 연결 장애는 AI 코드 수정 대상으로 오인하지 않음');
$executor->infrastructureFailure = false;
$oldRunKey = $jobs->items[$infra['id']]['run_key'];
$generation->retry($infra['id']);
verify($jobs->items[$infra['id']]['pipeline'] === [] && $jobs->items[$infra['id']]['run_key'] !== $oldRunKey, '교사 재시도는 새 실행키와 빈 검증 이력으로 시작');
verify(finishJob($generation, $infra['id'])['status'] === 'ready', '서버 복구 후 명시적 재시도 성공');

$pendingJob = $generation->start(array_replace($input, ['title' => '채점 대기·화면 복구']));
$generation->processForTeacher($pendingJob['id']);
$executor->pending = true;
$pendingState = $generation->processForTeacher($pendingJob['id']);
$callsBeforeResume = $generator->calls; $requestsBeforeResume = count($executor->requests);
verify($pendingState['status'] === 'queued' && $pendingState['phase'] === 'verifying' && ! isset($pendingState['executions']), '채점 대기는 실패 없이 비공개 ticket 저장');
$reloaded = new GenerationService($jobs, $records, $platform, $generator, $executor);
$reloaded->processForTeacher($pendingJob['id']);
verify(count($executor->requests) === $requestsBeforeResume && $generator->calls === $callsBeforeResume, '페이지·worker 재생성 후 기존 ticket 재조회로 AI·채점 중복 방지');
$executor->pending = false;
verify(finishJob($reloaded, $pendingJob['id'])['status'] === 'ready', '대기 후 기존 파이프라인 이어서 완료');

$revoked = $generation->start(array_replace($input, ['title' => '검증 중 교사 권한 해제']));
$generation->processForTeacher($revoked['id']);
$beforeActivities = count($records->activities);
$executor->hook = function () use ($platform): void { $platform->allowed = false; };
verify($generation->processForTeacher($revoked['id'])['status'] === 'failed' && count($records->activities) === $beforeActivities, 'OJ 실행 뒤에도 권한 재검사하고 배포 차단');
$platform->allowed = true; $executor->hook = null;

$changedExecution = $generation->start(array_replace($input, ['title' => '검증 중 원문 수정']));
$generation->processForTeacher($changedExecution['id']);
$executor->hook = function () use ($platform): void { $platform->hash = 'problem-v3'; };
verify($generation->processForTeacher($changedExecution['id'])['status'] === 'failed' && count($records->activities) === $beforeActivities, 'OJ 실행 뒤 원문 변경도 배포 차단');
$executor->hook = null;

$draftChanged = $generation->start(array_replace($input, ['title' => '검증 후 코드 변경']));
for ($step = 0; $step < 10; ++$step) {
    $state = $generation->processForTeacher($draftChanged['id']);
    if ($state['phase'] === 'publishing') { break; }
}
verify($state['phase'] === 'publishing' && count($records->activities) === $beforeActivities, '완료 전에도 배포 증거 checkpoint 보존');
$jobs->items[$draftChanged['id']]['pipeline']['lesson']['demo']['code'] = "print('changed')\n";
verify($generation->processForTeacher($draftChanged['id'])['status'] === 'failed' && count($records->activities) === $beforeActivities, '검증 후 코드 변경은 과거 결과 해시로 배포 불가');

$transientJob = $generation->start(array_replace($input, ['title' => '일시 오류 뒤 자동 재개']));
$generator->transient = true; $callsBeforeTransient = $generator->calls;
$waiting = $generation->processForTeacher($transientJob['id']);
verify($waiting['status'] === 'retry_wait' && $waiting['retryable'] && $waiting['error_code'] === 'addon_ai_request_pending'
    && $waiting['next_attempt_at'] > gmdate('Y-m-d H:i:s'), '일시 공급자 오류는 typed 코드와 지수 backoff로 대기');
$generation->processForTeacher($transientJob['id']);
verify($generator->calls === $callsBeforeTransient + 1 && $jobs->items[$transientJob['id']]['pipeline'] === [], 'backoff 전 재조회는 AI를 다시 부르지 않으며 저장 상태를 유지');
$jobs->items[$transientJob['id']]['next_attempt_at'] = '2000-01-01 00:00:00'; $generator->transient = false;
verify(finishJob($generation, $transientJob['id'])['status'] === 'ready', '일시 오류가 사라지면 저장된 작업을 자동 재개');
verify($generator->keys[count($generator->keys) - 1] === $generator->keys[count($generator->keys) - 2], '자동 backoff 재시도는 같은 AI 멱등 키를 재사용');
$cancelJob = $generation->start(array_replace($input, ['title' => '교사가 취소하고 재개']));
$generation->processForTeacher($cancelJob['id']); $savedPipeline = $jobs->items[$cancelJob['id']]['pipeline']; $savedRun = $jobs->items[$cancelJob['id']]['run_key'];
$canceled = $generation->cancel($cancelJob['id']);
verify($canceled['status'] === 'canceled' && $canceled['can_resume'] && !$canceled['can_cancel'], '교사 취소는 재개 가능한 종료 상태로 표시');
verify($generation->processForTeacher($cancelJob['id'])['status'] === 'canceled', '취소한 작업은 worker가 다시 처리하지 않음');
$generation->resume($cancelJob['id']);
verify($jobs->items[$cancelJob['id']]['pipeline'] === $savedPipeline && $jobs->items[$cancelJob['id']]['run_key'] === $savedRun, '교사 재개는 새 AI 생성 없이 기존 예제와 실행 이력을 유지');
deny(fn () => $generation->stop($cancelJob['id']), '일반 교사는 관리자 중지 기능을 사용할 수 없음');
$GLOBALS['curriculum_admin'] = true; $platform->actor = 11;
$foreign = $generation->job($cancelJob['id']);
verify(!$foreign['can_process'] && !$foreign['can_cancel'], '관리자가 타인 작업을 조회해도 대신 AI 처리하거나 교사 취소를 하지 않음');
verify($generation->stop($cancelJob['id'])['status'] === 'stopped', '관리자는 타인 작업을 강제로 중지할 수 있음');
$platform->actor = 10; $GLOBALS['curriculum_admin'] = false;
deny(fn () => $generation->resume($cancelJob['id']), '관리자 중지를 교사가 우회해 재개하지 못함');
$GLOBALS['curriculum_admin'] = true; $platform->actor = 11; $generation->resume($cancelJob['id']);
$platform->actor = 10; $GLOBALS['curriculum_admin'] = false;
verify(finishJob($generation, $cancelJob['id'])['status'] === 'ready', '관리자가 해제한 작업은 원래 교사 권한으로 이어서 처리');
deny(fn () => $generation->overview(true), '전체 현황과 다른 교사 자료는 관리자만 조회');
verify(! str_contains(json_encode($generation->overview(false), JSON_THROW_ON_ERROR), 'HIDDEN_CODE_VALUE'), '생성 현황에는 예제 정답·원시 수업·실행 ticket을 공개하지 않음');

$profileValues = [];
$profileSettings = new \OjPrimm\Application\ClassSettingsService($platform, static function () use (&$profileValues): array { return $profileValues; }, static function (array $all) use (&$profileValues): void { $profileValues = $all; });
$profileSettings->save('class', 'class:1', ['language' => 'python', 'level' => 'advanced', 'minutes' => 90, 'prerequisites' => ['변수'], 'auto_prepare' => true, 'auto_publish' => false]);
$profileGeneration = new GenerationService($jobs, $records, $platform, $generator, $executor, $profileSettings);
$automation = new \OjPrimm\Application\ProblemPreparationAutomation($profileSettings, $profileGeneration, $platform);
verify($automation->handle(['problem_id' => 100, 'actor_user_id' => 11]) === [], '문제 저장 이벤트의 요청자와 현재 교사가 다르면 자동 준비하지 않음');
$automatic = $automation->handle(['problem_id' => 100, 'actor_user_id' => 10, 'changed_at' => '2026-10-25 01:00:00']);
verify(count($automatic) === 1 && $jobs->items[$automatic[0]['id']]['profile']['minutes'] === 90 && !$jobs->items[$automatic[0]['id']]['profile']['auto_publish'], '자동 준비는 현재 교사가 허용한 학급 설정과 비공개 선택을 사용');
$sameAutomatic = $automation->handle(['problem_id' => 100, 'actor_user_id' => 10, 'changed_at' => '2026-10-25 02:00:00']);
verify($sameAutomatic[0]['id'] === $automatic[0]['id'], '같은 문제 내용을 여러 번 저장해도 중복 생성하지 않음');
$sourceActivity = $records->activities[1]; $sourceActivity['id'] = 999; $sourceActivity['status'] = 'draft';
$sourceActivity['target_hash'] = $platform->hash; $sourceActivity['revision'] = 7; $records->activities[999] = $sourceActivity;
$callsBeforeValidation = $generator->calls; $repairsBeforeValidation = $generator->repairs;
$validation = $generation->validateActivity(999, ['request_key' => 'validation-only-draft-999']);
verify($jobs->items[$validation['id']]['draft_activity_id'] === 999 && $jobs->items[$validation['id']]['draft_revision'] === 7, '초안 검증은 편집 revision에 결합하여 저장');
verify(finishJob($generation, $validation['id'])['status'] === 'ready' && $generator->calls === $callsBeforeValidation && $generator->repairs === $repairsBeforeValidation, '교사가 편집한 자료 검증에서는 AI를 호출하지 않음');
$records->activities[999]['status'] = 'active';
$activeValidation = $generation->validateActivity(999, ['request_key' => 'validation-only-active-999']);
verify(!isset($jobs->items[$activeValidation['id']]['draft_activity_id']) && $jobs->items[$activeValidation['id']]['parent_activity_id'] === 999, '공개 자료 재검증은 원본을 덮어쓰지 않고 새 버전으로 보존');
$invalidValidation = $generation->validateActivity(999, ['request_key' => 'validation-only-bad-999']);
$executor->badRounds = [0];
verify(finishJob($generation, $invalidValidation['id'])['status'] === 'failed' && $generator->repairs === $repairsBeforeValidation, '검증 전용 작업의 잘못된 코드를 AI에 무단 전달해 수정하지 않음');
$executor->badRounds = [];
// A malformed initial provider draft is quarantined durably before one budgeted format recovery.
$beforeActivities = count($records->activities); $beforeExecutions = count($executor->requests);
$generator->invalidDraft = true; $repairsBeforeFormat = $generator->validationRepairs;
$formatJob = $generation->start(array_replace($input, ['title' => '최초 구조 불일치 복구', 'support_kind' => 'code_ordering']));
$formatPending = $generation->processForTeacher($formatJob['id']);
verify($formatPending['status'] === 'queued' && $formatPending['phase'] === 'validation_repair' && $formatPending['usage']['ai_calls'] === 1,
    '초기 구조 오류는 수동 재시도 없이 비공개 복구 checkpoint로 저장하며 최초 AI 호출을 계상');
verify(! str_contains(json_encode($formatPending, JSON_THROW_ON_ERROR), 'HIDDEN_CODE_VALUE') && ! isset($formatPending['candidate'])
    && count($executor->requests) === $beforeExecutions && count($records->activities) === $beforeActivities, '잘못된 초안은 OJ 실행·학생 배포·공개 상태 응답에 사용하지 않음');
$reloadedFormat = new GenerationService($jobs, $records, $platform, $generator, $executor);
$recoveredFormat = finishJob($reloadedFormat, $formatJob['id']);
verify($recoveredFormat['status'] === 'ready' && $recoveredFormat['usage']['ai_calls'] === 2 && $generator->validationRepairs === $repairsBeforeFormat + 1,
    '재접속한 worker가 한도에 포함된 별도 AI 호출 한 번으로 형식을 복구');
verify(count($records->activities) === $beforeActivities + 1 && count($executor->requests) > $beforeExecutions
    && $recoveredFormat['verification']['status'] === 'verified', '복구 자료도 모든 OJ 검증을 통과한 뒤에만 새 활동으로 저장');
verify($jobs->items[$formatJob['id']]['pipeline']['validation_attempt'] === 1 && ! isset($jobs->items[$formatJob['id']]['pipeline']['candidate']), '정상 초안으로 전환하면 잘못된 비공개 후보를 제거');
$executor->badRounds = [0, 1];
$combinedJob = $generation->start(array_replace($input, ['title' => '구조 1회와 실행 오류 2회 복구']));
$combinedReady = finishJob($generation, $combinedJob['id']);
verify($combinedReady['status'] === 'ready' && $combinedReady['usage']['ai_calls'] === 4
    && $combinedReady['verification']['repair_attempts'] === 2 && $jobs->items[$combinedJob['id']]['pipeline']['validation_attempt'] === 1,
    '초기 구조 복구 1회와 기존 OJ 오류 수정 2회를 별도로 제한하며 총 AI 네 호출을 계상');
$executor->badRounds = [];
$beforeActivities = count($records->activities); $beforeExecutions = count($executor->requests); $repairsBeforeFormat = $generator->validationRepairs;
$generator->invalidRecovery = true;
$formatFailed = $generation->start(array_replace($input, ['title' => '구조 복구 반복 오류']));
$failedFormat = finishJob($generation, $formatFailed['id']);
verify($failedFormat['status'] === 'failed' && $failedFormat['usage']['ai_calls'] === 2 && $generator->validationRepairs === $repairsBeforeFormat + 1,
    '복구 응답도 형식 오류면 두 번째 복구를 호출하지 않고 종료');
verify(count($records->activities) === $beforeActivities && count($executor->requests) === $beforeExecutions, '반복 구조 오류는 배포와 OJ 제출 없이 차단');
$generator->invalidRecovery = false;
$budgetJob = $generation->start(array_replace($input, ['title' => '구조 복구 AI 예산 한도']));
$generation->processForTeacher($budgetJob['id']); $jobs->aiLimit = 1; $repairsBeforeFormat = $generator->validationRepairs;
verify($generation->processForTeacher($budgetJob['id'])['status'] === 'failed' && $generator->validationRepairs === $repairsBeforeFormat,
    'AI 예산 소진은 형식 복구 호출 이전 reserveAi에서 차단');
$jobs->aiLimit = null;
$transientFormatJob = $generation->start(array_replace($input, ['title' => '구조 복구 연결 재개']));
$generation->processForTeacher($transientFormatJob['id']); $generator->recoveryTransient = true;
verify($generation->processForTeacher($transientFormatJob['id'])['status'] === 'retry_wait', '형식 복구 중 공급자 일시 장애도 durable 상태에서 backoff');
$firstRecoveryKey = $generator->keys[array_key_last($generator->keys)];
$jobs->items[$transientFormatJob['id']]['next_attempt_at'] = '2000-01-01 00:00:00'; $generator->recoveryTransient = false;
verify(finishJob($generation, $transientFormatJob['id'])['status'] === 'ready' && $generator->keys[array_key_last($generator->keys)] === $firstRecoveryKey,
    '일시 장애 후 같은 논리적 형식 복구 멱등 키로 재개');
$generator->invalidDraft = false;
echo 'PRIMM generation service checks passed: ' . $checks . "\n";

function current_user_can(string $capability): bool { return $GLOBALS['curriculum_admin'] ?? false; }
