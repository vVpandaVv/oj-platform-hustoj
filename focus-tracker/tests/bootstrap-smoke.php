<?php
declare(strict_types=1);

// Load the real plugin entry with only its WordPress hook functions stubbed.
// No fallback autoloader or platform/test bootstrap can hide missing files.
if (PHP_SAPI !== 'cli') { exit(1); }
$main = $argv[1] ?? dirname(__DIR__) . '/focus-tracker.php';
define('ABSPATH', dirname($main) . '/');
$hooks = array();
function plugin_dir_path(string $file): string { return dirname($file) . '/'; }
function plugin_dir_url(string $file): string { return 'https://fixture.test/wp-content/plugins/focus-tracker/'; }
function plugin_basename(string $file): string { return 'focus-tracker/' . basename($file); }
function add_action($name, $callback, $priority = 10, $accepted = 1): void { $GLOBALS['hooks'][$name][] = $callback; }
function add_filter($name, $callback, $priority = 10, $accepted = 1): void { $GLOBALS['hooks'][$name][] = $callback; }
function register_activation_hook($file, $callback): void { $GLOBALS['hooks']['activation'][] = $callback; }
function register_deactivation_hook($file, $callback): void { $GLOBALS['hooks']['deactivation'][] = $callback; }
require $main;
$checks = 0;
foreach (array('activation', 'deactivation', 'plugins_loaded', 'rest_api_init', 'wp_enqueue_scripts', 'rest_pre_dispatch', 'site_status_tests', 'wp_privacy_personal_data_exporters', 'wp_privacy_personal_data_erasers', 'delete_user', 'deleted_user', 'focus_tracker_cleanup', 'focus_tracker_cleanup_continue', 'oj_platform_register_addons') as $hook) {
    ++$checks;
    if (empty($hooks[$hook])) { throw new RuntimeException('Missing installed-plugin hook: ' . $hook); }
}
foreach (array(FocusTracker\Support\Diagnostics::class, FocusTracker\Support\Release_Readiness::class, FocusTracker\Privacy\Privacy_Guard::class, FocusTracker\Checks\Check_Service::class) as $class) {
    ++$checks;
    if (! class_exists($class)) { throw new RuntimeException('Actual plugin autoload failed: ' . $class); }
}
echo "Bootstrap smoke: {$checks} checks passed (actual plugin entry and autoloader, WordPress hook stubs).\n";
