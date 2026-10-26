<?php
/**
 * Plugin Name: OJ PRIMM
 * Description: PRIMM 자료를 AI 생성하고 OJ 검증·자동 수정 후 학습 활동으로 연결합니다.
 * Version: 1.0.5
 * Requires at least: 6.6
 * Requires PHP: 8.3
 * Requires Plugins: oj-platform
 * Author: CodingPanda
 * License: GPL-3.0-or-later
 * Text Domain: oj-primm
 */
declare(strict_types=1);

defined('ABSPATH') || exit;

define('OJ_PRIMM_VERSION', '1.0.5');
define('OJ_PRIMM_FILE', __FILE__);
define('OJ_PRIMM_PATH', __DIR__ . DIRECTORY_SEPARATOR);
define('OJ_PRIMM_URL', plugin_dir_url(__FILE__));
require_once OJ_PRIMM_PATH . 'src/Core/Autoloader.php';
\OjPrimm\Core\Autoloader::register();
\OjPrimm\Core\Plugin::boot();
register_activation_hook(__FILE__, [\OjPrimm\Core\Plugin::class, 'activate']);
register_deactivation_hook(__FILE__, [\OjPrimm\Core\Plugin::class, 'deactivate']);
