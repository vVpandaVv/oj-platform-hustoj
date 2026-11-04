<?php
declare(strict_types=1);

/** Native Oracle MySQL regression for platform rate timeout/count/index storage. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
$finished = false;
register_shutdown_function(static function () use (&$finished): void { if (! $finished) { fwrite(STDERR, "Native rate storage verification did not complete.\n"); exit(1); } });

use CodingPanda\OjPlatform\Modules\Auth\Infrastructure\WordPress\WordPressAttemptLimiter;

$checks = [];
function nativeRateCheck(bool $condition, string $label): void { $GLOBALS['checks'][$label] = $condition; if (! $condition) { throw new RuntimeException($label); } }
function nativeRateJson(string $path): array { return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR); }
function nativeRateSave(string $path, array $value): void {
    $bytes = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    if (file_put_contents($path, $bytes, LOCK_EX) !== strlen($bytes)) { throw new RuntimeException('Could not preserve the private native rate evidence.'); }
}
function nativeRatePath(string $path): string { return strtolower(str_replace('\\', '/', rtrim($path, '/\\'))); }
function nativeRateCli(string $path): string {
    if (preg_match('//u', $path) !== 1 && function_exists('sapi_windows_cp_conv')) { $path = sapi_windows_cp_conv(0, 65001, $path); }
    $path = str_replace('\\', '/', $path); $root = str_replace('\\', '/', dirname(__DIR__, 3));
    if (str_starts_with(strtolower($path), strtolower($root) . '/')) { $path = substr($path, strlen($root) + 1); }
    if (preg_match('/^[\x20-\x7e]+$/D', $path) !== 1) { throw new RuntimeException('The native child arguments must be ASCII workspace-relative paths.'); }
    return $path;
}
function nativeRateKey(string $scope, string $identifier, bool $fixed = false): string {
    return 'oj_platform_rate_' . hash_hmac('sha256', ($fixed ? "fixed\0" : '') . $scope . "\0" . $identifier, wp_salt('nonce'));
}
function nativeRateTimeout(string $key): int|false {
    global $wpdb;
    $value = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", '_transient_timeout_' . $key));
    nativeRateCheck($wpdb->last_error === '', 'Native timeout inspection has no database error');
    return $value === null ? false : (int) $value;
}
function nativeRateDeadlocks(): int {
    global $wpdb;
    $row = $wpdb->get_row("SELECT `COUNT` AS n,STATUS AS status FROM information_schema.INNODB_METRICS WHERE NAME='lock_deadlocks'", ARRAY_A);
    nativeRateCheck(is_array($row) && $row['status'] === 'enabled', 'The actual Oracle InnoDB deadlock counter is enabled');
    return (int) $row['n'];
}

try {
    $phase = $argv[1] ?? '';
    nativeRateCheck(in_array($phase, ['baseline', 'verify', 'worker'], true), 'The native rate verification phase is valid');
    $workspace = realpath(dirname(__DIR__, 3)); $configPath = realpath($argv[2] ?? ''); $config = nativeRateJson($configPath ?: '');
    $runRoot = realpath($workspace . '/_notes/tmp/focus-tracker-mysql8'); $wpPath = realpath($config['wpPath'] ?? ''); $output = realpath($config['outputDir'] ?? '');
    nativeRateCheck($runRoot !== false && $wpPath !== false && $output !== false && nativeRatePath($wpPath) === nativeRatePath($runRoot . '/wordpress')
        && str_starts_with(nativeRatePath($output) . '/', nativeRatePath($runRoot) . '/'), 'Only the exact dedicated Oracle fixture paths are permitted');
    nativeRateCheck(($config['baseUrl'] ?? '') === 'http://127.0.0.1:33918' && ($config['dbHost'] ?? '') === '127.0.0.1:33917'
        && ($config['dbName'] ?? '') === 'focus_acceptance_mysql8_20261028', 'The native rate fixture stays within the exact loopback database and origin');
    $_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'] = '127.0.0.1'; $_SERVER['HTTP_HOST'] .= ':33918'; $_SERVER['SERVER_PORT'] = '33918';
    $_SERVER['REQUEST_URI'] = '/'; $_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    require $wpPath . '/wp-load.php'; $wpdb->suppress_errors(true);
    nativeRateCheck(wp_get_environment_type() === 'local' && defined('DISABLE_WP_CRON') && DISABLE_WP_CRON, 'Real WordPress boots locally with background cleanup disabled');
    $version = (string) $wpdb->get_var('SELECT VERSION()'); $comment = (string) $wpdb->get_var('SELECT @@version_comment');
    nativeRateCheck(preg_match('/^8\.0\./', $version) === 1 && str_contains($comment, 'MySQL Community Server') && ! str_contains(strtolower($version . $comment), 'mariadb'), 'The native rate storage uses actual Oracle MySQL8 Community Server');
    $fixturePath = $output . '/mysql-rate-limit-fixture.json'; $limiter = new WordPressAttemptLimiter();
    if ($phase === 'worker') {
        $jobPath = realpath($argv[3] ?? '');
        nativeRateCheck($jobPath !== false && str_starts_with(nativeRatePath($jobPath), nativeRatePath($output) . '/') && str_ends_with($jobPath, '.json'), 'The rate worker job belongs to this private fixture');
        $job = nativeRateJson($jobPath); file_put_contents($jobPath . '.ready', 'ready'); $until = microtime(true) + 45;
        while (! is_file($job['barrier'])) { if (microtime(true) > $until) { throw new RuntimeException('The rate worker start barrier timed out.'); } usleep(10000); }
        $started = microtime(true);
        $legacy = $limiter->consume('native_rate_concurrent', $job['identifier'], 1, 600);
        $fixed = $limiter->consumeFixedWindow('native_rate_concurrent', $job['identifier'], 1, 600);
        nativeRateSave($jobPath . '.result', ['legacyAllowed' => $legacy, 'fixedAllowed' => $fixed, 'serviceMs' => round((microtime(true) - $started) * 1000, 3)]);
        $finished = true; exit;
    }
    if ($phase === 'baseline') {
        nativeRateCheck(OJ_PLATFORM_VERSION === '2.5.4' && ! is_file($fixturePath), 'A new red fixture is created only on the unchanged official platform baseline');
        $fixture = ['identifier' => 'native-rate-' . bin2hex(random_bytes(8)), 'count' => 3, 'resetAt' => time() + 3600];
        foreach ([false, true] as $fixed) {
            $key = nativeRateKey('native_rate_orphan', $fixture['identifier'], $fixed);
            nativeRateCheck(set_transient($key, $fixed ? ['count' => 3, 'reset_at' => $fixture['resetAt']] : ['count' => 3], 3600), 'Real WordPress creates the isolated pre-existing budget');
            nativeRateCheck(delete_option('_transient_timeout_' . $key), 'Only the isolated probe timeout is removed to reproduce the observed orphan shape');
            $allowed = $fixed ? $limiter->consumeFixedWindow('native_rate_orphan', $fixture['identifier'], 3, 3600) : $limiter->consume('native_rate_orphan', $fixture['identifier'], 3, 3600);
            nativeRateCheck($allowed === false && (int) get_transient($key)['count'] === 3 && nativeRateTimeout($key) === false,
                'The old implementation leaves an already-full orphan without expiry');
        }
        nativeRateSave($fixturePath, $fixture);
        nativeRateSave($output . '/mysql-rate-limit-baseline.json', ['phase' => $phase, 'redObserved' => true, 'storageCriteriaPassed' => false, 'assertionCount' => count($checks), 'checks' => $checks,
            'capturedAt' => gmdate('c'), 'platform' => OJ_PLATFORM_VERSION, 'database' => $version, 'counterPreserved' => true, 'legacyTimeoutRepaired' => false, 'fixedTimeoutRepaired' => false,
            'scope' => 'Fault-seeded own synthetic rate keys reproduce the previously observed timeout-missing/value-present shape in real WordPress and Oracle MySQL. This does not claim a second natural deadlock reproduction; the earlier real HTTP login deadlock is independent preserved evidence. No actual account budget is changed.']);
        $finished = true; echo json_encode(['phase' => $phase, 'redObserved' => true, 'assertions' => count($checks)], JSON_THROW_ON_ERROR) . "\n"; exit;
    }
    nativeRateCheck(OJ_PLATFORM_VERSION === '2.5.5', 'The green verification uses the installed new platform in a fresh WordPress process');
    $fixture = nativeRateJson($fixturePath);
    foreach ([false, true] as $fixed) {
        $key = nativeRateKey('native_rate_orphan', $fixture['identifier'], $fixed);
        $allowed = $fixed ? $limiter->consumeFixedWindow('native_rate_orphan', $fixture['identifier'], 3, 3600) : $limiter->consume('native_rate_orphan', $fixture['identifier'], 3, 3600);
        $value = get_transient($key); $deadline = nativeRateTimeout($key);
        nativeRateCheck($allowed === false && is_array($value) && $value['count'] === 3, 'Native orphan repair denies an already-full budget without resetting its count');
        nativeRateCheck($deadline !== false && $deadline > time() && $deadline <= ($fixed ? $fixture['resetAt'] : time() + 3601), 'Both native orphan formats regain bounded expiry');
        if ($fixed) { nativeRateCheck($value['reset_at'] === $fixture['resetAt'], 'Native fixed-window repair preserves the original reset timestamp'); }
    }
    $beforeIndex = get_option('oj_platform_rate_index', []); $beforeDeadlocks = nativeRateDeadlocks();
    $runId = 'rate-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(3)); $directory = $output . '/' . $runId; mkdir($directory, 0700, true); chdir($workspace);
    $identifiers = []; $jobs = []; for ($index = 0; $index < 60; ++$index) { $identifier = $runId . '-' . $index; $identifiers[] = $identifier; $jobs[] = ['identifier' => $identifier]; $jobs[] = ['identifier' => $identifier]; }
    $runtime = [nativeRateCli(PHP_BINARY), '-n', '-d', 'extension_dir=' . nativeRateCli(ini_get('extension_dir'))];
    foreach (['mysqli', 'mbstring', 'openssl', 'curl', 'zip', 'intl', 'fileinfo', 'sodium'] as $extension) { $runtime[] = '-d'; $runtime[] = 'extension=' . $extension; }
    $results = []; $processes = [];
    foreach (array_chunk($jobs, 6) as $wave => $batch) {
        $barrier = $directory . '/wave-' . $wave . '.go'; $workers = [];
        foreach ($batch as $index => $job) {
            $jobPath = $directory . '/wave-' . $wave . '-job-' . $index . '.json'; $job['barrier'] = $barrier; nativeRateSave($jobPath, $job);
            $command = array_merge($runtime, [nativeRateCli(__FILE__), 'worker', nativeRateCli($configPath), nativeRateCli($jobPath)]);
            $process = proc_open($command, [0 => ['file', 'NUL', 'r'], 1 => ['file', $jobPath . '.stdout', 'w'], 2 => ['file', $jobPath . '.stderr', 'w']], $pipes, null, null, ['bypass_shell' => true, 'create_process_group' => true]);
            nativeRateCheck(is_resource($process), 'Every concurrent rate worker is an independent native PHP process');
            $processes[] = $process; $workers[] = ['process' => $process, 'path' => $jobPath, 'identifier' => $job['identifier'], 'exit' => null];
        }
        $until = microtime(true) + 45;
        while (count(array_filter($workers, static fn (array $worker): bool => is_file($worker['path'] . '.ready'))) !== count($workers)) {
            if (microtime(true) > $until) { throw new RuntimeException('Native rate workers did not reach their synchronized barrier.'); } usleep(10000);
        }
        file_put_contents($barrier, 'go'); $until = microtime(true) + 60;
        do {
            $running = 0;
            foreach ($workers as &$worker) { if ($worker['exit'] !== null) { continue; } $state = proc_get_status($worker['process']); if ($state['running']) { ++$running; } else { $worker['exit'] = $state['exitcode']; } } unset($worker);
            if (microtime(true) > $until) { throw new RuntimeException('A native rate operation exceeded its bounded deadline.'); } if ($running) { usleep(10000); }
        } while ($running);
        foreach ($workers as $worker) {
            proc_close($worker['process']); nativeRateCheck($worker['exit'] === 0 && is_file($worker['path'] . '.result'), 'All simultaneous rate operations finish without a storage error');
            $results[$worker['identifier']][] = nativeRateJson($worker['path'] . '.result');
        }
        echo json_encode(['phase' => 'verify', 'wave' => $wave + 1, 'waves' => 20], JSON_THROW_ON_ERROR) . "\n";
    }
    $afterIndex = get_option('oj_platform_rate_index', []);
    // The parent has its own request cache, unlike the independent writers.
    wp_cache_delete('oj_platform_rate_index', 'options'); wp_cache_delete('notoptions', 'options'); $afterIndex = get_option('oj_platform_rate_index', []);
    nativeRateCheck(is_array($beforeIndex) && is_array($afterIndex) && array_diff($beforeIndex, $afterIndex) === [], 'Concurrent tracking never loses an earlier rate-index entry');
    foreach ($identifiers as $identifier) {
        foreach (['legacyAllowed', 'fixedAllowed'] as $field) { nativeRateCheck(count(array_filter($results[$identifier], static fn (array $row): bool => $row[$field])) === 1, 'Two simultaneous callers consume exactly one available budget in each counter format'); }
        foreach ([false, true] as $fixed) {
            $key = nativeRateKey('native_rate_concurrent', $identifier, $fixed); wp_cache_delete('_transient_' . $key, 'options'); wp_cache_delete('_transient_timeout_' . $key, 'options');
            $value = get_transient($key); nativeRateCheck(is_array($value) && $value['count'] === 1 && nativeRateTimeout($key) > time() && in_array($key, $afterIndex, true), 'Every concurrent counter has its exact committed count, live timeout and recorded index entry');
        }
    }
    $afterDeadlocks = nativeRateDeadlocks(); nativeRateCheck($afterDeadlocks === $beforeDeadlocks, 'The simultaneous native rate operations add no InnoDB deadlock');
    nativeRateSave($output . '/mysql-rate-limit-verify.json', ['phase' => $phase, 'passed' => true, 'assertionCount' => count($checks), 'checks' => $checks, 'capturedAt' => gmdate('c'),
        'platform' => OJ_PLATFORM_VERSION, 'database' => $version, 'syntheticIdentities' => 60, 'callersPerIdentity' => 2, 'independentWorkers' => 120, 'maxConcurrentPhpProcesses' => 6,
        'operations' => 240, 'deadlockDelta' => $afterDeadlocks - $beforeDeadlocks, 'orphanCountPreserved' => true, 'legacyAndFixedExpiryRepaired' => true, 'indexPreserved' => true,
        'sourceHash' => hash_file('sha256', $wpPath . '/wp-content/plugins/oj-platform/modules/Auth/Infrastructure/WordPress/WordPressAttemptLimiter.php'),
        'scope' => 'Actual WordPress/Oracle counter storage and independent CLI process contention. Synthetic non-account identifiers never consume real user budgets. Actual cookie login and HTTP worker capacity are separate browser/protocol load tests. Global resetAll is covered in units, not invoked against existing account protection here.']);
    $finished = true; echo json_encode(['phase' => $phase, 'passed' => true, 'assertions' => count($checks), 'workers' => 120, 'operations' => 240], JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $error) {
    foreach ($processes ?? [] as $process) { if (is_resource($process)) { $state = proc_get_status($process); if ($state['running']) { proc_terminate($process); } proc_close($process); } }
    $failure = ['phase' => $phase ?? null, 'passed' => false, 'errorClass' => get_class($error), 'sourceLine' => $error->getLine(), 'failedAssertions' => array_keys(array_filter($checks, static fn (bool $value): bool => ! $value))];
    if (isset($output) && is_string($output)) { nativeRateSave($output . '/mysql-rate-limit-failure-' . gmdate('YmdHis') . '.json', $failure); }
    fwrite(STDERR, json_encode($failure, JSON_THROW_ON_ERROR) . "\n"); exit(1);
}
