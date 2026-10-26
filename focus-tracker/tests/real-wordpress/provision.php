<?php
/** Native WordPress provisioner for an explicitly disposable, local MySQL site. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit(1); }
$configurationPath = $argv[1] ?? '';
$mode = $argv[2] ?? 'install';
$config = json_decode((string) file_get_contents($configurationPath), true, 512, JSON_THROW_ON_ERROR);
if (($config['home'] ?? '') !== 'http://127.0.0.1:33918'
    || ($config['dbHost'] ?? '') !== '127.0.0.1:33917'
    || !preg_match('/^focus_acceptance_[a-z0-9_]+$/D', $config['dbName'] ?? '')) {
    throw new RuntimeException('Refusing a non-disposable test site.');
}
$wpPath = rtrim($config['wpPath'], '/\\');
$_SERVER['HTTP_HOST'] = '127.0.0.1:33918';
$_SERVER['SERVER_NAME'] = '127.0.0.1';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['SERVER_PORT'] = '33918';
$_SERVER['DOCUMENT_ROOT'] = $wpPath;
if ($mode === 'install') {
    $db = new mysqli('127.0.0.1', $config['dbUser'], $config['dbPassword'], '', 33917);
    $db->query('CREATE DATABASE `' . $config['dbName'] . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $db->close();
    $constants = [
        'DB_NAME' => $config['dbName'], 'DB_USER' => $config['dbUser'],
        'DB_PASSWORD' => $config['dbPassword'], 'DB_HOST' => $config['dbHost'],
        'DB_CHARSET' => 'utf8mb4', 'DB_COLLATE' => '', 'WP_HOME' => $config['home'],
        'WP_SITEURL' => $config['home'], 'WP_ENVIRONMENT_TYPE' => 'local', 'WP_DEBUG' => true, 'WP_DEBUG_DISPLAY' => false,
        'WP_DEBUG_LOG' => dirname($wpPath) . '/wordpress-debug.log',
        'WP_DISABLE_FATAL_ERROR_HANDLER' => true, 'DISABLE_WP_CRON' => true,
        'WP_HTTP_BLOCK_EXTERNAL' => true, 'AUTOMATIC_UPDATER_DISABLED' => true,
        'OJ_PLATFORM_SECRET_KEYS' => ['acceptance-local' => bin2hex(random_bytes(32))],
        'OJ_PLATFORM_SECRET_PRIMARY_KEY_ID' => 'acceptance-local',
    ];
    foreach (['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY',
              'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT'] as $key) {
        $constants[$key] = bin2hex(random_bytes(32));
    }
    $text = "<?php\n";
    foreach ($constants as $key => $value) { $text .= 'define(' . var_export($key, true) . ', ' . var_export($value, true) . ");\n"; }
    $text .= "\$table_prefix = 'ftreal_';\nif (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }\nrequire_once ABSPATH . 'wp-settings.php';\n";
    if (file_exists($wpPath . '/wp-config.php')) { throw new RuntimeException('Refusing to overwrite wp-config.'); }
    file_put_contents($wpPath . '/wp-config.php', $text);
    define('WP_INSTALLING', true);
    require $wpPath . '/wp-load.php';
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    wp_install('Focus Tracker native acceptance', $config['admin']['login'], 'focus-admin@example.invalid', false, '', $config['admin']['password']);
    update_option('permalink_structure', '/%postname%/');
    update_option('timezone_string', 'Asia/Seoul');
    echo "Native WordPress installed.\n";
    exit;
}
if ($mode === 'install-zip') { define('FS_METHOD', 'direct'); }
require $wpPath . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
if ($mode === 'platform') {
    $result = activate_plugin('oj-platform/oj-platform.php');
    if (is_wp_error($result)) { throw new RuntimeException($result->get_error_message()); }
    echo "Platform activated; run setup in a new WordPress request.\n";
} elseif ($mode === 'setup') {
    $environment = \CodingPanda\OjPlatform\Kernel\Environment\PluginEnvironment::fromPluginFile(OJ_PLATFORM_FILE, OJ_PLATFORM_VERSION, '6.6', '8.3');
    $root = new \CodingPanda\OjPlatform\Kernel\Composition\CompositionRoot($environment,
        new \CodingPanda\OjPlatform\Kernel\WordPress\WordPressHookRegistrar(),
        new \CodingPanda\OjPlatform\Kernel\WordPress\WordPressErrorReporter());
    $container = $root->build();
    $container->get(\CodingPanda\OjPlatform\Kernel\Boot\Kernel::class)->boot();
    $result = $container->get(\CodingPanda\OjPlatform\Kernel\Setup\SetupCoordinator::class)->run();
    echo json_encode(['setup' => $result], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n";
} elseif ($mode === 'focus') {
    $result = activate_plugin('focus-tracker/focus-tracker.php');
    if (is_wp_error($result)) { throw new RuntimeException($result->get_error_message()); }
    echo "Focus activated; defaults apply in the next real init.\n";
} elseif ($mode === 'install-zip') {
    $zip = realpath($argv[3] ?? '');
    $releaseRoot = realpath(dirname(__DIR__, 3) . '/_releases/focus-tracker/1.0.0');
    $destination = realpath(WP_PLUGIN_DIR . '/focus-tracker');
    $expected = realpath($wpPath . '/wp-content/plugins') . DIRECTORY_SEPARATOR . 'focus-tracker';
    if ($zip === false || $releaseRoot === false || dirname($zip) !== $releaseRoot || basename($zip) !== 'focus-tracker-1.0.0.zip'
        || $destination === false || $destination !== $expected || wp_get_environment_type() !== 'local') {
        throw new RuntimeException('Refusing an unexpected ZIP or replacement target.');
    }
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    wp_set_current_user((int) get_user_by('login', $config['admin']['username'])->ID);
    $skin = new Automatic_Upgrader_Skin();
    $upgrader = new Plugin_Upgrader($skin);
    $result = $upgrader->install($zip, ['overwrite_package' => true, 'clear_update_cache' => false]);
    if ($result !== true) {
        throw new RuntimeException('Native WordPress ZIP replacement failed: ' . (is_wp_error($result) ? $result->get_error_message() : wp_json_encode($skin->get_errors())));
    }
    echo "Official 1.0.0 ZIP installed by the native WordPress Plugin_Upgrader; verify in a new request.\n";
} else { throw new RuntimeException('Unknown mode.'); }
