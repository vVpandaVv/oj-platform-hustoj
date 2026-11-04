<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Core/Autoloader.php';
\OjMicrobit\Core\Autoloader::register();
$platformRoot = dirname(__DIR__, 2) . '/oj-integration-rebuild/oj-platform';
require_once $platformRoot . '/src/Kernel/Autoloader.php';
\CodingPanda\OjPlatform\Kernel\Autoloader::register($platformRoot);

if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }
if (!defined('DAY_IN_SECONDS')) { define('DAY_IN_SECONDS', 86400); }
if (!defined('OJ_MICROBIT_VERSION')) { define('OJ_MICROBIT_VERSION', '1.0.0-rc.9'); }
if (!defined('OJ_MICROBIT_FILE')) { define('OJ_MICROBIT_FILE', dirname(__DIR__) . '/oj-microbit.php'); }

$testChecks = 0;
function verify(bool $condition, string $message): void
{
    global $testChecks;
    if (!$condition) { throw new RuntimeException($message); }
    $testChecks++;
}
function throws(callable $operation, string $class, string $message): void
{
    try { $operation(); }
    catch (Throwable $error) {
        if (!$error instanceof $class) { throw new RuntimeException($message . ': unexpected ' . get_class($error) . ': ' . $error->getMessage(), 0, $error); }
        verify(true, $message); return;
    }
    throw new RuntimeException($message . ': exception was not thrown');
}

/** Test-only publication confirmation for the exact proposed payload. */
function reviewedExercise(array $input, string $starterMode = 'current'): array
{
    $input['publicationReview'] = ['starterMode' => $starterMode];
    $normalized = \OjMicrobit\Domain\ExerciseSchema::normalize(\OjMicrobit\Domain\PublicationReview::prepareInput($input));
    $description = \OjMicrobit\Domain\PublicationReview::describe($normalized, $starterMode);
    $input['publicationReview'] += ['confirmed' => true, 'fingerprint' => $description['fingerprint']];
    return $input;
}
function fixtureProject(): array
{
    return \OjMicrobit\Domain\ProjectSchema::normalize(['name' => '핀 실습', 'files' => [
        'main.ts' => 'basic.forever(() => { pins.digitalWritePin(DigitalPin.P1, pins.analogReadPin(AnalogPin.P0) >= 512 ? 1 : 0); basic.pause(20); });',
        'main.blocks' => '<xml></xml>',
        'pxt.json' => '{"name":"pin-test","dependencies":{"core":"*"},"files":["main.ts","main.blocks"],"preferredEditor":"blocksprj"}',
    ]]);
}
function fixtureExercise(): array
{
    $data = ['title' => '핀 입력 채점', 'description' => '값을 비교합니다.', 'status' => 'published', 'starterProject' => fixtureProject(),
        'connections' => [
            ['pin' => 0, 'direction' => 'input', 'signal' => 'analog', 'device' => 'light_sensor', 'label' => '센서'],
            ['pin' => 1, 'direction' => 'output', 'signal' => 'digital', 'device' => 'led', 'label' => 'LED'],
        ], 'scenarios' => []];
    foreach ([['public', true, 100, 0], ['secret', false, 901, 1]] as [$id, $visible, $value, $expected]) {
        $data['scenarios'][] = ['id' => $id, 'name' => $visible ? '공개 예제' : '비밀 입력 901', 'visible' => $visible, 'durationMs' => 500,
            'inputs' => [['atMs' => 0, 'pin' => 0, 'signal' => 'analog', 'value' => $value]],
            'assertions' => [['pin' => 1, 'signal' => 'digital', 'expected' => $expected, 'afterMs' => 0, 'withinMs' => 100, 'holdMs' => 100, 'tolerance' => 0]]];
    }
    return \OjMicrobit\Domain\ExerciseSchema::normalize($data);
}
function fixtureResult(array $project, array $exercise): array
{
    $scenarios = []; $trace = []; $i2cTrace = []; $traceId = null; $count = 0;
    foreach ($exercise['scenarios'] as $scenario) {
        $assertions = [];
        foreach ($scenario['assertions'] as $assertion) {
            $assertions[] = $assertion + ['passed' => true, 'matchedAtMs' => $assertion['afterMs'] + 1,
                'observed' => ['signal' => $assertion['signal'], 'value' => $assertion['expected'], 'atMs' => $assertion['afterMs'] + 1]];
            $count++;
        }
        $i2cAssertions = [];
        foreach ($scenario['i2cAssertions'] ?? [] as $index => $assertion) {
            $transaction = ['atMs' => $assertion['afterMs'] + $index + 1, 'address' => $assertion['address'], 'operation' => $assertion['operation'],
                'data' => $assertion['data'], 'repeated' => $assertion['repeated'], 'kind' => 'i2c'];
            $i2cAssertions[] = $assertion + ['passed' => true, 'matchedAtMs' => $transaction['atMs'], 'observed' => $transaction]; $count++;
        }
        $outputSequences = [];
        foreach ($scenario['outputSequences'] ?? [] as $sequence) {
            $cursor = $sequence['afterMs']; $steps = [];
            foreach ($sequence['steps'] as $step) {
                $matched = $cursor + 1; $completed = $matched + $step['holdMs'];
                $steps[] = $step + ['passed' => true, 'matchedAtMs' => $matched, 'completedAtMs' => $completed,
                    'observed' => ['signal' => $sequence['signal'], 'value' => $step['expected'], 'atMs' => $matched]];
                $cursor = $completed; $count++;
            }
            $outputSequences[] = ['pin' => $sequence['pin'], 'signal' => $sequence['signal'], 'afterMs' => $sequence['afterMs'], 'passed' => true, 'steps' => $steps];
        }
        if ($traceId === null && $scenario['visible']) {
            $traceId = $scenario['id'];
            foreach ($assertions as $assertion) { $trace[] = ['kind' => 'write', 'pin' => $assertion['pin'], 'signal' => $assertion['signal'], 'value' => $assertion['expected'], 'atMs' => $assertion['matchedAtMs']]; }
            foreach ($outputSequences as $sequence) {
                foreach ($sequence['steps'] as $step) { $trace[] = ['kind' => 'write', 'pin' => $sequence['pin'], 'signal' => $sequence['signal'], 'value' => $step['expected'], 'atMs' => $step['matchedAtMs']]; }
            }
            usort($trace, static fn (array $left, array $right): int => [$left['atMs'], $left['pin']] <=> [$right['atMs'], $right['pin']]);
            $i2cTrace = array_column($i2cAssertions, 'observed');
        }
        $scenarios[] = ['id' => $scenario['id'], 'name' => $scenario['name'], 'passed' => $assertions === [] && $i2cAssertions === [] && $outputSequences === [] ? null : true, 'error' => null, 'assertions' => $assertions, 'i2cAssertions' => $i2cAssertions, 'outputSequences' => $outputSequences];
    }
    return ['status' => $count > 0 ? 'graded' : 'preview', 'passed' => $count > 0 ? true : null, 'score' => $count > 0 ? 100 : null,
        'scenarios' => $scenarios, 'trace' => $trace, 'i2cTrace' => $i2cTrace, 'traceScenarioId' => $traceId, 'runtimeVersion' => \OjMicrobit\Domain\RunnerResultSchema::RUNTIME_VERSION,
        'projectHash' => \OjMicrobit\Domain\ProjectSchema::hash($project), 'diagnostics' => []];
}
function fixtureProtocolExercise(): array
{
    $data = fixtureExercise();
    $data['connections'][] = ['pin' => 2, 'direction' => 'output', 'signal' => 'servo', 'device' => 'servo', 'label' => '서보 각도'];
    $data['connections'][] = ['pin' => 8, 'direction' => 'output', 'signal' => 'servo_pulse', 'device' => 'servo', 'label' => '서보 펄스'];
    $data['i2cDevices'] = [['address' => 64, 'label' => '레지스터 장치', 'registers' => [
        ['register' => 0, 'value' => 12], ['register' => 1, 'value' => 13], ['register' => 16, 'value' => 0, 'writable' => true], ['register' => 255, 'value' => 0, 'writable' => true],
    ]]];
    foreach ($data['scenarios'] as &$scenario) {
        $scenario['assertions'][] = ['pin' => 2, 'signal' => 'servo', 'expected' => 90, 'afterMs' => 0, 'withinMs' => 100, 'holdMs' => 100, 'tolerance' => 0];
        $scenario['assertions'][] = ['pin' => 8, 'signal' => 'servo_pulse', 'expected' => 1500, 'afterMs' => 0, 'withinMs' => 100, 'holdMs' => 100, 'tolerance' => 10];
        $scenario['i2cInputs'] = [['atMs' => 0, 'address' => 64, 'register' => 0, 'value' => 10]];
        $scenario['i2cAssertions'] = [
            ['address' => 64, 'operation' => 'write', 'data' => [0], 'repeated' => true, 'afterMs' => 0, 'withinMs' => 100],
            ['address' => 64, 'operation' => 'read', 'data' => [10, 13], 'repeated' => false, 'afterMs' => 0, 'withinMs' => 100],
            ['address' => 64, 'operation' => 'write', 'data' => [16, 90], 'repeated' => false, 'afterMs' => 100, 'withinMs' => 100],
        ];
    }
    unset($scenario);
    return \OjMicrobit\Domain\ExerciseSchema::normalize($data);
}
function fixtureSequenceExercise(): array
{
    $data = fixtureExercise();
    $data['starterProject']['files']['main.ts'] = 'pins.digitalWritePin(DigitalPin.P1, 1); basic.pause(1000); pins.digitalWritePin(DigitalPin.P1, 0);';
    foreach ($data['scenarios'] as &$scenario) {
        $scenario['durationMs'] = 3000; $scenario['assertions'] = [];
        $scenario['outputSequences'] = [['pin' => 1, 'signal' => 'digital', 'afterMs' => 0, 'steps' => [
            ['expected' => 1, 'withinMs' => 200, 'holdMs' => 1000, 'tolerance' => 0],
            ['expected' => 0, 'withinMs' => 200, 'holdMs' => 500, 'tolerance' => 0],
        ]]];
    }
    unset($scenario);
    return \OjMicrobit\Domain\ExerciseSchema::normalize($data);
}

// WordPress boundary doubles. Business rules and Addon API value objects remain real.
$testOptions = []; $testHooks = []; $testUserId = 0; $testCapabilities = []; $testHttp = null;
function add_action(string $hook, callable $callback, int $priority = 10, int $arguments = 1): void { $GLOBALS['testHooks'][$hook][] = $callback; }
function add_filter(string $hook, callable $callback, int $priority = 10, int $arguments = 1): void { add_action($hook, $callback, $priority, $arguments); }
function do_action(string $hook, mixed ...$args): void { foreach ($GLOBALS['testHooks'][$hook] ?? [] as $callback) { $callback(...$args); } }
function get_option(string $key, mixed $default = false): mixed { return $GLOBALS['testOptions'][$key] ?? $default; }
function update_option(string $key, mixed $value, mixed $autoload = null): bool { $GLOBALS['testOptions'][$key] = $value; return true; }
function add_option(string $key, mixed $value, mixed $deprecated = '', mixed $autoload = null): bool { if (array_key_exists($key, $GLOBALS['testOptions'])) { return false; } return update_option($key, $value); }
function delete_option(string $key): bool { unset($GLOBALS['testOptions'][$key]); return true; }
function get_current_user_id(): int { return $GLOBALS['testUserId']; }
function current_user_can(string $capability): bool { return ($GLOBALS['testCapabilities'][$capability] ?? false) === true; }
function is_user_logged_in(): bool { return get_current_user_id() > 0; }
function wp_verify_nonce(mixed $nonce, string $action): bool { return $nonce === 'valid-rest' && $action === 'wp_rest'; }
function wp_create_nonce(string $action): string { return 'valid-rest'; }
function rest_url(string $path): string { return 'https://school.test/wp-json/' . $path; }
function wp_json_encode(mixed $value, int $flags = 0): string { return json_encode($value, $flags | JSON_THROW_ON_ERROR); }
function get_user_by(string $field, string $value): object|false { return $GLOBALS['testUsers'][$value] ?? false; }
function wp_remote_post(string $url, array $arguments): mixed { return ($GLOBALS['testHttp'])($url, $arguments, 'POST'); }
function wp_remote_get(string $url, array $arguments): mixed { return ($GLOBALS['testHttp'])($url, $arguments, 'GET'); }
function is_wp_error(mixed $value): bool { return $value instanceof WP_Error; }
function wp_remote_retrieve_response_code(array $response): int { return $response['response']['code']; }
function wp_remote_retrieve_body(array $response): string { return $response['body']; }
function register_rest_route(string $namespace, string $route, array $arguments): void { $GLOBALS['testRoutes'][] = [$namespace, $route, $arguments]; }
function rest_ensure_response(mixed $data): WP_REST_Response { return new WP_REST_Response($data); }
class WP_Error
{
    public function __construct(public string $code, public string $message, public array $data = []) {}
    public function get_error_code(): string { return $this->code; }
    public function get_error_data(): array { return $this->data; }
}
class WP_REST_Request implements ArrayAccess
{
    public function __construct(public array $parameters = [], public array $headers = [], public string $body = '{}') {}
    public function get_header(string $key): string { return $this->headers[$key] ?? ''; }
    public function get_body(): string { return $this->body; }
    public function get_json_params(): mixed { return json_decode($this->body, true); }
    public function offsetExists(mixed $offset): bool { return isset($this->parameters[$offset]); }
    public function offsetGet(mixed $offset): mixed { return $this->parameters[$offset] ?? null; }
    public function offsetSet(mixed $offset, mixed $value): void { $this->parameters[$offset] = $value; }
    public function offsetUnset(mixed $offset): void { unset($this->parameters[$offset]); }
}
class WP_REST_Response
{
    public array $headers = [];
    public function __construct(public mixed $data) {}
    public function header(string $name, string $value): void { $this->headers[$name] = $value; }
}
