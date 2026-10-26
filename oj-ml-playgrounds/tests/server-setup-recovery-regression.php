<?php
declare(strict_types=1);
define('ABSPATH', '/wordpress/');
require dirname(__DIR__, 2) . '/oj-integration-rebuild/oj-platform' . '/src/Kernel/Autoloader.php';
\CodingPanda\OjPlatform\Kernel\Autoloader::register(dirname(__DIR__, 2) . '/oj-integration-rebuild/oj-platform');
$GLOBALS['serverAdmin'] = true;
function current_user_can(string $cap): bool { return $GLOBALS['serverAdmin'] && $cap === 'install_plugins'; }
function esc_attr(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function esc_html(string $value): string { return esc_attr($value); }
function esc_textarea(string $value): string { return esc_attr($value); }
function setupAssert(bool $value, string $message): void { if (!$value) { throw new RuntimeException($message); } }

require __DIR__ . '/bootstrap.php';
$route = 'addon.oj_ml_playgrounds.console.settings';
$request = new \CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1($route, 1, [], [], 'ko_KR', 'server-setup-test');
$handler = new \OJML\Playgrounds\Platform\Addon_Page_Handler(new \OJML\Playgrounds\Platform\Addon_Runtime_Bridge(), $route, 'console-settings', 'ML 설정', 'settings');
$response = $handler->handle($request);
setupAssert($response->status() === 200 && str_contains((string) $response->body(), 'install.sh'), 'ML settings recovery must work before runtime is ready');
$GLOBALS['serverAdmin'] = false;
$response = $handler->handle($request);
setupAssert($response->status() === 503 && !str_contains((string) $response->body(), 'install.sh'), 'Unauthorized user must not get recovery command');
echo "ML server setup recovery and authorization passed.\n";
