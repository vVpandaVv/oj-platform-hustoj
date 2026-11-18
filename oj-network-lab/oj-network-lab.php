<?php
/**
 * Plugin Name: OJ Network Lab
 * Description: 브라우저에서 허브·라우터·방화벽·가상 VPN을 구성하고 명령어와 패킷으로 실습합니다.
 * Version: 1.4.0-rc.4
 * Requires at least: 6.6
 * Requires PHP: 8.3
 * Requires Plugins: oj-platform
 * Author: CodingPanda
 * License: GPL-3.0-or-later
 * Text Domain: oj-network-lab
 */
declare(strict_types=1);
defined('ABSPATH') || exit;
define('OJ_NETWORK_LAB_VERSION', '1.4.0-rc.4');
define('OJ_NETWORK_LAB_FILE', __FILE__);
define('OJ_NETWORK_LAB_PATH', __DIR__ . '/');
require_once __DIR__ . '/src/Core/Autoloader.php';
\OjNetworkLab\Core\Autoloader::register();
\OjNetworkLab\Core\Plugin::boot();
register_activation_hook(__FILE__, [\OjNetworkLab\Core\Plugin::class, 'activate']);
register_deactivation_hook(__FILE__, [\OjNetworkLab\Core\Plugin::class, 'deactivate']);
