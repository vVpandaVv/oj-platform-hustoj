<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Core/Autoloader.php';
\OjPrimm\Core\Autoloader::register();

use OjPrimm\Application\LessonVerification;
use OjPrimm\Generation\LessonSchema;

$checks = 0;
function verificationCheck(bool $condition, string $message): void
{
    global $checks; ++$checks;
    if (! $condition) { throw new RuntimeException($message); }
}
function verificationRejects(callable $work, string $message): void
{
    try { $work(); } catch (DomainException) { verificationCheck(true, $message); return; }
    verificationCheck(false, $message);
}
function verificationFixture(): array
{
    $stages = [];
    foreach (['predict', 'run', 'investigate', 'modify', 'make'] as $key) {
        $stages[] = ['key' => $key, 'label' => $key, 'prompt' => $key . ' 단계의 구체적인 안내', 'questions' => [$key . ' 단계에서 어떤 변화가 생길까요?']];
    }
    $stages[3] += ['task' => '입력한 0 이상의 정수에 1을 더해 출력하세요.', 'starter_code' => "n = int(input())\nprint(n + 1)\n",
        'tests' => [['input' => "2\n", 'expected_output' => "3\n"], ['input' => "0\n", 'expected_output' => "1\n"]]];
    return LessonSchema::normalize(['schema_version' => 1, 'language' => 'python', 'title' => '인사와 정수 출력', 'objectives' => ['예상 출력과 실행 결과를 비교한다.'],
        'demo' => ['code' => "print('안녕')\n", 'input' => '', 'expected_output' => "안녕\n", 'explanation' => '문자열과 줄바꿈을 출력한다.'],
        'stages' => $stages, 'support' => ['kind' => 'none'], 'verification' => ['status' => 'not_executed', 'note' => '초안']], 'python', 'none');
}
function executed(string $stdout, string $verdict = 'executed', mixed $diagnostics = ''): array
{
    return ['status' => 'completed', 'verdict' => $verdict, 'actual_output' => $stdout, 'diagnostics' => $diagnostics];
}

$lesson = verificationFixture();
$cases = LessonVerification::cases($lesson);
verificationCheck(array_column($cases, 'case_id') === ['demo', 'modify:0', 'modify:1'], '도입 예제와 정상·경계 수정 사례를 모두 독립 검증');
verificationCheck(array_column($cases, 'phase') === ['demo', 'modify', 'modify'], '원래 단계와 사례 번호 유지');
verificationCheck($cases[0]['source'] === $lesson['demo']['code'] && $cases[1]['source'] === $cases[2]['source']
    && $cases[1]['source'] === $lesson['stages'][3]['starter_code'], '수정 시험 사례마다 완전한 수정 기준 코드 사용');
verificationCheck(array_column($cases, 'language') === ['python', 'python', 'python'], '실행 증거는 언어에도 결합');
verificationCheck($cases[1]['input'] === "2\n" && $cases[2]['input'] === "0\n" && $cases[2]['expected_output'] === "1\n", '빈 입력과 여러 개의 입력·예상 출력을 원문 그대로 유지');

$demo = $cases[0];
$passed = LessonVerification::compare(executed("안녕\n"), $demo);
verificationCheck($passed['verdict'] === 'accepted' && $passed['actual_output'] === "안녕\n", '동일 UTF-8 출력만 통과');
verificationCheck(preg_match('/^[a-f0-9]{64}$/D', $passed['case_hash']) === 1 && preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/D', $passed['checked_at']) === 1, '결과에는 서버 사례 지문과 검증 시각 기록');
foreach (["안녕\r\n", "안녕\r"] as $transportNewline) {
    verificationCheck(LessonVerification::compare(executed($transportNewline), $demo)['verdict'] === 'accepted', '전송 줄바꿈 CRLF와 CR만 LF로 정규화');
}
foreach (['안녕', "안녕 \n", " 안녕\n", "안녕\n\n", "안녕\t\n", "\xEF\xBB\xBF안녕\n"] as $different) {
    verificationCheck(LessonVerification::compare(executed($different), $demo)['verdict'] === 'wrong_answer', '마지막 줄바꿈·공백·빈 줄·탭·BOM을 임의로 정답 처리하지 않음');
}
$empty = $demo; $empty['expected_output'] = '';
verificationCheck(LessonVerification::compare(executed(''), $empty)['verdict'] === 'accepted'
    && LessonVerification::compare(executed("\n"), $empty)['verdict'] === 'wrong_answer', '정상적인 빈 출력과 줄바꿈 한 개 구별');
$nul = LessonVerification::compare(executed("안\0녕\n"), $demo);
verificationCheck($nul['verdict'] === 'wrong_answer' && $nul['actual_output'] === $demo['expected_output'], 'NUL 제거 전 실제 bytes로 판정하여 진단 정제로 오답이 통과하지 않음');
$invalidUtf8 = LessonVerification::compare(executed("\xFF\n"), $demo);
verificationCheck($invalidUtf8['verdict'] === 'wrong_answer' && preg_match('//u', $invalidUtf8['actual_output']) === 1
    && ! str_contains($invalidUtf8['actual_output'], "\xFF"), '읽을 수 없는 UTF-8도 오답으로 판정하고 AI에는 안전한 문자열만 전달');
$longOutput = LessonVerification::compare(executed(str_repeat('한', 6000), 'executed', str_repeat('글', 6000)), $demo);
verificationCheck($longOutput['verdict'] === 'wrong_answer' && strlen($longOutput['actual_output']) <= 16384
    && strlen($longOutput['diagnostics']) <= 16384 && preg_match('//u', $longOutput['actual_output']) === 1
    && preg_match('//u', $longOutput['diagnostics']) === 1, '장문 출력·진단은 UTF-8 중간을 자르지 않고 제한하며 비교는 전체 출력으로 수행');
verificationCheck(LessonVerification::compare(executed('', 'runtime_error', ['unexpected' => 'map']), $demo)['diagnostics'] !== '', '문자열이 아닌 진단을 배열로 AI에 보내지 않음');
foreach (['compile_error', 'runtime_error', 'time_limit', 'memory_limit', 'output_limit', 'output_incomplete'] as $verdict) {
    verificationCheck(LessonVerification::compare(executed($demo['expected_output'], $verdict, '진단'), $demo)['verdict'] === $verdict,
        '예상 stdout과 같더라도 비정상 종료·제한·불완전 출력은 통과하지 않음: ' . $verdict);
}
foreach ([
    ['status' => 'running', 'verdict' => 'executed', 'actual_output' => $demo['expected_output']],
    ['status' => 'failed', 'verdict' => 'infrastructure_error', 'actual_output' => ''],
    ['status' => 'completed', 'verdict' => 'accepted', 'actual_output' => ''],
    ['status' => 'completed', 'verdict' => 'executed'],
    ['status' => 'completed', 'verdict' => 'executed', 'actual_output' => []],
] as $notExecutionResult) {
    verificationRejects(fn () => LessonVerification::compare($notExecutionResult, $demo), '미완료·연결 오류·불명 판정·누락 결과로 실행 성공을 꾸미지 않음');
}

$results = [];
foreach ($cases as $case) { $results[$case['case_id']] = LessonVerification::compare(executed($case['expected_output']), $case); }
verificationCheck(LessonVerification::feedback($lesson, $results, 1) === ['attempt' => 1, 'failures' => []], '모두 통과하면 불필요한 AI 수정 진단이 없음');
$metadata = LessonVerification::metadata($lesson, $results, 2);
verificationCheck($metadata['status'] === 'verified' && $metadata['engine'] === 'oj' && $metadata['case_count'] === 3
    && $metadata['repair_attempts'] === 2 && $metadata['source_hash'] === LessonVerification::lessonHash($lesson), '모든 결과가 통과했을 때만 서버 검증 메타데이터 발급');
verificationCheck(! isset($metadata['source'], $metadata['actual_output'], $metadata['tests']) && str_contains($metadata['note'], '제공된 모든 시험 사례'), '검증 문구는 제공 사례 통과 범위를 명시하고 기준 코드와 출력 증거를 공개하지 않음');

$mixed = $results;
$mixed['modify:0'] = LessonVerification::compare(executed("4\n", 'executed', '결과를 확인하세요.'), $cases[1]);
$feedback = LessonVerification::feedback($lesson, $mixed, 2);
verificationCheck($feedback['attempt'] === 2 && count($feedback['failures']) === 1 && $feedback['failures'][0]['case_id'] === 'modify:0', '실패한 사례만 번호와 재검증 횟수를 유지해 수정 요청에 전달');
verificationCheck($feedback['failures'][0]['input'] === "2\n" && $feedback['failures'][0]['expected_output'] === "3\n"
    && $feedback['failures'][0]['actual_output'] === "4\n" && $feedback['failures'][0]['verdict'] === 'wrong_answer', '실패 사례의 입력·예상·실제 결과를 함께 전달해 의미 있는 수정을 지원');
verificationCheck(array_keys($feedback['failures'][0]) === ['case_id', 'phase', 'input', 'expected_output', 'actual_output', 'verdict', 'runtime_error'], 'AI 수정에는 source·언어·서버 해시·시각 등 실행 메타를 중복 전달하지 않음');
verificationRejects(fn () => LessonVerification::metadata($lesson, $mixed, 0), '실패 사례가 한 개라도 있으면 검증 완료로 배포하지 않음');
$compile = $results;
$compile['demo'] = LessonVerification::compare(executed('', 'compile_error', 'SyntaxError: invalid syntax'), $demo);
$compileFailure = LessonVerification::feedback($lesson, $compile, 1)['failures'][0];
verificationCheck(isset($compileFailure['compiler_output']) && ! isset($compileFailure['runtime_error'])
    && $compileFailure['compiler_output'] === 'SyntaxError: invalid syntax', '컴파일 오류 진단과 실행 오류 진단을 구분');
$incomplete = $results;
$incomplete['demo'] = LessonVerification::compare(executed($demo['expected_output'], 'output_incomplete', '완료 표식 없음'), $demo);
verificationCheck(LessonVerification::feedback($lesson, $incomplete, 1)['failures'][0]['verdict'] === 'output_incomplete', '일치한 부분 출력만으로 완전한 실행을 인정하지 않음');
verificationRejects(fn () => LessonVerification::metadata($lesson, $incomplete, 0), '완료 표식이 없는 결과로 검증 증거를 발급하지 않음');
$missing = $results; unset($missing['modify:1']);
verificationRejects(fn () => LessonVerification::feedback($lesson, $missing, 1), '경계 사례 결과 누락 시 AI 수정이나 배포 전에 검증 실패');
verificationRejects(fn () => LessonVerification::metadata($lesson, $missing, 0), '일부 사례 통과를 전체 검증 성공으로 표시하지 않음');
$forged = $results; $forged['demo']['case_hash'] = str_repeat('0', 64);
verificationRejects(fn () => LessonVerification::metadata($lesson, $forged, 0), '다른 코드·입출력에서 얻은 판정의 해시 위조 거부');
$forged = $results; $forged['demo']['verdict'] = 'executed';
verificationRejects(fn () => LessonVerification::feedback($lesson, $forged, 1), '비교를 거치지 않은 runner 실행 판정을 수업 통과 판정으로 재사용하지 않음');

$hash = LessonVerification::lessonHash($lesson);
foreach ([
    static function (&$v) { $v['demo']['code'] .= "print(1)\n"; },
    static function (&$v) { $v['demo']['input'] = "1\n"; },
    static function (&$v) { $v['demo']['expected_output'] .= "\n"; },
    static function (&$v) { $v['stages'][3]['starter_code'] = "print(1)\n"; },
    static function (&$v) { $v['stages'][3]['tests'][1]['input'] = "10\n"; },
    static function (&$v) { $v['stages'][3]['tests'][1]['expected_output'] = "11\n"; },
    static function (&$v) { $v['stages'][3]['tests'] = array_reverse($v['stages'][3]['tests']); },
    static function (&$v) { $v['language'] = 'cpp'; },
] as $change) {
    $changed = $lesson; $change($changed);
    verificationCheck(LessonVerification::lessonHash($changed) !== $hash, '언어·코드·입출력·사례순서 변경을 검증 지문에서 구분');
    verificationRejects(fn () => LessonVerification::metadata($changed, $results, 0), '수정 후 이전 통과 결과를 새 자료에 재사용하지 못함');
}
$extraCase = $lesson; $extraCase['stages'][3]['tests'][] = ['input' => "10\n", 'expected_output' => "11\n"];
verificationRejects(fn () => LessonVerification::metadata($extraCase, $results, 0), '추가된 시험 사례는 별도로 실행해야 함');
$renamed = $lesson; $renamed['title'] = '수업 이름만 변경'; $renamed['objectives'][] = '출력을 읽는다.';
verificationCheck(LessonVerification::lessonHash($renamed) === $hash, '실행과 무관한 제목·목표 문장은 코드 검증 지문을 바꾸지 않음');

fwrite(STDOUT, 'OJ PRIMM lesson verification: ' . $checks . " checks passed. Runner responses were fixtures, not live executions.\n");
