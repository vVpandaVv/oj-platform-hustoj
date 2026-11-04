<?php
/**
 * Plugin Name: OJ Microbit
 * Description: MakeCode 기반 마이크로비트 실습, 핀 시뮬레이션과 자동 채점.
 * Version: 1.0.0-rc.9
 * Requires at least: 6.6
 * Requires PHP: 8.3
 * Requires Plugins: oj-platform
 * Author: CodingPanda
 * License: GPL-3.0-or-later
 * Text Domain: oj-microbit
 */
declare(strict_types=1);
defined('ABSPATH') || exit;
define('OJ_MICROBIT_VERSION', '1.0.0-rc.9');
define('OJ_MICROBIT_FILE', __FILE__);
define('OJ_MICROBIT_PATH', __DIR__ . DIRECTORY_SEPARATOR);
require_once __DIR__ . '/src/Core/Autoloader.php';
\OjMicrobit\Core\Autoloader::register();
\OjMicrobit\Core\Plugin::boot();
register_activation_hook(__FILE__, [\OjMicrobit\Core\Plugin::class, 'activate']);
register_deactivation_hook(__FILE__, [\OjMicrobit\Core\Plugin::class, 'deactivate']);
