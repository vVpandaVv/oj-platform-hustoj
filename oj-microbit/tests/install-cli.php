<?php
declare(strict_types=1);

// Exercise the real CLI entrypoint with a fake SHORTINIT WordPress loader.
// A missing POSIX extension on Windows is emulated only in this child harness.
$checks = 0;
function cliVerify(bool $condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } $GLOBALS['checks']++; }
$base = dirname(__DIR__, 2) . '/_notes/tmp';
if (!is_dir($base)) { mkdir($base, 0777, true); }
$temporary = $base . '/microbit-bridge-test-' . bin2hex(random_bytes(8)); mkdir($temporary);
$bridge = dirname(__DIR__) . '/runner/install-tools/configure-wordpress.php';
$loader = $temporary . '/wp-load.php'; $driver = $temporary . '/driver.php';
try {
    $uidStub = function_exists('posix_geteuid') ? '' : 'function posix_geteuid(): int { return 1001; }';
    file_put_contents($driver, '<?php ' . $uidStub . ' require ' . var_export($bridge, true) . ';');
    $run = static function (string $input) use ($driver): array {
        $process = proc_open([PHP_BINARY, $driver], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        if (!is_resource($process)) { throw new RuntimeException('CLI test process failed to start.'); }
        fwrite($pipes[0], $input); fclose($pipes[0]); $out = stream_get_contents($pipes[1]); fclose($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
        return [proc_close($process), $out, $err];
    };
    file_put_contents($loader, '<?php echo "PRIVATE_BOOTSTRAP_TEXT"; exit(0);');
    [$code, $output] = $run(json_encode(['mode' => 'configure', 'wpRoot' => $temporary]));
    $response = json_decode($output, true, 8, JSON_THROW_ON_ERROR);
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        cliVerify($code === 1 && $response['error'] === 'root_wordpress_execution_refused', 'Real root execution is rejected before loading WordPress');
    } else {
        cliVerify($code === 1 && $response['error'] === 'wordpress_bootstrap_aborted', 'A WordPress exit(0) is reported as failure rather than a committed configure');
        cliVerify(!str_contains($output, 'PRIVATE_BOOTSTRAP_TEXT'), 'Unexpected bootstrap output is discarded');
        file_put_contents($loader, '<?php echo "PRIVATE_BOOTSTRAP_TEXT"; function get_option($key,$default=false) { return $key === "active_plugins" ? ["oj-microbit/oj-microbit.php"] : $default; } function update_option(...$args) { throw new RuntimeException("Unexpected write"); } function delete_option(...$args) { throw new RuntimeException("Unexpected delete"); } function is_multisite() { return false; }');
        [$code, $output] = $run(json_encode(['mode' => 'inspect', 'wpRoot' => $temporary]));
        cliVerify($code === 0 && json_decode($output, true)['active'], 'Read-only inspection returns a successful structured response');
        cliVerify(!str_contains($output, 'PRIVATE_BOOTSTRAP_TEXT'), 'Successful inspection suppresses incidental WordPress output too');
        [$code, $output] = $run('not JSON PRIVATE_REQUEST_TOKEN');
        cliVerify($code === 1 && !str_contains($output, 'PRIVATE_REQUEST_TOKEN'), 'Malformed requests cannot leak their contents');
        [$code, $output] = $run(str_repeat('x', 8193));
        cliVerify($code === 1 && json_decode($output, true)['error'] === 'invalid_request', 'Oversized CLI input is rejected');
    }
} finally {
    // Only the three explicitly created files are removed; no recursive delete.
    foreach ([$driver, $loader] as $file) { if (is_file($file)) { unlink($file); } }
    rmdir($temporary);
}
echo '[install-cli] ' . $checks . " checks passed (real PHP entrypoint, fake WordPress/POSIX where unavailable; no live deployment)\n";
