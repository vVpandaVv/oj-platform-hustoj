<?php

declare(strict_types=1);

use OJML\Playgrounds\Inspection\DatasetProfiler;
use OJML\Playgrounds\Inspection\MlpxInspector;

require_once __DIR__ . '/bootstrap.php';

function profileAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function profileReference(string $path = 'dataset/private-raw-canary.csv', bool $header = true): array
{
    return ['path' => $path, 'hasHeader' => $header, 'encoding' => 'utf-8'];
}

function profileSettings(array $reference = [], array $features = ['feature'], string $target = 'target'): array
{
    return ['taskType' => 'classification', 'data' => ['dataset' => $reference === [] ? profileReference() : $reference,
        'features' => $features, 'target' => $target]];
}

/** Exercise real ZIP streams, and never retain temporary fixture data after the test. */
function profileFixture(array $files, array $settings, array $experiments = [], bool $view = false): array
{
    $path = tempnam(sys_get_temp_dir(), 'ojml-profile-');
    if ($path === false) {
        throw new RuntimeException('Cannot create temporary dataset fixture.');
    }
    $zip = new ZipArchive();
    try {
        profileAssert($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 'Fixture ZIP must open.');
        $manifest = ['formatVersion' => 2, 'appVersion' => '0.12.1', 'projectId' => '12345678-1234-4234-8234-123456789012',
            'name' => 'Profile fixture', 'createdAt' => '2026-10-23T00:00:00Z', 'updatedAt' => '2026-10-23T00:00:00Z',
            'taskType' => 'classification', 'dataType' => 'tabular', 'kind' => 'machineLearning'];
        $runs = ['experiments' => $experiments];
        foreach (['manifest.json' => $manifest, 'settings.json' => $settings, 'runs.json' => $runs,
            'portfolio/document.json' => ['answers' => []]] as $name => $document) {
            $zip->addFromString($name, json_encode($document, JSON_THROW_ON_ERROR));
            $zip->setCompressionName($name, ZipArchive::CM_STORE);
        }
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
            $zip->setCompressionName($name, ZipArchive::CM_STORE);
        }
        profileAssert($zip->close(), 'Fixture ZIP must close.');
        if ($view) {
            return (new MlpxInspector())->readOnlyView($path);
        }
        profileAssert($zip->open($path, ZipArchive::RDONLY) === true, 'Fixture ZIP must reopen.');
        $entries = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            $entries[$stat['name']] = ['size' => $stat['size'], 'directory' => str_ends_with($stat['name'], '/')];
        }
        return (new DatasetProfiler())->profile($zip, $entries, $manifest, $settings, $runs);
    } finally {
        if ($zip->status === ZipArchive::ER_OK && $zip->numFiles > 0) {
            @$zip->close();
        }
        unlink($path);
    }
}

$csv = "feature,missing,target\r\n";
for ($i = 0; $i < 20; $i++) {
    $csv .= (($i % 5) + 1) . ',' . ($i < 5 ? ' ' : 'NA') . ',' . ($i < 10 ? 'private-category-A-canary' : 'private-category-B-canary') . "\r\n";
}
$settings = profileSettings([], ['feature', 'missing']);
$profile = profileFixture(['dataset/private-raw-canary.csv' => $csv], $settings);
profileAssert($profile['status'] === 'complete', 'Full safe CSV must produce complete statistics.');
$dataset = $profile['datasets'][0];
profileAssert($dataset['rowCount'] === 20 && $dataset['rowsAnalyzed'] === 20 && $dataset['columnCount'] === 3, 'Header must not be counted as a data row.');
profileAssert($dataset['columns'][0]['type'] === 'numeric' && $dataset['columns'][0]['numeric']['mean'] === 3.0, 'Compute numeric aggregation from actual archived cells.');
profileAssert(abs($dataset['columns'][0]['numeric']['stdDev'] - sqrt(2)) < 0.00001, 'Numeric population standard deviation must be correct.');
profileAssert($dataset['columns'][1]['missingCount'] === 5 && $dataset['columns'][1]['missingRate'] === 0.25, 'Whitespace is missing, NA remains present.');
profileAssert($dataset['columns'][2]['distribution'] === ['counts' => [10, 10], 'suppressed' => false], 'Counts only, no category labels.');
profileAssert($profile['bindings'][0]['featureColumns'] === [0, 1] && $profile['bindings'][0]['targetColumn'] === 2, 'Bind selected feature/target names to opaque column indexes.');
profileAssert($profile['stage'] === 'before_preprocessing' && $profile['missingDefinition'] === 'empty_or_whitespace', 'State exact source and semantics.');
$encoded = json_encode($profile, JSON_THROW_ON_ERROR);
foreach (['private-category-A-canary', 'private-category-B-canary', 'private-raw-canary', 'dataset/', '"feature"', '"target"', '"NA"', 'sha256', 'originalFileName'] as $canary) {
    profileAssert(!str_contains($encoded, $canary), 'No raw values, headers, filenames or fingerprints may leave the profiler: ' . $canary);
}

$experiments = [['settings' => $settings, 'runs' => []], ['settings' => profileSettings(profileReference('dataset/old-private.csv')), 'runs' => []],
    ['settings' => ['data' => ['features' => ['feature'], 'target' => 'target']], 'runs' => []]];
$historical = profileFixture(['dataset/private-raw-canary.csv' => $csv, 'dataset/old-private.csv' => "feature,target\n1,A\n2,B\n"], $settings, $experiments);
profileAssert(count($historical['datasets']) === 2, 'Deduplicate same exact reference but never substitute current for historical data.');
profileAssert($historical['bindings'][1]['profileId'] === $historical['bindings'][0]['profileId'], 'Equal historical archive references share opaque identity.');
profileAssert($historical['bindings'][2]['profileId'] !== $historical['bindings'][0]['profileId'], 'Different historical data gets a different profile.');
profileAssert($historical['bindings'][3]['profileId'] === null && in_array('dataset_reference_missing', $historical['bindings'][3]['reasons'], true), 'Missing snapshot data must stay unavailable, not inherit current.');

$withTest = $settings;
$withTest['data']['testDataset'] = profileReference('dataset/test-private.csv');
$testBound = profileFixture(['dataset/private-raw-canary.csv' => $csv, 'dataset/test-private.csv' => $csv], $withTest);
profileAssert($testBound['bindings'][1]['usage'] === 'test' && $testBound['bindings'][1]['targetColumn'] === 2, 'External test data is bound separately.');

$quoted = "\xEF\xBB\xBF\"feature\",target\r\n\"10\",\"private comma,value\"\r\n\"20\",\"private\nmultiline\"\r\n30,\"doubled \"\"quote\"\"\"\r\n";
$quoteProfile = profileFixture(['dataset/private-raw-canary.csv' => $quoted], profileSettings());
profileAssert($quoteProfile['datasets'][0]['rowCount'] === 3 && $quoteProfile['bindings'][0]['featureColumns'] === [0], 'BOM, CRLF, quoted fields, embedded newlines and doubled quotes parse exactly.');
profileAssert(!str_contains(json_encode($quoteProfile), 'private'), 'Multiline/category values must not leak.');

$unicode = "feature,target\n\"\xC2\xA0\",A\n\"\xEF\xBB\xBF\",B\n\"\xC2\x85\",C\nnull,D\nNaN,E\n";
$unicodeProfile = profileFixture(['dataset/private-raw-canary.csv' => $unicode], profileSettings());
profileAssert($unicodeProfile['datasets'][0]['columns'][0]['missingCount'] === 2, 'Missing detection matches JS trim; NEL, null and NaN remain present.');
$noHeader = profileFixture(['dataset/private-raw-canary.csv' => "1,A\n2,B"], profileSettings(profileReference('dataset/private-raw-canary.csv', false), ['(A)'], '(B)'));
profileAssert($noHeader['status'] === 'complete' && $noHeader['datasets'][0]['rowCount'] === 2, 'No-header data keeps first data row.');
$duplicateHeaders = profileFixture(['dataset/private-raw-canary.csv' => "feature,feature,\n1,2,A\n2,3,B"], profileSettings([], ['feature', 'feature (B)'], '(C)'));
profileAssert($duplicateHeaders['bindings'][0]['featureColumns'] === [0, 1] && $duplicateHeaders['bindings'][0]['targetColumn'] === 2, 'Duplicate and empty headers use canonical names.');

foreach (["feature,target\n1,A,B" => 'row_width_mismatch', "feature,target\n\"1,A" => 'invalid_csv',
    "feature,target\n1\"oops,A" => 'invalid_csv', "feature,target\n\"1\"oops,A" => 'invalid_csv',
    "feature,target\n\xFF,A" => 'invalid_utf8', "feature,target\n" => 'empty_dataset'] as $bad => $reason) {
    $invalid = profileFixture(['dataset/private-raw-canary.csv' => $bad], profileSettings());
    profileAssert($invalid['datasets'][0]['status'] === 'unavailable' && $invalid['datasets'][0]['rowCount'] === null
        && $invalid['datasets'][0]['columns'] === [] && in_array($reason, $invalid['datasets'][0]['reasons'], true), 'Invalid CSV must not produce authoritative prefix statistics: ' . $reason);
}
foreach ([['path' => 'https://external.invalid/raw.csv', 'hasHeader' => true, 'encoding' => 'utf-8'],
    ['path' => 'dataset/../private.csv', 'hasHeader' => true, 'encoding' => 'utf-8'],
    ['path' => 'dataset/private-raw-canary.csv', 'hasHeader' => true, 'encoding' => 'cp949'],
    ['path' => 'dataset/missing.csv', 'hasHeader' => true, 'encoding' => 'utf-8'],
    ['path' => 'dataset/private.bin', 'hasHeader' => true, 'encoding' => 'utf-8']] as $ref) {
    $unsupported = profileFixture(['dataset/private-raw-canary.csv' => $csv], profileSettings($ref));
    profileAssert($unsupported['status'] === 'unavailable' && $unsupported['datasets'] === [], 'Unsupported references cannot open arbitrary entries or URLs.');
}
$unmapped = profileFixture(['dataset/private-raw-canary.csv' => $csv], profileSettings([], ['not-a-column'], 'not-a-target'));
profileAssert($unmapped['bindings'][0]['status'] === 'unavailable' && in_array('feature_column_unresolved', $unmapped['bindings'][0]['reasons'], true)
    && in_array('target_column_unresolved', $unmapped['bindings'][0]['reasons'], true), 'Unresolved feature and target mappings must fail closed.');

$unique = "feature,target\n";
for ($i = 0; $i < 20; $i++) {
    $unique .= (99000000 + $i) . ',' . ($i < 19 ? 'private-common' : 'private-singleton') . "\n";
}
$privacy = profileFixture(['dataset/private-raw-canary.csv' => $unique], profileSettings());
profileAssert($privacy['datasets'][0]['columns'][0]['numeric'] === null && in_array('high_uniqueness', $privacy['datasets'][0]['columns'][0]['suppressionReasons'], true), 'Unique numeric identifiers have no extrema/means.');
profileAssert($privacy['datasets'][0]['columns'][1]['distribution'] === ['counts' => [], 'suppressed' => true], 'A single small category suppresses every distribution count, preventing subtraction.');
$constant = profileFixture(['dataset/private-raw-canary.csv' => "feature,target\n" . str_repeat("987654321,A\n", 10)], profileSettings());
profileAssert($constant['datasets'][0]['columns'][0]['numeric'] === null && !str_contains(json_encode($constant), '987654321'), 'Constant numeric aggregates cannot expose the original singleton value.');
$small = profileFixture(['dataset/private-raw-canary.csv' => "feature,target\n1,A\n2,B\n3,A\n4,B\n"], profileSettings());
profileAssert($small['datasets'][0]['columns'][0]['numeric'] === null, 'Small-group numeric aggregates suppressed.');

$rowLimit = profileFixture(['dataset/private-raw-canary.csv' => "feature,target\n" . str_repeat("1,A\n", DatasetProfiler::MAX_ROWS + 1)], profileSettings());
profileAssert($rowLimit['datasets'][0]['status'] === 'partial' && $rowLimit['datasets'][0]['rowCount'] === null
    && $rowLimit['datasets'][0]['rowsAnalyzed'] === DatasetProfiler::MAX_ROWS && in_array('row_limit', $rowLimit['reasons'], true), 'Truncated rows must not claim full counts or completeness.');
$wide = profileFixture(['dataset/private-raw-canary.csv' => implode(',', array_fill(0, DatasetProfiler::MAX_COLUMNS + 1, 'header')) . "\n"], profileSettings());
profileAssert(in_array('column_limit', $wide['reasons'], true), 'Column caps apply before building column accumulators.');
$largeRecord = profileFixture(['dataset/private-raw-canary.csv' => "feature,target\n" . str_repeat('x', 262145) . ',A'], profileSettings());
profileAssert(in_array('record_limit', $largeRecord['reasons'], true), 'Large single records are rejected before CSV parsing allocations.');
$largeFile = profileFixture(['dataset/private-raw-canary.csv' => str_repeat('x', DatasetProfiler::MAX_BYTES + 1)], profileSettings());
profileAssert(in_array('byte_limit', $largeFile['reasons'], true) && $largeFile['datasets'][0]['rowsAnalyzed'] === 0, 'Byte cap prevents opening oversized CSV content.');

$many = "feature,target\n";
for ($i = 0; $i < DatasetProfiler::MAX_DISTINCT_PER_COLUMN + 10; $i++) {
    $many .= $i . ',' . ($i % 2) . "\n";
}
$cardinality = profileFixture(['dataset/private-raw-canary.csv' => $many], profileSettings());
profileAssert($cardinality['datasets'][0]['columns'][0]['distinctCount'] === null
    && $cardinality['datasets'][0]['columns'][0]['distinctAtLeast'] === DatasetProfiler::MAX_DISTINCT_PER_COLUMN + 1
    && $cardinality['datasets'][0]['columns'][0]['numeric'] === null, 'Cardinality cap is explicit lower bound, not a false complete count.');

$largeDistinct = "a,b,c,d,e,f,target\n";
for ($i = 0; $i < 4000; $i++) {
    $largeDistinct .= implode(',', array_fill(0, 6, (string) $i)) . ',label' . ($i % 2) . "\n";
}
$globalDistinct = profileFixture(['dataset/private-raw-canary.csv' => $largeDistinct], profileSettings([], ['a'], 'target'));
profileAssert(count(array_filter($globalDistinct['datasets'][0]['columns'], static fn (array $column): bool => $column['distinctCount'] === null)) > 0,
    'Global hashed cardinality budget prevents columns multiplying memory beyond the shared cap.');

$header128 = implode(',', array_map(static fn (int $index): string => 'c' . $index, range(0, 127))) . "\n";
$row128 = implode(',', array_fill(0, 128, '1')) . "\n";
$cellLimit = profileFixture(['dataset/private-raw-canary.csv' => $header128 . str_repeat($row128, 8000)], profileSettings([], ['c0'], 'c1'));
profileAssert(in_array('cell_limit', $cellLimit['reasons'], true)
    && $cellLimit['datasets'][0]['rowsAnalyzed'] === intdiv(DatasetProfiler::MAX_CELLS, 128)
    && $cellLimit['datasets'][0]['rowCount'] === null, 'Shared cell work budget must return explicit prefix statistics.');

$chunkBoundary = "feature,target\n1,\"" . str_repeat('x', 65519) . "\"\"tail\"\n2,B\n";
$chunkProfile = profileFixture(['dataset/private-raw-canary.csv' => $chunkBoundary], profileSettings());
profileAssert($chunkProfile['datasets'][0]['rowCount'] === 2, 'Doubled quotes split across 64 KiB chunks must preserve logical records.');

$numberForms = profileFixture(['dataset/private-raw-canary.csv' => "feature,target\n0x10,A\n0b10,B\n0o10,A\n1e2,B\nInfinity,A\n1,000\n"], profileSettings());
profileAssert($numberForms['datasets'][0]['columns'][0]['type'] === 'mixed', 'Finite JS numeric forms accepted but Infinity remains non-numeric.');
$overflow = profileFixture(['dataset/private-raw-canary.csv' => "feature,target\n" . str_repeat("1e308,A\n-1e308,B\n", 5)], profileSettings());
profileAssert($overflow['datasets'][0]['columns'][0]['numeric'] === null
    && in_array('numeric_range', $overflow['datasets'][0]['columns'][0]['suppressionReasons'], true), 'Numeric overflow never serializes NaN/Infinity as evidence.');

$manyFiles = [];
$manyExperiments = [];
for ($i = 0; $i < DatasetProfiler::MAX_DATASETS + 1; $i++) {
    $name = 'dataset/file-' . $i . '.csv';
    $manyFiles[$name] = "feature,target\n1,A\n2,B";
    $manyExperiments[] = ['settings' => profileSettings(profileReference($name))];
}
$datasetLimit = profileFixture($manyFiles, profileSettings(profileReference('dataset/file-0.csv')), $manyExperiments);
profileAssert(count($datasetLimit['datasets']) === DatasetProfiler::MAX_DATASETS && in_array('dataset_limit', $datasetLimit['reasons'], true), 'Limit archive datasets globally and mark missing bindings.');
$responseFiles = [];
$responseExperiments = [];
for ($i = 0; $i < DatasetProfiler::MAX_DATASETS; $i++) {
    $name = 'dataset/response-' . $i . '.csv';
    $responseFiles[$name] = $header128 . str_repeat($row128, 10);
    $responseExperiments[] = ['settings' => profileSettings(profileReference($name), ['c0'], 'c1')];
}
$responseLimit = profileFixture($responseFiles, profileSettings(profileReference('dataset/response-0.csv'), ['c0'], 'c1'), $responseExperiments);
profileAssert(in_array('response_limit', $responseLimit['reasons'], true) && strlen(json_encode($responseLimit)) <= DatasetProfiler::MAX_RESPONSE_BYTES
    && $responseLimit['datasets'][0]['columns'] === [], 'Response budget omission must be explicit and unavailable, not complete empty data.');
$experimentLimit = profileFixture(['dataset/private-raw-canary.csv' => $csv], $settings, array_fill(0, 21, ['settings' => $settings]));
profileAssert(count($experimentLimit['bindings']) === 21 && in_array('experiment_limit', $experimentLimit['reasons'], true), 'Experiment cap must be explicit.');

// Reproduce the reported classroom shape with synthetic cells only: 8,760 x 14,
// including a 2,166-category target. No student CSV, samples, names or IDs involved.
$classroomHeaders = array_map(static fn (int $index): string => 'synthetic-column-' . $index, range(0, 13));
$classroomCsv = implode(',', $classroomHeaders) . "\n";
for ($row = 0; $row < 8760; $row++) {
    $cells = [];
    for ($column = 0; $column < 13; $column++) {
        $cells[] = (string) (($row + $column) % 100);
    }
    $cells[] = 'private-synthetic-category-canary-' . ($row % 2166);
    $classroomCsv .= implode(',', $cells) . "\n";
}
gc_collect_cycles();
$classroomMemoryBefore = memory_get_usage(false);
if (function_exists('memory_reset_peak_usage')) {
    memory_reset_peak_usage();
}
$classroomStart = microtime(true);
$classroom = profileFixture(['dataset/private-raw-canary.csv' => $classroomCsv],
    profileSettings([], array_slice($classroomHeaders, 0, 13), $classroomHeaders[13]));
$classroomDuration = microtime(true) - $classroomStart;
$classroomMemoryDelta = max(0, memory_get_usage(false) - $classroomMemoryBefore);
$classroomPeakDelta = function_exists('memory_reset_peak_usage') ? max(0, memory_get_peak_usage(false) - $classroomMemoryBefore) : null;
profileAssert($classroom['status'] === 'complete' && $classroom['datasets'][0]['rowCount'] === 8760
    && $classroom['datasets'][0]['columnCount'] === 14 && $classroom['datasets'][0]['columns'][13]['distinctCount'] === 2166,
    'The realistic 8,760-row x 14-column classroom dataset must complete, including a 2,166-category target.');
profileAssert($classroom['datasets'][0]['columns'][0]['missingCount'] === 0
    && $classroom['datasets'][0]['columns'][0]['numeric'] !== null,
    'Repeated classroom numeric features must retain useful bounded aggregates.');
profileAssert($classroom['datasets'][0]['columns'][13]['distribution'] === ['counts' => [], 'suppressed' => true],
    'Classroom target categories with fewer than five occurrences must suppress every category count.');
profileAssert(!str_contains(json_encode($classroom, JSON_THROW_ON_ERROR), 'private-synthetic-category-canary-')
    && !str_contains(json_encode($classroom, JSON_THROW_ON_ERROR), 'synthetic-column-'),
    'Realistic high-cardinality profiles must never expose synthetic category values or raw header strings.');
fwrite(STDOUT, sprintf("Classroom profile: 8760 rows x 14 columns; %.3f s; retained memory delta %d bytes; peak delta %s bytes; CSV %d bytes.\n",
    $classroomDuration, $classroomMemoryDelta, $classroomPeakDelta === null ? 'unavailable' : (string) $classroomPeakDelta, strlen($classroomCsv)));
unset($classroomCsv, $classroom, $cells);

$integrated = profileFixture(['dataset/private-raw-canary.csv' => $csv], $settings, [['settings' => $settings, 'runs' => []]], true);
profileAssert($integrated['datasetProfiles']['status'] === 'complete' && $integrated['schemaVersion'] === 1, 'Actual inspector preview adds profiles without breaking existing schema.');
profileAssert(strlen(json_encode($integrated, JSON_THROW_ON_ERROR)) <= 262144, 'Whole inspector response retains its transport bound.');
foreach (['private-category-A-canary', 'private-category-B-canary', 'private-raw-canary', 'dataset/private'] as $canary) {
    profileAssert(!str_contains(json_encode($integrated), $canary), 'Actual inspector preview must not expose raw CSV values or paths.');
}

fwrite(STDOUT, "OJ ML dataset profile regression passed.\n");
