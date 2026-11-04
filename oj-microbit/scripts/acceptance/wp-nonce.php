<?php
declare(strict_types=1);
require_once __DIR__ . '/AcceptanceSupport.php';

use OjMicrobit\Acceptance\AcceptanceSupport as Support;
use OjMicrobit\Acceptance\WordPressNoncePreparer;

if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
ini_set('display_errors', '0'); ini_set('log_errors', '0');
$level = ob_get_level(); ob_start(); $finished = false; $format = 'oj-microbit-rest-nonce-preparation/v1';
register_shutdown_function(static function () use (&$finished, $level, $format): void {
    if (!$finished) { while (ob_get_level() > $level) { ob_end_clean(); } echo json_encode(Support::error('wordpress_bootstrap_aborted', $format)), "\n"; exit(1); }
});
try {
    $ojAcceptanceArgs = Support::arguments(array_slice($argv, 1), ['--wp-root', '--config', '--output'], ['--allow-wordpress-bootstrap', '--help']);
    if (isset($ojAcceptanceArgs['--help'])) {
        while (ob_get_level() > $level) { ob_end_clean(); } $finished = true;
        echo "Prepare sessionless REST nonces for four distinct test accounts. PHP 8.3 POSIX; never root.\n"
            . "php wp-nonce.php --wp-root /home/judge/src/web --config /private/accounts.json --output /private/accounts-with-nonce.json --allow-wordpress-bootstrap\n"
            . "Input must be UID-owned 0600 with testServer:true and match the local public test marker. Input/output must be outside the WordPress web/content root. Output is a NEW exclusive 0600 file; existing files are never replaced.\n"
            . "This performs FULL WordPress bootstrap. DISABLE_WP_CRON is set and the tool makes no direct DB writes, but normal plugin/theme and current-user hooks may write. Use only the marked trial server. Application passwords are validated later by the REST checks.\n";
        exit(0);
    }
    Support::requireCli();
    if (!isset($ojAcceptanceArgs['--allow-wordpress-bootstrap'])) { throw new RuntimeException('full_wordpress_bootstrap_not_confirmed'); }
    if (!isset($ojAcceptanceArgs['--config'], $ojAcceptanceArgs['--output'])) { throw new RuntimeException('invalid_arguments'); }
    $ojAcceptanceConfig = Support::privateJson($ojAcceptanceArgs['--config']);
    $ojAcceptanceRoot = Support::wordpressRoot($ojAcceptanceArgs['--wp-root'] ?? '/home/judge/src/web'); Support::marker($ojAcceptanceRoot, $ojAcceptanceConfig);
    Support::requireOutsideWebRoot($ojAcceptanceRoot, $ojAcceptanceArgs['--config'], 'unsafe_private_config');
    Support::requireOutsideWebRoot($ojAcceptanceRoot, $ojAcceptanceArgs['--output'], 'unsafe_output_directory');
    if (file_exists($ojAcceptanceArgs['--output']) || is_link($ojAcceptanceArgs['--output'])) { throw new RuntimeException('output_already_exists'); }
    define('DISABLE_WP_CRON', true); define('WP_USE_THEMES', false);
    if (!defined('WP_DEBUG_DISPLAY')) { define('WP_DEBUG_DISPLAY', false); }
    if (!defined('WP_DEBUG_LOG')) { define('WP_DEBUG_LOG', false); }
    $_COOKIE = [];
    require $ojAcceptanceRoot . '/wp-load.php';
    ini_set('display_errors', '0'); ini_set('log_errors', '0');
    foreach (['get_option', 'get_user_by', 'user_can', 'get_current_user_id', 'wp_set_current_user', 'wp_get_session_token', 'wp_create_nonce', 'wp_verify_nonce', 'is_multisite'] as $function) { if (!function_exists($function)) { throw new RuntimeException('wordpress_bootstrap_failed'); } }
    if (is_multisite()) { throw new RuntimeException('multisite_not_supported'); }
    if (defined('WP_CONTENT_DIR') && realpath(WP_CONTENT_DIR) !== false) {
        Support::requireOutsideWebRoot(realpath(WP_CONTENT_DIR), $ojAcceptanceArgs['--config'], 'unsafe_private_config');
        Support::requireOutsideWebRoot(realpath(WP_CONTENT_DIR), $ojAcceptanceArgs['--output'], 'unsafe_output_directory');
    }
    if (!class_exists(\CodingPanda\OjPlatform\AddonApi\V1\AddonCapabilityKeyV1::class)) { throw new RuntimeException('wordpress_bootstrap_failed'); }
    require_once __DIR__ . '/WordPressNoncePreparer.php';
    $ojAcceptancePrepared = WordPressNoncePreparer::prepare($ojAcceptanceConfig);
    Support::writeNewPrivate($ojAcceptanceArgs['--output'], $ojAcceptancePrepared);
    while (ob_get_level() > $level) { ob_end_clean(); } $finished = true;
    echo json_encode(['format' => $format, 'ok' => true, 'accountsPrepared' => 4, 'markerVerified' => true, 'wordpressBootstrap' => 'full',
        'directDatabaseWrites' => false, 'bootstrapHooksMayWrite' => true, 'wordpressHooksMayWrite' => true, 'applicationPasswordsValidated' => false, 'serverAcceptanceApproved' => false], JSON_UNESCAPED_SLASHES), "\n";
} catch (Throwable $error) {
    while (ob_get_level() > $level) { ob_end_clean(); } $finished = true;
    echo json_encode(Support::error($error->getMessage(), $format), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n"; exit(1);
}
