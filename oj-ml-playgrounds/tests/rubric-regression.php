<?php

declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

use OJML\Playgrounds\Domain\Rubric;
use OJML\Playgrounds\Domain\Problem;
use OJML\Playgrounds\Rest\Presenter;

function rubricAssert(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}
function rubricReject(callable $callback, string $message): void {
    try { $callback(); } catch (Problem $error) {
        rubricAssert($error->httpStatus() === 400, $message . ': expected 400');
        return;
    }
    throw new RuntimeException($message . ': no error');
}
$definition = ['version' => 1, 'criteria' => [
    ['id' => 'data', 'title' => '데이터 이해', 'description' => '근거 설명', 'maxScore' => 25],
    ['id' => 'analysis', 'title' => '분석과 성찰', 'description' => '', 'maxScore' => 75.25],
]];
$normalized = Rubric::normalize($definition);
rubricAssert($normalized['criteria'][1]['maxScore'] === 75.25, 'Precise maximum preserved');
rubricAssert(Rubric::normalize([]) === [], 'Empty legacy rubric preserved');
$legacy = ['weights' => ['accuracy' => 1], 'custom' => '교사 기존 기준'];
rubricAssert(Rubric::normalize($legacy) === $legacy && Rubric::review($legacy, []) === null, 'Legacy definition and total score flow preserved');
$legacyCriteria = ['criteria' => [['key' => 'old-weight', 'weight' => 100]]];
rubricAssert(Rubric::normalize($legacyCriteria) === $legacyCriteria && Rubric::review($legacyCriteria, []) === null,
    'Versionless legacy criteria documents do not silently opt into the new contract');
rubricAssert(Rubric::review(['version' => 1, 'criteria' => []], []) === null, 'Empty criteria uses legacy total score flow');

foreach ([null, 'invalid', ['version' => 2, 'criteria' => []], ['version' => 1, 'criteria' => ['x' => []]],
    ['version' => 1, 'criteria' => array_fill(0, 26, $definition['criteria'][0])]] as $invalid) {
    rubricReject(fn () => Rubric::normalize($invalid), 'Invalid rubric shape');
}
foreach (['', '1starts_with_digit', 'has space', str_repeat('a', 65)] as $id) {
    $invalid = $definition; $invalid['criteria'][0]['id'] = $id;
    rubricReject(fn () => Rubric::normalize($invalid), 'Invalid criterion ID');
}
$invalid = $definition; $invalid['criteria'][1]['id'] = 'data';
rubricReject(fn () => Rubric::normalize($invalid), 'Duplicate criterion ID');
foreach ([0, -1, 10000.01, INF, NAN, true, 0.001, ''] as $maximum) {
    $invalid = $definition; $invalid['criteria'][0]['maxScore'] = $maximum;
    rubricReject(fn () => Rubric::normalize($invalid), 'Invalid maximum');
}
$invalid = $definition; $invalid['criteria'][0]['title'] = str_repeat('가', 201);
rubricReject(fn () => Rubric::normalize($invalid), 'Unicode title bounded');
$invalid = $definition; $invalid['criteria'][0]['description'] = str_repeat('가', 2001);
rubricReject(fn () => Rubric::normalize($invalid), 'Unicode description bounded');
$valid = $definition; $valid['criteria'][0]['title'] = str_repeat('가', 200);
rubricAssert(Rubric::normalize($valid)['criteria'][0]['title'] === $valid['criteria'][0]['title'], '200 Unicode code points supported');
$unsafe = $definition; $unsafe['criteria'][0]['title'] = '<img src=x onerror=x>이해';
rubricAssert(Rubric::normalize($unsafe)['criteria'][0]['title'] === '이해', 'Stored rubric is plain text');

$entries = [
    ['id' => 'analysis', 'score' => 70.12, 'feedback' => '검토 의견'],
    ['id' => 'data', 'score' => 20.01, 'feedback' => ''],
];
$review = Rubric::review($definition, ['criteriaScores' => $entries, 'score' => 99999, 'maxScore' => 99999]);
rubricAssert($review['score'] === 90.13 && $review['max_score'] === 100.25, 'Authoritative integer hundredth totals replace forged totals');
rubricAssert(array_column($review['details']['criteriaScores'], 'id') === ['data', 'analysis'], 'Snapshot uses definition order');
rubricAssert($review['details']['rubricSnapshot'] === $normalized, 'Immutable rubric snapshot persisted');
rubricAssert(Rubric::details($review['details']) === $review['details'], 'Details normalize round trip');
$feedbackOnly = Rubric::review($definition, []);
rubricAssert($feedbackOnly['score'] === null && $feedbackOnly['max_score'] === null && count($feedbackOnly['details']['criteriaScores']) === 2, 'Feedback only supported with complete blank snapshot');
$blank = array_map(static fn ($entry) => array_replace($entry, ['score' => null]), $entries);
rubricAssert(Rubric::review($definition, ['criteriaScores' => $blank])['details']['criteriaScores'][1]['feedback'] === '검토 의견', 'Per-criterion feedback can be saved with all scores blank');

$invalidEntries = [
    [$entries[0]], [$entries[0], $entries[0]],
    [array_replace($entries[0], ['id' => 'unexpected']), $entries[1]],
    [array_replace($entries[0], ['score' => null]), $entries[1]],
    [array_replace($entries[0], ['score' => 76]), $entries[1]],
    [array_replace($entries[0], ['score' => -1]), $entries[1]],
    [array_replace($entries[0], ['score' => 0.001]), $entries[1]],
    [array_replace($entries[0], ['score' => NAN]), $entries[1]],
    [array_replace($entries[0], ['feedback' => str_repeat('가', 2001)]), $entries[1]],
    [['id' => 'analysis'], $entries[1]],
];
foreach ($invalidEntries as $entry) {
    rubricReject(fn () => Rubric::review($definition, ['criteriaScores' => $entry]), 'Invalid/incomplete criteria scores');
}
rubricReject(fn () => Rubric::review($definition, ['score' => 3, 'maxScore' => 100]), 'Cannot bypass rubric with total score');
rubricReject(fn () => Rubric::review($legacy, ['criteriaScores' => $entries]), 'Criteria cannot silently attach to legacy definition');
rubricAssert(Rubric::details(['version' => 1, 'rubricSnapshot' => $definition, 'criteriaScores' => ['bad']]) === null, 'Malformed persisted details fail closed');
$hostile = $review['details']; $hostile['privateToken'] = 'never-expose';
rubricAssert(!isset(Rubric::details($hostile)['privateToken']), 'Unknown persisted details not projected');

$presenter = new Presenter();
$raw = [
    'version' => 1, 'status' => 'released', 'score' => 90.13, 'max_score' => 100.25,
    'feedback' => '종합 의견', 'rubric_revision' => 2, 'reviewer_id' => 7, 'details' => $review['details'],
];
$public = $presenter->review($raw);
rubricAssert($public['details'] === $review['details'] && !isset($public['reviewerId']), 'Learner sees released criteria only without reviewer identity');
$draft = array_replace($raw, ['status' => 'draft']);
rubricAssert($presenter->review($draft)['details'] === null, 'Draft criteria never projected to learner');
rubricAssert($presenter->review($draft, true)['details'] === $review['details'], 'Authorized teacher can view draft criteria');
$assignment = ['id' => 1, 'title' => '과제', 'description' => '', 'task_type' => 'classification', 'status' => 'published',
    'max_attempts' => 1, 'revision' => 1, 'rubric' => $definition, 'policy' => [
        'requiredDatasetSha256' => str_repeat('a', 64), 'requiredDatasetPath' => 'dataset/data.csv',
        'portfolioRequirements' => ['requiredSectionIds' => ['topic'], 'minAnswerChars' => 3],
        'datasetResource' => ['id' => 'fixture', 'name' => 'data.csv', 'sha256' => str_repeat('a', 64), 'bytes' => 128,
            'rowCount' => 2, 'columnCount' => 2, 'createdAt' => '2026-09-07T00:00:00Z', 'path' => 'dataset/data.csv',
            'storageKey' => 'private/never-expose', 'ownerId' => 7, 'audit' => ['secret' => true]],
    ]];
$assignmentDto = $presenter->assignment($assignment);
rubricAssert(!isset($assignmentDto['submissionPolicy']['datasetResource']['storageKey'])
    && !isset($assignmentDto['submissionPolicy']['datasetResource']['ownerId'])
    && !isset($assignmentDto['submissionPolicy']['datasetResource']['audit']), 'Dataset resource projection hides storage owner and audit data');
rubricAssert($assignmentDto['submissionPolicy']['portfolioRequirements']['minAnswerChars'] === 3
    && $assignmentDto['submissionPolicy']['requiredDatasetPath'] === 'dataset/data.csv', 'Learner receives assignment requirements and public dataset identity');
$source = file_get_contents(dirname(__DIR__) . '/src/Database/Repository.php');
rubricAssert(str_contains($source, "'details' => \$latest['details'] ?? null"), 'Release keeps prior review details');
$schema = file_get_contents(dirname(__DIR__) . '/src/Database/Installer.php');
rubricAssert(str_contains($schema, "DB_VERSION = '4'") && str_contains($schema, 'details_json longtext NULL'), 'Additive nullable snapshot migration exists');

$leveled = ['version' => 2, 'criteria' => [
    ['id' => 'analysis', 'title' => '실험 분석', 'description' => '비교 근거의 구체성', 'maxScore' => 60, 'levels' => [
        ['id' => 'excellent', 'label' => '충분', 'description' => '두 실험의 조건과 결과 차이를 수치 근거로 설명한다.', 'score' => 60],
        ['id' => 'developing', 'label' => '부분', 'description' => '실험 결과는 설명하지만 조건 차이와 근거가 부족하다.', 'score' => 35.25],
        ['id' => 'missing', 'label' => '미확인', 'description' => '분석 답안이 없어 기준 충족을 확인할 수 없다.', 'score' => 0],
    ]],
    ['id' => 'reflection', 'title' => '성찰', 'description' => '개선 제안', 'maxScore' => 40, 'levels' => [
        ['id' => 'excellent', 'label' => '충분', 'description' => '실험 한계에 연결한 다음 실험을 제안한다.', 'score' => 40],
        ['id' => 'missing', 'label' => '미확인', 'description' => '개선 제안을 확인할 수 없다.', 'score' => 0],
    ]],
]];
$leveledNormalized = Rubric::normalize($leveled);
rubricAssert($leveledNormalized === $leveled, 'Version 2 keeps exact criterion/level identifiers, labels, descriptions and score precision.');
$v1WithLevels = $leveled; $v1WithLevels['version'] = 1;
rubricReject(fn () => Rubric::normalize($v1WithLevels), 'Version 1 cannot silently discard level restrictions');
$v1WithNullLevels = $definition; $v1WithNullLevels['criteria'][0]['levels'] = null;
rubricReject(fn () => Rubric::normalize($v1WithNullLevels), 'A levels key cannot be smuggled into version 1');
foreach ([null, [], [$leveled['criteria'][0]['levels'][0]], ['x' => $leveled['criteria'][0]['levels'][0]],
    array_fill(0, 11, $leveled['criteria'][0]['levels'][0])] as $levels) {
    $invalid = $leveled; $invalid['criteria'][0]['levels'] = $levels;
    rubricReject(fn () => Rubric::normalize($invalid), 'Every version 2 criterion requires two to ten explicit levels');
}
$invalid = $leveled; unset($invalid['criteria'][0]['levels']);
rubricReject(fn () => Rubric::normalize($invalid), 'Missing level table cannot become arbitrary score mode');
$invalid = $leveled; $invalid['criteria'][0]['maxScore'] = 60.01;
rubricReject(fn () => Rubric::normalize($invalid), 'Criterion maximum must exactly equal its highest level score');
$invalid = $leveled; $invalid['criteria'][0]['maxScore'] = 59.99;
rubricReject(fn () => Rubric::normalize($invalid), 'A level cannot exceed its declared criterion maximum');
$invalid = $leveled; $invalid['criteria'][0]['levels'][1]['id'] = 'excellent';
rubricReject(fn () => Rubric::normalize($invalid), 'Level IDs are unique within a criterion');
$invalid = $leveled; $invalid['criteria'][0]['levels'][1]['score'] = '60.00';
rubricReject(fn () => Rubric::normalize($invalid), 'Numerically equal level scores cannot be used for ambiguous inverse mapping');
foreach (['', '9bad', 'has space', str_repeat('a', 65), false, null] as $id) {
    $invalid = $leveled; $invalid['criteria'][0]['levels'][1]['id'] = $id;
    rubricReject(fn () => Rubric::normalize($invalid), 'Invalid level identifier');
}
foreach ([-0.01, 10000.01, 35.251, INF, NAN, true, null, ''] as $score) {
    $invalid = $leveled; $invalid['criteria'][0]['levels'][1]['score'] = $score;
    rubricReject(fn () => Rubric::normalize($invalid), 'Level scores must be finite bounded hundredths');
}
foreach (['label', 'description'] as $key) {
    foreach (['', '   ', '<img src=x>', null] as $text) {
        $invalid = $leveled; $invalid['criteria'][0]['levels'][0][$key] = $text;
        rubricReject(fn () => Rubric::normalize($invalid), 'Every level needs a nonempty plain label and concrete description');
    }
}
$invalid = $leveled; $invalid['criteria'][0]['levels'][0]['label'] = str_repeat('가', 201);
rubricReject(fn () => Rubric::normalize($invalid), 'Level labels are bounded in Unicode characters');
$invalid = $leveled; $invalid['criteria'][0]['levels'][0]['description'] = str_repeat('가', 2001);
rubricReject(fn () => Rubric::normalize($invalid), 'Level descriptions are bounded in Unicode characters');
$bounded = $leveled; $bounded['criteria'][0]['levels'][0]['label'] = str_repeat('가', 200);
$bounded['criteria'][0]['levels'][0]['description'] = str_repeat('나', 2000);
rubricAssert(Rubric::normalize($bounded)['criteria'][0]['levels'][0]['description'] === str_repeat('나', 2000), 'Maximum Unicode label and description lengths are preserved.');
$ten = ['version' => 2, 'criteria' => [array_replace($leveled['criteria'][0], ['maxScore' => 10000, 'levels' => array_map(
    static fn (int $index): array => ['id' => 'level' . $index, 'label' => '등급 ' . $index, 'description' => '구체 기준 ' . $index, 'score' => $index === 9 ? 10000 : $index],
    range(0, 9)
)])]];
rubricAssert(count(Rubric::normalize($ten)['criteria'][0]['levels']) === 10, 'Ten levels and the 10000-point bound are supported.');

$levelEntries = [
    ['id' => 'reflection', 'levelId' => 'excellent', 'score' => 40, 'feedback' => '다음 실험을 제안함'],
    ['id' => 'analysis', 'levelId' => 'developing', 'score' => 35.25, 'feedback' => '비교 조건 보완'],
];
$levelReview = Rubric::review($leveled, ['criteriaScores' => $levelEntries, 'score' => 9999, 'maxScore' => 9999]);
rubricAssert($levelReview['score'] === 75.25 && $levelReview['max_score'] === 100, 'The grade choices alone determine authoritative totals.');
rubricAssert($levelReview['details']['version'] === 2 && $levelReview['details']['rubricSnapshot'] === $leveledNormalized,
    'Version 2 details preserve the complete immutable grade table.');
rubricAssert(array_column($levelReview['details']['criteriaScores'], 'levelId') === ['developing', 'excellent'],
    'Saved levels follow rubric order, not request order.');
rubricAssert(Rubric::details($levelReview['details']) === $levelReview['details'], 'Version 2 details must round-trip without losing chosen grades.');
$zero = $levelEntries; $zero[0] = array_replace($zero[0], ['levelId' => 'missing', 'score' => 0]);
$zero[1] = array_replace($zero[1], ['levelId' => 'missing', 'score' => 0]);
$zeroReview = Rubric::review($leveled, ['criteriaScores' => $zero]);
rubricAssert($zeroReview['score'] === 0 && $zeroReview['max_score'] === 100, 'Explicit zero-point levels are scored, not treated as missing.');
$levelBlank = array_map(static fn (array $item): array => array_replace($item, ['levelId' => null, 'score' => null]), $levelEntries);
$levelFeedback = Rubric::review($leveled, ['criteriaScores' => $levelBlank]);
rubricAssert($levelFeedback['score'] === null && $levelFeedback['max_score'] === null
    && array_column($levelFeedback['details']['criteriaScores'], 'levelId') === [null, null], 'Feedback-only review remains possible with all grades unselected.');
rubricAssert(Rubric::review($leveled, [])['details']['criteriaScores'][0]['levelId'] === null,
    'An empty feedback-only request receives a complete blank version 2 snapshot.');
foreach ([
    array_replace($levelEntries[1], ['levelId' => null]),
    array_replace($levelEntries[1], ['levelId' => 'unknown']),
    array_replace($levelEntries[1], ['levelId' => 'excellent']),
    array_replace($levelEntries[1], ['score' => 35.24]),
    array_replace($levelEntries[1], ['score' => 59]),
    array_replace($levelEntries[1], ['score' => null]),
] as $invalidEntry) {
    rubricReject(fn () => Rubric::review($leveled, ['criteriaScores' => [$levelEntries[0], $invalidEntry]]), 'Arbitrary or contradictory grade scores must fail closed');
}
$missingLevel = $levelEntries[1]; unset($missingLevel['levelId']);
rubricReject(fn () => Rubric::review($leveled, ['criteriaScores' => [$levelEntries[0], $missingLevel]]), 'A permitted numeric score still requires an explicit manual grade selection');
rubricReject(fn () => Rubric::review($leveled, ['score' => 75.25, 'maxScore' => 100]), 'Total scores cannot bypass version 2 grade choices');
rubricReject(fn () => Rubric::review($leveled, ['criteriaScores' => [$levelEntries[0], $levelBlank[1]]]), 'A partially graded rubric cannot be silently saved as complete');
$hostileLevels = $levelReview['details']; $hostileLevels['criteriaScores'][0]['levelLabel'] = 'forged label';
rubricAssert(!isset(Rubric::details($hostileLevels)['criteriaScores'][0]['levelLabel']), 'Client-supplied grade labels are never authoritative.');
$wrongDetailVersion = $levelReview['details']; $wrongDetailVersion['version'] = 1;
rubricAssert(Rubric::details($wrongDetailVersion) === null, 'Grade snapshots cannot be smuggled through version 1 details.');
$tamperedGrade = $levelReview['details']; $tamperedGrade['criteriaScores'][0]['score'] = 35.24;
rubricAssert(Rubric::details($tamperedGrade) === null, 'Malformed persisted grade choices are not publicly projected.');
$levelRaw = array_replace($raw, ['score' => 75.25, 'max_score' => 100, 'details' => $levelReview['details']]);
rubricAssert($presenter->review($levelRaw)['details'] === $levelReview['details'], 'Students see released grade labels, criteria and selected level IDs.');
rubricAssert($presenter->review(array_replace($levelRaw, ['status' => 'draft']))['details'] === null, 'Unreleased grade selections remain private.');
echo "rubric-regression passed\n";
