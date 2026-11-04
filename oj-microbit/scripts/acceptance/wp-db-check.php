<?php
declare(strict_types=1);
require_once __DIR__ . '/AcceptanceSupport.php';

use OjMicrobit\Acceptance\AcceptanceSupport as Support;
use OjMicrobit\Acceptance\WordPressDbProbe;

if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
ini_set('display_errors', '0'); ini_set('log_errors', '0');
$level = ob_get_level(); ob_start(); $finished = false;
$format = 'oj-microbit-wp-db-acceptance/v1';
register_shutdown_function(static function () use (&$finished, $level, $format): void {
    if (!$finished) {
        while (ob_get_level() > $level) { ob_end_clean(); }
        echo json_encode(Support::error('wordpress_bootstrap_aborted', $format) + ['readOnly' => true]), "\n"; exit(1);
    }
});
try {
    $args = Support::arguments(array_slice($argv, 1), ['--wp-root', '--owner-id', '--exercise-id', '--learner-id', '--before-id', '--assignment-id', '--class-id', '--group-id', '--iterations', '--config'], ['--stdin', '--help']);
    if (isset($args['--help'])) {
        while (ob_get_level() > $level) { ob_end_clean(); } $finished = true;
        echo "Read-only WordPress/DB acceptance probe (PHP 8.3 POSIX, never root).\n"
            . "php wp-db-check.php --wp-root /home/judge/src/web [--owner-id N --exercise-id N --learner-id N --before-id N --assignment-id N --group-id N --class-id N --iterations 5]\n"
            . "--class-id (lesson) and optional legacy --group-id (school class) repeat up to 100 each; --assignment-id selects a lesson assignment; iterations 1..10. --stdin or --config (0600 owner-only file) accepts the same named JSON options.\n"
            . "No DB credentials, tokens, writes, DDL, full COUNT, ANALYZE or data dumps. Passing this probe leaves live permission, upgrade, restore, locking and load approval pending.\n";
        exit(0);
    }
    Support::requireCli();
    if (isset($args['--stdin'], $args['--config'])) { throw new RuntimeException('invalid_arguments'); }
    $input = [];
    if (isset($args['--config'])) { $input = Support::privateJson($args['--config']); }
    elseif (isset($args['--stdin'])) {
        $text = stream_get_contents(STDIN, 65537);
        if ($text === false || strlen($text) > 65536) { throw new RuntimeException('invalid_config'); }
        $input = Support::json($text);
    }
    if (array_diff(array_keys($input), ['wpRoot', 'ownerId', 'exerciseId', 'learnerId', 'beforeId', 'assignmentId', 'classIds', 'groupIds', 'iterations']) !== []) { throw new RuntimeException('invalid_config'); }
    foreach (['--owner-id' => 'ownerId', '--exercise-id' => 'exerciseId', '--learner-id' => 'learnerId', '--before-id' => 'beforeId', '--assignment-id' => 'assignmentId', '--iterations' => 'iterations', '--class-id' => 'classIds', '--group-id' => 'groupIds'] as $flag => $key) { if (isset($args[$flag])) { $input[$key] = $args[$flag]; } }
    $root = Support::wordpressRoot($args['--wp-root'] ?? $input['wpRoot'] ?? '/home/judge/src/web'); unset($input['wpRoot']);
    // WordPress is loaded in its normal global scope. Keep tool input under
    // distinct names so incidental site globals cannot replace probe options.
    $ojAcceptanceOptions = $input; $ojAcceptanceRoot = $root;
    define('SHORTINIT', true); define('DISABLE_WP_CRON', true);
    if (!defined('WP_DEBUG_DISPLAY')) { define('WP_DEBUG_DISPLAY', false); }
    if (!defined('WP_DEBUG_LOG')) { define('WP_DEBUG_LOG', false); }
    require $ojAcceptanceRoot . '/wp-load.php';
    ini_set('display_errors', '0'); ini_set('log_errors', '0');
    if (!function_exists('get_option') || !function_exists('is_multisite') || !isset($wpdb) || !is_object($wpdb)) { throw new RuntimeException('wordpress_bootstrap_failed'); }
    if (is_multisite()) { throw new RuntimeException('multisite_not_supported'); }
    $pluginRoot = realpath(dirname(__DIR__, 2));
    $installed = realpath((defined('WP_PLUGIN_DIR') ? WP_PLUGIN_DIR : $ojAcceptanceRoot . '/wp-content/plugins') . '/oj-microbit');
    if ($pluginRoot === false || $installed !== $pluginRoot) { throw new RuntimeException('tool_plugin_location_mismatch'); }
    require_once $pluginRoot . '/src/Core/Autoloader.php'; \OjMicrobit\Core\Autoloader::register();
    require_once __DIR__ . '/WordPressDbProbe.php';
    $header = file_get_contents($pluginRoot . '/oj-microbit.php', false, null, 0, 8192);
    if (!is_string($header) || !preg_match('/^[ \t*#\/]*Version:\s*([0-9]+\.[0-9]+\.[0-9]+(?:-[A-Za-z0-9.-]+)?)[ \t\r]*$/m', $header, $match)) { throw new RuntimeException('wordpress_bootstrap_failed'); }
    $report = (new WordPressDbProbe($wpdb))->run($ojAcceptanceOptions, $match[1]);
    while (ob_get_level() > $level) { ob_end_clean(); } $finished = true;
    echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
    exit($report['ok'] ? 0 : 1);
} catch (Throwable $error) {
    while (ob_get_level() > $level) { ob_end_clean(); } $finished = true;
    echo json_encode(Support::error($error->getMessage(), $format) + ['readOnly' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n"; exit(1);
}
