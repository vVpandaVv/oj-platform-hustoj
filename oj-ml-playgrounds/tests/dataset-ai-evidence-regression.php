<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use OJML\Playgrounds\Application\AiEvidence;
use OJML\Playgrounds\Application\DatasetProfileEvidence;
use OJML\Playgrounds\Domain\DatasetContext;
use OJML\Playgrounds\Domain\Problem;
use OJML\Playgrounds\Inspection\DatasetProfiler;
use OJML\Playgrounds\Inspection\MlpxReadOnlyProjector;

function dataAiAssert(bool $value, string $message): void { if (!$value) { throw new RuntimeException($message); } }

$context = DatasetContext::policy(['datasetDescription' => '<b>관측 자료</b>', 'datasetDescriptionSectionId' => 'source_info']);
dataAiAssert($context['datasetDescription'] === '관측 자료', 'Description must be plain text.');
foreach ([['datasetDescription' => str_repeat('가', 4001)], ['datasetDescription' => "\xFF"], ['datasetDescriptionSectionId' => '../raw']] as $invalid) {
    try { DatasetContext::policy($invalid); throw new RuntimeException('Invalid description accepted.'); }
    catch (Problem $problem) { dataAiAssert($problem->problemCode() === 'OJ_ML_DATASET_DESCRIPTION_INVALID', 'Stable error.'); }
}
$path = tempnam(sys_get_temp_dir(), 'oj-ml-data-ai-');
dataAiAssert(is_string($path), 'Fixture temporary path.');
$zip = new ZipArchive();
dataAiAssert($zip->open($path, ZipArchive::OVERWRITE) === true, 'Fixture zip.');
$csv = "x,target,note\n";
for ($row = 0; $row < 12; $row++) {
    $csv .= ($row < 6 ? '10,CATEGORY_SECRET_A' : '20,CATEGORY_SECRET_B') . ',ROW_SECRET_' . $row . "\n";
}
$zip->addFromString('dataset/data.csv', $csv);
$zip->close();
$zip->open($path);
try {
    $manifest = ['dataType' => 'tabular', 'taskType' => 'classification', 'appVersion' => '0.12.1', 'formatVersion' => 2];
    $settings = ['taskType' => 'classification', 'runtime' => 'browser', 'nSamples' => 12,
        'split' => ['method' => 'holdout', 'testSize' => 0.25, 'stratify' => true, 'randomState' => 42],
        'data' => ['dataset' => ['path' => 'dataset/data.csv', 'hasHeader' => true, 'encoding' => 'utf-8', 'originalFileName' => 'PRIVATE_FILENAME.csv'],
            'features' => ['x'], 'target' => 'target', 'preprocessing' => ['missing' => 'mean', 'scaling' => 'standard']]];
    $runs = ['experiments' => [['settings' => $settings, 'runs' => [
        ['algorithm' => 'decision_tree', 'status' => 'done', 'metrics' => ['accuracy' => 0.8],
            'perClass' => [['label' => 'CATEGORY_SECRET_A', 'precision' => 0.8, 'support' => 6]],
            'confusionMatrix' => ['labels' => ['CATEGORY_SECRET_A', 'CATEGORY_SECRET_B'], 'matrix' => [[5, 1], [1, 5]]]],
        ['algorithm' => 'naive_bayes', 'status' => 'done', 'metrics' => ['accuracy' => 0.9]],
    ]]]];
    $profile = (new DatasetProfiler())->profile($zip, ['dataset/data.csv' => ['size' => strlen($csv), 'directory' => false]], $manifest, $settings, $runs);
    dataAiAssert($profile['datasets'][0]['status'] === 'complete', 'Actual CSV must be completely aggregated.');
    $portfolio = ['template' => ['sections' => [['id' => 'dataset_description', 'title' => 'STUDENT_INSTRUCTION_SECRET']]],
        'answers' => ['dataset_description' => '학생 설명: 입력 변수와 예측 목표를 기록했습니다.']];
    $view = (new MlpxReadOnlyProjector())->project($manifest, $settings, $runs, $portfolio);
    $view['datasetProfiles'] = $profile;
    $rubric = ['version' => 1, 'criteria' => [['id' => 'data', 'title' => '데이터 평가', 'description' => '선택과 비교의 근거', 'maxScore' => 100,
        'evidenceRequirements' => ['datasetDescription', 'datasetStatistics', 'experimentComparison']]]];
    $attempt = ['uuid' => '11111111-1111-4111-8111-111111111111', 'artifact' => ['sha256' => str_repeat('a', 64)]];
    $assignment = ['title' => '과제', 'context_type' => 'group', 'context_id' => '7', 'rubric' => $rubric,
        'policy' => ['datasetDescription' => '교사 설명: 수업용 관측 자료와 변수 정의입니다.']];
    $build = static fn (array $view, array $assignment): array => AiEvidence::build($attempt, $assignment, ['source' => 'verified-read-only', 'content' => $view]);
    $snapshot = $build($view, $assignment);
    $project = $snapshot['evidence']['project'];
    dataAiAssert($project['evidenceReadiness']['status'] === 'ready', 'Real CSV + real projector metric list + descriptions must satisfy the minimum evidence contract.');
    dataAiAssert($project['datasetProfiles']['datasets'][0]['columns'][0]['numeric']['mean'] === 15.0, 'Computed numeric aggregate must reach AI.');
    dataAiAssert($project['datasetContext']['teacherDescription'] !== $project['datasetContext']['studentDescription']
        && $project['datasetContext']['verifiedMeaning'] === false, 'Separate unverified description provenance.');
    require_once dirname(__DIR__, 2) . '/oj-ai-dashboard/src/ExternalAssessment/Assessment_Profile.php';
    $contract = \OJAI\Dashboard\ExternalAssessment\Assessment_Profile::snapshot($snapshot, $attempt['uuid']);
    $input = \OJAI\Dashboard\ExternalAssessment\Assessment_Profile::input($contract);
    $encoded = json_encode($input, JSON_UNESCAPED_UNICODE);
    foreach (['ROW_SECRET', 'CATEGORY_SECRET', 'PRIVATE_FILENAME', 'dataset/data.csv', 'STUDENT_INSTRUCTION_SECRET'] as $secret) {
        dataAiAssert(!str_contains($encoded, $secret), 'External AI leaked a raw value, file path or student instruction: ' . $secret);
    }
    $poisoned = $profile;
    $poisoned['rawRows'] = [['RAW_CANARY']];
    $poisoned['datasets'][0]['columns'][0]['sampleValues'] = ['RAW_CANARY'];
    $poisoned['datasets'][0]['columns'][0]['numeric']['min'] = 'RAW_CANARY';
    $poisoned['datasets'][0]['path'] = 'RAW_CANARY';
    dataAiAssert(!str_contains(json_encode(DatasetProfileEvidence::project($poisoned)), 'RAW_CANARY'), 'Future profile fields must not cross the allowlist.');
    $imageLabels = $view;
    $imageLabels['settings']['data']['categories'] = ['CATEGORY_SECRET_IMAGE'];
    dataAiAssert(!str_contains(json_encode($build($imageLabels, $assignment)), 'CATEGORY_SECRET_IMAGE'), 'Image settings category labels must also stay out of AI input.');
    $timedOut = $profile;
    $timedOut['reasons'] = ['time_limit'];
    $anotherTimeout = $timedOut;
    $anotherTimeout['datasets'][0]['rowsAnalyzed'] = 3;
    dataAiAssert(DatasetProfileEvidence::project($timedOut) === DatasetProfileEvidence::project($anotherTimeout), 'Timeout prefixes must not produce timing-dependent AI cache identities.');
    $changed = $assignment;
    $changed['policy']['datasetDescription'] .= ' 설명 수정';
    $next = \OJAI\Dashboard\ExternalAssessment\Assessment_Profile::snapshot($build($view, $changed), $attempt['uuid']);
    dataAiAssert(\OJAI\Dashboard\ExternalAssessment\Assessment_Profile::digest($contract) !== \OJAI\Dashboard\ExternalAssessment\Assessment_Profile::digest($next), 'Description changes must invalidate cached AI.');
    $limited = $view;
    $limited['datasetProfiles']['datasets'][0]['status'] = 'partial';
    $limited['datasetProfiles']['datasets'][0]['rowCount'] = null;
    $limitedResult = $build($limited, $assignment);
    dataAiAssert($limitedResult['evidence']['project']['evidenceReadiness']['status'] === 'needs_review', 'Partial statistics are explicit missing evidence, not a score or invented full count.');
} finally {
    $zip->close();
    unlink($path);
}
fwrite(STDOUT, "OJ ML dataset description / actual CSV / AI privacy boundary regression passed.\n");
