<?php
/**
 * Plugin Name: OJ Addon Starter
 * Description: OJ Platform Addon API 개발용 개인 메모 예제입니다.
 * Version: 1.0.0
 * Requires at least: 6.6
 * Requires PHP: 8.3
 * Requires Plugins: oj-platform
 * Text Domain: oj-addon-starter
 * License: GPL-3.0-or-later
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

define('OJ_ADDON_STARTER_VERSION', '1.0.0');
define('OJ_ADDON_STARTER_FILE', __FILE__);
define('OJ_ADDON_STARTER_PATH', __DIR__ . '/');

require_once OJ_ADDON_STARTER_PATH . 'src/Core/Autoloader.php';
\OjAddonStarter\Core\Autoloader::register();
\OjAddonStarter\Core\Plugin::boot();
