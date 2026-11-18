<?php
/**
 * Plugin Name: OJ Logic Lab
 * Description: 논리게이트·플립플롭 회로 편집과 이벤트 기반 타이밍 실습.
 * Version: 1.0.2
 * Requires at least: 6.6
 * Requires PHP: 8.3
 * Requires Plugins: oj-platform
 * Author: CodingPanda
 * License: GPL-3.0-or-later
 * Text Domain: oj-logic-lab
 */
declare(strict_types=1);
defined('ABSPATH') || exit;
define('OJ_LOGIC_LAB_VERSION', '1.0.2');
define('OJ_LOGIC_LAB_FILE', __FILE__);
require_once __DIR__ . '/src/Core/Autoloader.php';
\OjLogicLab\Core\Autoloader::register();
\OjLogicLab\Core\Plugin::boot();
register_activation_hook(__FILE__, [\OjLogicLab\Core\Plugin::class, 'activate']);
