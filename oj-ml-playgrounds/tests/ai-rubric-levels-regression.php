<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use OJML\Playgrounds\Application\AiEvidence;
use OJML\Playgrounds\Application\AiReviewDraft;
use OJML\Playgrounds\Domain\Problem;
use OJML\Playgrounds\Domain\Rubric;

function mlLevelAssert(bool $value, string $message): void
{
    if (!$value) { throw new RuntimeException($message); }
}

function mlLevelReject(callable $callback, string $code): void
{
    try { $callback(); } catch (Problem $problem) {
        mlLevelAssert($problem->problemCode() === $code, 'Expected ' . $code . ', got ' . $problem->problemCode());
        return;
    }
    throw new RuntimeException('Expected rejection: ' . $code);
}

$rubric = ['version' => 2, 'criteria' => [
    ['id' => 'analysis', 'title' => '실험 분석', 'description' => '비교 실험과 근거의 타당성을 평가합니다.', 'maxScore' => 60,
        'levels' => [
            ['id' => 'complete', 'label' => '충분함', 'description' => '동일 조건의 여러 실험을 지표와 연결해 분석했습니다.', 'score' => 60],
            ['id' => 'partial', 'label' => '부분 충족', 'description' => '일부 비교 근거를 제시했지만 설정 차이에 대한 해석이 부족합니다.', 'score' => 40],
            ['id' => 'initial', 'label' => '초기 단계', 'description' => '실험 결과를 제시했으나 비교 근거가 없습니다.', 'score' => 20.5],
            ['id' => 'absent', 'label' => '미제시', 'description' => '제출물에서 해당 수행의 근거를 확인할 수 없습니다.', 'score' => 0],
        ]],
    ['id' => 'reflection', 'title' => '성찰', 'description' => '한계와 개선 방향을 설명합니다.', 'maxScore' => 40,
        'levels' => [
            ['id' => 'complete', 'label' => '구체적 성찰', 'description' => '실험 한계와 실행 가능한 개선 계획을 모두 설명했습니다.', 'score' => 40],
            ['id' => 'partial', 'label' => '부분 성찰', 'description' => '한계나 개선 계획 중 한 가지만 설명했습니다.', 'score' => 20],
            ['id' => 'absent', 'label' => '미제시', 'description' => '성찰 내용을 제시하지 않았습니다.', 'score' => 0],
        ]],
]];
$attempt = ['uuid' => '11111111-1111-4111-8111-111111111111', 'rubric' => $rubric,
    'artifact' => ['sha256' => str_repeat('a', 64)]];
$assignment = ['context_type' => 'group', 'context_id' => 'private-group-id', 'title' => '등급별 ML 평가',
    'description' => '실험 근거와 성찰을 평가합니다.', 'rubric' => $rubric, 'policy' => []];
$preview = ['source' => 'verified-read-only', 'content' => [
    'project' => ['taskType' => 'classification', 'dataType' => 'tabular'],
    'settings' => [], 'experiments' => [], 'notices' => [],
    'portfolio' => ['sections' => [['id' => 'analysis', 'answer' => '실험을 비교하고 한계를 설명했습니다.', 'answered' => true, 'attachmentCount' => 0]]],
]];
$snapshot = AiEvidence::build($attempt, $assignment, $preview);
foreach ($rubric['criteria'] as $index => $criterion) {
    $description = $snapshot['rubric'][$index]['description'];
    mlLevelAssert(str_contains($description, $criterion['description']), 'Teacher criterion description must remain intact.');
    foreach ($criterion['levels'] as $level) {
        foreach (['id', 'label', 'description'] as $field) {
            mlLevelAssert(str_contains($description, $level[$field]), 'The legacy AI description must carry every grade ' . $field . '.');
        }
        mlLevelAssert(str_contains($description, (string) $level['score']), 'Every exact grade score must reach the legacy AI description.');
    }
    mlLevelAssert(strlen($description) <= 8000, 'Grade descriptions must respect the existing AI input boundary.');
}

// Every individual description is valid ML input, but their combined AI text is too large.
$largeAssignment = $assignment;
foreach ($largeAssignment['rubric']['criteria'][0]['levels'] as &$level) { $level['description'] = str_repeat('가', 1000); }
unset($level);
Rubric::normalize($largeAssignment['rubric']);
mlLevelReject(fn () => AiEvidence::build($attempt, $largeAssignment, $preview), 'OJ_ML_AI_EVIDENCE_TOO_LARGE');
$partial = $preview;
$partial['content']['notices'] = ['미리보기 내용 일부 생략'];
mlLevelReject(fn () => AiEvidence::build($attempt, $assignment, $partial), 'OJ_ML_AI_EVIDENCE_INCOMPLETE');

$profilePath = dirname(__DIR__, 2) . '/oj-ai-dashboard/src/ExternalAssessment/Assessment_Profile.php';
if (!is_file($profilePath)) {
    fwrite(STDOUT, "OJ ML AI rubric levels local boundary regression passed; optional AI source is not installed.\n");
    exit(0);
}
require_once $profilePath;
use OJAI\Dashboard\ExternalAssessment\Assessment_Profile;

$contract = Assessment_Profile::snapshot($snapshot, $attempt['uuid']);
$input = Assessment_Profile::input($contract);
$digest = Assessment_Profile::digest($contract);
mlLevelAssert($input['schema'] === 'ml_submission_assessment.v1', 'Grade support must preserve the existing optional AI contract.');
foreach ($snapshot['rubric'] as $index => $criterion) {
    mlLevelAssert($input['rubric'][$index]['description'] === $criterion['description'], 'The existing AI profile must transmit the complete grade table without truncating it.');
}
mlLevelAssert(!isset($input['source_id'], $input['source_sha256'], $input['context']), 'Grade support must not expose internal submission or group identities.');

// Isolate each grade dimension; a change must invalidate already computed results.
foreach (['id' => 'initial_revision', 'label' => '수정한 등급명', 'description' => '수정한 수행 기준', 'score' => 20.75] as $field => $value) {
    $changedAssignment = $assignment;
    $changedAssignment['rubric']['criteria'][0]['levels'][2][$field] = $value;
    $changedSnapshot = AiEvidence::build($attempt, $changedAssignment, $preview);
    $changedContract = Assessment_Profile::snapshot($changedSnapshot, $attempt['uuid']);
    mlLevelAssert(Assessment_Profile::digest($changedContract) !== $digest, 'Grade ' . $field . ' edits must change the provider input digest.');
}

$runId = 'ml-rubric-levels-run';
$output = ['schema' => 'ml_submission_assessment.v1', 'needs_review' => false,
    'criteria' => [
        ['id' => 'analysis', 'score' => 20.5, 'feedback' => '결과를 제시했으나 비교 설명이 부족합니다.', 'evidence' => 'portfolio.answers[analysis]'],
        ['id' => 'reflection', 'score' => 20, 'feedback' => '한계 설명을 확인했습니다.', 'evidence' => 'portfolio.answers[analysis]'],
    ], 'summary' => '실험 결과를 바탕으로 분석을 확장하세요.', 'limitations' => []];
$normalized = Assessment_Profile::output($output, $contract);
$result = $normalized + ['state' => 'ready', 'run_id' => $runId, 'input_hash' => $digest, 'source_sha256' => $snapshot['source_sha256']];
$draft = AiReviewDraft::build($attempt, $result, $runId, $digest);
mlLevelAssert((float) $draft['score'] === 40.5 && $draft['status'] === 'draft', 'Exact grade scores must produce a private draft with authoritative totals.');
mlLevelAssert($draft['details']['version'] === 2
    && array_column($draft['details']['criteriaScores'], 'levelId') === ['initial', 'partial'], 'The old AI score-only result must map to the exact server-owned grade IDs.');
mlLevelAssert($draft['details']['rubricSnapshot'] === Rubric::normalize($rubric), 'The final review must retain the exact grade definition snapshot.');

$zeroOutput = $output;
$zeroOutput['criteria'][0]['score'] = 0;
$zeroOutput['criteria'][1]['score'] = 0;
$zeroResult = Assessment_Profile::output($zeroOutput, $contract)
    + ['state' => 'ready', 'run_id' => $runId, 'input_hash' => $digest, 'source_sha256' => $snapshot['source_sha256']];
$zeroDraft = AiReviewDraft::build($attempt, $zeroResult, $runId, $digest);
mlLevelAssert((float) $zeroDraft['score'] === 0.0
    && array_column($zeroDraft['details']['criteriaScores'], 'levelId') === ['absent', 'absent'], 'A defined zero-point grade is valid and must not be mistaken for an absent score.');

foreach ([20.49, 20.51, 33.33] as $offGrid) {
    $invalidOutput = $output;
    $invalidOutput['criteria'][0]['score'] = $offGrid;
    $invalidResult = Assessment_Profile::output($invalidOutput, $contract)
        + ['state' => 'ready', 'run_id' => $runId, 'input_hash' => $digest, 'source_sha256' => $snapshot['source_sha256']];
    mlLevelReject(fn () => AiReviewDraft::build($attempt, $invalidResult, $runId, $digest), 'OJ_ML_AI_LEVEL_SCORE_INVALID');
}
foreach (['levelId', 'level_id'] as $key) {
    $wrongId = $result;
    $wrongId['criteria'][0][$key] = 'complete';
    mlLevelReject(fn () => AiReviewDraft::build($attempt, $wrongId, $runId, $digest), 'OJ_ML_AI_LEVEL_SCORE_INVALID');
    $nullId = $result;
    $nullId['criteria'][0][$key] = null;
    mlLevelReject(fn () => AiReviewDraft::build($attempt, $nullId, $runId, $digest), 'OJ_ML_AI_LEVEL_SCORE_INVALID');
}
$conflictingIds = $result;
$conflictingIds['criteria'][0]['levelId'] = 'initial';
$conflictingIds['criteria'][0]['level_id'] = 'complete';
mlLevelReject(fn () => AiReviewDraft::build($attempt, $conflictingIds, $runId, $digest), 'OJ_ML_AI_LEVEL_SCORE_INVALID');
$preciseOffGrid = $result;
$preciseOffGrid['criteria'][0]['score'] = 20.5001;
mlLevelReject(fn () => AiReviewDraft::build($attempt, $preciseOffGrid, $runId, $digest), 'OJ_ML_AI_LEVEL_SCORE_INVALID');
mlLevelReject(fn () => AiReviewDraft::build($attempt, $result, $runId, Assessment_Profile::digest($changedContract)), 'OJ_ML_AI_RESULT_STALE');
$uncertainOutput = $output;
$uncertainOutput['criteria'][0]['score'] = null;
$uncertainOutput['needs_review'] = true;
$uncertainResult = Assessment_Profile::output($uncertainOutput, $contract)
    + ['state' => 'needs_review', 'run_id' => $runId, 'input_hash' => $digest, 'source_sha256' => $snapshot['source_sha256']];
mlLevelAssert($uncertainResult['total_score'] === null, 'Insufficient evidence must not become the zero-point grade.');
mlLevelReject(fn () => AiReviewDraft::build($attempt, $uncertainResult, $runId, $digest), 'OJ_ML_AI_RESULT_STALE');

// Existing free-score rubrics must remain readable and must not acquire grade rules.
$legacyAssignment = $assignment;
$legacyAssignment['rubric']['version'] = 1;
foreach ($legacyAssignment['rubric']['criteria'] as &$criterion) { unset($criterion['levels']); }
unset($criterion);
$legacyAttempt = $attempt;
$legacyAttempt['rubric'] = $legacyAssignment['rubric'];
$legacySnapshot = AiEvidence::build($legacyAttempt, $legacyAssignment, $preview);
mlLevelAssert(str_starts_with($legacySnapshot['rubric'][0]['description'], $legacyAssignment['rubric']['criteria'][0]['description'])
    && !str_contains($legacySnapshot['rubric'][0]['description'], '교사 등급별 채점표'),
    'Legacy criterion instructions must be preserved without silently acquiring grade restrictions.');
$legacyContract = Assessment_Profile::snapshot($legacySnapshot, $attempt['uuid']);
$legacyDigest = Assessment_Profile::digest($legacyContract);
$legacyOutput = $output;
$legacyOutput['criteria'][0]['score'] = 37.25;
$legacyResult = Assessment_Profile::output($legacyOutput, $legacyContract)
    + ['state' => 'ready', 'run_id' => $runId, 'input_hash' => $legacyDigest, 'source_sha256' => $snapshot['source_sha256']];
$legacyDraft = AiReviewDraft::build($legacyAttempt, $legacyResult, $runId, $legacyDigest);
mlLevelAssert((float) $legacyDraft['score'] === 57.25 && $legacyDraft['details']['version'] === 1
    && !isset($legacyDraft['details']['criteriaScores'][0]['levelId']), 'Legacy v1 free-score AI reviews must remain compatible.');

fwrite(STDOUT, "OJ ML grade-rubric / unchanged AI v1 contract / exact-grade draft regression passed.\n");
