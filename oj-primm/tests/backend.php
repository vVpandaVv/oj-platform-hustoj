<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Core/Autoloader.php';
\OjPrimm\Core\Autoloader::register();

use OjPrimm\Application\PlatformAccess;
use OjPrimm\Application\PrimmService;
use OjPrimm\Storage\Repository;

$checks = 0;
function check(bool $condition, string $message): void {
    global $checks;
    ++$checks;
    if (! $condition) { throw new RuntimeException($message); }
}
function rejects(callable $work, string $message): void {
    $rejected = false;
    try { $work(); } catch (DomainException) { $rejected = true; }
    check($rejected, $message);
}

final class MemoryRecords implements Repository
{
    public array $activities = [];
    public array $attempts = [];
    public array $events = [];
    public bool $failWrite = false;
    public function createActivity(array $data): array {
        $data['id'] = count($this->activities) + 1;
        return $this->activities[$data['id']] = $data;
    }
    public function findActivity(int $id): ?array { return $this->activities[$id] ?? null; }
    public function activitiesForContext(string $type, string $id): array {
        return array_values(array_filter($this->activities, static fn ($a) => $a['context_type'] === $type && $a['context_id'] === $id));
    }
    public function findAttempt(int $activityId, int $userId): ?array { return $this->attempts[$activityId . ':' . $userId] ?? null; }
    public function saveAttempt(int $activityId, int $userId, int $expectedVersion, array $state, array $event, string $requestHash): array {
        $key = $activityId . ':' . $userId;
        $current = $this->attempts[$key] ?? null;
        if (($current['version'] ?? 0) !== $expectedVersion) { throw new DomainException('버전 충돌'); }
        if ($this->failWrite) { throw new RuntimeException('저장 장애'); }
        $data = ['id' => $current['id'] ?? count($this->attempts) + 1, 'activity_id' => $activityId, 'user_id' => $userId,
            'version' => $expectedVersion + 1, 'last_request_hash' => $requestHash,
            'created_at' => $current['created_at'] ?? '2026-10-24 01:00:00', 'updated_at' => '2026-10-24 01:00:00'] + $state;
        $this->events[] = ['user_id' => $userId, 'attempt_id' => $data['id']] + $event;
        return $this->attempts[$key] = $data;
    }
    public function attemptsForActivities(array $activityIds): array { return array_values(array_filter($this->attempts, static fn ($a) => in_array($a['activity_id'], $activityIds, true))); }
    public function exportUser(int $userId, int $page = 1): array { return ['done' => true, 'activities' => [], 'attempts' => array_values(array_filter($this->attempts, static fn ($a) => $a['user_id'] === $userId)), 'events' => []]; }
    public function eraseUser(int $userId): bool {
        $this->attempts = array_filter($this->attempts, static fn ($a) => $a['user_id'] !== $userId);
        $this->events = array_filter($this->events, static fn ($a) => $a['user_id'] !== $userId);
        return true;
    }
    public function purgeBefore(string $cutoff): int { return 0; }
}
final class FakePlatform implements PlatformAccess
{
    public int $actor = 10;
    public array $members = [20, 21];
    public int $authorizeCount = 0;
    public ?int $revokeAt = null;
    public array $problems;
    public array $submissions = [];
    public function __construct() {
        foreach ([100, 200, 201] as $id) {
            $this->problems[$id] = ['id' => $id, 'title' => '문제 ' . $id, 'description' => '두 수를 더합니다.',
                'input' => '정수', 'output' => '합', 'sample_input' => '1 2', 'sample_output' => '3', 'content_hash' => hash('sha256', (string) $id)];
        }
    }
    public function actorId(): int { if ($this->actor < 1) { throw new DomainException('로그인 필요'); } return $this->actor; }
    public function assertRole(string $role): void {
        $this->actorId();
        if (($role === 'teach' && $this->actor !== 10) || ($role === 'learn' && ! in_array($this->actor, [20, 21], true))) { throw new DomainException('역할 권한 없음'); }
    }
    public function contexts(bool $teaching): array { return [['type' => 'class', 'id' => 'class:1', 'label' => '1반', 'revision' => 'rev1']]; }
    public function authorize(string $type, string $id, string $operation, ?int $learnerId = null): array {
        ++$this->authorizeCount;
        if ($this->revokeAt === $this->authorizeCount) { $this->members = array_values(array_diff($this->members, [$this->actor])); }
        if ($type !== 'class' || $id !== 'class:1') { throw new DomainException('다른 수업 접근 금지'); }
        if ($operation === 'participate') {
            if ($learnerId !== $this->actor || ! in_array($this->actor, $this->members, true)) { throw new DomainException('학생 소속 없음'); }
        } elseif ($this->actor !== 10 || ($learnerId !== null && ! in_array($learnerId, $this->members, true))) { throw new DomainException('담당 범위 없음'); }
        return ['type' => $type, 'id' => $id, 'label' => '1반', 'revision' => 'rev1'];
    }
    public function searchProblems(string $search): array { return array_map(static fn ($p) => ['id' => $p['id'], 'title' => $p['title']], array_values($this->problems)); }
    public function assertSupport(int $problemId, string $kind, string $contextType, string $contextId): void {
        if (($problemId === 200 && $kind === 'code_ordering') || ($problemId === 201 && $kind === 'code_completion')) { return; }
        throw new DomainException('지원 문제 방식 불일치');
    }
    public function problem(int $problemId, string $contextType, string $contextId, ?int $submissionId = null): array {
        if (! isset($this->problems[$problemId])) { throw new DomainException('문제 접근 금지'); }
        $submission = null;
        if ($submissionId !== null) {
            $submission = $this->submissions[$submissionId] ?? null;
            if ($submission === null || $submission['owner'] !== $this->actor || $submission['problem_id'] !== $problemId) { throw new DomainException('본인 제출 아님'); }
            unset($submission['owner']);
        }
        return ['problem' => $this->problems[$problemId], 'submission' => $submission];
    }
    public function problemUrl(int $problemId, string $contextType, string $contextId): string { return '/problems/' . $problemId . '?class_id=1'; }
}

$db = new MemoryRecords();
$host = new FakePlatform();
$service = new PrimmService($db, $host);
check(count($service->teachingContexts()) === 1, '담당 수업 조회');
check(count($service->searchProblems()) === 3, '실제 문제 선택 목록');
$activity = $service->saveActivity(['title' => '합 학습', 'context_type' => 'class', 'context_id' => 1,
    'target_problem_id' => 100, 'support_kind' => 'code_ordering', 'support_problem_id' => 200, 'demo_code' => 'sum']);
check($activity['id'] === 1 && $activity['context_id'] === 'class:1', '활동 영구 저장과 맥락 정규화');
check($activity['support_problem_id'] === 200 && ! $activity['support_is_target'], '목표와 지원 문제 분리');
$none = $service->saveActivity(['context_type' => 'class', 'context_id' => 'class:1', 'target_problem_id' => 100, 'support_kind' => 'none']);
check($none['support_problem_id'] === null, '도움 없음 활동 저장');
$completion = $service->saveActivity(['context_type' => 'class', 'context_id' => 1, 'target_problem_id' => 100, 'support_kind' => 'code_completion', 'support_problem_id' => 201]);
check($completion['support_kind'] === 'code_completion', '빈칸 도움 활동 저장');
rejects(fn () => $service->saveActivity(['context_type' => 'class', 'context_id' => 2, 'target_problem_id' => 100]), '타 수업 배정 차단');
rejects(fn () => $service->saveActivity(['context_type' => 'class', 'context_id' => 1, 'target_problem_id' => 100, 'support_kind' => 'code_completion', 'support_problem_id' => 200]), '잘못된 지원 방식 차단');
rejects(fn () => $service->saveActivity(['context_type' => 'class', 'context_id' => 1, 'target_problem_id' => 100, 'demo_code' => 'print(secret)']), '검증되지 않은 임의 예제를 검증된 출력으로 꾸미지 않음');
rejects(fn () => $service->saveActivity(['context_type' => 'class', 'context_id' => 1, 'target_problem_id' => 100, 'demo_code' => 'none']), '도입 예제가 없는 영구 대기 활동을 배정하지 않음');
check($service->activity(1)['read_only'], '교사 미리보기 읽기 전용');
$host->actor = 20;
rejects(fn () => $service->teacherActivities('class', 'class:1'), '학생의 교사 보고서 접근 차단');
check(count($service->studentActivities()) === 3, '학생 소속 활동 목록');
$view = $service->activity(1);
check($view['attempt']['version'] === 0 && count($db->attempts) === 0, '읽기는 시도를 생성하지 않음');
check(! isset($view['demo']['expected_output']), '예측 전에 데모 정답 미전송');
rejects(fn () => $service->progress(1, ['expected_version' => 0, 'stage' => 'make', 'responses' => ['make' => '완료'], 'advance' => true]), '단계 건너뛰기 차단');
$predict = ['expected_version' => 0, 'stage' => 'predict', 'responses' => ['predict' => '999'], 'advance' => true];
$view = $service->progress(1, $predict);
check($view['attempt']['current_stage'] === 'run', '예측 오답도 실행 확인으로 진급');
check($view['attempt']['first_prediction'] === '999', '최초 제출 예측 보존');
check($view['demo']['expected_output'] === "6\n", '실행 확인 단계에서 검증된 결과 공개');
$eventCount = count($db->events);
$retry = $service->progress(1, $predict);
check($retry['attempt']['version'] === 1 && count($db->events) === $eventCount, '재시도 요청은 중복 전이·이력을 만들지 않음');
rejects(fn () => $service->progress(1, ['expected_version' => 0, 'stage' => 'run']), '두 탭 버전 충돌 차단');
rejects(fn () => $service->progress(1, ['expected_version' => 1, 'stage' => 'run', 'advance' => true]), '빈 관찰 응답 진급 차단');
rejects(fn () => $service->progress(1, ['expected_version' => 1, 'stage' => 'run', 'responses' => ['make' => '우회']]), '다른 단계 응답 쓰기 차단');
$view = $service->progress(1, ['expected_version' => 1, 'stage' => 'run', 'responses' => ['run' => '예측은 999, 관찰은 6이라 달랐다.'], 'advance' => true]);
$view = $service->progress(1, ['expected_version' => 2, 'stage' => 'investigate', 'responses' => ['investigate' => 'total은 반복마다 현재 원소를 더한다.'], 'advance' => true]);
check($view['attempt']['current_stage'] === 'modify', '탐구 응답과 진급');
$view = $service->progress(1, ['expected_version' => 3, 'stage' => 'modify', 'responses' => ['modify' => '값과 순서를 바꿔 보았다.'],
    'draft_code' => 'print(3)', 'support_state' => '{"ids":["opaque-b","opaque-a"]}']);
$resumed = (new PrimmService($db, $host))->activity(1);
check($resumed['attempt']['draft_code'] === 'print(3)' && $resumed['attempt']['support_state'] === '{"ids":["opaque-b","opaque-a"]}', '재접속 시 초안과 지원 조각 상태 복원');
$view = $service->progress(1, ['expected_version' => 4, 'stage' => 'modify', 'advance' => true]);
check($view['attempt']['current_stage'] === 'make', '수정 과정에서 제작으로 진급');
$host->submissions = [
    900 => ['owner' => 21, 'id' => 900, 'problem_id' => 100, 'result_code' => 4, 'processing' => false],
    901 => ['owner' => 20, 'id' => 901, 'problem_id' => 200, 'result_code' => 4, 'processing' => false],
    902 => ['owner' => 20, 'id' => 902, 'problem_id' => 100, 'result_code' => 0, 'processing' => true],
    903 => ['owner' => 20, 'id' => 903, 'problem_id' => 100, 'result_code' => 6, 'processing' => false],
    904 => ['owner' => 20, 'id' => 904, 'problem_id' => 100, 'result_code' => 4, 'processing' => false],
];
foreach ([900, 901] as $invalid) { rejects(fn () => $service->progress(1, ['expected_version' => 5, 'stage' => 'make', 'submission_id' => $invalid]), '타인·다른 문제 제출 차단'); }
$view = $service->progress(1, ['expected_version' => 5, 'stage' => 'make', 'responses' => ['make' => '독립적으로 다시 작성했다.'], 'submission_id' => 902, 'is_accepted' => true, 'result_code' => 4]);
check(! $view['attempt']['is_accepted'] && $view['attempt']['result_code'] === 0, '클라이언트 AC 대신 실제 채점 대기 결과 사용');
$view = $service->progress(1, ['expected_version' => 6, 'stage' => 'make', 'submission_id' => 903, 'advance' => true]);
check($view['attempt']['status'] === 'process_complete' && ! $view['attempt']['is_accepted'], '자가 과정 완료와 기능 정답 구분');
$view = $service->progress(1, ['expected_version' => 7, 'stage' => 'make', 'submission_id' => 904]);
check($view['attempt']['is_accepted'] && $view['attempt']['result_code'] === 4, '본인 목표 문제의 실제 AC 확인');
check($view['attempt']['submission_provenance'] === 'verified_own_submission', 'PRIMM에서 생성된 제출이라고 오인시키지 않음');
check($view['attempt']['first_prediction'] === '999', '최종 단계에도 최초 예측 유지');
$host->actor = 21;
check($service->activity(1)['attempt']['version'] === 0, '다른 학생은 타인의 기록을 열람하지 못함');
$host->actor = 10;
check(count($service->teacherActivities('class', 'class:1')['attempts']) === 1, '담당 교사가 학습 기록 조회');
$host->members = [21];
check(count($service->teacherActivities('class', 'class:1')['attempts']) === 0, '현재 명단에서 빠진 학생의 보고서 제한');
$host->actor = 20;
rejects(fn () => $service->activity(1), '소속 해제 후 기록 열람 제한');
rejects(fn () => $service->progress(1, ['expected_version' => 8, 'stage' => 'make']), '소속 해제 후 저장 제한');
$host->members = [20, 21];
$host->problems[100]['content_hash'] = hash('sha256', 'changed');
check($service->activity(1)['stale'], '문제 본문 변경 감지');
rejects(fn () => $service->progress(1, ['expected_version' => 8, 'stage' => 'make']), '변경된 원문으로 조용히 진행하지 않음');
$host->problems[100]['content_hash'] = hash('sha256', '100');
$host->revokeAt = $host->authorizeCount + 2;
rejects(fn () => $service->progress(1, ['expected_version' => 8, 'stage' => 'make', 'responses' => ['make' => '저장 사이 소속 변경']]), '저장 직전 소속 재검사');
check($db->findAttempt(1, 20)['version'] === 8, '권한 철회 중 저장하지 않음');
$host->members = [20, 21]; $host->revokeAt = null;
rejects(fn () => $service->progress(1, ['expected_version' => 8, 'stage' => 'make', 'draft_code' => str_repeat('x', 65537)]), '초안 용량 제한');
check(count($db->exportUser(20)['attempts']) === 1, '개인별 저장 기록 export');
$db->eraseUser(20);
check($db->findAttempt(1, 20) === null && $db->findActivity(1) !== null, '개인 응답 삭제와 수업 활동 보존');
$host->actor = 0;
rejects(fn () => $service->studentActivities(), '로그아웃 요청 거부');

$primmOptions = [];
$primmActions = [];
function get_option(string $name, mixed $default = false): mixed { global $primmOptions; return $primmOptions[$name] ?? $default; }
function delete_option(string $name): bool { global $primmOptions; unset($primmOptions[$name]); return true; }
function get_current_user_id(): int { return 10; }
function do_action(string $name, mixed ...$arguments): void { global $primmActions; $primmActions[] = [$name, $arguments]; }
function wp_die(string $message): never { throw new DomainException($message); }
function current_user_can(string $capability): bool { return $GLOBALS['primm_settings_allowed'] ?? true; }
function register_setting(string $group, string $name, array $definition): void { $GLOBALS['primm_registered_settings'][$name] = [$group, $definition]; }
function settings_fields(string $group): void { echo '<input type="hidden" name="_wpnonce" value="settings-test">'; }
function checked(mixed $value, mixed $expected, bool $echo = true): string { return $value === $expected ? ' checked' : ''; }
function submit_button(string $label): void { echo '<button type="submit">' . $label . '</button>'; }
function esc_html(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function esc_attr(string $value): string { return esc_html($value); }
\OjPrimm\Support\Settings::registerOptions();
check(count($GLOBALS['primm_registered_settings']) === 3 && $GLOBALS['primm_registered_settings']['oj_primm_retention_days'][0] === 'oj_primm'
    && isset($GLOBALS['primm_registered_settings']['oj_primm_generation_policy']), 'WordPress Settings API에 보관·삭제·생성 한도 설정 등록');
ob_start(); \OjPrimm\Support\Settings::render(); $settingsHtml = ob_get_clean();
check(str_contains($settingsHtml, 'options.php') && str_contains($settingsHtml, '_wpnonce') && str_contains($settingsHtml, 'oj_primm_delete_on_uninstall'), '설정 화면의 표준 저장·nonce·삭제 옵션');
check(str_contains($settingsHtml, 'teacher_daily_ai_limit') && str_contains($settingsHtml, '[paused]'), '관리자가 호출 예산과 사이트 생성 일시 중지를 설정');
$GLOBALS['primm_settings_allowed'] = false;
rejects(fn () => \OjPrimm\Support\Settings::render(), '설정 화면 관리자 권한 확인');
$GLOBALS['primm_settings_allowed'] = true;
check(\OjPrimm\Support\Settings::retentionDays() === 0 && ! \OjPrimm\Support\Settings::deleteOnUninstall(), '기본 무기한 기록 보존과 삭제 보존');
check(\OjPrimm\Support\Settings::sanitizeRetention('99999') === 3650, '보관 기간 상한');
check(\OjPrimm\Support\Settings::sanitizeRetention(['malformed']) === 0, '잘못된 설정 안전한 기본값');
$primmOptions['oj_primm_delete_on_uninstall'] = '1';
check(\OjPrimm\Support\Settings::deleteOnUninstall(), 'WordPress에 문자열로 저장된 명시 삭제 옵션 인식');
\OjPrimm\Support\Settings::updated('oj_primm_retention_days', 0, 30);
check($primmActions[0][0] === 'oj_primm_settings_changed', '설정 변경 감사 이벤트');
rejects(fn () => \OjPrimm\Core\Plugin::activate(true), '사이트별 설치만 허용하고 네트워크 활성화 거부');
$wpdb = new class {
    public string $prefix = 'test_';
    public array $queries = [];
    public function query(string $query): int { $this->queries[] = $query; return 1; }
};
define('WP_UNINSTALL_PLUGIN', true);
$primmOptions['oj_primm_delete_on_uninstall'] = false;
require dirname(__DIR__) . '/uninstall.php';
check($wpdb->queries === [], '일반 플러그인 삭제에서 학습 테이블 보존');
$primmOptions['oj_primm_delete_on_uninstall'] = '1';
require dirname(__DIR__) . '/uninstall.php';
check(count($wpdb->queries) === 6 && ! isset($primmOptions['oj_primm_delete_on_uninstall']), '명시 삭제 옵션에서 PRIMM 전용 테이블만 정리');
require_once dirname(__DIR__, 2) . '/oj-integration-rebuild/oj-platform/src/Kernel/Autoloader.php';
\CodingPanda\OjPlatform\Kernel\Autoloader::register(dirname(__DIR__, 2) . '/oj-integration-rebuild/oj-platform');
$keys = \OjPrimm\Support\LearnerCapabilities::unprivileged([]);
check($keys === ['oj_addon_oj_primm_learn'], '학습자 본인 참여 권한만 운영계정 분류 예외에 등록');
rejects(fn () => (new \OjPrimm\Support\PlatformGateway(static fn () => null))->assertRole('teach'), '플랫폼 runtime 누락 시 안전한 안내로 거부');

fwrite(STDOUT, 'OJ PRIMM backend: ' . $checks . " checks passed.\n");
