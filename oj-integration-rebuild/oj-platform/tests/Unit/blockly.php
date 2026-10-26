<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\Kernel\Autoloader;
use CodingPanda\OjPlatform\Kernel\Environment\PluginEnvironment;
use CodingPanda\OjPlatform\Modules\Blockly\Application\BlocklyProfileCatalog;
use CodingPanda\OjPlatform\Modules\Blockly\Application\BlocklyPythonGenerator;
use CodingPanda\OjPlatform\Modules\Blockly\Application\BlocklySettingsProvider;
use CodingPanda\OjPlatform\Modules\Blockly\Application\BlocklySourceMode;
use CodingPanda\OjPlatform\Modules\Blockly\Application\BlocklyStoredSourceCodec;
use CodingPanda\OjPlatform\Modules\Blockly\Application\BlocklyWorkspaceValidator;
use CodingPanda\OjPlatform\Modules\Blockly\Infrastructure\WordPress\WordPressBlocklyAssets;
use CodingPanda\OjPlatform\Modules\Settings\Contract\FeatureFlags;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsManager;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsSnapshot;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionLanguageIds;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionSourceRequest;

$pluginRoot = dirname(__DIR__, 2);
require_once $pluginRoot . '/src/Kernel/Autoloader.php';
Autoloader::register($pluginRoot);

$blocklyAssertions = 0;

function blocklyAssert(bool $condition, string $message): void
{
    global $blocklyAssertions;
    ++$blocklyAssertions;
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function blocklySame(mixed $expected, mixed $actual, string $message): void
{
    blocklyAssert(
        $expected === $actual,
        $message . PHP_EOL . 'Expected: ' . var_export($expected, true)
            . PHP_EOL . 'Actual: ' . var_export($actual, true)
    );
}

function blocklyThrows(callable $callback, string $messagePart): void
{
    try {
        $callback();
    } catch (DomainException $error) {
        blocklyAssert(
            str_contains($error->getMessage(), $messagePart),
            '예외 문구가 다릅니다: ' . $error->getMessage()
        );

        return;
    }
    throw new RuntimeException('예상한 DomainException이 발생하지 않았습니다.');
}

final class BlocklyTestSettings implements SettingsManager
{
    public function read(string $key, mixed $default = null): SettingsSnapshot
    {
        return new SettingsSnapshot(
            1,
            $key === 'blockly'
                ? [
                    'enabled' => true,
                    'default_profile' => 'standard',
                    'maximum_workspace_kb' => 512,
                ]
                : $default
        );
    }

    public function save(
        string $key,
        mixed $value,
        int $expectedRevision
    ): SettingsSnapshot {
        unset($key, $expectedRevision);

        return new SettingsSnapshot(2, $value);
    }
}

final class BlocklyTestFeatures implements FeatureFlags
{
    public function isEnabled(string $featureKey): bool
    {
        return $featureKey === 'blockly';
    }
}

/**
 * @param array<string, mixed> $workspace
 */
function blocklyPayload(string $profile, array $workspace): string
{
    return json_encode([
        'engine_version' => BlocklyProfileCatalog::ENGINE_VERSION,
        'format' => BlocklyProfileCatalog::FORMAT,
        'generator_version' => BlocklyProfileCatalog::GENERATOR_VERSION,
        'profile' => $profile,
        'registry_version' => BlocklyProfileCatalog::REGISTRY_VERSION,
        'workspace' => $workspace,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
}

/**
 * @return array<string, mixed>
 */
function blocklyNumber(float|int $value): array
{
    return [
        'type' => 'math_number',
        'fields' => ['NUM' => (string) $value],
    ];
}

$profiles = new BlocklyProfileCatalog();
blocklySame('12.5.1', BlocklyProfileCatalog::ENGINE_VERSION, 'Blockly 버전을 고정해야 합니다.');
blocklySame(
    ['intro', 'standard', 'extended'],
    array_keys($profiles->profiles()),
    'Blockly 프로필 순서가 다릅니다.'
);
blocklyAssert(
    ! in_array('text', $profiles->allowedTypes('intro'), true),
    '입문 프로필에 문자열 고급 블록을 노출하면 안 됩니다.'
);
blocklyAssert(
    in_array('py_dict_set', $profiles->allowedTypes('extended'), true)
        && in_array('procedures_defreturn', $profiles->allowedTypes('extended'), true),
    '확장 프로필은 딕셔너리와 함수 블록을 포함해야 합니다.'
);

$settings = new BlocklySettingsProvider(
    new BlocklyTestSettings(),
    new BlocklyTestFeatures()
);
$validator = new BlocklyWorkspaceValidator($profiles, $settings);
$generator = new BlocklyPythonGenerator();
$workspace = [
    'blocks' => [
        'blocks' => [[
            'type' => 'oj_start',
            'id' => 'start',
            'x' => 20,
            'y' => 20,
            'clientOnly' => '<script>',
            'inputs' => [
                'DO' => [
                    'block' => [
                        'type' => 'variables_set',
                        'fields' => [
                            'VAR' => ['id' => 'score', 'name' => 'score'],
                        ],
                        'inputs' => [
                            'VALUE' => ['shadow' => blocklyNumber(2)],
                        ],
                        'next' => [
                            'block' => [
                                'type' => 'oj_print',
                                'fields' => ['END' => 'NEWLINE'],
                                'inputs' => [
                                    'VALUE' => [
                                        'block' => [
                                            'type' => 'variables_get',
                                            'fields' => [
                                                'VAR' => [
                                                    'id' => 'score',
                                                    'name' => 'score',
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]],
    ],
    'variables' => [[
        'name' => 'score',
        'id' => 'score',
    ]],
];
$validated = $validator->validate(
    blocklyPayload('standard', $workspace),
    'standard'
);
blocklySame(5, $validated->blockCount(), '연결된 Blockly 블록 수가 다릅니다.');
blocklyAssert(
    ! str_contains($validated->canonicalJson(), 'clientOnly'),
    '서버 canonical JSON에 임의 클라이언트 속성을 남기면 안 됩니다.'
);
blocklySame(
    "score = 2\nprint(score)\n",
    $generator->generate($validated),
    '서버 Python 생성 결과가 다릅니다.'
);

$roundWorkspace = [
    'blocks' => [
        'blocks' => [[
            'type' => 'oj_start',
            'inputs' => [
                'DO' => [
                    'block' => [
                        'type' => 'oj_print',
                        'fields' => ['END' => 'NEWLINE'],
                        'inputs' => [
                            'VALUE' => [
                                'block' => [
                                    'type' => 'math_round',
                                    'fields' => ['OP' => 'ROUND'],
                                    'inputs' => [
                                        'NUM' => [
                                            'block' => blocklyNumber(1.235),
                                        ],
                                        'PLACES' => [
                                            'block' => blocklyNumber(2),
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]],
    ],
];
$roundSource = $generator->generate($validator->validate(
    blocklyPayload('standard', $roundWorkspace),
    'standard'
));
blocklySame(
    "print(round(1.235, int(2)))\n",
    $roundSource,
    '소수점 자리 반올림은 Python round 방식이어야 합니다.'
);

$inputWorkspace = [
    'blocks' => [
        'blocks' => [[
            'type' => 'oj_start',
            'inputs' => [
                'DO' => [
                    'block' => [
                        'type' => 'oj_print',
                        'fields' => ['END' => 'NEWLINE'],
                        'inputs' => [
                            'VALUE' => ['block' => ['type' => 'oj_input_int']],
                        ],
                    ],
                ],
            ],
        ]],
    ],
];
$inputValidated = $validator->validate(
    blocklyPayload('intro', $inputWorkspace),
    'intro'
);
$inputSource = $generator->generate($inputValidated);
blocklyAssert($inputValidated->usesInput(), '입력 블록 사용 여부를 기록해야 합니다.');
blocklyAssert(
    str_contains($inputSource, 'class __OjInput:')
        && str_ends_with($inputSource, "print(int(__oj_next()))\n"),
    '입력 블록은 공통 stdin 판독기를 포함해야 합니다.'
);

$invalidWorkspace = $workspace;
$invalidWorkspace['blocks']['blocks'][0]['inputs']['DO']['block']['next']['block']['inputs']['VALUE'] = [
    'block' => ['type' => 'text', 'fields' => ['TEXT' => '허용 안 됨']],
];
blocklyThrows(
    fn () => $validator->validate(
        blocklyPayload('intro', $invalidWorkspace),
        'intro'
    ),
    '현재 프로필에서 사용할 수 없는'
);
blocklyThrows(
    fn () => $validator->validate(
        blocklyPayload('standard', $workspace),
        'extended'
    ),
    '작업공간 프로필이 다릅니다'
);
$twoStarts = $workspace;
$twoStarts['blocks']['blocks'][] = ['type' => 'oj_start'];
blocklyThrows(
    fn () => $validator->validate(
        blocklyPayload('standard', $twoStarts),
        'standard'
    ),
    '시작 블록이 하나'
);

$codec = new BlocklyStoredSourceCodec();
if (function_exists('gzencode') && function_exists('gzdecode')) {
    $stored = $codec->encode(
        $generator->generate($validated),
        $validated->canonicalJson()
    );
    $decoded = $codec->decode($stored);
    blocklyAssert($decoded !== null, 'Blockly 복원 정보를 다시 읽을 수 있어야 합니다.');
    blocklySame(
        "score = 2\nprint(score)\n",
        $decoded?->displaySource(),
        '제출 상세에는 압축 마커를 제외한 생성 코드를 표시해야 합니다.'
    );
    blocklySame(
        'standard',
        $decoded?->metadata()['profile'] ?? null,
        'Blockly 복원 메타데이터에 프로필이 필요합니다.'
    );
}

$sourceMode = new BlocklySourceMode($validator, $generator, $codec);
$sourcePayload = $sourceMode->build(new SubmissionSourceRequest(
    new CodingPanda\OjPlatform\Modules\Problems\Contract\SubmissionCodePolicy(
        1000,
        'Blockly 합계',
        'full_code',
        [],
        '',
        '',
        '',
        null,
        null,
        null,
        'problem-code-policy-v1:' . str_repeat('d', 64)
    ),
    0,
    '',
    '',
    [],
    blocklyPayload('standard', $workspace),
    'standard'
));
blocklySame(
    SubmissionLanguageIds::PYTHON_3,
    $sourcePayload->languageId(),
    '검증된 Blockly 제출은 Python 3을 자동 허용해야 합니다.'
);
blocklyAssert(
    $sourcePayload->allowsLanguageOverride(),
    'Blockly 소스 모드는 검증 뒤 언어 자동 허용을 표시해야 합니다.'
);

$environment = PluginEnvironment::fromPluginFile(
    $pluginRoot . '/oj-platform.php',
    '2.0.0-dev.102',
    '6.6',
    '8.1'
);
$assets = new WordPressBlocklyAssets($environment);
blocklyAssert($assets->isReady(), '고정 Blockly 로컬 자산이 준비되어야 합니다.');
foreach ($assets->diagnostics() as $diagnostic) {
    blocklyAssert(
        $diagnostic['exists'] && $diagnostic['integrity'],
        'Blockly 자산 존재 여부와 SHA-256 무결성을 통과해야 합니다.'
    );
}

$licensePath = $pluginRoot
    . '/resources/features/blockly/vendor/LICENSE.txt';
$noticePath = $pluginRoot
    . '/resources/features/blockly/vendor/THIRD_PARTY_NOTICES.txt';
blocklyAssert(
    is_file($licensePath) && is_file($noticePath),
    'Blockly 배포에는 라이선스 원문과 제3자 고지가 필요합니다.'
);
$license = (string) file_get_contents($licensePath);
blocklySame(
    'cfc7749b96f63bd31c3c42b5c471bf756814053e847c10f3eb003417bc523d30',
    hash('sha256', $license),
    'Blockly Apache-2.0 라이선스 원문이 공식 commit과 달라졌습니다.'
);
$notice = (string) file_get_contents($noticePath);
foreach ([
    'Blockly',
    'Version: 12.5.1',
    'Source repository: https://github.com/RaspberryPiFoundation/blockly',
    'Source commit: 6f7d50552ee658299de16c139673c2d126ac9b16',
    'License: Apache License 2.0 (Apache-2.0)',
    'blockly_compressed.js',
    'blocks_compressed.js',
    'python_compressed.js',
    'msg/ko.js',
] as $requiredNotice) {
    blocklyAssert(
        str_contains($notice, $requiredNotice),
        'Blockly 제3자 고지에 필수 항목이 없습니다: ' . $requiredNotice
    );
}
$generatorSource = (string) file_get_contents(
    $pluginRoot . '/modules/Blockly/Application/BlocklyPythonGenerator.php'
);
foreach ($profiles->allowedTypes('extended') as $type) {
    blocklyAssert(
        str_contains($generatorSource, "'" . $type . "'")
            || (
                $type === 'procedures_defnoreturn'
                && str_contains($generatorSource, "'procedures_def'")
            ),
        '서버 Python 생성기에 허용 블록 처리가 없습니다: ' . $type
    );
}

fwrite(
    STDOUT,
    sprintf(
        "R-06 Blockly 단위 검사 통과: assertion %d개\n",
        $blocklyAssertions
    )
);
