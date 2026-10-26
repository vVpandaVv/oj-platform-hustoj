<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use OJML\Playgrounds\Application\AiReviewDraft;
use OJML\Playgrounds\Application\AiEvidence;
use OJML\Playgrounds\Application\EvidenceReadiness;
use OJML\Playgrounds\Domain\Problem;
use OJML\Playgrounds\Domain\Rubric;
use OJML\Playgrounds\Inspection\MlpxReadOnlyProjector;

function evidenceAssert(bool $value, string $message): void
{
    if (!$value) { throw new RuntimeException($message); }
}

function evidenceReject(callable $callback, string $code): void
{
    try { $callback(); } catch (Problem $problem) {
        evidenceAssert($problem->problemCode() === $code, 'Expected ' . $code . ', got ' . $problem->problemCode());
        return;
    }
    throw new RuntimeException('Expected rejection: ' . $code);
}

$rubric = ['version' => 2, 'criteria' => [
    ['id' => 'data', 'title' => '전처리 판단', 'description' => '데이터 특성과 처리 선택을 연결합니다.', 'maxScore' => 40,
        'levels' => [['id' => 'full', 'label' => '충분', 'description' => '선택에 대한 타당한 근거가 있습니다.', 'score' => 40],
            ['id' => 'none', 'label' => '미제시', 'description' => '선택 이유를 제시하지 않았습니다.', 'score' => 0]]],
    ['id' => 'comparison', 'title' => '성능 비교', 'description' => '동일 조건에서 성능 변화를 해석합니다.', 'maxScore' => 40,
        'levels' => [['id' => 'full', 'label' => '충분', 'description' => '동일 조건의 비교 근거가 있습니다.', 'score' => 40],
            ['id' => 'none', 'label' => '미제시', 'description' => '비교 수행을 제시하지 않았습니다.', 'score' => 0]],
        'evidenceRequirements' => ['datasetDescription', 'datasetStatistics', 'experimentComparison']],
    ['id' => 'reflection', 'title' => '성찰', 'description' => '학생이 서술한 향후 계획의 구체성만 평가합니다.', 'maxScore' => 20,
        'levels' => [['id' => 'full', 'label' => '충분', 'description' => '구체적인 계획을 제시했습니다.', 'score' => 20],
            ['id' => 'none', 'label' => '미제시', 'description' => '계획을 제시하지 않았습니다.', 'score' => 0]],
        'evidenceRequirements' => []],
]];
$normalized = Rubric::normalize($rubric);
evidenceAssert($normalized === $rubric && !array_key_exists('evidenceRequirements', $normalized['criteria'][0]),
    'Missing metadata must stay absent in old rubric snapshots; explicit report-only requirements must stay empty.');
foreach ([null, '', ['unknown'], ['datasetDescription', 'datasetDescription'], [false], ['x' => 'datasetDescription'],
    ['datasetDescription', 'datasetStatistics', 'experimentComparison', 'datasetDescription']] as $invalid) {
    $bad = $rubric; $bad['criteria'][0]['evidenceRequirements'] = $invalid;
    evidenceReject(fn () => Rubric::normalize($bad), 'OJ_ML_RUBRIC_EVIDENCE_INVALID');
}
$v1 = $rubric; $v1['version'] = 1;
foreach ($v1['criteria'] as &$criterion) { unset($criterion['levels']); } unset($criterion);
evidenceAssert(Rubric::normalize($v1) === $v1, 'Version 1 evidence requirements must remain supported without grade conversion.');
$manualEntries = array_map(static fn (array $criterion): array => [
    'id' => $criterion['id'], 'score' => $criterion['maxScore'], 'levelId' => 'full', 'feedback' => '교사가 직접 확인함',
], $rubric['criteria']);
$manual = Rubric::review($rubric, ['criteriaScores' => $manualEntries]);
evidenceAssert($manual['score'] === 100 && Rubric::details($manual['details']) === $manual['details'],
    'AI evidence gates must not require the optional AI plugin for independent manual evaluation or snapshot round-trips.');

$dataset = ['profileId' => 'dataset-1', 'status' => 'complete', 'reasons' => [], 'rowsAnalyzed' => 100, 'rowCount' => 100,
    'columnCount' => 3, 'columns' => [
        ['index' => 0, 'type' => 'numeric', 'presentCount' => 80, 'missingCount' => 20, 'missingRate' => 0.2,
            'distinctCount' => 10, 'numeric' => ['mean' => 5.1, 'stdDev' => 2.3], 'suppressionReasons' => []],
        ['index' => 1, 'type' => 'categorical', 'presentCount' => 100, 'missingCount' => 0, 'missingRate' => 0,
            'distinctCount' => 2, 'distribution' => ['counts' => [50, 50], 'suppressed' => false], 'suppressionReasons' => []],
        ['index' => 2, 'type' => 'numeric', 'presentCount' => 100, 'missingCount' => 0, 'missingRate' => 0,
            'distinctCount' => 100, 'numeric' => null, 'suppressionReasons' => ['identifier_like']],
    ]];
$binding = ['role' => 'current', 'experimentIndex' => null, 'usage' => 'training', 'profileId' => 'dataset-1',
    'featureColumns' => [0], 'targetColumn' => 1, 'status' => 'complete', 'reasons' => []];
$project = ['datasetContext' => ['teacherDescription' => '공개 교육용 표 데이터로 길이에서 두 범주를 예측합니다.', 'studentDescription' => ''],
    'datasetProfiles' => ['schemaVersion' => 1, 'source' => 'server_archive_csv', 'status' => 'complete', 'reasons' => [],
        'datasets' => [$dataset], 'bindings' => [$binding]],
    'settings' => ['data' => ['testDatasetConfigured' => false]], 'experiments' => []];
$checks = EvidenceReadiness::assess($rubric, $project);
evidenceAssert($checks['criteria'][0]['status'] === 'ready'
    && $checks['criteria'][0]['requirements'] === ['datasetDescription', 'datasetStatistics'],
    'Existing criteria must resolve conservative defaults; suppression on unrelated identifier columns must not block base statistics.');
evidenceAssert($checks['criteria'][1]['missing'] === ['experimentComparison'] && $checks['criteria'][2]['status'] === 'ready',
    'Explicit requirements must distinguish data comparison from report-only evaluation.');
$absent = EvidenceReadiness::assess($rubric, []);
evidenceAssert($absent['criteria'][0]['missing'] === ['datasetDescription', 'datasetStatistics']
    && $absent['criteria'][2]['status'] === 'ready', 'Absent evidence is teacher review, not a zero-point performance grade.');
$studentDescription = $project;
$studentDescription['datasetContext'] = ['studentDescription' => '이 데이터는 실습용 공개 길이 측정 자료입니다.'];
evidenceAssert(EvidenceReadiness::assess($rubric, $studentDescription)['criteria'][0]['status'] === 'ready',
    'Explicit student dataset description is contextual evidence, without being treated as verified fact.');
$blankDescription = $project; $blankDescription['datasetContext'] = ['teacherDescription' => '<p> </p>', 'studentDescription' => null];
evidenceAssert(in_array('datasetDescription', EvidenceReadiness::assess($rubric, $blankDescription)['criteria'][0]['missing'], true),
    'Empty markup cannot satisfy dataset description requirements.');

foreach (['source', 'rows', 'status', 'mapping', 'counts'] as $case) {
    $bad = $project;
    if ($case === 'source') { $bad['datasetProfiles']['source'] = 'browser_claim'; }
    if ($case === 'rows') { $bad['datasetProfiles']['datasets'][0]['rowsAnalyzed'] = 50; }
    if ($case === 'status') { $bad['datasetProfiles']['datasets'][0]['status'] = 'partial'; }
    if ($case === 'mapping') { $bad['datasetProfiles']['bindings'][0]['targetColumn'] = 99; }
    if ($case === 'counts') { $bad['datasetProfiles']['datasets'][0]['columns'][0]['missingCount'] = 0; }
    evidenceAssert(in_array('datasetStatistics', EvidenceReadiness::assess($rubric, $bad)['criteria'][0]['missing'], true),
        'Unverified, partial, unmatched or inconsistent statistics must fail closed: ' . $case);
}

$experiment = ['taskType' => 'classification', 'sampleCount' => 100,
    'split' => ['method' => 'holdout', 'testSize' => 0.25, 'randomState' => 21, 'stratify' => true],
    'data' => ['testDatasetConfigured' => false],
    'runs' => [['status' => 'done', 'algorithm' => 'decision_tree', 'metrics' => ['accuracy' => 0.8]],
        ['status' => 'done', 'algorithm' => 'naive_bayes', 'metrics' => ['accuracy' => 0.85]]]];
$complete = $project; $complete['experiments'] = [$experiment];
$complete['datasetProfiles']['bindings'][] = array_replace($binding, ['role' => 'experiment', 'experimentIndex' => 0]);
$ready = EvidenceReadiness::assess($rubric, $complete);
evidenceAssert($ready['status'] === 'ready', 'Two runs in one experiment with complete historical data/split and a common metric are comparable records.');
$actualView = (new MlpxReadOnlyProjector())->project(
    ['taskType' => 'classification', 'dataType' => 'tabular', 'appVersion' => '0.12.1', 'formatVersion' => 2],
    ['nSamples' => 100, 'data' => ['features' => ['length'], 'target' => 'class']],
    ['experiments' => [['settings' => ['taskType' => 'classification', 'runtime' => 'browser', 'nSamples' => 100,
        'split' => $experiment['split'], 'data' => ['features' => ['length'], 'target' => 'class',
            'dataset' => ['path' => 'datasets/private.csv']]], 'runs' => $experiment['runs']]]],
    ['template' => ['sections' => []], 'answers' => []]
);
evidenceAssert($actualView['experiments'][0]['runs'][0]['metrics'] === [['name' => 'accuracy', 'value' => 0.8]],
    'The actual read-only metric DTO shape must be used in integration tests.');
$projectorEvidence = array_replace($complete, ['experiments' => $actualView['experiments']]);
evidenceAssert(EvidenceReadiness::assess($rubric, $projectorEvidence)['status'] === 'ready',
    'Historical evidence with the actual projected metric-list shape must satisfy comparison requirements.');
$actualView['datasetProfiles'] = $complete['datasetProfiles'];
$actualSnapshot = AiEvidence::build(
    ['uuid' => '11111111-1111-4111-8111-111111111111', 'artifact' => ['sha256' => str_repeat('a', 64)]],
    ['context_type' => 'group', 'context_id' => 'test-group', 'title' => '실습 평가', 'rubric' => $rubric,
        'policy' => ['datasetDescription' => $project['datasetContext']['teacherDescription']]],
    ['source' => 'verified-read-only', 'content' => $actualView]
);
evidenceAssert($actualSnapshot['evidence']['project']['evidenceReadiness']['status'] === 'ready',
    'The complete read-only projector → AI safe projection → readiness chain must accept the real metric-list DTO.');
$skipped = $projectorEvidence;
$skipped['experiments'][0]['sourceExperimentIndex'] = 1;
$skipped['datasetProfiles']['bindings'][1]['experimentIndex'] = 1;
evidenceAssert(EvidenceReadiness::assess($rubric, $skipped)['status'] === 'ready',
    'Original archive indices must survive skipped malformed experiment entries.');
$wrongBinding = $skipped; $wrongBinding['datasetProfiles']['bindings'][1]['experimentIndex'] = 0;
evidenceAssert(EvidenceReadiness::assess($rubric, $wrongBinding)['criteria'][0]['status'] === 'needs_review',
    'An original experiment index cannot accidentally consume a different projected-list binding.');
$staleCurrent = $complete;
$staleCurrent['datasetProfiles']['bindings'][0]['status'] = 'unavailable';
evidenceAssert(EvidenceReadiness::assess($rubric, $staleCurrent)['status'] === 'ready',
    'A later incomplete current configuration must not replace complete historical experiment evidence.');
$historicMissing = $complete; array_pop($historicMissing['datasetProfiles']['bindings']);
evidenceAssert(EvidenceReadiness::assess($rubric, $historicMissing)['criteria'][0]['missing'] === ['datasetStatistics'],
    'Complete current data must not be substituted for missing historical experiment data.');
$two = $complete; $two['experiments'][0]['runs'] = [$experiment['runs'][0]];
$two['experiments'][] = array_replace($experiment, ['runs' => [$experiment['runs'][1]]]);
$two['datasetProfiles']['bindings'][] = array_replace($binding, ['role' => 'experiment', 'experimentIndex' => 1, 'featureColumns' => [0, 2]]);
evidenceAssert(EvidenceReadiness::assess($rubric, $two)['status'] === 'ready',
    'Feature selection may differ intentionally while dataset, target, evaluation split and metric match.');
foreach (['seed', 'dataset', 'target', 'sampleCount', 'metric', 'failed', 'seedMissing', 'taskMissing', 'nan', 'testMissing'] as $case) {
    $bad = $two;
    if ($case === 'seed') { $bad['experiments'][1]['split']['randomState'] = 99; }
    if ($case === 'dataset') {
        $bad['datasetProfiles']['datasets'][] = array_replace($dataset, ['profileId' => 'dataset-2']);
        $bad['datasetProfiles']['bindings'][2]['profileId'] = 'dataset-2';
    }
    if ($case === 'target') { $bad['datasetProfiles']['bindings'][2]['targetColumn'] = 0; }
    if ($case === 'sampleCount') { $bad['experiments'][1]['sampleCount'] = 99; }
    if ($case === 'metric') { $bad['experiments'][1]['runs'][0]['metrics'] = ['f1' => 0.8]; }
    if ($case === 'failed') { $bad['experiments'][1]['runs'][0]['status'] = 'failed'; }
    if ($case === 'seedMissing') { unset($bad['experiments'][1]['split']['randomState']); }
    if ($case === 'taskMissing') { unset($bad['experiments'][1]['taskType']); }
    if ($case === 'nan') { $bad['experiments'][1]['runs'][0]['metrics'] = ['accuracy' => NAN]; }
    if ($case === 'testMissing') { $bad['experiments'][1]['data']['testDatasetConfigured'] = true; }
    evidenceAssert(in_array('experimentComparison', EvidenceReadiness::assess($rubric, $bad)['criteria'][1]['missing'], true),
        'Incomparable/missing historical settings and metrics cannot substantiate improvement: ' . $case);
}

$runId = 'evidence-ready-run'; $hash = str_repeat('b', 64);
$attempt = ['artifact' => ['sha256' => str_repeat('a', 64)], 'rubric' => $rubric];
$result = ['run_id' => $runId, 'state' => 'ready', 'input_hash' => $hash, 'source_sha256' => str_repeat('a', 64),
    'criteria' => array_map(static fn (array $criterion): array => ['id' => $criterion['id'], 'score' => $criterion['maxScore'],
        'levelId' => 'full', 'level_id' => 'full', 'feedback' => 'AI가 근거 없이 자신 있게 만점을 부여함'], $rubric['criteria']),
    'total_score' => 100, 'max_score' => 100, 'summary' => '모든 항목 만점', 'limitations' => []];
$guarded = EvidenceReadiness::guardResult($result, $checks);
evidenceAssert($guarded['state'] === 'needs_review' && $guarded['needs_review'] === true && $guarded['total_score'] === null,
    'A confident provider must not override deterministic missing-evidence checks.');
evidenceAssert($guarded['criteria'][1]['score'] === null && !isset($guarded['criteria'][1]['levelId'], $guarded['criteria'][1]['level_id'])
    && $guarded['criteria'][1]['reviewStatus'] === 'needs_review'
    && str_contains($guarded['criteria'][1]['feedback'], '교사 확인 필요')
    && !str_contains($guarded['criteria'][1]['feedback'], '만점'), 'An unsupported score/grade/claim is replaced by teacher-review reasons.');
evidenceAssert($guarded['criteria'][0]['score'] === 40 && $guarded['criteria'][2]['score'] === 20,
    'Only unsupported criterion scores are removed; supported analysis remains available for review.');
evidenceReject(fn () => AiReviewDraft::build($attempt, $result, $runId, $hash, $checks), 'OJ_ML_AI_REVIEW_REQUIRED');
evidenceReject(fn () => AiReviewDraft::build($attempt, $result, $runId, $hash, []), 'OJ_ML_AI_REVIEW_REQUIRED');
evidenceAssert(AiReviewDraft::build($attempt, $result, $runId, $hash, $ready)['score'] === 100,
    'Complete minimum evidence permits exact-grade private drafts; it does not bypass teacher publication.');
$providerReview = $result; $providerReview['needs_review'] = true;
evidenceReject(fn () => AiReviewDraft::build($attempt, $providerReview, $runId, $hash, $ready), 'OJ_ML_AI_REVIEW_REQUIRED');
$providerUnknown = $result; $providerUnknown['criteria'][0]['score'] = null;
$unknownGuarded = EvidenceReadiness::guardResult($providerUnknown, $ready);
evidenceAssert($unknownGuarded['state'] === 'needs_review' && $unknownGuarded['total_score'] === null
    && $unknownGuarded['criteria'][0]['reviewStatus'] === 'needs_review',
    'Sufficient minimum inputs must not override the provider identifying content-level insufficient evidence.');
foreach (['queued', 'running', 'failed', 'stale'] as $state) {
    $pending = array_replace($result, ['state' => $state]);
    evidenceAssert(EvidenceReadiness::guardResult($pending, $absent) === $pending, 'Evidence checks must not disguise lifecycle state: ' . $state);
}

fwrite(STDOUT, "OJ ML rubric evidence requirements / historical-data readiness / fail-closed AI draft regression passed.\n");
