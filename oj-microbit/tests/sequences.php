<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

use OjMicrobit\Domain\ExerciseSchema;
use OjMicrobit\Domain\ResultVisibility;
use OjMicrobit\Domain\RunnerResultSchema;

$exercise = fixtureSequenceExercise(); $project = $exercise['starterProject'];
verify($exercise['status'] === 'published' && $exercise['scenarios'][0]['assertions'] === [] && count($exercise['scenarios'][0]['outputSequences']) === 1, 'Published sequence-only scenario is supported');
verify(ExerciseSchema::normalize($exercise) === $exercise, 'Sequence contract normalization is idempotent');
$legacy = fixtureExercise();
foreach ($legacy['scenarios'] as &$scenario) { unset($scenario['outputSequences']); }
unset($scenario);
verify(ExerciseSchema::normalize($legacy)['scenarios'][0]['outputSequences'] === [], 'Absent old sequence conditions default to empty list');

foreach ([
    static function ($e) { $e['scenarios'][0]['outputSequences'] = null; return $e; },
    static function ($e) { $e['scenarios'][0]['outputSequences'][0]['steps'] = []; return $e; },
    static function ($e) { $e['scenarios'][0]['outputSequences'][0]['steps'] = null; return $e; },
    static function ($e) { $e['scenarios'][0]['outputSequences'][0]['pin'] = 0; return $e; },
    static function ($e) { $e['scenarios'][0]['outputSequences'][0]['pin'] = 17; return $e; },
    static function ($e) { $e['scenarios'][0]['outputSequences'][0]['signal'] = 'pwm'; return $e; },
    static function ($e) { $e['scenarios'][0]['outputSequences'][0]['afterMs'] = 3000; return $e; },
    static function ($e) { $e['scenarios'][0]['outputSequences'][0]['afterMs'] = '0'; return $e; },
    static function ($e) { $e['scenarios'][0]['outputSequences'][0]['steps'][0]['expected'] = 2; return $e; },
    static function ($e) { $e['scenarios'][0]['outputSequences'][0]['steps'][0]['expected'] = true; return $e; },
    static function ($e) { $e['scenarios'][0]['outputSequences'][0]['steps'][0]['withinMs'] = 0; return $e; },
    static function ($e) { $e['scenarios'][0]['outputSequences'][0]['steps'][0]['holdMs'] = 0; return $e; },
    static function ($e) { $e['scenarios'][0]['outputSequences'][0]['steps'][0]['holdMs'] = 1000.0; return $e; },
    static function ($e) { $e['scenarios'][0]['outputSequences'][0]['steps'][0]['tolerance'] = 1; return $e; },
    static function ($e) { $e['scenarios'][0]['outputSequences'][0]['steps'][1]['holdMs'] = 2000; return $e; },
    static function ($e) { unset($e['scenarios'][0]['outputSequences'][0]['steps'][0]['tolerance']); return $e; },
    static function ($e) { $e['scenarios'][0]['outputSequences'] = array_fill(0, 65, $e['scenarios'][0]['outputSequences'][0]); return $e; },
    static function ($e) { $e['scenarios'][0]['outputSequences'][0]['steps'] = array_fill(0, 21, ['expected' => 1, 'withinMs' => 1, 'holdMs' => 1, 'tolerance' => 0]); return $e; },
] as $mutation) {
    $bad = $mutation($exercise);
    throws(fn () => ExerciseSchema::normalize($bad), InvalidArgumentException::class, 'Invalid or unbounded sequence contract rejected');
}
$bounded = $exercise;
$plan = ['pin' => 1, 'signal' => 'digital', 'afterMs' => 0, 'steps' => array_fill(0, 20, ['expected' => 1, 'withinMs' => 1, 'holdMs' => 1, 'tolerance' => 0])];
$bounded['scenarios'][0]['outputSequences'] = array_fill(0, 12, $plan);
$last = $plan; $last['steps'] = array_slice($last['steps'], 0, 16); $bounded['scenarios'][0]['outputSequences'][] = $last;
verify(count(ExerciseSchema::normalize($bounded)['scenarios'][0]['outputSequences']) === 13, 'Exact 256 scenario sequence-step budget accepted');
$bounded['scenarios'][0]['outputSequences'][12]['steps'][] = $plan['steps'][0];
throws(fn () => ExerciseSchema::normalize($bounded), InvalidArgumentException::class, 'Step budget spans all sequences in scenario');
foreach ([['pwm', 'led', 1023], ['servo', 'servo', 180], ['servo_pulse', 'servo', 20000]] as [$signal, $device, $maximum]) {
    $variant = $exercise; $variant['connections'][1]['signal'] = $signal; $variant['connections'][1]['device'] = $device;
    foreach ($variant['scenarios'] as &$scenario) {
        $scenario['outputSequences'][0]['signal'] = $signal;
        $scenario['outputSequences'][0]['steps'][0]['expected'] = $maximum;
        $scenario['outputSequences'][0]['steps'][1]['expected'] = $signal === 'servo_pulse' ? 1 : 0;
    }
    unset($scenario);
    verify(ExerciseSchema::normalize($variant)['scenarios'][0]['outputSequences'][0]['steps'][0]['expected'] === $maximum, 'Native sequence signal range supported');
    $variant['scenarios'][0]['outputSequences'][0]['steps'][0]['tolerance'] = $maximum;
    throws(fn () => ExerciseSchema::normalize($variant), InvalidArgumentException::class, 'Tolerance cannot cover complete sequence signal range');
}

$result = fixtureResult($project, $exercise);
$normalized = RunnerResultSchema::normalize($result, $project, $exercise);
verify($normalized['score'] === 100 && $normalized['scenarios'][0]['outputSequences'][0]['steps'][1]['completedAtMs'] === 1502, 'Sequential result preserves actual matching and completion times');
foreach ([
    static function ($r) { $r['scenarios'][0]['outputSequences'] = []; return $r; },
    static function ($r) { $r['scenarios'][0]['outputSequences'][0]['pin'] = 2; return $r; },
    static function ($r) { $r['scenarios'][0]['outputSequences'][0]['passed'] = false; return $r; },
    static function ($r) { $r['scenarios'][0]['outputSequences'][0]['steps'][0]['expected'] = 0; return $r; },
    static function ($r) { $r['scenarios'][0]['outputSequences'][0]['steps'][0]['completedAtMs'] = 1000; return $r; },
    static function ($r) { $r['scenarios'][0]['outputSequences'][0]['steps'][1]['matchedAtMs'] = 1000; return $r; },
    static function ($r) { $r['scenarios'][0]['outputSequences'][0]['steps'][0]['observed'] = null; return $r; },
    static function ($r) { $r['scenarios'][0]['outputSequences'][0]['steps'][0]['observed']['value'] = 0; return $r; },
    static function ($r) { $r['scenarios'][0]['outputSequences'][0]['steps'][0]['observed']['atMs'] = 2; return $r; },
    static function ($r) { unset($r['scenarios'][0]['outputSequences'][0]['steps'][0]['completedAtMs']); return $r; },
] as $mutation) {
    $bad = $mutation($result);
    throws(fn () => RunnerResultSchema::normalize($bad, $project, $exercise), RuntimeException::class, 'Forged sequence result rejected');
}
$partial = $result; $partial['passed'] = false; $partial['score'] = 75; $partial['scenarios'][0]['passed'] = false;
$partial['scenarios'][0]['outputSequences'][0]['passed'] = false;
$partial['scenarios'][0]['outputSequences'][0]['steps'][1] = $exercise['scenarios'][0]['outputSequences'][0]['steps'][1]
    + ['passed' => false, 'matchedAtMs' => null, 'completedAtMs' => null, 'observed' => ['signal' => 'digital', 'value' => 1, 'atMs' => 1100]];
verify(RunnerResultSchema::normalize($partial, $project, $exercise)['score'] === 75, 'Each sequence step contributes its own point to total score');
$mixed = fixtureProtocolExercise();
foreach ($mixed['scenarios'] as &$scenario) {
    $scenario['outputSequences'] = [['pin' => 2, 'signal' => 'servo', 'afterMs' => 0, 'steps' => [['expected' => 90, 'withinMs' => 100, 'holdMs' => 100, 'tolerance' => 0]]]];
}
unset($scenario);
$mixed = ExerciseSchema::normalize($mixed); $mixedResult = fixtureResult(fixtureProject(), $mixed);
$mixedResult['passed'] = false; $mixedResult['score'] = 92.86; $mixedResult['scenarios'][0]['passed'] = false;
$mixedResult['scenarios'][0]['outputSequences'][0]['passed'] = false;
$mixedResult['scenarios'][0]['outputSequences'][0]['steps'][0] = $mixed['scenarios'][0]['outputSequences'][0]['steps'][0]
    + ['passed' => false, 'matchedAtMs' => null, 'completedAtMs' => null, 'observed' => null];
verify(RunnerResultSchema::normalize($mixedResult, fixtureProject(), $mixed)['score'] === 92.86, 'GPIO, I2C and sequence steps share one score denominator');
$partial['score'] = 100;
throws(fn () => RunnerResultSchema::normalize($partial, $project, $exercise), RuntimeException::class, 'Sequence failure cannot be hidden behind overall score');
$failed = $result; $failed['passed'] = false; $failed['score'] = 50; $failed['scenarios'][0]['passed'] = false; $failed['scenarios'][0]['outputSequences'][0]['passed'] = false;
foreach ($failed['scenarios'][0]['outputSequences'][0]['steps'] as &$step) { $step['passed'] = false; $step['matchedAtMs'] = null; $step['completedAtMs'] = null; $step['observed'] = null; }
unset($step);
$failed['scenarios'][0]['outputSequences'][0]['steps'][0]['observed'] = ['signal' => 'digital', 'value' => 0, 'atMs' => 500];
verify(RunnerResultSchema::normalize($failed, $project, $exercise)['score'] === 50, 'Hold-window glitch may be reported after response deadline and skips remaining steps');
$failed['scenarios'][0]['outputSequences'][0]['steps'][1]['observed'] = ['signal' => 'digital', 'value' => 0, 'atMs' => 1000];
throws(fn () => RunnerResultSchema::normalize($failed, $project, $exercise), RuntimeException::class, 'Skipped step cannot claim later observation');
$runtime = $result; $runtime['status'] = 'runtime_error'; $runtime['passed'] = false; $runtime['score'] = 0;
foreach ($runtime['scenarios'] as &$scenario) {
    $scenario['passed'] = false; $scenario['error'] = 'Execution failed';
    foreach ($scenario['outputSequences'] as &$sequence) {
        $sequence['passed'] = false;
        foreach ($sequence['steps'] as &$step) { $step['passed'] = false; $step['matchedAtMs'] = null; $step['completedAtMs'] = null; $step['observed'] = null; }
        unset($step);
    }
    unset($sequence);
}
unset($scenario);
verify(RunnerResultSchema::normalize($runtime, $project, $exercise)['score'] === 0, 'Runtime failure grants no sequence step points');
$runtime['scenarios'][0]['outputSequences'][0]['steps'][0]['observed'] = ['signal' => 'digital', 'value' => 1, 'atMs' => 1];
throws(fn () => RunnerResultSchema::normalize($runtime, $project, $exercise), RuntimeException::class, 'Runtime-failed sequence does not retain misleading completion evidence');
$result['_visibility'] = ['public' => true, 'secret' => false];
$public = ResultVisibility::learner($result);
verify(count($public['scenarios'][0]['outputSequences'][0]['steps']) === 2 && !isset($public['scenarios'][1]['outputSequences']), 'Hidden sequence conditions and observations stay private');
unset($result['scenarios'][0]['outputSequences']);
verify(ResultVisibility::learner($result)['scenarios'][0]['outputSequences'] === [], 'Historical result missing sequence field remains readable');
$result['runtimeVersion'] = 'pxt-microbit/9.1.1;pxt-core/13.0.1;oj-gpio/2';
throws(fn () => RunnerResultSchema::normalize($result, $project, $exercise), RuntimeException::class, 'New grades require the sequence-aware runtime');

$genuine = json_decode((string) file_get_contents(__DIR__ . '/fixtures/pxt-sequence-result.json'), true, 64, JSON_THROW_ON_ERROR);
$verified = RunnerResultSchema::normalize($genuine['result'], $genuine['request']['project'], $genuine['request']['exercise']);
verify($verified['score'] === 100 && $verified['passed'], 'Recorded genuine PXT delayed LED and servo sequences validate through PHP');
echo '[sequences] ' . $testChecks . " checks passed (schema, response and recorded genuine PXT contracts)\n";
