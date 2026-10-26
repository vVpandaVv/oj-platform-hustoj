<?php

declare(strict_types=1);

use OJML\Playgrounds\Inspection\InspectionResult;
use OJML\Playgrounds\Inspection\MlpxInspector;

require_once __DIR__ . '/bootstrap.php'; // classes the inspector now uses (PortfolioRequirements)
require dirname(__DIR__) . '/src/Domain/Problem.php';
require dirname(__DIR__) . '/src/Inspection/InspectionResult.php';
require dirname(__DIR__) . '/src/Inspection/MlpxInspector.php';

/** @param mixed $expected @param mixed $actual */
function nextAssertSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . "\nexpected: " . var_export($expected, true) . "\nactual: " . var_export($actual, true)
        );
    }
}

/** @param list<array<string,mixed>> $findings */
function nextHasFinding(array $findings, string $code): bool
{
    foreach ($findings as $finding) {
        if (($finding['code'] ?? null) === $code) {
            return true;
        }
    }
    return false;
}

/** @return array<string,mixed> */
function nextFinding(InspectionResult $result, string $code): array
{
    foreach ($result->findings() as $finding) {
        if (($finding['code'] ?? null) === $code) {
            return $finding;
        }
    }
    throw new RuntimeException('Expected inspection finding: ' . $code);
}

/** @param array<string,string> $hashes */
function nextContentHash(array $hashes): string
{
    ksort($hashes, SORT_STRING);
    $canonical = '';
    foreach ($hashes as $path => $hash) {
        $canonical .= $path . "\n" . $hash . "\n";
    }
    return hash('sha256', $canonical);
}

/**
 * @param list<string> $currentFeatures
 * @param list<string> $experimentFeatures
 * @return array<string,string>
 */
function nextFixtureEntries(
    array $currentFeatures = ['age', 'age (B)', '(C)'],
    array $experimentFeatures = ['age', 'note']
): array {
    // Intentionally retain a pre-0.12 project to verify backward-compatible inspection.
    $manifest = [
        'formatVersion' => 2,
        'appVersion' => '0.11.3',
        'projectId' => '00000000-0000-4000-8000-000000000002',
        'name' => 'Next inspector fixture',
        'createdAt' => '2026-08-31T00:00:00.000Z',
        'updatedAt' => '2026-08-31T00:00:00.000Z',
        'dataType' => 'tabular',
        'taskType' => 'classification',
        'kind' => 'machineLearning',
    ];
    $settings = [
        'data' => [
            'dataset' => [
                'path' => 'dataset/data.csv',
                'originalFileName' => 'data.csv',
                'hasHeader' => true,
                'encoding' => 'utf-8',
            ],
            'features' => $currentFeatures,
        ],
    ];
    $runs = [
        'experiments' => [
            [
                'settings' => ['data' => ['features' => $experimentFeatures]],
                'runs' => [['algorithm' => 'decision_tree']],
            ],
        ],
    ];
    $jsonFlags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    return [
        'manifest.json' => json_encode($manifest, $jsonFlags),
        'settings.json' => json_encode($settings, $jsonFlags),
        'runs.json' => json_encode($runs, $jsonFlags),
        'portfolio/document.json' => json_encode(new stdClass(), $jsonFlags),
        'dataset/data.csv' => "\xEF\xBB\xBFage,age,,note\n1,2,x,\"line one\nline two\"\n3,4,y,done",
    ];
}

/**
 * @param array<string,string> $entries
 * @param list<string> $omitFromHashes
 */
function nextMakeMlpx(array $entries, array $omitFromHashes = []): string
{
    $entryHashes = [];
    foreach ($entries as $path => $bytes) {
        if (!in_array($path, $omitFromHashes, true)) {
            $entryHashes[$path] = hash('sha256', $bytes);
        }
    }
    $hashes = [
        'algorithm' => 'sha256',
        'entries' => $entryHashes,
        'contentHash' => nextContentHash($entryHashes),
    ];
    $entries['hashes.json'] = json_encode(
        $hashes,
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );

    $path = tempnam(sys_get_temp_dir(), 'oj-ml-next-');
    if (!is_string($path)) {
        throw new RuntimeException('Could not create a temporary fixture.');
    }
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Could not create the fixture archive.');
    }
    foreach ($entries as $entry => $bytes) {
        if (!$zip->addFromString($entry, $bytes)) {
            $zip->close();
            throw new RuntimeException('Could not add fixture entry: ' . $entry);
        }
    }
    $zip->close();
    return $path;
}

/** @param array<string,string> $entries @param array<string,mixed> $policy */
function nextInspectEntries(MlpxInspector $inspector, array $entries, array $policy): InspectionResult
{
    $path = nextMakeMlpx($entries);
    try {
        return $inspector->inspect($path, $policy);
    } finally {
        @unlink($path);
    }
}

/** @return array{records:int,rows:int,columns:int,columnNames:list<string>} */
function nextInspectCsv(MlpxInspector $inspector, ReflectionMethod $method, string $csv, bool $hasHeader): array
{
    $stream = fopen('php://temp', 'w+b');
    if (!is_resource($stream)) {
        throw new RuntimeException('Could not create an in-memory CSV stream.');
    }
    try {
        if (fwrite($stream, $csv) !== strlen($csv)) {
            throw new RuntimeException('Could not write the in-memory CSV stream.');
        }
        rewind($stream);
        /** @var array{records:int,rows:int,columns:int,columnNames:list<string>} $facts */
        $facts = $method->invoke($inspector, $stream, 'dataset/data.csv', $hasHeader);
        return $facts;
    } finally {
        fclose($stream);
    }
}

$inspector = new MlpxInspector();
nextAssertSame('5', MlpxInspector::VERSION, 'Required portfolio policy must advance the evaluator version.');
$columnNames = new ReflectionMethod(MlpxInspector::class, 'columnNames');
nextAssertSame(
    ['score', 'score (B)', '(C)', '(D)'],
    $columnNames->invoke($inspector, [' score ', 'score', '', '(D)'], 4),
    'Header normalization must match upstream columnNames().'
);
nextAssertSame(
    ['(B)', '(B) (2)'],
    $columnNames->invoke($inspector, ['(B)', ''], 2),
    'Generated header names must be made unique like the upstream implementation.'
);
$noHeader = $columnNames->invoke($inspector, [], 28);
nextAssertSame('(A)', $noHeader[0] ?? null, 'No-header column A must use the upstream parenthesized name.');
nextAssertSame('(Z)', $noHeader[25] ?? null, 'No-header column Z must use spreadsheet naming.');
nextAssertSame('(AA)', $noHeader[26] ?? null, 'No-header column AA must use spreadsheet naming.');

$csvStream = new ReflectionMethod(MlpxInspector::class, 'inspectCanonicalCsvStream');
$embeddedCrLf = "\xEF\xBB\xBFfirst,second\r\n\"line one\r\nline two\",\"say \"\"hi\"\"\"\r\nx,y";
$embeddedFacts = nextInspectCsv($inspector, $csvStream, $embeddedCrLf, true);
nextAssertSame(3, $embeddedFacts['records'], 'CRLF inside quotes must stay within one CSV record.');
nextAssertSame(2, $embeddedFacts['rows'], 'Only record-separator CRLF may increment the data row count.');
nextAssertSame(2, $embeddedFacts['columns'], 'Embedded CRLF and escaped quotes must preserve CSV width.');

// After the BOM, the first escaped quote is byte 65,535 and its pair begins the next 64 KiB chunk.
$chunkBoundaryCsv = "\xEF\xBB\xBFvalue\r\n\""
    . str_repeat('a', 65527)
    . "\"\"tail\"\r\n";
$chunkFacts = nextInspectCsv($inspector, $csvStream, $chunkBoundaryCsv, true);
nextAssertSame(2, $chunkFacts['records'], 'An escaped quote pair split across chunks must not split records.');
nextAssertSame(1, $chunkFacts['rows'], 'The chunk-boundary fixture must contain one data row.');
nextAssertSame(1, $chunkFacts['columns'], 'The chunk-boundary escaped quote must remain one field.');

$backslashQuoteRecord = '"a\\""b"';
nextAssertSame(
    ['a\\"b'],
    str_getcsv($backslashQuoteRecord, ',', '"', ''),
    'RFC 4180 parsing must preserve a literal backslash immediately before a doubled quote.'
);
$backslashHeaderFacts = nextInspectCsv(
    $inspector,
    $csvStream,
    "\xEF\xBB\xBF" . $backslashQuoteRecord . "\nvalue",
    true
);
nextAssertSame(
    ['a\\"b'],
    $backslashHeaderFacts['columnNames'],
    'The inspector itself must parse backslash plus doubled quote with no proprietary escape semantics.'
);
$backslashFacts = nextInspectCsv(
    $inspector,
    $csvStream,
    "\xEF\xBB\xBFvalue\n" . $backslashQuoteRecord,
    true
);
nextAssertSame(2, $backslashFacts['records'], 'A canonical backslash plus doubled quote must be accepted.');
try {
    nextInspectCsv($inspector, $csvStream, "\xEF\xBB\xBFvalue\n\"a\\\"b\"", true);
    throw new RuntimeException('PHP-style backslash quote escaping must be rejected as non-canonical CSV.');
} catch (OJML\Playgrounds\Domain\Problem $problem) {
    nextAssertSame('OJ_ML_MLPX_DATASET_CSV_INVALID', $problem->problemCode(), 'Backslash quote needs RFC rejection.');
}

try {
    nextInspectCsv($inspector, $csvStream, "\xEF\xBB\xBFname\n\"unterminated", true);
    throw new RuntimeException('An unterminated quoted record must be rejected.');
} catch (OJML\Playgrounds\Domain\Problem $problem) {
    nextAssertSame('OJ_ML_MLPX_DATASET_CSV_INVALID', $problem->problemCode(), 'Unclosed quotes need a precise code.');
}

if (!class_exists(ZipArchive::class)) {
    fwrite(STDOUT, "MlpxInspector pure regressions passed; SKIP ZIP integration (ZipArchive unavailable).\n");
    exit(0);
}

$basePolicy = [
    'allowedFormatVersions' => [2],
    'allowedDataTypes' => ['tabular'],
    'allowedTaskTypes' => ['classification'],
    'allowedAlgorithms' => ['decision_tree'],
    'requireIntegrityManifest' => true,
    'maxRows' => 2,
    'maxFeatures' => 3,
];

$validPath = nextMakeMlpx(nextFixtureEntries());
try {
    $result = $inspector->inspect($validPath, $basePolicy);
    nextAssertSame(InspectionResult::ACCEPTED, $result->status(), 'A canonical tabular fixture must pass.');
    nextAssertSame('Next inspector fixture', $result->facts()['projectName'] ?? null, 'Project name must be available to the teacher summary.');
    nextAssertSame('2026-08-31T00:00:00.000Z', $result->facts()['projectCreatedAt'] ?? null, 'Project creation time must be recorded.');
    nextAssertSame('2026-08-31T00:00:00.000Z', $result->facts()['projectUpdatedAt'] ?? null, 'Project update time must be recorded.');
    $tabular = $result->facts()['tabular'] ?? [];
    nextAssertSame(3, $tabular['records'] ?? null, 'CSV multiline fields must not inflate record count.');
    nextAssertSame(2, $tabular['rows'] ?? null, 'The header must be excluded from policy row count.');
    nextAssertSame(4, $tabular['columns'] ?? null, 'The actual canonical CSV width must be recorded.');
    nextAssertSame(3, $tabular['currentFeatureCount'] ?? null, 'Current source-feature count must be recorded.');
    nextAssertSame(true, $result->facts()['integrity']['verified'] ?? null, 'Complete hashes must verify.');
} finally {
    @unlink($validPath);
}

// Real ZIP documents: diagnostics must explain policy mismatches without changing
// the exact task/algorithm checks or echoing arbitrary uploaded identifiers.
$policyEntries = nextFixtureEntries();
$policyManifest = json_decode($policyEntries['manifest.json'], true, 64, JSON_THROW_ON_ERROR);
$policyManifest['appVersion'] = '0.12.1';
$policyEntries['manifest.json'] = json_encode($policyManifest, JSON_THROW_ON_ERROR);
$policyRuns = json_decode($policyEntries['runs.json'], true, 64, JSON_THROW_ON_ERROR);
$policyRuns['experiments'][0]['settings']['taskType'] = 'classification';
$policyRuns['experiments'][0]['runs'][0]['status'] = 'done';
$policyEntries['runs.json'] = json_encode($policyRuns, JSON_THROW_ON_ERROR);

$taskMismatch = nextInspectEntries($inspector, $policyEntries, array_replace($basePolicy, [
    'allowedTaskTypes' => ['regression', 'clustering'],
]));
nextAssertSame(InspectionResult::REJECTED, $taskMismatch->status(), 'A valid format cannot bypass the allowed task types.');
$taskMessage = nextFinding($taskMismatch, 'OJ_ML_POLICY_TASK_TYPE')['message'];
nextAssertSame(true, str_contains($taskMessage, '제출 유형: 분류'), 'Task mismatch must name the actual canonical type.');
nextAssertSame(true, str_contains($taskMessage, '허용 유형: 회귀, 군집화'), 'Task mismatch must list the allowed canonical types.');

$missingTaskEntries = $policyEntries;
unset($policyManifest['taskType']);
$missingTaskEntries['manifest.json'] = json_encode($policyManifest, JSON_THROW_ON_ERROR);
$missingTask = nextInspectEntries($inspector, $missingTaskEntries, $basePolicy);
nextAssertSame(true, str_contains(nextFinding($missingTask, 'OJ_ML_POLICY_TASK_TYPE')['message'], '제출 유형: 미선택'), 'An optional upstream manifest task must be explained as unselected.');
nextAssertSame(true, str_contains(nextFinding($missingTask, 'OJ_ML_POLICY_TASK_TYPE')['message'], '허용 유형: 분류'), 'Unselected task still needs the allowed task guidance.');
$policyManifest['taskType'] = '<script>PRIVATE_TASK</script>';
$missingTaskEntries['manifest.json'] = json_encode($policyManifest, JSON_THROW_ON_ERROR);
$invalidTask = nextInspectEntries($inspector, $missingTaskEntries, $basePolicy);
nextAssertSame('OJ_ML_MLPX_TASK_TYPE_INVALID', $invalidTask->firstFailureCode(), 'Unknown task strings must still fail structural validation before policy comparison.');
nextAssertSame(false, str_contains(nextFinding($invalidTask, 'OJ_ML_MLPX_TASK_TYPE_INVALID')['message'], 'PRIVATE_TASK'), 'Invalid uploaded task text must not enter diagnostics.');

$algorithmEntries = $policyEntries;
$algorithmRuns = $policyRuns;
$algorithmRuns['experiments'][0]['runs'][0]['algorithm'] = 'random_forest';
$algorithmEntries['runs.json'] = json_encode($algorithmRuns, JSON_THROW_ON_ERROR);
$algorithmMismatch = nextInspectEntries($inspector, $algorithmEntries, array_replace($basePolicy, [
    'allowedAlgorithms' => ['decision_tree', 'knn'],
]));
nextAssertSame(InspectionResult::REJECTED, $algorithmMismatch->status(), 'Correct classification type with a disallowed executed algorithm must still reject.');
nextAssertSame(false, nextHasFinding($algorithmMismatch->findings(), 'OJ_ML_POLICY_TASK_TYPE'), 'Algorithm mismatch must not be misreported as a task type mismatch.');
$algorithmMessage = nextFinding($algorithmMismatch, 'OJ_ML_POLICY_ALGORITHM')['message'];
nextAssertSame(true, str_contains($algorithmMessage, '제출 실행 기록: 랜덤 포레스트'), 'Algorithm mismatch must identify the canonical executed model.');
nextAssertSame(true, str_contains($algorithmMessage, '허용 알고리즘: 의사결정나무, K-최근접 이웃'), 'Algorithm mismatch must list the allowed canonical models.');
nextAssertSame(true, str_contains($algorithmMessage, '과거 실험과 실패·취소 실행도 검사'), 'The message must explain that older and unsuccessful runs remain in scope.');

$historyEntries = $policyEntries;
$historyRuns = $policyRuns;
$oldExperiment = $historyRuns['experiments'][0];
$oldExperiment['settings']['taskType'] = 'regression';
$oldExperiment['runs'] = [
    ['algorithm' => 'random_forest', 'status' => 'failed'],
    ['algorithm' => 'svm', 'status' => 'cancelled'],
];
array_unshift($historyRuns['experiments'], $oldExperiment);
$historyEntries['runs.json'] = json_encode($historyRuns, JSON_THROW_ON_ERROR);
$historyResult = nextInspectEntries($inspector, $historyEntries, $basePolicy);
nextAssertSame(InspectionResult::REJECTED, $historyResult->status(), 'A currently allowed model must not hide disallowed historical failed/cancelled runs.');
nextAssertSame(['decision_tree', 'random_forest', 'svm'], $historyResult->facts()['algorithms'], 'All actual experiment runs remain subject to policy.');
$historyFindings = array_values(array_filter($historyResult->findings(), static fn (array $finding): bool => $finding['code'] === 'OJ_ML_POLICY_ALGORITHM'));
nextAssertSame(2, count($historyFindings), 'Each distinct disallowed historical algorithm needs an actionable finding.');
nextAssertSame(true, str_contains($historyFindings[0]['message'], '랜덤 포레스트'), 'Historical failed model must be named.');
nextAssertSame(true, str_contains($historyFindings[1]['message'], '서포트 벡터 머신'), 'Historical cancelled model must be named.');

$selectionEntries = $policyEntries;
$selectionSettings = json_decode($selectionEntries['settings.json'], true, 64, JSON_THROW_ON_ERROR);
$selectionSettings['selectedAlgorithms'] = [['algorithm' => 'random_forest']];
$selectionSettings['hyperparameters'] = ['svm' => ['browser' => ['algorithm' => 'linear_regression']]];
$selectionEntries['settings.json'] = json_encode($selectionSettings, JSON_THROW_ON_ERROR);
$selectionRuns = $policyRuns;
$selectionRuns['algorithm'] = 'root_decoy';
$selectionRuns['experiments'][0]['algorithm'] = 'experiment_decoy';
$selectionRuns['experiments'][0]['settings']['selectedAlgorithms'] = [['algorithm' => 'random_forest']];
$selectionRuns['experiments'][0]['runs'][0]['parameters'] = ['algorithm' => 'parameter_decoy'];
$selectionEntries['runs.json'] = json_encode($selectionRuns, JSON_THROW_ON_ERROR);
$selectionResult = nextInspectEntries($inspector, $selectionEntries, $basePolicy);
nextAssertSame(InspectionResult::ACCEPTED, $selectionResult->status(), 'Selection-only algorithms and nested metadata keys must not be mistaken for actual run algorithms.');
nextAssertSame(['decision_tree'], $selectionResult->facts()['algorithms'], 'Only experiments[].runs[].algorithm is collected.');

$privateAlgorithm = str_repeat('<script>PRIVATE_STUDENT/path</script>', 100);
$unknownEntries = $policyEntries;
$unknownRuns = $policyRuns;
$unknownRuns['experiments'][0]['runs'][0]['algorithm'] = $privateAlgorithm;
$unknownEntries['runs.json'] = json_encode($unknownRuns, JSON_THROW_ON_ERROR);
$unknownResult = nextInspectEntries($inspector, $unknownEntries, $basePolicy);
nextAssertSame(InspectionResult::REJECTED, $unknownResult->status(), 'An unknown algorithm must not be treated as unrestricted when an allowlist is present.');
$unknownMessage = nextFinding($unknownResult, 'OJ_ML_POLICY_ALGORITHM')['message'];
nextAssertSame(true, str_contains($unknownMessage, '제출 실행 기록: 알 수 없는 알고리즘'), 'Unknown model IDs receive a safe generic label.');
nextAssertSame(false, str_contains($unknownMessage, 'PRIVATE_STUDENT'), 'Uploaded arbitrary identifiers must not enter policy messages.');
nextAssertSame(true, strlen($unknownMessage) < 1000, 'An arbitrarily long uploaded identifier must not inflate the public diagnostic.');
$unknownPolicy = nextInspectEntries($inspector, $algorithmEntries, array_replace($basePolicy, [
    'allowedAlgorithms' => ['decision_tree', $privateAlgorithm, $privateAlgorithm . '2'],
]));
$unknownPolicyMessage = nextFinding($unknownPolicy, 'OJ_ML_POLICY_ALGORITHM')['message'];
nextAssertSame(true, str_contains($unknownPolicyMessage, '허용 알고리즘: 의사결정나무, 기타 지정 알고리즘'), 'Legacy policy IDs must use bounded, deduplicated safe labels.');
nextAssertSame(false, str_contains($unknownPolicyMessage, 'PRIVATE_STUDENT'), 'Arbitrary legacy policy identifiers must not enter public diagnostics.');
nextAssertSame(1, substr_count($unknownPolicyMessage, '기타 지정 알고리즘'), 'Multiple unknown policy IDs must not create an unbounded message.');

foreach ([$algorithmEntries, $historyEntries, $unknownEntries] as $unrestrictedEntries) {
    $unrestricted = nextInspectEntries($inspector, $unrestrictedEntries, array_replace($basePolicy, ['allowedAlgorithms' => []]));
    nextAssertSame(InspectionResult::ACCEPTED, $unrestricted->status(), 'An empty algorithm allowlist must preserve the existing unrestricted policy.');
}

// Large-class evaluations retain exact summaries without a dense confusion matrix.
// Its absence must not force students to resubmit or bypass assignment policy.
$largeClassEntries = $policyEntries;
$largeClassRuns = $policyRuns;
$largeClassRun = &$largeClassRuns['experiments'][0]['runs'][0];
$largeClassRun['algorithm'] = 'decision_tree';
$largeClassRun['metrics'] = ['accuracy' => 1.0, 'f1Macro' => 1.0];
$largeClassRun['perClass'] = [];
for ($classIndex = 0; $classIndex < 2166; $classIndex++) {
    $largeClassRun['perClass'][] = [
        'label' => (string) $classIndex, 'precision' => 1.0, 'recall' => 1.0,
        'specificity' => 1.0, 'f1' => 1.0, 'support' => 4,
    ];
}
unset($largeClassRun['confusionMatrix'], $largeClassRun);
$largeClassEntries['runs.json'] = json_encode($largeClassRuns, JSON_THROW_ON_ERROR);
$largeClassResult = nextInspectEntries($inspector, $largeClassEntries, $basePolicy);
nextAssertSame(InspectionResult::ACCEPTED, $largeClassResult->status(), 'A large-class result without the optional dense matrix must remain a valid submission.');
$largeClassRejected = nextInspectEntries($inspector, $largeClassEntries, array_replace($basePolicy, ['allowedAlgorithms' => ['naive_bayes']]));
nextAssertSame(true, nextHasFinding($largeClassRejected->findings(), 'OJ_ML_POLICY_ALGORITHM'), 'Omitting a dense matrix must not bypass the algorithm policy.');

$presenter = new OJML\Playgrounds\Rest\Presenter();
$presented = $presenter->attempt([
    'uuid' => '00000000-0000-4000-8000-000000000002',
    'assignment_id' => 1, 'attempt_no' => 1, 'status' => 'needs_resubmit', 'policy_revision' => 1,
    'failure' => ['code' => 'OJ_ML_POLICY_ALGORITHM', 'details' => $unknownPolicy->findings()],
    'evaluation' => [
        'id' => 1, 'status' => $unknownPolicy->status(), 'evaluator' => 'mlpx-preflight',
        'evaluator_version' => MlpxInspector::VERSION, 'findings' => $unknownPolicy->findings(),
        'facts' => [],
    ],
]);
nextAssertSame($unknownPolicyMessage, $presented['evaluation']['findings'][0]['message'], 'The learner presenter must preserve actionable policy messages.');
nextAssertSame(['severity', 'code', 'message'], array_keys($presented['evaluation']['findings'][0]), 'Private finding context must remain excluded from REST projection.');
nextAssertSame(['code' => 'OJ_ML_POLICY_ALGORITHM'], $presented['failure'], 'Raw failure details must remain excluded from REST projection.');
nextAssertSame(false, str_contains(json_encode($presented, JSON_THROW_ON_ERROR), 'PRIVATE_STUDENT'), 'The public diagnostic must not expose raw private metadata.');

$unhashedEntries = nextFixtureEntries();
$unhashedEntries['dataset/unhashed.csv'] = "\xEF\xBB\xBFx\n1";
$unhashedPath = nextMakeMlpx($unhashedEntries, ['dataset/unhashed.csv']);
try {
    $result = $inspector->inspect($unhashedPath, $basePolicy);
    nextAssertSame(InspectionResult::REJECTED, $result->status(), 'An unhashed archive entry must be rejected.');
    nextAssertSame(
        true,
        nextHasFinding($result->findings(), 'OJ_ML_MLPX_HASHES_INCOMPLETE'),
        'An unhashed archive entry must emit HASHES_INCOMPLETE.'
    );
    nextAssertSame(false, $result->facts()['integrity']['verified'] ?? null, 'Incomplete hashes cannot verify.');
} finally {
    @unlink($unhashedPath);
}

$rowPath = nextMakeMlpx(nextFixtureEntries());
try {
    $result = $inspector->inspect($rowPath, array_replace($basePolicy, ['maxRows' => 1]));
    nextAssertSame(
        true,
        nextHasFinding($result->findings(), 'OJ_ML_POLICY_MAX_ROWS'),
        'Actual data rows must enforce maxRows.'
    );
} finally {
    @unlink($rowPath);
}

$featurePath = nextMakeMlpx(nextFixtureEntries());
try {
    $result = $inspector->inspect($featurePath, array_replace($basePolicy, ['maxFeatures' => 2]));
    nextAssertSame(
        true,
        nextHasFinding($result->findings(), 'OJ_ML_POLICY_MAX_FEATURES'),
        'Current and experiment source-feature counts must enforce maxFeatures.'
    );
} finally {
    @unlink($featurePath);
}

$duplicatePath = nextMakeMlpx(nextFixtureEntries(['age', 'age']));
try {
    $result = $inspector->inspect($duplicatePath, $basePolicy);
    nextAssertSame(
        true,
        nextHasFinding($result->findings(), 'OJ_ML_MLPX_FEATURE_DUPLICATE'),
        'Duplicate source-feature names must be rejected.'
    );
} finally {
    @unlink($duplicatePath);
}

$requiredPolicy = array_replace($basePolicy, ['portfolioRequirements' => ['requiredSectionIds' => ['topic'], 'minAnswerChars' => 3]]);
foreach (['' => true, '답변' => true, '답변입니다' => false] as $answer => $shouldReject) {
    $entries = nextFixtureEntries();
    $entries['portfolio/document.json'] = json_encode(['template' => ['sections' => [['id' => 'topic', 'title' => '주제']]], 'answers' => ['topic' => $answer]], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $requiredPath = nextMakeMlpx($entries);
    try {
        $result = $inspector->inspect($requiredPath, $requiredPolicy);
        nextAssertSame($shouldReject, nextHasFinding($result->findings(), 'OJ_ML_POLICY_PORTFOLIO_REQUIRED'), 'Server must inspect required answer text inside the actual archive.');
    } finally { @unlink($requiredPath); }
}

fwrite(STDOUT, "MlpxInspector regression tests passed.\n");
