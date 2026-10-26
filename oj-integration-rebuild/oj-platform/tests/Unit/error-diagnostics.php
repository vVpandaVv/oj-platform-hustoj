<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\Kernel\Autoloader;
use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Kernel\Error\ExceptionDiagnostics;
use CodingPanda\OjPlatform\Kernel\Error\TrackingLogger;
use CodingPanda\OjPlatform\Kernel\Integration\Operations\RecordingErrorReporter;
use CodingPanda\OjPlatform\Kernel\WordPress\WordPressTrackingLogger;
use CodingPanda\OjPlatform\Kernel\WordPress\WordPressErrorReporter;
use CodingPanda\OjPlatform\Modules\Settings\Contract\AiProviderException;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingStore;

$root = dirname(__DIR__, 2);
require_once $root . '/src/Kernel/Autoloader.php';
Autoloader::register($root);
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    ++$checks;
    if (! $condition) { throw new RuntimeException($message); }
};
$store = new class implements SettingStore {
    public array $value = [];
    public bool $fail = false;
    public function read(string $name, mixed $default = null): mixed { return $this->value[$name] ?? $default; }
    public function write(string $name, mixed $value): bool {
        if ($this->fail) { throw new RuntimeException('store unavailable'); }
        $this->value[$name] = $value;
        return true;
    }
};
$delegate = new class implements ErrorReporter {
    public ?Throwable $reported = null;
    public bool $fail = false;
    public function report(Throwable $error): void {
        $this->reported = $error;
        if ($this->fail) { throw new RuntimeException('delegate unavailable'); }
    }
};
$log = tempnam(sys_get_temp_dir(), 'oj-diagnostic-');
$oldLog = ini_set('error_log', $log);
$oldPost = $_POST;
if (! defined('WP_DEBUG')) { define('WP_DEBUG', true); }
try {
    $_POST = ['action' => 'PRIVATE-ACTION', 'source' => 'PRIVATE-SOURCE'];
    $logger = new WordPressTrackingLogger();
    $reporter = new RecordingErrorReporter($delegate, $store, $logger);
    $privateValue = 'API-KEY-PRIVATE-AND-STUDENT-CODE';
    $makeError = static function (string $input): Throwable {
        return new RuntimeException($input, 0, new LogicException('PRIVATE-NESTED-MESSAGE'));
    };
    $error = $makeError($privateValue);
    $reporter->report($error);
    (new WordPressErrorReporter())->report($error);
    $raw = (string) file_get_contents($log);
    preg_match('/exception_context=(\{[^\r\n]+\})/', $raw, $match);
    $record = json_decode($match[1] ?? '', true, 512, JSON_THROW_ON_ERROR);
    $snapshot = $store->value['oj_platform_last_fatal']['value'];
    $assert($record['tracking_id'] === $snapshot['tracking_id'], 'Snapshot and atomic log must share the same ID.');
    $assert($record['origin']['line'] === $error->getLine(), 'Exact origin line must be recorded.');
    $assert(str_ends_with($record['origin']['file'], 'tests/Unit/error-diagnostics.php'), 'Origin must identify the plugin-relative source.');
    $assert(! str_contains($raw, $privateValue) && ! str_contains($raw, 'PRIVATE-NESTED-MESSAGE'), 'Messages and arguments must not leak.');
    $assert(! str_contains($raw, str_replace('\\', '/', dirname($root))), 'Host directories must not leak in closure frames.');
    $assert(count($record['causes']) === 2 && count($record['frames']) <= 8, 'Causes and frames must be bounded.');
    $assert($delegate->reported === $error, 'Original error must still reach the delegate.');
    $assert(array_keys($snapshot) === ['tracking_id', 'error_class', 'reported_at', 'diagnostic'], 'Only the curated diagnostic may be added to the snapshot.');
    $stored = json_encode($snapshot, JSON_THROW_ON_ERROR);
    $assert(! str_contains($stored, $privateValue) && ! str_contains($stored, 'PRIVATE-'), 'No raw exception or request values may enter settings.');
    $assert($snapshot['diagnostic']['classification'] === 'unknown', 'Unknown failures must not be presented as confirmed causes.');
    $assert($snapshot['diagnostic']['operation'] === '플랫폼 요청 처리', 'Unknown actions must use a static operation label.');
    $assert($record['diagnostic'] === $snapshot['diagnostic'], 'The screen and log must share the same curated diagnostic.');
    $assert(! str_contains($raw, 'PRIVATE-'), 'The actual WordPress reporter must not leak messages, arguments or request values even with WP_DEBUG.');

    $diagnostics = new ExceptionDiagnostics();
    $cases = [
        [new DomainException('요청이 너무 많습니다. 잠시 뒤 다시 시도해 주세요.'), 'request_rate_limited'],
        [new RuntimeException('요청 횟수 제한 상태를 저장하지 못했습니다.'), 'rate_limit_storage_failed'],
        [new DomainException('요청 확인 정보가 만료되었습니다.'), 'request_nonce_expired'],
        [new DomainException('이 작업을 수행할 권한이 없습니다.'), 'request_permission_denied'],
        [new RuntimeException('Judge DB에 연결하지 못했습니다.'), 'judge_database_connection_failed'],
        [new DomainException('쪽지 발송 한도(10분에 10건)에 도달했습니다. 잠시 후 다시 시도해 주세요.'), 'messaging_send_rate_limited'],
        [new TypeError($privateValue), 'php_type_error'],
    ];
    foreach ($cases as [$failure, $expected]) {
        $detail = $diagnostics->describe($failure);
        $assert($detail['code'] === $expected && $detail['classification'] === 'identified', 'Known failure must have a stable diagnostic code: ' . $expected);
        $assert($detail['summary'] !== '' && $detail['next_action'] !== '' && str_contains($detail['source'], 'error-diagnostics.php:'), 'Known failures need an explanation, next action and source location.');
    }
    foreach (['authentication_failed', 'rate_limited', 'provider_unavailable', 'invalid_response', 'context_too_small', 'model_not_found', 'transport_unavailable'] as $code) {
        $detail = $diagnostics->describe(new AiProviderException($code, $privateValue, true));
        $assert($detail['code'] === 'ai_' . $code && ! str_contains(json_encode($detail), $privateValue), 'AI diagnostics must use only recognized safe codes: ' . $code);
    }
    $assert($diagnostics->describe(new AiProviderException($privateValue, $privateValue, false))['code'] === 'unclassified_exception', 'Unknown provider codes must never be echoed.');
    foreach ([1045 => 'authentication_failed', 1049 => 'not_found', 1054 => 'column_missing', 1146 => 'table_missing', 1205 => 'lock_timeout', 1213 => 'deadlock', 2002 => 'connection_failed', 2006 => 'connection_lost'] as $code => $label) {
        $detail = $diagnostics->describe(new PDOException($privateValue, $code));
        $assert($detail['code'] === 'database_' . $label, 'Native database codes need precise, query-free explanations: ' . $code);
    }
    $pdo = new PDOException('PRIVATE SQL WITH PASSWORD');
    $pdo->errorInfo = ['HY000', 1205, 'PRIVATE TABLE AND QUERY'];
    $assert($diagnostics->describe($pdo)['code'] === 'database_lock_timeout', 'PDO driver error codes must take precedence over a generic SQLSTATE.');
    $assert($diagnostics->describe(new RuntimeException($privateValue, 1045))['code'] === 'unclassified_exception', 'Non-database numeric codes must not be guessed as DB failures.');
    $nested = new RuntimeException('Judge DB에 연결하지 못했습니다.', 0, new PDOException($privateValue, 1045));
    $detail = $diagnostics->describe($nested);
    $assert($detail['code'] === 'database_authentication_failed' && $detail['root_cause_class'] === PDOException::class, 'The more specific nested cause must override the generic wrapper.');
    $anonymous = new class($privateValue) extends RuntimeException {};
    $assert($diagnostics->describe($anonymous)['error_class'] === 'RuntimeException@anonymous', 'Anonymous classes must not leak embedded host paths.');
    $_POST = ['action' => ['PRIVATE-ACTION']];
    $assert($diagnostics->describe($error)['operation'] === '플랫폼 요청 처리', 'Malformed action input must not break error reporting.');
    $_POST = ['action' => 'oj_message_send', 'source' => $privateValue];
    $storageFailure = new RuntimeException('요청 횟수 제한 상태를 저장하지 못했습니다.');
    $reporter->report($storageFailure);
    $detail = $store->value['oj_platform_last_fatal']['value']['diagnostic'];
    $assert($detail['operation'] === '쪽지 발송' && $detail['code'] === 'rate_limit_storage_failed', 'The failing operation and cause must be saved together.');
    $assert(str_contains($detail['next_action'], '오브젝트 캐시'), 'The storage failure must identify the actual dependencies to inspect.');
    $raw = (string) file_get_contents($log);
    $assert(str_contains($raw, 'diagnostic_code=rate_limit_storage_failed operation=쪽지 발송 summary=요청 제한 카운터를 저장하지 못했습니다.'), 'A readable log line must explain the cause without parsing JSON.');
    $assert(! str_contains($raw, $privateValue), 'Known failure reporting must still exclude submitted source.');
    $failing = new class implements TrackingLogger {
        public function log(string $trackingId, string $errorClass): void { throw new RuntimeException('logger unavailable'); }
    };
    $delegate->reported = null;
    (new RecordingErrorReporter($delegate, $store, $failing))->report($error);
    $assert($delegate->reported === $error, 'A failing logger must not suppress original reporting.');
    $store->fail = true;
    $delegate->fail = true;
    (new RecordingErrorReporter($delegate, $store, $failing))->report($error);
    $assert($delegate->reported === $error, 'Reporting infrastructure failure must not escape the error boundary.');
} finally {
    $_POST = $oldPost;
    ini_set('error_log', $oldLog === false ? '' : $oldLog);
    unlink($log);
}
fwrite(STDOUT, "Error diagnostics: $checks checks passed.\n");
