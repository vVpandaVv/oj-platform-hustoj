<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/src/Core/Autoloader.php';
\OjPrimm\Core\Autoloader::register();

use OjPrimm\Application\ClassSettingsService;
use OjPrimm\Application\PlatformAccess;
use OjPrimm\Application\LessonVerification;
use OjPrimm\Generation\LessonTemplate;
use OjPrimm\Generation\LessonQuality;
use OjPrimm\Generation\LessonSchema;
use OjPrimm\Support\GenerationPolicy;

$checks = 0;
function curriculumCheck(bool $condition, string $message): void { global $checks; ++$checks; if (! $condition) { throw new RuntimeException($message); } }
function curriculumRejects(callable $work, string $message): void { try { $work(); } catch (DomainException) { curriculumCheck(true, $message); return; } curriculumCheck(false, $message); }
final class CurriculumSettingsPlatform implements PlatformAccess
{
    public bool $allowed = true;
    public function actorId(): int { return 10; }
    public function assertRole(string $role): void { if (! $this->allowed) { throw new DomainException('권한 없음'); } }
    public function contexts(bool $teaching): array { return [['type' => 'class', 'id' => 'class:1'], ['type' => 'class', 'id' => 'class:2']]; }
    public function authorize(string $type, string $id, string $operation, ?int $learnerId = null): array { if (! $this->allowed || $type !== 'class' || ! in_array($id, ['class:1', 'class:2'], true)) { throw new DomainException('소속 없음'); } return ['revision' => 'r1']; }
    public function searchProblems(string $search): array { return []; }
    public function assertSupport(int $problemId, string $kind, string $type, string $id): void {}
    public function problem(int $problemId, string $type, string $id, ?int $submissionId = null): array { return []; }
    public function problemUrl(int $problemId, string $type, string $id): string { return '/problem'; }
}
$platform = new CurriculumSettingsPlatform(); $stored = [];
$settings = new ClassSettingsService($platform, static function () use (&$stored): array { return $stored; }, static function (array $all) use (&$stored): void { $stored = $all; });
$defaults = $settings->get('class', 'class:1');
curriculumCheck($defaults['auto_publish'] && ! $defaults['auto_prepare'] && $defaults['minutes'] === 45 && $defaults['language'] === 'python', '기존 자동 게시 흐름을 유지하며 문제 저장 자동 준비는 명시 선택');
$saved = $settings->save('class', 'class:1', ['language' => 'cpp', 'level' => 'intermediate', 'prerequisites' => ['변수', '조건', '변수'], 'minutes' => 60,
    'support_kind' => 'code_completion', 'auto_prepare' => true, 'auto_publish' => false]);
curriculumCheck($saved['language'] === 'cpp' && $saved['default_language'] === 'cpp' && $saved['prerequisites'] === ['변수', '조건'], '언어 alias와 선수 개념 중복을 정규화');
curriculumCheck($settings->get('class', 'class:1') === $saved && $settings->get('class', 'class:2')['language'] === 'python', '학급별 설정을 분리하고 재조회 가능');
curriculumCheck(count($settings->automaticContexts()) === 1 && $settings->automaticContexts()[0]['id'] === 'class:1', '자동 준비를 허용한 담당 학급만 선택');
foreach ([['minutes' => 10], ['minutes' => '45'], ['level' => 'unknown'], ['prerequisites' => ['']], ['prerequisites' => str_repeat('x', 100)],
    ['auto_prepare' => '1'], ['unknown' => true], ['language' => 'ruby'], ['support_kind' => 'unknown']] as $bad) {
    curriculumRejects(fn () => $settings->save('class', 'class:1', $bad), '잘못된 학급 설정을 저장하지 않음');
}
curriculumRejects(fn () => $settings->get('class', 'class:99'), '담당하지 않는 학급 설정 조회 차단');
$platform->allowed = false;
curriculumRejects(fn () => $settings->save('class', 'class:1', []), '소속 철회 후 저장 차단');
curriculumRejects(fn () => $settings->automaticContexts(), '학생·비로그인 요청은 자동 준비 대상 열람 차단');
$platform->allowed = true;

foreach (LessonSchema::LANGUAGES as $language) {
    foreach (LessonSchema::KINDS as $kind) {
        $lesson = LessonTemplate::create($language, $kind);
        curriculumCheck(LessonSchema::validateNormalized($lesson, $language, $kind) === $lesson && $lesson['verification']['status'] === 'not_executed', '안전한 언어별 템플릿과 선택 도움의 코드 정합을 검사하며 실행 전 통과 표시 금지');
        curriculumCheck(count($lesson['stages'][3]['tests']) === 2 && $lesson['stages'][3]['tests'][1]['expected_output'] === "0\n", '안전한 템플릿에 정상·경계 입력을 모두 제공');
    }
}
$lesson = LessonTemplate::create('python', 'none'); $baseline = [];
foreach (LessonQuality::baselineCases($lesson) as $case) {
    $baseline[$case['case_id']] = LessonVerification::compare(['status' => 'completed', 'verdict' => 'executed', 'actual_output' => "7\n"], $case);
}
$quality = LessonQuality::assess($lesson, ClassSettingsService::defaults(), $baseline);
curriculumCheck($quality['status'] === 'passed' && $quality['baseline_case_count'] === 2 && $quality['baseline_failures'] === 1, '기준 코드의 기존 출력과 다른 사례가 있어야 수정 필요성을 확인');
$same = $baseline; foreach ($same as &$result) { $result['verdict'] = 'accepted'; } unset($result);
curriculumCheck(LessonQuality::assess($lesson, [], $same)['status'] === 'needs_review', '원본 코드로도 모두 통과하면 수정 과제 의미 검토 보류');
$crash = $baseline; $crash['baseline:1']['verdict'] = 'runtime_error';
curriculumCheck(LessonQuality::assess($lesson, [], $crash)['status'] === 'needs_review', '원본 실행 오류를 유효한 행동 차이로 오인하지 않음');
$single = $lesson; array_pop($single['stages'][3]['tests']);
curriculumCheck(LessonQuality::assess($single, [], $baseline)['status'] === 'needs_review', '시험 사례가 부족한 자료는 교사 검토');
$unsafe = $lesson; $unsafe['demo']['code'] = "import os\nos.system('echo unsafe')\n";
curriculumCheck(LessonQuality::assess($unsafe, [], $baseline)['status'] === 'needs_review', '시스템 실행 등 수업 범위 밖의 코드는 자동 게시 보류');
$long = $lesson; $long['demo']['code'] = str_repeat("print(1)\n", 35);
curriculumCheck(LessonQuality::assess($long, ['minutes' => 15, 'level' => 'beginner'], $baseline)['status'] === 'needs_review', '짧은 수업과 초보 학급에 과도한 코드 길이 검토');
curriculumCheck(LessonQuality::assess($long, ['minutes' => 90, 'level' => 'advanced'], $baseline)['status'] === 'passed', '수준과 시간에 맞는 범위는 과도하게 보류하지 않음');
$bounds = GenerationPolicy::sanitize(['teacher_active_limit' => 0, 'site_daily_ai_limit' => 999999, 'transient_retry_limit' => -1, 'paused' => '1']);
curriculumCheck($bounds['teacher_active_limit'] === 1 && $bounds['site_daily_ai_limit'] === 10000 && $bounds['transient_retry_limit'] === 0 && $bounds['paused'], '사이트 활성·호출·재시도 한도는 관리자 설정에서도 경계를 유지');
fwrite(STDOUT, 'PRIMM curriculum/settings/quality: ' . $checks . " checks passed. Template code was not executed in this test.\n");
