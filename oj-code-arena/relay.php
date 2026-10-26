<?php
/**
 * Built-in mode polling endpoint. Loads only the WordPress core basics
 * (SHORTINIT) so 30 students polling every half second stay cheap.
 * Authentication is the signed ticket in the body, not a login cookie.
 * If this file cannot run on a host, the browser falls back to
 * POST /wp-json/oj-code-arena/v1/relay (same handler, full WordPress).
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if ('POST' !== ($_SERVER['REQUEST_METHOD'] ?? '')) {
    http_response_code(405);
    echo '{"ok":false,"why":"post-only"}';
    exit;
}

$body = (string) file_get_contents('php://input', false, null, 0, 65536);

$load = '';
$dir = __DIR__;
for ($i = 0; $i < 8 && '' === $load; $i++) {
    $dir = dirname($dir);
    if (is_file($dir . '/wp-load.php')) {
        $load = $dir . '/wp-load.php';
    }
}
if ('' === $load) {
    http_response_code(503);
    echo '{"ok":false,"why":"relay-unavailable"}';
    exit;
}

define('SHORTINIT', true);
require $load;

if (! defined('OJ_CODE_ARENA_PATH')) {
    define('OJ_CODE_ARENA_PATH', __DIR__ . '/');
}
require_once OJ_CODE_ARENA_PATH . 'src/Core/Autoloader.php';
\OjCodeArena\Core\Autoloader::register();

global $wpdb;
echo \OjCodeArena\Engine\Factory::handle_raw($wpdb, $body); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
