<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
ini_set('display_errors', '0');
ini_set('log_errors', '0');
$bufferLevel = ob_get_level(); ob_start();
$finished = false;
register_shutdown_function(static function () use (&$finished, $bufferLevel): void {
    // wp-config.php or WordPress maintenance handling may terminate the CLI
    // with exit(0). That must never be mistaken for a committed configuration.
    if (!$finished) {
        while (ob_get_level() > $bufferLevel) { ob_end_clean(); }
        echo '{"ok":false,"error":"wordpress_bootstrap_aborted","message":"WordPress CLI bootstrap did not complete."}', "\n";
        exit(1);
    }
});
try {
    if (PHP_VERSION_ID < 80300 || !function_exists('posix_geteuid')) { throw new RuntimeException('php_cli_requirements'); }
    if (posix_geteuid() === 0) { throw new RuntimeException('root_wordpress_execution_refused'); }
    $input = stream_get_contents(STDIN, 8193);
    if ($input === false || strlen($input) > 8192) { throw new RuntimeException('invalid_request'); }
    $request = json_decode($input, true, 12, JSON_THROW_ON_ERROR);
    if (!is_array($request) || !is_string($request['wpRoot'] ?? null)) { throw new RuntimeException('invalid_request'); }
    $root = realpath($request['wpRoot']);
    if ($root === false || !is_file($root . '/wp-load.php') || !is_readable($root . '/wp-load.php')) { throw new RuntimeException('wordpress_not_found'); }
    // Options and wp-config constants are available without running plugins,
    // themes, schema upgrades or cron callbacks during preflight/check mode.
    define('SHORTINIT', true);
    define('DISABLE_WP_CRON', true);
    require $root . '/wp-load.php';
    if (!function_exists('get_option') || !function_exists('update_option') || !function_exists('delete_option') || !function_exists('is_multisite')) {
        throw new RuntimeException('wordpress_bootstrap_failed');
    }
    if (is_multisite()) { throw new RuntimeException('multisite_not_supported'); }
    require_once __DIR__ . '/WordPressConfigurator.php';
    $overrides = [];
    if (defined('OJ_MICROBIT_RUNNER_URL')) { $overrides['url'] = OJ_MICROBIT_RUNNER_URL; }
    if (defined('OJ_MICROBIT_RUNNER_TOKEN')) { $overrides['token'] = OJ_MICROBIT_RUNNER_TOKEN; }
    $response = (new \OjMicrobit\Installer\WordPressConfigurator($overrides))->execute($request);
    while (ob_get_level() > $bufferLevel) { ob_end_clean(); }
    $finished = true;
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
} catch (Throwable $error) {
    while (ob_get_level() > $bufferLevel) { ob_end_clean(); }
    $known = ['php_cli_requirements', 'root_wordpress_execution_refused', 'invalid_request', 'wordpress_not_found', 'wordpress_bootstrap_failed', 'multisite_not_supported',
        'invalid_mode', 'plugin_inactive', 'invalid_stored_settings', 'url_constant_conflict', 'token_constant_conflict', 'invalid_token', 'invalid_local_url',
        'invalid_private_file', 'unsafe_private_file', 'private_file_write_failed', 'connection_settings_mismatch', 'settings_changed', 'settings_write_failed', 'settings_rollback_failed', 'settings_changed_after_write'];
    $code = in_array($error->getMessage(), $known, true) ? $error->getMessage() : 'wordpress_configuration_failed';
    $finished = true;
    echo json_encode(['ok' => false, 'error' => $code, 'message' => 'WordPress 연결 설정을 완료하지 못했습니다. 오류 코드와 설치 안내를 확인해 주세요.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
    exit(1);
}
