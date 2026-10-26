<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\Kernel\Autoloader;
use CodingPanda\OjPlatform\Modules\JudgeDb\Application\JudgeResultCatalog;
use CodingPanda\OjPlatform\Modules\Submissions\Application\JudgeExecutionParser;
use CodingPanda\OjPlatform\Modules\Submissions\Application\RuntimeErrorParser;
use CodingPanda\OjPlatform\Modules\Submissions\Presentation\JudgeExecutionHtml;

require_once dirname(__DIR__, 2) . '/src/Kernel/Autoloader.php';
Autoloader::register(dirname(__DIR__, 2));
$assertions = 0;
$check = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (! $condition) { throw new RuntimeException($message); }
};
$parser = new JudgeExecutionParser(new RuntimeErrorParser());
$html = new JudgeExecutionHtml($parser, new JudgeResultCatalog());
$runtimeRaw = file_get_contents(dirname(__DIR__) . '/Fixtures/judge-runtime-error.txt');
$wrongRaw = file_get_contents(dirname(__DIR__) . '/Fixtures/judge-wrong-answer.txt');
$runtime = $parser->parse($runtimeRaw);
$check(count($runtime->testCases()) === 7, '사용자 RE 원문의 테스트 7개를 보존');
$check($runtime->diagnostics()->diagnostics() === [], '구조화된 표는 일반 오류로 중복 표시하지 않음');
$check(! $runtime->truncated(), '정상 길이 원문은 잘리지 않음');
foreach ($runtime->testCases() as $case) {
    $check($case['result'] === 'RE' && $case['exit_code'] === 1, '테스트별 결과와 종료 코드를 연결');
    $check(count($case['diagnostics']->diagnostics()) === 2, '같은 오류도 다른 테스트와 합치지 않음');
    $location = $case['diagnostics']->diagnostics()[0];
    $error = $case['diagnostics']->diagnostics()[1];
    $check($location->file() === '/home/judge/run0/Main.py' && $location->line() === 1, '호출 파일과 줄 보존');
    $check($error->type() === 'AttributeError' && str_contains($error->message(), "Did you mean: 'split'?"), '예외 유형과 제안 보존');
}
$check($runtime->testCases()[0]['time'] === '16ms' && $runtime->testCases()[0]['memory'] === '17936k', '실행 정보 단위 보존');
$check($runtime->testCases()[2]['size'] === 11, '파일 크기 보존');
$runtimeHtml = $html->render($runtimeRaw);
$check(substr_count($runtimeHtml, 'data-oj-case-error') === 7, '각 테스트의 오류를 별도 펼침 영역으로 표시');
$check(str_contains($runtimeHtml, 'Main.py:1') && ! str_contains($runtimeHtml, '/home/judge/'), '학생 화면의 서버 경로 비노출');
$check(substr_count($runtimeHtml, '런타임 오류</span>') === 7, 'RE는 공통 결과 문구 사용');
$check(! str_contains($runtimeHtml, 'Traceback') && ! str_contains($runtimeHtml, '원문 확인'), '학생에게 원문 미제공');
$check(str_contains($html->render($runtimeRaw, true), '채점 기록 원문 확인'), '관리자에게만 접힌 원문 제공');
$hiddenMetrics = $html->render($runtimeRaw, false, ['show_time' => false, 'show_memory' => false]);
$check(! str_contains($hiddenMetrics, '17936k') && ! str_contains($hiddenMetrics, '16ms'), '성능 정보 숨김 설정 유지');
$wrong = $parser->parse($wrongRaw);
$check(count($wrong->testCases()) === 7 && count($wrong->comparisons()) === 6, 'WA 원문의 테스트 7개와 비교 6개 분리');
$check($wrong->testCases()[0]['result'] === 'AC' && $wrong->testCases()[1]['result'] === 'WA', '맞은 예제와 틀린 테스트 구분');
$check($wrong->diagnostics()->diagnostics() === [], '오답 비교 문법은 실행 오류로 표시하지 않음');
$check($wrong->comparisons()[0]['rows'][4] === ['expected' => '⛔', 'actual' => ''], '기대 출력 종료·빈 실제 출력 셀 보존');
$check($wrong->comparisons()[1]['rows'][1] === ['expected' => '0.9↩', 'actual' => '0.`1↩`'], '부분 차이 구간과 줄바꿈 기호 보존');
$wrongHtml = $html->render($wrongRaw);
$check(substr_count($wrongHtml, '기대 출력</th>') === 6 && substr_count($wrongHtml, '실제 출력</th>') === 6, '오답을 두 열의 비교표로 표시');
$check(str_contains($wrongHtml, '0.<mark class="ojp-output-difference">1↩</mark>'), '부분 차이만 강조');
$check(substr_count($wrongHtml, '틀렸습니다</span>') === 6 && substr_count($wrongHtml, '맞았습니다</span>') === 1, 'AC·WA 문구는 공통 카탈로그 사용');
$check(! str_contains($wrongHtml, 'data-oj-runtime-diagnostics'), 'WA를 일반 실행 오류 표로 잘못 표시하지 않음');
$check(str_contains($wrongHtml, 'aria-label="빈 출력"') && str_contains($wrongHtml, '⛔'), '빈 출력과 원문 표식을 구별');
$check($html->render('') === '', '빈 기록은 빈 화면 조각');
$check(count($parser->parse(str_replace("\n", "\r\n", $runtimeRaw))->testCases()) === 7, 'CRLF도 동일한 결과');
$check(count($parser->parse("\033[31m" . $runtimeRaw . "\033[0m")->testCases()) === 7, 'ANSI 색상 제거');
$safeRaw = "unsafe.out\n--\n|Expected|Yours\n|--|--\n|  <script>alert(1)</script>↩|`<img src=x onerror=alert(1)>`\n";
$safeHtml = $html->render($safeRaw, true);
$check(! str_contains($safeHtml, '<script>') && ! str_contains($safeHtml, '<img '), '출력 및 원문 HTML 실행 방지');
$check(str_starts_with($parser->parse($safeRaw)->comparisons()[0]['rows'][0]['expected'], '  <script>'), '비교 셀의 앞 공백 보존');
$check(str_contains($safeHtml, 'class="ojp-output-whitespace"'), '공백을 표시할 안전한 요소 생성');
$pipes = $parser->parse("pipe.out\n--\n|Expected|Yours\n|--|--\n|a\\|b|`c|d`\n");
$check($pipes->comparisons()[0]['rows'][0] === ['expected' => 'a|b', 'actual' => '`c|d`'], '이스케이프 파이프와 강조 구간 파이프 처리');
$extra = $parser->parse($wrongRaw . "\n알 수 없는 추가 기록\n");
$check($extra->diagnostics()->diagnostics()[0]->message() === '알 수 없는 추가 기록', '인식한 표 뒤의 미인식 기록도 보존');
$malformed = $parser->parse("bad.out\n--\n|Expected|Yours\n|--|--\n|a|b|c\n");
$check($malformed->comparisons() === [] && $malformed->diagnostics()->diagnostics() !== [], '모호한 다중 열은 잘못 조합하지 않음');
$check($parser->parse(str_repeat('x', 70000))->truncated(), '64KB 원문 제한 표시');
$many = "filename|size|result|memory|time\n--|--|--|--|--\n";
for ($i = 0; $i < 110; ++$i) { $many .= "test$i.in|1|AC|1k|1ms\n"; }
$check(count($parser->parse($many)->testCases()) === 100 && $parser->parse($many)->truncated(), '테스트 개수 제한 표시');
$manyRows = "large.out\n--\n|Expected|Yours\n|--|--\n" . str_repeat("|x|y\n", 220);
$check(count($parser->parse($manyRows)->comparisons()[0]['rows']) === 200 && $parser->parse($manyRows)->truncated(), '비교 행 제한 표시');
$whitespaceRaw = file_get_contents(dirname(__DIR__) . '/Fixtures/judge-whitespace-answer.txt');
$whitespaceRows = $parser->parse($whitespaceRaw)->comparisons()[0]['rows'];
$check($whitespaceRows[0]['expected'] === '  Hello world  ↩', '앞·중간·뒤 공백 모두 보존');
$check($whitespaceRows[1]['expected'] === "\t42 \t↩", '탭과 공백을 구분하여 보존');
$check($whitespaceRows[2]['expected'] === ' ' && $whitespaceRows[3]['expected'] === '', '공백만 있는 출력과 빈 출력을 구분');
$whitespaceHtml = $html->render($whitespaceRaw);
$check(substr_count($whitespaceHtml, 'ojp-output-whitespace--tab') === 2, '각 탭을 별도 표시');
$check(substr_count($whitespaceHtml, 'ojp-output-whitespace--nbsp') === 1, '줄 바꿈 없는 공백을 별도 표시');
$check(str_contains($whitespaceHtml, '<mark class="ojp-output-difference"><span class="ojp-output-whitespace"'), '공백 차이 강조 보존');
preg_match_all('/<pre class="ojp-output-text"[^>]*><code>(.*?)<\/code><\/pre>/s', $whitespaceHtml, $outputCells);
$plainCells = array_map(
    static fn (string $cell): string => html_entity_decode(strip_tags($cell), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
    $outputCells[1]
);
foreach ($whitespaceRows as $index => $row) {
    $check($plainCells[$index * 2] === $row['expected'], '시각 공백 표시가 기대 출력 텍스트를 변경하면 안 됨');
    $check($plainCells[$index * 2 + 1] === str_replace(chr(96), '', $row['actual']), '시각 공백 표시가 실제 출력 텍스트를 변경하면 안 됨');
}
$unframed = $parser->parse("plain.out\n--\nExpected|Yours\n--|--\n  expected  | actual \t\n");
$check($unframed->comparisons()[0]['rows'][0] === ['expected' => '  expected  ', 'actual' => " actual \t"], '선행 파이프 없는 표도 양쪽 공백·탭 보존');
echo "Judge execution reports: $assertions assertions passed.\n";
