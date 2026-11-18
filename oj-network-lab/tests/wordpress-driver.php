<?php
declare(strict_types=1);

// This driver is restricted to the disposable site created by wordpress-real.cjs.
$site = realpath((string) getenv('OJNL_WP_ROOT'));
$marker = $site ? dirname($site) . '/network-lab-test.json' : '';
if (!$site || !is_file($marker)) { throw new RuntimeException('Isolated WordPress test marker required.'); }
$settings = json_decode((string) file_get_contents($marker), true, 16, JSON_THROW_ON_ERROR);
if (($settings['purpose'] ?? '') !== 'oj-network-lab-disposable-wordpress' || realpath($settings['root'] ?? '') !== $site) { throw new RuntimeException('Invalid test root.'); }
$mode = $argv[1] ?? 'report';
if ($mode === 'install') { define('WP_INSTALLING', true); }
$_SERVER['HTTP_HOST'] = parse_url($settings['url'], PHP_URL_HOST) . ':' . parse_url($settings['url'], PHP_URL_PORT);
$_SERVER['SERVER_NAME'] = '127.0.0.1'; $_SERVER['SERVER_PORT'] = (string) parse_url($settings['url'], PHP_URL_PORT); $_SERVER['REQUEST_URI'] = '/';
require_once $site . '/wp-load.php';
add_filter('pre_wp_mail', '__return_true');
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$result = [];
if ($mode === 'install') {
    if (is_blog_installed()) { throw new RuntimeException('Refusing to overwrite an installed test site.'); }
    wp_install('Network Lab isolated verification', 'network-admin', 'admin@example.invalid', true, '', (string) getenv('OJNL_TEST_PASSWORD'), 'en_US');
    update_option('permalink_structure', '/%postname%/');
    $result = ['installed' => is_blog_installed(), 'wordpress' => get_bloginfo('version'), 'php' => PHP_VERSION];
} elseif ($mode === 'install-platform') {
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    wp_set_current_user(1);
    $upgrader = new Plugin_Upgrader(new Automatic_Upgrader_Skin());
    $installed = $upgrader->install((string) getenv('OJNL_PLATFORM_ZIP'));
    if (is_wp_error($installed)) { throw new RuntimeException($installed->get_error_code()); }
    if (!$installed) { throw new RuntimeException('Platform ZIP installation failed.'); }
    $active = activate_plugin('oj-platform/oj-platform.php');
    if (is_wp_error($active)) { throw new RuntimeException($active->get_error_code()); }
    flush_rewrite_rules(false);
    $result = ['platformActive' => is_plugin_active('oj-platform/oj-platform.php')];
} elseif ($mode === 'users') {
    foreach (['network-student-a', 'network-student-b', 'network-denied'] as $login) {
        $id = wp_create_user($login, (string) getenv('OJNL_TEST_PASSWORD'), $login . '@example.invalid');
        if (is_wp_error($id)) { throw new RuntimeException($id->get_error_code()); }
        $user = get_user_by('id', $id); $user->set_role('subscriber');
        if ($login !== 'network-denied') { $user->add_cap(\CodingPanda\OjPlatform\AddonApi\V1\AddonCapabilityKeyV1::wordpress('oj_network_lab_learn')); }
        $result[$login] = (int) $id;
    }
    flush_rewrite_rules(false);
} elseif ($mode === 'debug-profile') {
    wp_set_current_user(1);
    $environment = \CodingPanda\OjPlatform\Kernel\Environment\PluginEnvironment::fromPluginFile(OJ_PLATFORM_FILE, OJ_PLATFORM_VERSION, '6.6', '8.3');
    $container = (new \CodingPanda\OjPlatform\Kernel\Composition\CompositionRoot($environment, new \CodingPanda\OjPlatform\Kernel\WordPress\WordPressHookRegistrar(), new \CodingPanda\OjPlatform\Kernel\WordPress\WordPressErrorReporter()))->build();
    $container->get(\CodingPanda\OjPlatform\Kernel\Boot\Kernel::class)->boot();
    try {
        $container->get(\CodingPanda\OjPlatform\Modules\Auth\Application\UserAccountService::class)->completeProfile(1, new \CodingPanda\OjPlatform\Modules\Auth\Application\UserSettingsInput('admin@example.invalid', 'Verification admin', 'Local verification', '2000-01-01', null, null, 'members', 'members', null, 'chrome', 'code', 16, null));
        $result = ['profileCreated' => true];
    } catch (\Throwable $error) { $result = ['class' => get_class($error), 'message' => substr($error->getMessage(), 0, 500), 'file' => basename($error->getFile()), 'line' => $error->getLine()]; }
} elseif ($mode === 'report') {
    global $wpdb;
    $table = $wpdb->prefix . 'oj_network_lab_projects';
    $status = $wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS LIKE %s', $wpdb->esc_like($table)), ARRAY_A);
    $snapshot = \OjNetworkLab\Core\Plugin::$runtime?->snapshot();
    $result = ['wordpress' => get_bloginfo('version'), 'php' => PHP_VERSION, 'database' => $wpdb->get_var('SELECT VERSION()'),
        'platformActive' => is_plugin_active('oj-platform/oj-platform.php'), 'pluginActive' => is_plugin_active('oj-network-lab/oj-network-lab.php'),
        'version' => defined('OJ_NETWORK_LAB_VERSION') ? OJ_NETWORK_LAB_VERSION : '', 'addonStatus' => $snapshot?->status(), 'addonCode' => $snapshot?->code(),
        'schema' => get_option('oj_network_lab_schema'), 'engine' => $status['Engine'] ?? null,
        'columns' => $status ? $wpdb->get_col('SHOW COLUMNS FROM ' . $table) : [],
        'indexes' => $status ? array_values(array_unique(array_column($wpdb->get_results('SHOW INDEX FROM ' . $table, ARRAY_A), 'Key_name'))) : [],
        'cleanupScheduled' => (bool) wp_next_scheduled('oj_network_lab_cleanup'), 'projectCount' => $status ? (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $table) : null,
        'projectDigest' => $status ? hash('sha256', (string) wp_json_encode($wpdb->get_results('SELECT id,user_id,revision,project_hash FROM ' . $table . ' ORDER BY id', ARRAY_A))) : null];
} elseif ($mode === 'deactivate') {
    deactivate_plugins('oj-network-lab/oj-network-lab.php');
    $result = ['active' => is_plugin_active('oj-network-lab/oj-network-lab.php'), 'schema' => get_option('oj_network_lab_schema'), 'cleanupScheduled' => (bool) wp_next_scheduled('oj_network_lab_cleanup')];
} elseif ($mode === 'activate') {
    $active = activate_plugin('oj-network-lab/oj-network-lab.php');
    if (is_wp_error($active)) { throw new RuntimeException($active->get_error_code()); }
    flush_rewrite_rules(false);
    $result = ['active' => is_plugin_active('oj-network-lab/oj-network-lab.php')];
} elseif ($mode === 'privacy') {
    $export = \OjNetworkLab\Support\Privacy::export('network-student-a@example.invalid');
    if (is_wp_error($export)) { throw new RuntimeException($export->get_error_code()); }
    $result = ['exportCount' => count($export['data']), 'done' => $export['done']];
} else { throw new RuntimeException('Unsupported driver mode.'); }
echo "\nOJNL_JSON:" . wp_json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
