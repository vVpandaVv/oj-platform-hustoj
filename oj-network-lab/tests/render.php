<?php
declare(strict_types=1);
$root = dirname(__DIR__); $platform = dirname($root) . '/oj-integration-rebuild/oj-platform';
require_once $platform . '/src/Kernel/Autoloader.php'; \CodingPanda\OjPlatform\Kernel\Autoloader::register($platform);
require_once $root . '/src/Core/Autoloader.php'; \OjNetworkLab\Core\Autoloader::register();
define('OJ_NETWORK_LAB_VERSION', '1.4.0-rc.4'); define('OJ_NETWORK_LAB_FILE', $root . '/oj-network-lab.php');
function wp_json_encode(mixed $v): string { return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
function rest_url(string $v): string { return getenv('OJNL_TEST_ORIGIN') . '/api/'; }
function wp_create_nonce(string $v): string { return 'test-nonce'; }
function get_current_user_id(): int { return 7; }
function home_url(string $v): string { return getenv('OJNL_TEST_ORIGIN') . '/'; }
function plugins_url(string $v, string $file): string { return getenv('OJNL_TEST_ORIGIN') . '/' . $v; }
$request = new \CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1('addon.oj_network_lab.learn', 7, [], [], 'ko_KR', 'test:network');
echo (new \OjNetworkLab\Addon\PageHandler())->handle($request)->body();
