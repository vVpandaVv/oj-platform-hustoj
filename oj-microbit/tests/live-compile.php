<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
use OjMicrobit\Domain\ProjectSchema;
use OjMicrobit\Domain\RunnerResultSchema;
use OjMicrobit\Support\HttpRunner;
use OjMicrobit\Support\OperationError;
use OjMicrobit\Support\Settings;
$testOptions[Settings::OPTION] = ['runnerUrl' => 'http://127.0.0.1:3037', 'runnerToken' => str_repeat('t', 40)];
$project = ProjectSchema::normalize(fixtureProject());
$base = ['status' => 'compiled', 'liveProtocol' => 'oj-live/1', 'runtimeVersion' => RunnerResultSchema::RUNTIME_VERSION, 'sandbox' => 'docker-no-network', 'projectHash' => ProjectSchema::hash($project), 'js' => 'compiled-code', 'diagnostics' => []];
$seen = [];
$testHttp = static function ($url, $arguments) use (&$seen, $base) { $seen = [$url, json_decode($arguments['body'], true)]; return ['response' => ['code' => 200], 'body' => json_encode($base)]; };
$client = new HttpRunner(); $value = $client->compile($project);
verify($value['status'] === 'compiled' && $value['js'] === 'compiled-code', 'Validated compiled bytecode is returned separately from grading');
verify(str_ends_with($seen[0], '/v1/compile') && array_keys($seen[1]) === ['project'], 'Compile transport contains source only');
verify(!isset($value['sandbox'], $value['score'], $value['exercise']), 'Compiler result exposes no grading state or exercise');
foreach ([['liveProtocol' => 'stale'], ['projectHash' => str_repeat('0', 64)], ['sandbox' => 'test-process'], ['js' => str_repeat('x', 2097153)], ['js' => ''], ['diagnostics' => [['message' => 123]]], ['status' => 'graded']] as $change) {
    $testHttp = static fn () => ['response' => ['code' => 200], 'body' => json_encode(array_replace($base, $change))];
    throws(fn () => $client->compile($project), OperationError::class, 'Invalid compiled response is rejected before browser execution');
}
$testHttp = static fn () => ['response' => ['code' => 404], 'body' => '{}'];
try { $client->compile($project); throw new LogicException('Expected old runner rejection'); } catch (OperationError $error) { verify($error->errorCode === 'version_mismatch', 'Old execution servers explicitly require an update'); }
$testHttp = static fn () => ['response' => ['code' => 200], 'body' => json_encode(array_replace($base, ['status' => 'compile_error', 'js' => 'untrusted', 'diagnostics' => [['file' => 'main.ts', 'line' => 2, 'message' => '<bad>']]]))];
$bad = $client->compile($project); verify(!isset($bad['js']) && $bad['diagnostics'][0]['message'] === '<bad>', 'Compile failure returns diagnostics with no executable code');
echo '[live-compile] ' . $testChecks . " checks passed\n";
