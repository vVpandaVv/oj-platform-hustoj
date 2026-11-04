<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

use OjMicrobit\Addon\RestController;
use OjMicrobit\Domain\RunnerResultSchema;
use OjMicrobit\Support\HttpRunner;
use OjMicrobit\Support\OperationError;
use OjMicrobit\Support\RequestContext;
use OjMicrobit\Support\Settings;

$logFile = tempnam(dirname(__DIR__, 2) . '/_notes/tmp', 'microbit-safe-errors-');
$oldLog = ini_set('error_log', $logFile);
try {
    $testOptions[Settings::OPTION] = ['runnerUrl' => 'http://127.0.0.1:3037', 'runnerToken' => str_repeat('t', 40)];
    $project = fixtureProject(); $exercise = fixtureExercise(); $result = fixtureResult($project, $exercise);
    $client = new HttpRunner();
    foreach ([[429, 'busy', 429, 2], [503, 'unavailable', 503, 5], [500, 'unavailable', 503, 5], [504, 'timeout', 504, 5], [401, 'runner_auth_failed', 502, null], [400, 'invalid_input', 400, null], [302, 'invalid_response', 502, null]] as [$status, $code, $expectedStatus, $retry]) {
        $testHttp = static fn () => ['response' => ['code' => $status], 'body' => '{"message":"RAW_UPSTREAM_SECRET"}'];
        try { $client->run($project, $exercise); throw new LogicException('Expected safe operational failure.'); }
        catch (OperationError $error) {
            verify($error->errorCode === $code && $error->httpStatus === $expectedStatus && $error->retryAfterSeconds === $retry, 'HTTP operational failures have distinct stable status and retry policy');
            verify(RequestContext::validId($error->requestId) && !str_contains($error->getMessage(), 'RAW_UPSTREAM_SECRET'), 'Error has trusted request ID and no upstream message');
        }
    }
    foreach ([['http_request_failed', 'connection_failed', 'private RAW_UPSTREAM_SECRET'], ['http_request_timeout', 'timeout', 'private RAW_UPSTREAM_SECRET'], ['http_request_failed', 'timeout', 'cURL error 28: RAW_UPSTREAM_SECRET timed out']] as [$wpCode, $code, $message]) {
        $testHttp = static fn () => new WP_Error($wpCode, $message);
        try { $client->run($project, $exercise); throw new LogicException('Expected transport failure.'); }
        catch (OperationError $error) { verify($error->errorCode === $code && $error->retryAfterSeconds === 5, 'Timeout and network connection failures remain distinct'); }
    }
    foreach ([['runtimeVersion' => 'stale'], ['sandbox' => 'test-process'], ['score' => 23]] as $change) {
        $testHttp = static fn () => ['response' => ['code' => 200], 'body' => json_encode(array_replace($result + ['sandbox' => 'docker-no-network'], $change))];
        throws(fn () => $client->run($project, $exercise), OperationError::class, 'Mismatched version/isolation/result never becomes a grade');
    }
    $testHttp = static fn () => ['response' => ['code' => 200], 'body' => json_encode($result + ['sandbox' => 'docker-no-network'])];
    verify($client->run($project, $exercise)['score'] === 100, 'A successful call recovers after operational errors');
    $call = new ReflectionMethod(RestController::class, 'call');
    $error = $call->invoke(null, static function (): void { throw new OperationError('conflict', 409, '최신 내용을 다시 불러와 주세요.'); });
    verify($error->get_error_code() === 'oj_microbit_conflict' && $error->get_error_data()['status'] === 409, 'Explicit save conflict remains HTTP 409');
    $error = $call->invoke(null, static function (): void { throw new InvalidArgumentException('exercise.scenarios.0.outputSequences.0.steps.1.holdMs: SECRET_STUDENT_SOURCE expected 901'); });
    verify($error->get_error_data()['fieldPath'] === 'exercise.scenarios.0.outputSequences.0.steps.1.holdMs', 'Known canonical field path survives sanitization');
    verify(!str_contains($error->message, 'SECRET') && !str_contains($error->message, '901'), 'Schema raw values and hidden inputs are never exposed');
    $error = $call->invoke(null, static function (): void { throw new InvalidArgumentException('project.files.SECRET_STUDENT_SOURCE: secret'); });
    verify(!isset($error->get_error_data()['fieldPath']), 'Unknown path segments cannot expose filenames');
    foreach ([
        ['exercise.scenarios.0.assertions.0', '응답 시간과 유지 시간이 시나리오 종료를 넘습니다.', '반응 대기 시간과 유지 시간의 합'],
        ['exercise.scenarios.0', '모든 입력 핀에 0ms 초기값이 필요합니다.', '시작 시점(0ms)'],
        ['exercise.connections.1', '한 핀에 연결을 중복 설정할 수 없습니다.', '겹친 연결의 핀 번호'],
        ['exercise.connections.0', '아날로그 입력은 P0~P4, P10만 사용할 수 있습니다.', 'P0~P4 또는 P10'],
        ['exercise.connections.0', '장치와 핀 방향 또는 신호 종류가 맞지 않습니다.', '장치에 맞는 입력·출력'],
        ['exercise.publicationReview', '점검 후 과제 내용이 바뀌었습니다. 최신 내용으로 공개 전 점검을 다시 진행해 주세요.', '점검 후 과제 내용이 바뀌었습니다.'],
    ] as [$path, $detail, $expected]) {
        $error = $call->invoke(null, static function () use ($path, $detail): void { throw new InvalidArgumentException($path . ': ' . $detail); });
        verify(str_contains($error->message, $expected) && $error->get_error_data()['fieldPath'] === $path, 'Exact trusted schema phrase yields actionable localized guidance');
        $error = $call->invoke(null, static function () use ($path, $detail): void { throw new InvalidArgumentException($path . ': ' . $detail . ' SECRET_STUDENT_SOURCE 901'); });
        verify(!str_contains($error->message, 'SECRET') && !str_contains($error->message, '901') && !str_contains($error->message, $expected), 'Untrusted suffix never passes the schema phrase allowlist');
    }
    $response = $call->invoke(null, static fn () => ['status' => 'compile_error', 'passed' => false, 'score' => 0]);
    verify($response->data['status'] === 'compile_error' && RequestContext::validId($response->data['requestId']), 'Student compile errors remain ordinary results with request ID');
    $response = $call->invoke(null, static fn () => ['status' => 'runtime_error', 'passed' => false, 'score' => 0]);
    verify($response->data['status'] === 'runtime_error', 'Student runtime errors remain ordinary results');
    $testUserId = 0;
    $error = RestController::authorize(new WP_REST_Request([], ['X-Request-ID' => 'forged']), false);
    verify($error->get_error_code() === 'oj_microbit_unauthorized' && RequestContext::validId($error->get_error_data()['requestId']), 'Authentication errors ignore browser supplied request IDs');
    verify(!str_contains((string) file_get_contents($logFile), 'SECRET') && !str_contains((string) file_get_contents($logFile), str_repeat('t', 40)), 'Operational logs contain no source, upstream message, or token');
    echo '[errors] ' . $testChecks . " checks passed\n";
} finally { ini_set('error_log', $oldLog); unlink($logFile); }
