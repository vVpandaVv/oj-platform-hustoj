<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/oj-integration-rebuild/oj-platform/src/Kernel/Autoloader.php';
CodingPanda\OjPlatform\Kernel\Autoloader::register(dirname(__DIR__, 2) . '/oj-integration-rebuild/oj-platform');
require_once dirname(__DIR__) . '/src/Core/Autoloader.php';
OjPrimm\Core\Autoloader::register();
function wp_json_encode(mixed $value): string { return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE); }
function rest_url(string $path): string { return '/api/'; }
function wp_create_nonce(string $action): string { return 'ui-nonce'; }
function get_current_user_id(): int { return 19; }
function current_user_can(string $capability): bool { return ($GLOBALS['argv'][1]??'')==='teacher'&&$capability==='manage_options'; }
$request = (new ReflectionClass(CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1::class))->newInstanceWithoutConstructor();
echo (new OjPrimm\Addon\PageHandler(($argv[1] ?? '') === 'teacher' ? 'teacher' : 'student'))->handle($request)->body();
