<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

use OjMicrobit\Domain\ExerciseSchema;
use OjMicrobit\Domain\ResultVisibility;
use OjMicrobit\Domain\RunnerResultSchema;

$exercise = fixtureProtocolExercise();
verify(ExerciseSchema::normalize($exercise) === $exercise, 'Combined GPIO, servo and I2C contract is idempotent');
verify($exercise['i2cDevices'][0]['registers'][0]['writable'] === false, 'Sensor registers default read-only');
verify($exercise['i2cDevices'][0]['registers'][2]['writable'] === true, 'Explicit writable register preserved');
$i2cOnly = $exercise; $i2cOnly['connections'] = [];
foreach ($i2cOnly['scenarios'] as &$scenario) { $scenario['inputs'] = []; $scenario['assertions'] = []; }
unset($scenario);
verify(ExerciseSchema::normalize($i2cOnly)['connections'] === [], 'Published I2C-only exercise needs no GPIO connections');
$draft = $i2cOnly; $draft['status'] = 'draft'; $draft['i2cDevices'][0]['registers'] = [];
foreach ($draft['scenarios'] as &$scenario) { $scenario['i2cInputs'] = []; $scenario['i2cAssertions'] = []; }
unset($scenario);
verify(ExerciseSchema::normalize($draft)['i2cDevices'][0]['registers'] === [], 'Incomplete register map can be saved as a draft');
$draft['status'] = 'published';
throws(fn () => ExerciseSchema::normalize($draft), InvalidArgumentException::class, 'Published devices require explicit register map');

foreach ([['servo', 0], ['servo', 180], ['servo_pulse', 1], ['servo_pulse', 20000]] as [$signal, $value]) {
    $valid = $exercise; $index = $signal === 'servo' ? 1 : 2;
    $valid['scenarios'][0]['assertions'][$index]['expected'] = $value;
    verify(ExerciseSchema::normalize($valid)['scenarios'][0]['assertions'][$index]['expected'] === $value, 'Servo native range boundary accepted');
}
foreach ([['servo', -1], ['servo', 181], ['servo_pulse', 0], ['servo_pulse', 20001]] as [$signal, $value]) {
    $bad = $exercise; $index = $signal === 'servo' ? 1 : 2; $bad['scenarios'][0]['assertions'][$index]['expected'] = $value;
    throws(fn () => ExerciseSchema::normalize($bad), InvalidArgumentException::class, 'Out-of-range servo command rejected');
}
$bad = $exercise; $bad['scenarios'][0]['assertions'][1]['tolerance'] = 90;
throws(fn () => ExerciseSchema::normalize($bad), InvalidArgumentException::class, 'Servo tolerance cannot match every angle');
$bad = $exercise; $bad['scenarios'][0]['assertions'][2]['expected'] = 10000; $bad['scenarios'][0]['assertions'][2]['tolerance'] = 10000;
throws(fn () => ExerciseSchema::normalize($bad), InvalidArgumentException::class, 'Pulse tolerance cannot match whole range');
$bad = $exercise; $bad['connections'][2]['device'] = 'motor';
throws(fn () => ExerciseSchema::normalize($bad), InvalidArgumentException::class, 'Servo output requires servo device adapter');

foreach ([-1, 128, '64', 64.0, null, false] as $address) {
    $bad = $exercise; $bad['i2cDevices'][0]['address'] = $address;
    throws(fn () => ExerciseSchema::normalize($bad), InvalidArgumentException::class, 'I2C address bounded strict integer');
}
$bad = $exercise; $bad['i2cDevices'][] = $bad['i2cDevices'][0];
throws(fn () => ExerciseSchema::normalize($bad), InvalidArgumentException::class, 'Duplicate bus address rejected');
$bad = $exercise; $bad['i2cDevices'][0]['registers'][] = $bad['i2cDevices'][0]['registers'][0];
throws(fn () => ExerciseSchema::normalize($bad), InvalidArgumentException::class, 'Duplicate register rejected');
foreach ([0, 1, 'false', null] as $writable) {
    $bad = $exercise; $bad['i2cDevices'][0]['registers'][0]['writable'] = $writable;
    throws(fn () => ExerciseSchema::normalize($bad), InvalidArgumentException::class, 'Writable property requires an actual boolean');
}
foreach ([-1, 256, '1', false, 1.0] as $byte) {
    $bad = $exercise; $bad['scenarios'][0]['i2cAssertions'][1]['data'][0] = $byte;
    throws(fn () => ExerciseSchema::normalize($bad), InvalidArgumentException::class, 'I2C data bytes bounded strict integer');
}
foreach ([[], array_fill(0, 65, 0)] as $bytes) {
    $bad = $exercise; $bad['scenarios'][0]['i2cAssertions'][1]['data'] = $bytes;
    throws(fn () => ExerciseSchema::normalize($bad), InvalidArgumentException::class, 'Transaction byte count bounded');
}
$bad = $exercise; $bad['scenarios'][0]['i2cAssertions'][0]['repeated'] = 'true';
throws(fn () => ExerciseSchema::normalize($bad), InvalidArgumentException::class, 'Repeated-start flag strict boolean');
$bad = $exercise; $bad['scenarios'][0]['i2cAssertions'][0]['address'] = 65;
throws(fn () => ExerciseSchema::normalize($bad), InvalidArgumentException::class, 'Expected transaction cannot target undeclared address');
$bad = $exercise; $bad['scenarios'][0]['i2cAssertions'][2]['data'] = [17, 90];
throws(fn () => ExerciseSchema::normalize($bad), InvalidArgumentException::class, 'Expected writes cannot target undeclared registers');
$bad = $exercise; $bad['scenarios'][0]['i2cAssertions'][2]['data'] = [0, 90];
throws(fn () => ExerciseSchema::normalize($bad), InvalidArgumentException::class, 'Expected data writes cannot mutate read-only sensor register');
$bad = $exercise; $bad['scenarios'][0]['i2cAssertions'][2]['data'] = [255, 90, 91];
throws(fn () => ExerciseSchema::normalize($bad), InvalidArgumentException::class, 'Sequential write validates wrapped destination permissions');
$wrapped = $bad; $wrapped['i2cDevices'][0]['registers'][0]['writable'] = true;
verify(ExerciseSchema::normalize($wrapped)['scenarios'][0]['i2cAssertions'][2]['data'] === [255, 90, 91], 'Explicitly writable sequential wrap accepted');
verify($exercise['scenarios'][0]['i2cAssertions'][0]['data'] === [0], 'Read-only register pointer selection remains supported');
$bad = $exercise; $bad['scenarios'][0]['i2cInputs'][0]['register'] = 2;
throws(fn () => ExerciseSchema::normalize($bad), InvalidArgumentException::class, 'Environment input must target declared register');
verify(ExerciseSchema::normalize($exercise)['scenarios'][0]['i2cInputs'][0]['value'] === 10, 'Environment may update read-only sensor register');
$bad = $exercise; $bad['scenarios'][0]['i2cInputs'][] = $bad['scenarios'][0]['i2cInputs'][0];
throws(fn () => ExerciseSchema::normalize($bad), InvalidArgumentException::class, 'Duplicate same-time register input rejected');
$ordered = $exercise; $ordered['scenarios'][0]['i2cInputs'][] = ['atMs' => 300, 'address' => 64, 'register' => 0, 'value' => 40];
$ordered['scenarios'][0]['i2cInputs'] = array_reverse($ordered['scenarios'][0]['i2cInputs']);
verify(ExerciseSchema::normalize($ordered)['scenarios'][0]['i2cInputs'][0]['atMs'] === 0, 'Environment register timeline canonicalized');
$ordered = $exercise; $ordered['scenarios'][0]['i2cAssertions'] = array_reverse($ordered['scenarios'][0]['i2cAssertions']);
verify(ExerciseSchema::normalize($ordered)['scenarios'][0]['i2cAssertions'][0]['data'] === [16, 90], 'Teacher bus assertion order preserved');
$duplicate = $exercise; $duplicate['scenarios'][0]['i2cAssertions'][] = $duplicate['scenarios'][0]['i2cAssertions'][0];
verify(count(ExerciseSchema::normalize($duplicate)['scenarios'][0]['i2cAssertions']) === 4, 'Repeated identical transactions represent separate expected bus events');
$bad = $exercise; $bad['scenarios'][0]['i2cAssertions'][2]['afterMs'] = 450;
throws(fn () => ExerciseSchema::normalize($bad), InvalidArgumentException::class, 'I2C deadline cannot exceed duration');
foreach ([19, 20] as $pin) {
    $bad = $exercise; $bad['connections'][] = ['pin' => $pin, 'direction' => 'output', 'signal' => 'digital', 'device' => 'led'];
    throws(fn () => ExerciseSchema::normalize($bad), InvalidArgumentException::class, 'GPIO connection cannot share I2C bus pins');
}
foreach (['i2cDevices', 'i2cInputs', 'i2cAssertions'] as $field) {
    $bad = $exercise;
    if ($field === 'i2cDevices') { $bad[$field] = null; } else { $bad['scenarios'][0][$field] = null; }
    throws(fn () => ExerciseSchema::normalize($bad), InvalidArgumentException::class, 'Explicit null cannot silently erase I2C conditions');
}
$bad = $exercise; $bad['scenarios'][0]['i2cInputs'] = array_fill(0, 257, $bad['scenarios'][0]['i2cInputs'][0]);
throws(fn () => ExerciseSchema::normalize($bad), InvalidArgumentException::class, 'Environment event limit enforced');
$bad = $exercise; $bad['scenarios'][0]['i2cAssertions'] = array_fill(0, 257, $bad['scenarios'][0]['i2cAssertions'][0]);
throws(fn () => ExerciseSchema::normalize($bad), InvalidArgumentException::class, 'Bus assertion limit enforced');

$project = fixtureProject(); $result = fixtureResult($project, $exercise);
$normalized = RunnerResultSchema::normalize($result, $project, $exercise);
verify($normalized['passed'] === true && count($normalized['i2cTrace']) === 3 && $normalized['trace'][1]['signal'] === 'servo', 'Combined result and servo telemetry accepted');
$partial = $result; $partial['passed'] = false; $partial['score'] = 91.67; $partial['scenarios'][1]['passed'] = false;
$partial['scenarios'][1]['i2cAssertions'][0]['passed'] = false; $partial['scenarios'][1]['i2cAssertions'][0]['matchedAtMs'] = null;
verify(RunnerResultSchema::normalize($partial, $project, $exercise)['score'] === 91.67, 'GPIO and bus assertions share combined score denominator');
$partial['score'] = 100;
throws(fn () => RunnerResultSchema::normalize($partial, $project, $exercise), RuntimeException::class, 'I2C failures cannot be hidden behind GPIO-only score');
foreach ([
    static function ($r) { $r['scenarios'][0]['i2cAssertions'][0]['observed']['data'] = [1]; return $r; },
    static function ($r) { $r['scenarios'][0]['i2cAssertions'][0]['observed']['repeated'] = false; return $r; },
    static function ($r) { $r['scenarios'][0]['i2cAssertions'][0]['observed']['operation'] = 'read'; return $r; },
    static function ($r) { $r['scenarios'][0]['i2cAssertions'][0]['observed']['address'] = 65; return $r; },
    static function ($r) { $r['scenarios'][0]['i2cAssertions'][0]['observed']['atMs'] = 2; return $r; },
    static function ($r) { $r['scenarios'][0]['i2cAssertions'][0]['matchedAtMs'] = 101; return $r; },
    static function ($r) { $r['scenarios'][0]['i2cAssertions'][0]['data'] = [1]; return $r; },
    static function ($r) { $r['scenarios'][0]['i2cAssertions'] = array_reverse($r['scenarios'][0]['i2cAssertions']); return $r; },
    static function ($r) { unset($r['scenarios'][0]['i2cAssertions']); return $r; },
    static function ($r) { $r['i2cTrace'][0]['data'] = [256]; return $r; },
    static function ($r) { $r['i2cTrace'][2]['data'] = [0, 90]; return $r; },
    static function ($r) { $r['i2cTrace'][0]['atMs'] = 501; return $r; },
    static function ($r) { $r['trace'][1]['value'] = 181; return $r; },
    static function ($r) { $r['trace'][2]['value'] = 0; return $r; },
] as $mutation) {
    $bad = $mutation($result);
    throws(fn () => RunnerResultSchema::normalize($bad, $project, $exercise), RuntimeException::class, 'Forged protocol result fails closed');
}
$result['_visibility'] = ['public' => true, 'secret' => false];
$public = ResultVisibility::learner($result);
verify(!isset($public['scenarios'][1]['i2cAssertions']) && count($public['scenarios'][0]['i2cAssertions']) === 3, 'Hidden bus conditions redacted while public checks remain visible');
$result['traceScenarioId'] = 'secret';
verify(ResultVisibility::learner($result)['i2cTrace'] === [], 'Hidden-scoped I2C telemetry redacted');
unset($result['_visibility']);
verify(ResultVisibility::learner($result)['i2cTrace'] === [], 'Legacy missing visibility never leaks bus telemetry');
foreach (['servo', 'i2c'] as $preset) {
    $sample = json_decode((string) file_get_contents(__DIR__ . '/fixtures/' . $preset . '-exercise.json'), true, 64, JSON_THROW_ON_ERROR);
    $sample['status'] = 'published';
    $normalizedSample = ExerciseSchema::normalize($sample);
    verify($normalizedSample['status'] === 'published' && count($normalizedSample['scenarios']) > 0, 'Actual GUI ' . $preset . ' preset validates');
}
echo '[protocols] ' . $testChecks . " checks passed (schema and authenticated response contracts; actual PXT covered by integration fixture)\n";
