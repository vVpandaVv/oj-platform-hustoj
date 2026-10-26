<?php

declare(strict_types=1);

use OJML\Playgrounds\Inspection\MlpxReadOnlyProjector;

function ojMlPreviewAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
require_once $root . '/src/Inspection/MlpxReadOnlyProjector.php';

$manifest = [
    'formatVersion' => 2,
    'appVersion' => '0.12.1',
    'projectId' => '11111111-1111-4111-8111-111111111111',
    'name' => '<img src=x onerror=alert(1)>',
    'createdAt' => '2026-09-01T00:00:00Z',
    'updatedAt' => '2026-09-01T01:00:00Z',
    'student' => ['studentId' => 'private-student-id', 'name' => 'private-student-name'],
    'taskType' => 'classification',
    'dataType' => 'tabular',
    'locale' => 'ko-KR',
    'unknownSecret' => 'manifest-secret',
];
$settings = [
    'runtime' => 'browser',
    'nSamples' => 120,
    'split' => ['method' => 'holdout', 'testSize' => 0.2, 'stratify' => true, 'randomState' => 42],
    'selectedAlgorithms' => [['algorithm' => 'decision_tree', 'runtime' => 'browser']],
    'hyperparameters' => [
        'decision_tree' => [
            'browser' => [
                'maxDepth' => 5,
                'enabled' => true,
                'apiKey' => 'api-key-secret',
                'path' => 'C:/Users/student/private-dataset.csv',
                'nestedSecret' => ['path' => 'never-render'],
            ],
        ],
    ],
    'data' => [
        'dataset' => [
            'path' => 'dataset/private.csv',
            'originalFileName' => 'student-private-name.csv',
            'hasHeader' => true,
            'encoding' => 'utf-8',
        ],
        'features' => ['age', '<script>feature</script>'],
        'target' => 'label',
        'preprocessing' => ['missing' => 'drop', 'scaling' => 'standard', 'categoricalEncoding' => 'onehot'],
    ],
];
$runs = [
    'experiments' => [[
        'id' => 'experiment-1',
        'startedAt' => '2026-09-01T01:10:00Z',
        'settings' => [
            'taskType' => 'classification',
            'runtime' => 'browser',
            'nSamples' => 120,
            'split' => ['method' => 'holdout', 'testSize' => 0.2, 'stratify' => true, 'randomState' => 42],
            'selectedAlgorithms' => [['algorithm' => 'decision_tree', 'runtime' => 'browser']],
            'hyperparameters' => ['decision_tree' => ['browser' => ['maxDepth' => 3]]],
            'data' => [
                'type' => 'tabular',
                'features' => ['historic-feature'],
                'target' => 'historic-target',
                'preprocessing' => ['missing' => 'drop', 'scaling' => 'minmax', 'categoricalEncoding' => 'ordinal'],
                'dataset' => ['path' => 'dataset/private-experiment.csv', 'rows' => [['private-experiment-value']]],
                'unknownSnapshotSecret' => 'snapshot-secret',
            ],
            'trainIndices' => [1, 2, 3],
            'testIndices' => [4, 5],
        ],
        'preprocessor' => ['format' => 'json', 'path' => 'preprocessor/private.json'],
        'runs' => [[
            'id' => 'run-1',
            'algorithm' => 'decision_tree',
            'computedBy' => 'browser',
            'trainedAt' => '2026-09-01T01:11:00Z',
            'status' => 'done',
            'hyperparameters' => [
                'maxDepth' => 5,
                'apiKey' => 'run-api-key-secret',
                'path' => 'C:/Users/student/model-cache.bin',
                'complex' => ['private' => true],
            ],
            'metrics' => ['accuracy' => 0.91, 'f1Macro' => 0.88, 'unknownMetric' => 999],
            'perClass' => [[
                'label' => '<script>class</script>',
                'precision' => 0.9,
                'recall' => 0.8,
                'specificity' => 0.7,
                'f1' => 0.85,
                'support' => 10,
            ]],
            'confusionMatrix' => ['labels' => ['A', 'B'], 'matrix' => [[8, 2], [1, 9]]],
            'featureImportance' => [['feature' => 'age', 'importance' => 0.7]],
            'engine' => ['kind' => 'mljs', 'version' => '1'],
            'failure' => ['code' => 'NOT_USED', 'params' => ['secret' => 'failure-secret']],
            'warning' => ['code' => 'WARN_ONLY', 'params' => ['secret' => 'warning-secret']],
            'model' => [
                'format' => 'json',
                'path' => 'model/private.bin',
                'includesPreprocessing' => true,
                'sizeBytes' => 1234,
            ],
            'modelOmitted' => 'engineUnsupported',
            'modelOmittedDetail' => 'serializer failed at C:/Users/student/model-cache.bin with token-detail-secret',
            'unknownRunSecret' => 'run-secret',
        ]],
    ]],
];
$portfolio = [
    'template' => [
        'locale' => 'ko-KR',
        'sections' => [[
            'id' => 'reflection',
            'title' => '<img src=x onerror=alert(2)>',
            'description' => '실험을 설명하세요.',
        ]],
    ],
    'answers' => [
        'reflection' => "<script>alert('answer')</script>\n두 번째 줄",
        'orphan' => '추가 답변',
    ],
    'answerFormat' => 'plain-v1',
    'attachments' => [
        'reflection' => ['portfolio/attachments/private-file.txt'],
    ],
    'unknownPortfolioSecret' => 'portfolio-secret',
];

$projector = new MlpxReadOnlyProjector();
$view = $projector->project($manifest, $settings, $runs, $portfolio);
$encoded = json_encode($view, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

ojMlPreviewAssert($view['schemaVersion'] === 1, 'Preview schema version must be explicit.');
ojMlPreviewAssert($view['portfolio']['sections'][0]['answer'] === $portfolio['answers']['reflection'], 'Plain answers must be preserved for escaped UI rendering.');
ojMlPreviewAssert($view['portfolio']['sections'][0]['attachmentCount'] === 1, 'Attachments must be represented by count only.');
ojMlPreviewAssert($view['settings']['hyperparameters'][0]['runtime'] === 'browser', 'Nested algorithm/runtime parameters must be projected.');
ojMlPreviewAssert($view['settings']['hyperparameters'][0]['items'][0]['name'] === 'maxDepth', 'Known numeric parameters must be visible.');
ojMlPreviewAssert(count($view['settings']['hyperparameters'][0]['items']) === 1, 'Unknown or non-numeric parameters must be omitted.');
ojMlPreviewAssert($view['experiments'][0]['runs'][0]['modelOmitted'] === 'engineUnsupported', 'Known model omission codes may be displayed.');
ojMlPreviewAssert(!array_key_exists('modelOmittedDetail', $view['experiments'][0]['runs'][0]), 'Raw model omission details must never be projected.');
ojMlPreviewAssert(array_column($view['experiments'][0]['runs'][0]['metrics'], 'name') === ['accuracy', 'f1Macro'], 'Only known metrics may be projected.');
ojMlPreviewAssert($view['experiments'][0]['runs'][0]['confusionMatrix']['matrix'][1][1] === 9, 'A valid bounded square confusion matrix must be visible.');
ojMlPreviewAssert($view['experiments'][0]['data']['features'] === ['historic-feature'] && $view['experiments'][0]['data']['target'] === 'historic-target', 'Experiment features and target must come from its own snapshot.');
ojMlPreviewAssert($view['experiments'][0]['data']['preprocessing']['scaling'] === 'minmax', 'Historical preprocessing must not be replaced with current project settings.');
ojMlPreviewAssert($view['experiments'][0]['hyperparameters'][0]['items'] === [['name' => 'maxDepth', 'value' => '3']], 'Historical selected parameters must be allowlisted separately from each run.');

foreach ([
    '11111111-1111-4111-8111-111111111111',
    'private-student-id',
    'private-student-name',
    'manifest-secret',
    'dataset/private.csv',
    'student-private-name.csv',
    'preprocessor/private.json',
    'model/private.bin',
    'portfolio/attachments/private-file.txt',
    'failure-secret',
    'warning-secret',
    'unknownMetric',
    'run-secret',
    'portfolio-secret',
    'api-key-secret',
    'run-api-key-secret',
    'C:/Users/student/private-dataset.csv',
    'C:/Users/student/model-cache.bin',
    'token-detail-secret',
    'dataset/private-experiment.csv',
    'private-experiment-value',
    'snapshot-secret',
] as $forbidden) {
    ojMlPreviewAssert(!str_contains($encoded, $forbidden), 'Preview leaked a forbidden value: ' . $forbidden);
}

$invalidMatrixRuns = $runs;
$invalidMatrixRuns['experiments'][0]['runs'][0]['confusionMatrix'] = [
    'labels' => ['A', 'B'],
    'matrix' => [[1, 2, 3]],
];
$invalidView = $projector->project($manifest, $settings, $invalidMatrixRuns, $portfolio);
ojMlPreviewAssert($invalidView['experiments'][0]['runs'][0]['confusionMatrix'] === null, 'Malformed matrices must be omitted.');

$largePortfolio = $portfolio;
$largePortfolio['answers']['reflection'] = str_repeat('가', 200000);
$largeView = $projector->project($manifest, $settings, $runs, $largePortfolio);
$largeEncoded = json_encode($largeView, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
ojMlPreviewAssert(strlen($largeEncoded) <= 262144, 'Preview response must stay inside the hard response budget.');
ojMlPreviewAssert($largeView['notices'] !== [], 'Truncated previews must explain display limits.');

$pathologicalSettings = $settings;
$pathologicalSettings['hyperparameters'] = [];
for ($algorithmIndex = 0; $algorithmIndex < 20; $algorithmIndex++) {
    $parameters = [];
    for ($parameterIndex = 0; $parameterIndex < 20; $parameterIndex++) {
        $parameters['apiKey' . $parameterIndex] = 'budget-secret-' . str_repeat('z', 480);
    }
    $pathologicalSettings['hyperparameters']['unknown_algorithm_' . $algorithmIndex] = [
        'browser' => $parameters,
    ];
}
$pathologicalPortfolio = $portfolio;
$pathologicalPortfolio['template']['sections'] = [];
$pathologicalPortfolio['answers'] = [];
for ($sectionIndex = 0; $sectionIndex < 30; $sectionIndex++) {
    $id = 'section-' . $sectionIndex;
    $pathologicalPortfolio['template']['sections'][] = [
        'id' => $id,
        'title' => str_repeat('T', 500),
        'description' => str_repeat('D', 4000),
    ];
    $pathologicalPortfolio['answers'][$id] = str_repeat('A', 20000);
}
$pathologicalView = $projector->project($manifest, $pathologicalSettings, $runs, $pathologicalPortfolio);
$pathologicalEncoded = json_encode($pathologicalView, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
ojMlPreviewAssert(strlen($pathologicalEncoded) <= 262144, 'The hard response ceiling must hold after every reduction stage.');
ojMlPreviewAssert(!str_contains($pathologicalEncoded, 'budget-secret-'), 'Unknown string parameters must not survive response reduction.');

$legacyRuns = $runs;
unset($legacyRuns['experiments'][0]['settings']['data']);
$legacyView = $projector->project($manifest, $settings, $legacyRuns, $portfolio);
ojMlPreviewAssert($legacyView['experiments'][0]['data'] === [], 'Missing historical data settings must not be inferred from current project settings.');

$fiftyRuns = $runs;
$fiftyRuns['experiments'][0]['runs'] = array_fill(0, 50, ['algorithm' => 'decision_tree', 'status' => 'done']);
$fiftyRuns['experiments'][] = ['settings' => ['taskType' => 'classification'], 'runs' => [['algorithm' => 'knn']]];
$fiftyView = $projector->project($manifest, $settings, $fiftyRuns, $portfolio);
ojMlPreviewAssert(count($fiftyView['experiments'][0]['runs']) === 50, 'Run display count must remain bounded.');
ojMlPreviewAssert(in_array('실행 기록 표시 한도 때문에 이후 실험을 생략했습니다.', $fiftyView['notices'], true), 'Hitting exactly 50 runs must not silently omit later experiments.');

$exactFifty = $fiftyRuns;
array_pop($exactFifty['experiments']);
$exactFiftyView = $projector->project($manifest, ['data' => []], $exactFifty, []);
ojMlPreviewAssert($exactFiftyView['notices'] === [], 'Exactly 50 complete valid runs without later experiments must not be considered truncated.');

$imageManifest = $manifest;
$imageManifest['dataType'] = 'image';
$imageRuns = ['experiments' => [['settings' => ['data' => ['categories' => ['cat', 'dog'], 'backboneId' => 'mobilenet',
    'dataset' => ['canonicalSize' => 224, 'format' => 'webp', 'quality' => 0.8, 'path' => 'private-image-path', 'images' => ['private-image-bytes']]]]]]];
$imageView = $projector->project($imageManifest, [], $imageRuns, []);
ojMlPreviewAssert($imageView['experiments'][0]['data']['categories'] === ['cat', 'dog'] && $imageView['experiments'][0]['data']['canonicalSize'] === 224, 'Image experiment settings must use the image allowlist.');
ojMlPreviewAssert(!str_contains(json_encode($imageView), 'private-image-'), 'Historical image metadata must exclude paths and image bytes.');

$largeSnapshots = $runs;
$largeSnapshots['experiments'] = array_fill(0, 20, $runs['experiments'][0]);
foreach ($largeSnapshots['experiments'] as &$experiment) {
    $experiment['settings']['data']['features'] = array_fill(0, 50, str_repeat('f', 500));
}
unset($experiment);
$largeSnapshotsView = $projector->project($manifest, $settings, $largeSnapshots, $portfolio);
ojMlPreviewAssert(strlen(json_encode($largeSnapshotsView, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) <= 262144, 'Historical settings must obey the existing total response budget.');
ojMlPreviewAssert($largeSnapshotsView['notices'] !== [], 'Oversized historical snapshots must report omitted details.');

fwrite(STDOUT, "OJ ML read-only MLPX preview regression passed.\n");
