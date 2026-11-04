<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

use OjMicrobit\Domain\ProjectSchema;
use OjMicrobit\Domain\RunnerResultSchema;
use OjMicrobit\Support\HttpRunner;
use OjMicrobit\Support\OperationError;
use OjMicrobit\Support\Settings;

$project = fixtureProject(); $exercise = fixtureExercise(); $result = fixtureResult($project, $exercise);
verify(RunnerResultSchema::normalize($result, $project, $exercise)['passed'] === true, 'Complete valid grade accepted');
$preview = $exercise; $preview['scenarios'] = [$exercise['scenarios'][0]]; $preview['scenarios'][0]['assertions'] = [];
$previewResult = fixtureResult($project, $preview);
verify(RunnerResultSchema::normalize($previewResult, $project, $preview)['score'] === null, 'Preview null score accepted without claiming pass');
$compile = $result; $compile['status'] = 'compile_error'; $compile['passed'] = false; $compile['score'] = 0; $compile['scenarios'] = []; $compile['trace'] = []; $compile['traceScenarioId'] = null;
$compile['diagnostics'] = [['code' => 1005, 'file' => 'main.ts', 'line' => 1, 'message' => 'Expected token.']];
verify(RunnerResultSchema::normalize($compile, $project, $exercise)['status'] === 'compile_error', 'Compilation error with no scenario is a recorded failure');
$partial = $result; $partial['status'] = 'runtime_error'; $partial['passed'] = false; $partial['score'] = 50;
$partial['scenarios'][1]['passed'] = false; $partial['scenarios'][1]['error'] = 'Peripheral API unsupported'; $partial['scenarios'][1]['assertions'][0]['passed'] = false;
verify(RunnerResultSchema::normalize($partial, $project, $exercise)['score'] === 50, 'Runtime error permits genuine partial points but never overall pass');

foreach ([
    static function ($r) { $r['projectHash'] = str_repeat('0', 64); return $r; },
    static function ($r) { $r['runtimeVersion'] = 'another-runtime'; return $r; },
    static function ($r) { $r['score'] = '100'; return $r; },
    static function ($r) { $r['score'] = 99; return $r; },
    static function ($r) { $r['scenarios'] = array_reverse($r['scenarios']); return $r; },
    static function ($r) { $r['scenarios'][1]['assertions'][0]['expected'] = 0; return $r; },
    static function ($r) { $r['scenarios'][1]['assertions'] = []; return $r; },
    static function ($r) { $r['scenarios'][1]['passed'] = false; return $r; },
    static function ($r) { $r['scenarios'][0]['assertions'][0]['matchedAtMs'] = null; return $r; },
    static function ($r) { $r['traceScenarioId'] = 'nonexistent'; return $r; },
    static function ($r) { $r['traceScenarioId'] = null; return $r; },
    static function ($r) { $r['trace'][0]['value'] = 2; return $r; },
    static function ($r) { $r['trace'][0]['atMs'] = 501; return $r; },
    static function ($r) { $r['diagnostics'] = [['code' => 'bad', 'file' => '', 'line' => 0, 'message' => 'x']]; return $r; },
] as $mutation) {
    $bad = $mutation($result);
    throws(fn () => RunnerResultSchema::normalize($bad, $project, $exercise), RuntimeException::class, 'Malformed or mismatched runner result fails closed');
}
$extra = $result; $extra['internalSecret'] = 'never expose'; $extra['scenarios'][0]['untrusted'] = 'never expose';
$normalized = RunnerResultSchema::normalize($extra, $project, $exercise);
verify(!isset($normalized['internalSecret'], $normalized['scenarios'][0]['untrusted']), 'Unknown runner fields stripped');
$bad = $compile; $bad['passed'] = true;
throws(fn () => RunnerResultSchema::normalize($bad, $project, $exercise), RuntimeException::class, 'Compile failure cannot be converted to success');

$testOptions[Settings::OPTION] = ['runnerUrl' => 'http://127.0.0.1:3037', 'runnerToken' => str_repeat('t', 40)];
$requests = [];
$testHttp = static function (string $url, array $args, string $method) use (&$requests, $result): array {
    $requests[] = [$url, $args, $method];
    return ['response' => ['code' => 200], 'body' => json_encode($result + ['sandbox' => 'docker-no-network'], JSON_THROW_ON_ERROR)];
};
$client = new HttpRunner();
verify($client->run($project, $exercise)['passed'] === true, 'HTTP runner validates matching response');
verify($requests[0][0] === 'http://127.0.0.1:3037/v1/run' && $requests[0][2] === 'POST', 'Runner endpoint and method');
verify($requests[0][1]['redirection'] === 0 && $requests[0][1]['headers']['Authorization'] === 'Bearer ' . str_repeat('t', 40), 'Runner auth stays in bounded server request without redirects');
$requestBody = json_decode($requests[0][1]['body'], true, 64, JSON_THROW_ON_ERROR);
verify($requestBody['exercise']['scenarios'][1]['inputs'][0]['value'] === 901, 'Saved hidden scenarios reach runner through backend');
foreach ([null, 'test-process', 'docker-with-network'] as $sandbox) {
    $testHttp = static fn () => ['response' => ['code' => 200], 'body' => json_encode($result + ($sandbox === null ? [] : ['sandbox' => $sandbox]), JSON_THROW_ON_ERROR)];
    throws(fn () => $client->run($project, $exercise), OperationError::class, 'Non-production or missing isolation marker never becomes an official grade');
}
foreach ([429, 503] as $code) {
    $testHttp = static fn () => ['response' => ['code' => $code], 'body' => '{}'];
    throws(fn () => $client->run($project, $exercise), DomainException::class, 'Busy runner produces retryable domain error');
}
foreach ([302, 401, 500] as $code) {
    $testHttp = static fn () => ['response' => ['code' => $code], 'body' => '{}'];
    throws(fn () => $client->run($project, $exercise), OperationError::class, 'Unexpected HTTP status cannot become a result');
}
$testHttp = static fn () => ['response' => ['code' => 200], 'body' => '{'];
throws(fn () => $client->run($project, $exercise), OperationError::class, 'Truncated JSON cannot become a result');
$testHttp = static fn () => ['response' => ['code' => 200], 'body' => json_encode(['ready' => true, 'runtimeVersion' => RunnerResultSchema::RUNTIME_VERSION, 'sandbox' => 'docker-no-network'])];
verify($client->status()['ready'] === true, 'Health checks pinned runtime and production isolation');
foreach ([null, 'test-process', 'docker-with-network'] as $sandbox) {
    $testHttp = static fn () => ['response' => ['code' => 200], 'body' => json_encode(['ready' => true, 'runtimeVersion' => RunnerResultSchema::RUNTIME_VERSION] + ($sandbox === null ? [] : ['sandbox' => $sandbox]))];
    verify($client->status()['ready'] === false, 'Development or unknown isolation is not reported ready');
}
$testHttp = static fn () => ['response' => ['code' => 200], 'body' => '{"ready":true,"runtimeVersion":"stale"}'];
verify($client->status()['ready'] === false, 'Incompatible runner is not reported ready');
$testOptions[Settings::OPTION] = [];
verify($client->status()['ready'] === false, 'Unconfigured service reports unavailable');
throws(fn () => $client->run($project, $exercise), DomainException::class, 'Unconfigured service never grades');
throws(fn () => Settings::validateUrl('http://external.test:3037'), InvalidArgumentException::class, 'Remote token transport requires HTTPS');
throws(fn () => Settings::validateUrl('https://user:pass@external.test'), InvalidArgumentException::class, 'Embedded URL credentials refused');
$contract = json_decode((string) file_get_contents(__DIR__ . '/fixtures/pxt-run-result.json'), true, 64, JSON_THROW_ON_ERROR);
verify(RunnerResultSchema::normalize($contract['result'], $contract['request']['project'], $contract['request']['exercise'])['passed'] === true,
    'Recorded genuine PXT compiler/simulator response accepted with matching PHP project hash and assertion contract');
echo '[runner-result] ' . $testChecks . " checks passed (HTTP fixture and recorded genuine PXT contract)\n";
