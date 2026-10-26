<?php
/**
 * Plugin Name: OJ Platform
 * Plugin URI: https://judge.codingpanda.kr
 * Description: 학습용 프로그래밍 문제, 제출, 채점 및 학습 공간을 통합합니다.
 * Version: 2.5.1
 * Author: codingpanda
 * Author URI: https://judge.codingpanda.kr
 * Text Domain: oj-platform
 * Requires at least: 6.6
 * Requires PHP: 8.3
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Copyright (c) 2026 codingpanda.
 * OJ Platform's own code is free software: you may redistribute and modify it
 * under the GNU General Public License, version 3 or (at your option) later.
 * It is distributed WITHOUT ANY WARRANTY; without even the implied warranty
 * of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See LICENSE.txt.
 * Third-party components retain their original licenses and notices.
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

define('OJ_PLATFORM_VERSION', '2.5.1');
define('OJ_PLATFORM_ADDON_API_VERSION', '1.8.0');
define('OJ_PLATFORM_FILE', __FILE__);
define('OJ_PLATFORM_PATH', __DIR__ . DIRECTORY_SEPARATOR);
define('OJ_PLATFORM_MIN_WP_VERSION', '6.6');
define('OJ_PLATFORM_MIN_PHP_VERSION', '8.3');

if (PHP_VERSION_ID < 80300) {
    if (function_exists('add_action')) {
        add_action('admin_notices', static function (): void {
            $message = 'OJ Platform은 PHP 8.3 이상이 필요합니다.';
            $escaped = function_exists('esc_html')
                ? esc_html($message)
                : htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            echo '<div class="notice notice-error"><p>' . $escaped . '</p></div>';
        });
    }

    return;
}

$ojPlatformVendorAutoload = OJ_PLATFORM_PATH . 'vendor/autoload.php';

if (is_file($ojPlatformVendorAutoload)) {
    require_once $ojPlatformVendorAutoload;
} else {
    require_once OJ_PLATFORM_PATH . 'src/Kernel/Autoloader.php';
    \CodingPanda\OjPlatform\Kernel\Autoloader::register(OJ_PLATFORM_PATH);
}

\CodingPanda\OjPlatform\Kernel\Bootstrap::start(
    \CodingPanda\OjPlatform\Kernel\Environment\PluginEnvironment::fromPluginFile(
        OJ_PLATFORM_FILE,
        OJ_PLATFORM_VERSION,
        OJ_PLATFORM_MIN_WP_VERSION,
        OJ_PLATFORM_MIN_PHP_VERSION
    )
);
