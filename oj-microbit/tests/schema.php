<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Domain/SchemaValue.php';
require_once dirname(__DIR__) . '/src/Domain/ProjectSchema.php';
require_once dirname(__DIR__) . '/src/Domain/SignalSchema.php';
require_once dirname(__DIR__) . '/src/Domain/I2cSchema.php';
require_once dirname(__DIR__) . '/src/Domain/OutputSequenceSchema.php';
require_once dirname(__DIR__) . '/src/Domain/ExerciseSchema.php';

use OjMicrobit\Domain\ExerciseSchema;
use OjMicrobit\Domain\ProjectSchema;

$passed = 0;

function check(bool $condition, string $message): void
{
    global $passed;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $passed++;
}

function rejects(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (InvalidArgumentException $error) {
        check($error->getMessage() !== '', $message . ' (error message)');
        return;
    }
    throw new RuntimeException('Expected rejection: ' . $message);
}

function projectFixture(): array
{
    return [
        'name' => '핀 실습',
        'files' => [
            'main.ts' => "basic.forever(() => { pins.digitalWritePin(DigitalPin.P1, pins.analogReadPin(AnalogPin.P0) >= 512 ? 1 : 0); basic.pause(20); });\n",
            'main.blocks' => '<xml xmlns="https://developers.google.com/blockly/xml"></xml>',
            'pxt.json' => json_encode([
                'name' => '핀 실습',
                'dependencies' => ['radio' => '*', 'core' => '*', 'microphone' => '*'],
                'files' => ['main.ts', 'main.blocks'],
                'preferredEditor' => 'blocksprj',
                'targetVersions' => ['targetId' => 'microbit', 'target' => '9.1.1'],
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ],
    ];
}

function exerciseFixture(): array
{
    return [
        'title' => '조도에 따라 LED 켜기', 'description' => '임계값은 512입니다.', 'status' => 'published',
        'connections' => [
            ['pin' => 0, 'direction' => 'input', 'signal' => 'analog', 'device' => 'light_sensor', 'label' => '조도센서'],
            ['pin' => 1, 'direction' => 'output', 'signal' => 'digital', 'device' => 'led', 'label' => '경고 LED'],
        ],
        'scenarios' => [[
            'id' => 'threshold', 'name' => '임계값 경계 검사', 'visible' => false, 'durationMs' => 1500,
            'inputs' => [
                ['atMs' => 500, 'pin' => 0, 'signal' => 'analog', 'value' => 1023],
                ['atMs' => 0, 'pin' => 0, 'signal' => 'analog', 'value' => 0],
                ['atMs' => 1000, 'pin' => 0, 'signal' => 'analog', 'value' => 511],
            ],
            'assertions' => [
                ['pin' => 1, 'signal' => 'digital', 'expected' => 1, 'afterMs' => 500, 'withinMs' => 100, 'holdMs' => 200, 'tolerance' => 0],
                ['pin' => 1, 'signal' => 'digital', 'expected' => 0, 'afterMs' => 0, 'withinMs' => 100, 'holdMs' => 200, 'tolerance' => 0],
            ],
        ]],
        'starterProject' => projectFixture(),
    ];
}

$exercise = ExerciseSchema::normalize(exerciseFixture());
check($exercise['scenarios'][0]['inputs'][0]['atMs'] === 0, 'Input timeline sorted');
check($exercise['scenarios'][0]['assertions'][0]['afterMs'] === 0, 'Assertions sorted');
check($exercise['scenarios'][0]['visible'] === false, 'Hidden scenario retained');
check($exercise['title'] === '조도에 따라 LED 켜기', 'Korean text retained');
check(ExerciseSchema::normalize($exercise) === $exercise, 'Exercise normalization is idempotent');

$project = ProjectSchema::normalize(projectFixture());
check(isset($project['files']['main.blocks']), 'Block workspace retained');
check($project['files']['main.ts'] === projectFixture()['files']['main.ts'], 'Source bytes retained');
check(ProjectSchema::normalize($project) === $project, 'Project normalization is idempotent');
$reordered = projectFixture();
$config = json_decode($reordered['files']['pxt.json'], true, 32, JSON_THROW_ON_ERROR);
$config['dependencies'] = ['microphone' => '*', 'core' => '*', 'radio' => '*'];
$reordered['files']['pxt.json'] = json_encode(array_reverse($config, true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$reordered['files'] = array_reverse($reordered['files'], true);
check(ProjectSchema::hash($reordered) === ProjectSchema::hash(projectFixture()), 'Hash ignores map order and JSON formatting');
$reordered['files']['main.ts'] .= '// another source';
check(ProjectSchema::hash($reordered) !== ProjectSchema::hash(projectFixture()), 'Hash covers source changes');

$draft = exerciseFixture();
$draft['status'] = 'draft';
$draft['scenarios'][0]['assertions'] = [];
check(ExerciseSchema::normalize($draft)['scenarios'][0]['assertions'] === [], 'Direct preview draft permits empty assertions');
$draft['connections'] = [];
$draft['scenarios'] = [];
check(ExerciseSchema::normalize($draft)['connections'] === [], 'Empty draft can be saved');
$draft['untrustedInternalField'] = 'discard';
check(!isset(ExerciseSchema::normalize($draft)['untrustedInternalField']), 'Unknown exercise metadata discarded');

foreach ([1024, -1, 1.0, '512', true, INF, NAN, null] as $value) {
    $bad = exerciseFixture();
    $bad['scenarios'][0]['inputs'][0]['value'] = $value;
    rejects(static fn () => ExerciseSchema::normalize($bad), 'Analog input strict integer/range');
}
foreach ([17, 18, 21, -1, '0', 0.0, false] as $pin) {
    $bad = exerciseFixture();
    $bad['connections'][0]['pin'] = $pin;
    rejects(static fn () => ExerciseSchema::normalize($bad), 'Invalid GPIO pin');
}

$bad = exerciseFixture();
$bad['connections'][0]['pin'] = 8;
rejects(static fn () => ExerciseSchema::normalize($bad), 'ADC capability enforced');
$pwm = exerciseFixture();
$pwm['connections'][1] = ['pin' => 8, 'direction' => 'output', 'signal' => 'pwm', 'device' => 'motor'];
foreach ($pwm['scenarios'][0]['assertions'] as &$assertion) {
    $assertion['pin'] = 8;
    $assertion['signal'] = 'pwm';
    $assertion['expected'] = 1023;
}
unset($assertion);
check(ExerciseSchema::normalize($pwm)['connections'][1]['pin'] === 8, 'PWM allowed on digital GPIO AnalogPin enum');

$bad = exerciseFixture();
$bad['connections'][] = $bad['connections'][0];
rejects(static fn () => ExerciseSchema::normalize($bad), 'Duplicate physical connection');
$bad = exerciseFixture();
$bad['connections'][0]['device'] = 'motor';
rejects(static fn () => ExerciseSchema::normalize($bad), 'Device cannot forge input direction');
$bad = exerciseFixture();
$bad['scenarios'][0]['inputs'][0]['pin'] = 1;
rejects(static fn () => ExerciseSchema::normalize($bad), 'Input cannot overwrite output connection');
$bad = exerciseFixture();
$bad['scenarios'][0]['assertions'][0]['pin'] = 0;
rejects(static fn () => ExerciseSchema::normalize($bad), 'Input cannot be declared expected output');
$bad = exerciseFixture();
$bad['scenarios'][0]['inputs'][] = $bad['scenarios'][0]['inputs'][0];
rejects(static fn () => ExerciseSchema::normalize($bad), 'Conflicting same-pin same-time inputs');
$bad = exerciseFixture();
$bad['scenarios'][0]['assertions'][] = $bad['scenarios'][0]['assertions'][0];
rejects(static fn () => ExerciseSchema::normalize($bad), 'Duplicate same-pin assertion');

foreach (['expected', 'afterMs', 'withinMs', 'holdMs', 'tolerance'] as $field) {
    $bad = exerciseFixture();
    unset($bad['scenarios'][0]['assertions'][0][$field]);
    rejects(static fn () => ExerciseSchema::normalize($bad), 'Missing numeric field is not silently zero: ' . $field);
}
$bad = exerciseFixture();
$bad['scenarios'][0]['assertions'][0]['afterMs'] = 1400;
rejects(static fn () => ExerciseSchema::normalize($bad), 'Assertion deadline and hold must fit duration');
$bad = exerciseFixture();
$bad['scenarios'][0]['assertions'][0]['holdMs'] = 0;
rejects(static fn () => ExerciseSchema::normalize($bad), 'Zero hold rejected');
$bad = exerciseFixture();
$bad['scenarios'][0]['assertions'][0]['expected'] = 2;
rejects(static fn () => ExerciseSchema::normalize($bad), 'Digital output range');
$bad = exerciseFixture();
$bad['scenarios'][0]['assertions'][0]['tolerance'] = 1;
rejects(static fn () => ExerciseSchema::normalize($bad), 'Digital tolerance cannot accept both states');
$bad = $pwm;
$bad['scenarios'][0]['assertions'][0]['tolerance'] = 1023;
rejects(static fn () => ExerciseSchema::normalize($bad), 'Tolerance cannot match all PWM states');
$bad = exerciseFixture();
$bad['scenarios'][0]['inputs'][1]['atMs'] = 1;
rejects(static fn () => ExerciseSchema::normalize($bad), 'Published input initial state explicit');
$bad = exerciseFixture();
$bad['scenarios'][0]['assertions'] = [];
rejects(static fn () => ExerciseSchema::normalize($bad), 'Published scenario needs assertions');
$bad = exerciseFixture();
$bad['scenarios'] = [];
rejects(static fn () => ExerciseSchema::normalize($bad), 'Published exercise needs scenario');
$bad = exerciseFixture();
$bad['scenarios'][] = $bad['scenarios'][0];
rejects(static fn () => ExerciseSchema::normalize($bad), 'Scenario identifiers unique');
$bad = exerciseFixture();
$bad['scenarios'][0]['visible'] = 'false';
rejects(static fn () => ExerciseSchema::normalize($bad), 'Visibility boolean strict');
$bad = exerciseFixture();
$bad['scenarios'][0]['durationMs'] = 30001;
rejects(static fn () => ExerciseSchema::normalize($bad), 'Duration bounded');
$bad = exerciseFixture();
$bad['scenarios'] = [];
for ($index = 0; $index < 5; $index++) {
    $scenario = exerciseFixture()['scenarios'][0];
    $scenario['id'] = 'case' . $index;
    $scenario['durationMs'] = 30000;
    $bad['scenarios'][] = $scenario;
}
rejects(static fn () => ExerciseSchema::normalize($bad), 'Total duration bounded');
$bad = exerciseFixture();
$bad['scenarios'][0]['inputs'] = array_fill(0, 257, $bad['scenarios'][0]['inputs'][0]);
rejects(static fn () => ExerciseSchema::normalize($bad), 'Input count bounded before iteration');

foreach (['../main.ts', 'folder/main.ts', 'folder\\main.ts', 'https://example.test/main.ts', 'main.ts.php', "bad\0.ts", 'CON.ts', 'lpt1.py'] as $filename) {
    $bad = projectFixture();
    $bad['files'][$filename] = '';
    rejects(static fn () => ProjectSchema::normalize($bad), 'Unsafe filename');
}
$bad = projectFixture();
$bad['files']['Main.ts'] = '';
rejects(static fn () => ProjectSchema::normalize($bad), 'Portable file names cannot differ only in case');
foreach (["a\0b", "\xC3\x28", str_repeat('a', ProjectSchema::MAX_BYTES)] as $source) {
    $bad = projectFixture();
    $bad['files']['main.ts'] = $source;
    rejects(static fn () => ProjectSchema::normalize($bad), 'Invalid or oversized source');
}
$bad = projectFixture();
unset($bad['files']['main.ts']);
rejects(static fn () => ProjectSchema::normalize($bad), 'main.ts required even when blocks available');
$bad = projectFixture();
$bad['files']['pxt.json'] = '[]';
rejects(static fn () => ProjectSchema::normalize($bad), 'Config object required');
$bad = projectFixture();
$bad['files']['pxt.json'] = '{';
rejects(static fn () => ProjectSchema::normalize($bad), 'Malformed JSON');

foreach ([
    ['dependencies' => ['core' => '*', 'evil' => 'github:someone/repo']],
    ['dependencies' => ['core' => 'file:../core']],
    ['dependencies' => ['radio' => '*']],
    ['files' => ['../main.ts']],
    ['files' => ['main.ts', 'missing.ts']],
    ['files' => ['main.ts', 'main.ts']],
    ['files' => ['main.blocks']],
    ['files' => ['main.ts', 'pxt.json']],
    ['additionalFilePath' => '../../runner'],
    ['simFiles' => ['main.ts']],
    ['compileService' => ['url' => 'https://evil.test']],
    ['targetVersions' => ['targetId' => 'arcade']],
] as $changes) {
    $bad = projectFixture();
    $config = json_decode($bad['files']['pxt.json'], true, 32, JSON_THROW_ON_ERROR);
    $bad['files']['pxt.json'] = json_encode(array_replace($config, $changes), JSON_THROW_ON_ERROR);
    rejects(static fn () => ProjectSchema::normalize($bad), 'Untrusted package config');
}

foreach (['makecode-project.json', 'makecode-servo-project.json', 'makecode-i2c-project.json'] as $capturedProject) {
    $makeCodeProject = json_decode((string) file_get_contents(__DIR__ . '/fixtures/' . $capturedProject), true, 64, JSON_THROW_ON_ERROR);
    $savedMakeCodeProject = ProjectSchema::normalize($makeCodeProject);
    check($savedMakeCodeProject['files']['main.ts'] === $makeCodeProject['files']['main.ts'], 'Actual MakeCode workspacesave source accepted without changes: ' . $capturedProject);
    check(str_contains($savedMakeCodeProject['files']['main.blocks'], '<block ') && $savedMakeCodeProject['files']['main.blocks'] === $makeCodeProject['files']['main.blocks'], 'Actual nonempty MakeCode block workspace retained byte for byte: ' . $capturedProject);
    check(ProjectSchema::normalize($savedMakeCodeProject) === $savedMakeCodeProject, 'Actual MakeCode saved project normalization is idempotent: ' . $capturedProject);
}
$servoPackageProject = projectFixture();
$servoPackageConfig = json_decode($servoPackageProject['files']['pxt.json'], true, 32, JSON_THROW_ON_ERROR);
$servoPackageConfig['dependencies']['servo'] = '*';
$servoPackageProject['files']['pxt.json'] = json_encode($servoPackageConfig, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
check(isset(json_decode(ProjectSchema::normalize($servoPackageProject)['files']['pxt.json'], true)['dependencies']['servo']), 'Vetted built-in servo package accepted');
$servoPackageConfig['dependencies']['servo'] = 'github:someone/servo';
$servoPackageProject['files']['pxt.json'] = json_encode($servoPackageConfig, JSON_THROW_ON_ERROR);
rejects(static fn () => ProjectSchema::normalize($servoPackageProject), 'External servo extensions remain forbidden');

$workspaceProject = projectFixture();
$workspaceProject['files']['.simstate.json'] = '{"buttonA":true}';
$workspaceProject['files']['.git.json'] = '{"repo":"workspace metadata"}';
$workspaceProject['files']['_history'] = '{"entries":[]}';
$workspaceConfig = json_decode($workspaceProject['files']['pxt.json'], true, 32, JSON_THROW_ON_ERROR);
$workspaceConfig['files'] = array_merge($workspaceConfig['files'], ProjectSchema::WORKSPACE_FILES);
$workspaceConfig['testFiles'] = ProjectSchema::WORKSPACE_FILES;
$workspaceProject['files']['pxt.json'] = json_encode($workspaceConfig, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$withoutWorkspace = ProjectSchema::normalize($workspaceProject);
check(!isset($withoutWorkspace['files']['.simstate.json'], $withoutWorkspace['files']['.git.json'], $withoutWorkspace['files']['_history']), 'Exact official workspace metadata excluded at server boundary');
check($withoutWorkspace['files']['main.ts'] === projectFixture()['files']['main.ts'] && $withoutWorkspace['files']['main.blocks'] === projectFixture()['files']['main.blocks'], 'Removing metadata preserves program and block source bytes');
$cleanConfig = json_decode($withoutWorkspace['files']['pxt.json'], true, 32, JSON_THROW_ON_ERROR);
check($cleanConfig['files'] === ['main.ts', 'main.blocks'] && $cleanConfig['testFiles'] === [], 'Source lists remain consistent after workspace metadata exclusion');
$workspaceProject['files']['.simstate.json'] = '{"buttonA":false}';
check(ProjectSchema::hash($workspaceProject) === ProjectSchema::hash($withoutWorkspace), 'Workspace state cannot change graded source hash');
$resourceProject = projectFixture();
$resourceProject['files']['센서 도우미.ts'] = 'function 센서값(): number { return 1; }';
$resourceProject['files']['images.g.jres'] = '{"*":{"namespace":"images","dataEncoding":"base64","mimeType":"image/x-mkcd-f4"}}';
$resourceProject['files']['측정 자료.txt'] = '기록';
$resourceProject['files']['_onCodeStart.ts'] = '// ordinary tutorial source';
$resourceProject['files']['notes..copy.txt'] = 'A basename containing dots is not a directory path.';
$resourceConfig = json_decode($resourceProject['files']['pxt.json'], true, 32, JSON_THROW_ON_ERROR);
$resourceConfig['files'] = array_merge($resourceConfig['files'], ['센서 도우미.ts', 'images.g.jres', '측정 자료.txt', '_onCodeStart.ts']);
$resourceProject['files']['pxt.json'] = json_encode($resourceConfig, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$normalizedResources = ProjectSchema::normalize($resourceProject);
check($normalizedResources['files']['센서 도우미.ts'] === $resourceProject['files']['센서 도우미.ts'] && isset($normalizedResources['files']['images.g.jres'], $normalizedResources['files']['_onCodeStart.ts']), 'Unicode source, standard JRES and underscore basenames remain valid project files');
check(ProjectSchema::normalize($normalizedResources) === $normalizedResources, 'Resource project normalization is idempotent');
check(isset($normalizedResources['files']['notes..copy.txt']), 'Consecutive dots in a flat basename are not path traversal');
foreach (['../.simstate.json', 'folder/.git.json', 'C:\\센서.ts', '/센서.ts', 'https://example.test/센서.ts', '.unknown.json', "센서\n.ts", 'CON .ts', 'bad:name.ts'] as $filename) {
    $bad = projectFixture(); $bad['files'][$filename] = '{}';
    rejects(static fn () => ProjectSchema::normalize($bad), 'Metadata exceptions never bypass path validation');
}
$bad = projectFixture(); $bad['files']['folder/센서.ts'] = '';
try { ProjectSchema::normalize($bad); throw new RuntimeException('Expected file name error'); }
catch (InvalidArgumentException $error) { check(str_contains($error->getMessage(), 'folder/센서.ts'), 'Filename error identifies offending file safely'); }
$capturedWorkspace = json_decode((string) file_get_contents(__DIR__ . '/fixtures/makecode-workspace-project.json'), true, 64, JSON_THROW_ON_ERROR);
check(isset($capturedWorkspace['files']['.simstate.json'], $capturedWorkspace['files']['_history']), 'Actual MakeCode simulator/blocks/save capture contains workspace state');
$normalizedWorkspace = ProjectSchema::normalize($capturedWorkspace);
check(array_keys($normalizedWorkspace['files']) === ['main.blocks', 'main.ts', 'pxt.json'], 'Actual raw workspace normalizes to source files without internal state');
check($normalizedWorkspace['files']['main.ts'] === $capturedWorkspace['files']['main.ts'] && $normalizedWorkspace['files']['main.blocks'] === $capturedWorkspace['files']['main.blocks'], 'Actual reproduced project retains its code and nonempty blocks exactly');
$unicodeFixture = json_decode((string) file_get_contents(__DIR__ . '/fixtures/unicode-project.json'), true, 64, JSON_THROW_ON_ERROR);
$unicodeHash = json_decode((string) file_get_contents(__DIR__ . '/fixtures/unicode-project-hash.json'), true, 8, JSON_THROW_ON_ERROR);
check(ProjectSchema::hash($unicodeFixture) === $unicodeHash['hash'] && isset($unicodeFixture['files']['神.txt'], $unicodeFixture['files']['𠀀.txt'], $unicodeFixture['files']['센서 도우미.ts']), 'Canonical Unicode filename fixture and PHP hash remain stable across runtimes');
check(ExerciseSchema::audience(['mode' => 'site', 'classIds' => []]) === ['mode' => 'site', 'classIds' => []], 'Site audience must remain explicit');
check(ExerciseSchema::audience(['mode' => 'classes', 'classIds' => [9, 7]]) === ['mode' => 'classes', 'classIds' => [7, 9]], 'Class audience canonicalizes the same selected IDs');
foreach ([null, [], ['mode' => 'classes', 'classIds' => []], ['mode' => 'site', 'classIds' => [7]], ['mode' => 'classes', 'classIds' => [7, 7]], ['mode' => 'classes', 'classIds' => ['7']], ['mode' => 'classes', 'classIds' => [0]], ['mode' => 'classes', 'classIds' => [1.5]], ['mode' => 'classes', 'classIds' => range(1, 51)]] as $audience) {
    rejects(static fn () => ExerciseSchema::audience($audience), 'Malformed or ambiguous audience never widens distribution to site');
}
echo '[schema] ' . $passed . " checks passed\n";
