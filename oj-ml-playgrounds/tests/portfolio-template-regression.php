<?php

declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

use OJML\Playgrounds\Application\AssignmentService;
use OJML\Playgrounds\Domain\PortfolioTemplate;
use OJML\Playgrounds\Domain\Problem;
use OJML\Playgrounds\Rest\Presenter;

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $value): string { return trim(strip_tags($value)); }
}
if (!function_exists('sanitize_key')) {
    function sanitize_key(string $value): string { return strtolower((string) preg_replace('/[^a-z0-9_\-]/', '', $value)); }
}
if (!function_exists('wp_kses_post')) {
    function wp_kses_post(string $value): string { return $value; }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode(mixed $value, int $flags = 0): string|false { return json_encode($value, $flags); }
}

$checks = 0;
function templateAssert(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) { throw new RuntimeException($message); }
    $checks++;
}
function templateReject(callable $callback, string $code = 'OJ_ML_PORTFOLIO_TEMPLATE_INVALID', int $status = 400): void
{
    try { $callback(); } catch (Problem $problem) {
        templateAssert($problem->problemCode() === $code && $problem->httpStatus() === $status, 'Expected bounded template validation failure.');
        return;
    }
    throw new RuntimeException('Invalid portfolio template was accepted.');
}

$input = [
    'title' => '  탐구 보고서  ',
    'markdown' => "# 보고서\r\n\r\n## 문제 정의 {#topic}\r\n어떤 문제인가요?\r\n\r\n## 성찰 {#reflection}\r\n- 결과를 설명하세요.\r\n",
];
$template = PortfolioTemplate::normalize($input);
templateAssert($template['title'] === '탐구 보고서', 'Template names must trim whitespace.');
templateAssert(!str_contains($template['markdown'], "\r"), 'Markdown must normalize CRLF.');
templateAssert(str_contains($template['markdown'], '## 문제 정의 {#topic}'), 'Stable heading IDs must survive normalization.');
templateAssert(PortfolioTemplate::normalize($template) === $template, 'Repeated saving or assignment copying must be lossless.');
templateAssert(PortfolioTemplate::normalize(null) === null, 'Null explicitly removes a draft template.');
foreach ([
    "##질문\n공백 없는 제목도 upstream 0.12.1 문항입니다.\n",
    "## 질문{#topic}\nID 앞 공백은 선택입니다.\n",
    "## 한글 질문\nID 없는 문항의 원래 제목을 보존하세요.\n",
    "# 문서 제목\n\n## 질문 {#topic}\n### 상세 안내\n하위 제목은 문항의 안내문입니다.\n",
] as $upstreamMarkdown) {
    templateAssert(PortfolioTemplate::normalize(['title' => '형식 호환', 'markdown' => $upstreamMarkdown])['markdown'] === $upstreamMarkdown,
        'The server must preserve valid upstream 0.12.1 headings without inventing IDs or deleting guidance.');
}
templateAssert(PortfolioTemplate::normalize(['title' => str_repeat('가', 120), 'markdown' => "## 질문\n안내\n"]) !== null, 'Title limit must count Unicode characters.');
templateAssert(PortfolioTemplate::normalize(['title' => '형식', 'markdown' => "\xEF\xBB\xBF<!-- author note -->\r\n## 질문\r\n설명\r\n"])['markdown'] === "## 질문\n설명\n", 'A UTF-8 BOM and author comments must not appear as learner questions.');
$builtin = file_get_contents(dirname(__DIR__) . '/resources/playground/portfolio/default.ko.md');
$built = PortfolioTemplate::normalize(['title' => '기본 탐구 보고서', 'markdown' => $builtin]);
templateAssert(substr_count($built['markdown'], '## ') === 8, 'The bundled eight-question template must be accepted.');
$thirty = implode("\n", array_map(static fn (int $i): string => "## 질문 $i {#q$i}\n설명", range(1, 30)));
templateAssert(PortfolioTemplate::normalize(['title' => '30 문항', 'markdown' => $thirty]) !== null, 'Thirty questions must fit the safe preview limit.');
$max = "## 질문\n" . str_repeat('x', PortfolioTemplate::MAX_MARKDOWN_BYTES - strlen("## 질문\n") - 1) . "\n";
templateAssert(strlen(PortfolioTemplate::normalize(['title' => '한도', 'markdown' => $max])['markdown']) === 32768, 'The inclusive byte limit must work.');

foreach ([false, '', [], ['title' => '없음'], ['title' => 'x', 'markdown' => [], 'url' => 'https://invalid.example']] as $bad) {
    templateReject(static fn () => PortfolioTemplate::normalize($bad));
}
foreach ([
    ['title' => '', 'markdown' => '## 질문'],
    ['title' => str_repeat('가', 121), 'markdown' => '## 질문'],
    ['title' => '<b>제목</b>', 'markdown' => '## 질문'],
    ['title' => "줄\n제목", 'markdown' => '## 질문'],
    ['title' => '질문', 'markdown' => str_repeat('x', 32769)],
    ['title' => '질문', 'markdown' => "## 질문\n\xFF"],
    ['title' => '질문', 'markdown' => "## 질문\n\x00"],
    ['title' => '질문', 'markdown' => '# 문서 제목만 있음'],
    ['title' => '질문', 'markdown' => "##\n안내만 있음"],
    ['title' => '질문', 'markdown' => "## {#q1}\n빈 문항"],
    ['title' => '질문', 'markdown' => "## 첫째 {#q1}\n\n## 둘째 {#q1}"],
    ['title' => '질문', 'markdown' => '## ' . str_repeat('가', 201)],
    ['title' => '질문', 'markdown' => $thirty . "\n## 질문31"],
    ['title' => '질문', 'markdown' => "## 질문\n<script>alert(1)</script>"],
    ['title' => '질문', 'markdown' => "## 질문\n<img src=x onerror=alert(1)>"],
    ['title' => '질문', 'markdown' => "## 질문\n<?php echo 1; ?>"],
    ['title' => '질문', 'markdown' => "## 질문\n<!-- unclosed comment"],
] as $bad) {
    templateReject(static fn () => PortfolioTemplate::normalize($bad));
}

$reflection = new ReflectionClass(AssignmentService::class);
$service = $reflection->newInstanceWithoutConstructor();
$policyMethod = $reflection->getMethod('normalizePolicy');
$policy = $policyMethod->invoke($service, [
    'portfolioTemplate' => $input,
    'allowedDataTypes' => ['tabular'],
    'allowedAlgorithms' => ['RandomForestClassifier'],
    'maxRows' => 500,
    'allowLateSubmission' => true,
    'requiredDatasetSha256' => str_repeat('a', 64),
    'datasetDescription' => '공통 데이터 출처·변수 의미·예측 목표',
    'datasetDescriptionSectionId' => 'data_context',
], 'classification');
templateAssert($policy['portfolioTemplate'] === $template, 'The assignment policy must retain the validated template.');
templateAssert($policy['maxRows'] === 500 && $policy['allowLateSubmission'] === true && $policy['allowedAlgorithms'] === ['RandomForestClassifier'], 'Existing teaching rules must be preserved.');
templateAssert($policy['requiredDatasetSha256'] === str_repeat('a', 64), 'Dataset verification rules must not be lost.');
templateAssert($policy['datasetDescription'] === '공통 데이터 출처·변수 의미·예측 목표' && $policy['datasetDescriptionSectionId'] === 'data_context', 'Teacher context and student section binding must persist in real assignment normalization.');
templateAssert(json_decode(json_encode($policy), true)['portfolioTemplate'] === $template, 'The existing policy JSON storage round trip must preserve Korean Markdown.');
templateAssert($policyMethod->invoke($service, $policy, 'classification') === $policy, 'Policy renormalization during duplication must preserve the template.');
$cleared = $policyMethod->invoke($service, array_replace($policy, ['portfolioTemplate' => null]), 'classification');
templateAssert(!isset($cleared['portfolioTemplate']) && $cleared['maxRows'] === 500, 'Clearing the template must keep other policy rules.');
templateAssert(!isset($policyMethod->invoke($service, [], 'classification')['portfolioTemplate']), 'Legacy assignments without a template must remain unchanged.');
templateReject(static fn () => $policyMethod->invoke($service, ['portfolioTemplate' => ['title' => '빈 양식', 'markdown' => '']], 'classification'));

$normalize = $reflection->getMethod('normalizeAssignment');
$normalized = $normalize->invoke($service, [
    'title' => '과제', 'taskType' => 'classification',
    'policy' => $policy, 'rubric' => ['criteria' => [['name' => '성찰', 'points' => 20]]],
], true);
templateAssert($normalized['rubric']['criteria'][0]['points'] === 20 && $normalized['policy']['portfolioTemplate'] === $template, 'Template storage must not repurpose or overwrite rubric JSON.');
$published = $reflection->getMethod('normalizePublishedChanges');
templateReject(static fn () => $published->invoke($service, ['policy' => ['portfolioTemplate' => null]], [
    'task_type' => 'classification', 'max_attempts' => 1, 'opens_at' => null, 'due_at' => null,
]), 'OJ_ML_ASSIGNMENT_IMMUTABLE', 409);

$assignment = [
    'id' => 2, 'title' => '과제', 'description' => '', 'task_type' => 'classification',
    'status' => 'published', 'max_attempts' => 2, 'revision' => 1,
    'context_type' => 'class', 'context_id' => '7', 'created_by' => 1,
    'policy' => $policy, 'rubric' => ['private_note' => 'teacher-only'],
];
$presenter = new Presenter();
$student = $presenter->assignment($assignment);
$teacher = $presenter->assignment($assignment, true);
templateAssert($student['submissionPolicy']['portfolioTemplate'] === $template, 'Authorized learner DTO must expose the assigned form.');
templateAssert($student['submissionPolicy']['datasetDescription'] === $policy['datasetDescription'], 'Assigned common dataset context must reach authorized learners.');
templateAssert(!isset($student['rubric']) && $student['submissionPolicy']['requiredDatasetSha256'] === str_repeat('a', 64)
    && !isset($student['submissionPolicy']['maxJsonBytes']), 'Dataset identity is public guidance but legacy rubric and private parser limits remain hidden.');
templateAssert($teacher['policy']['portfolioTemplate'] === $template && $teacher['rubric']['private_note'] === 'teacher-only', 'Teacher edit DTO must retain the form and existing rubric.');
$legacy = $presenter->assignment(array_replace($assignment, ['policy' => []]));
templateAssert(!isset($legacy['submissionPolicy']['portfolioTemplate']), 'Legacy learner DTO must not fabricate an assigned form.');

echo "Portfolio template regression passed ($checks checks).\n";
