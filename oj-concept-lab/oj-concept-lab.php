<?php
/**
 * Plugin Name: OJ Concept Lab
 * Description: 데이터·알고리즘·컴퓨팅 개념을 직접 조작하고 비교하는 정보 개념 체험.
 * Version: 1.0.0
 * Requires at least: 6.6
 * Requires PHP: 8.3
 * Requires Plugins: oj-platform
 * Author: CodingPanda
 * License: GPL-3.0-or-later
 * Text Domain: oj-concept-lab
 */
declare(strict_types=1);
defined('ABSPATH') || exit;
define('OJ_CONCEPT_LAB_VERSION', '1.0.0');
define('OJ_CONCEPT_LAB_FILE', __FILE__);
define('OJ_CONCEPT_LAB_PATH', __DIR__ . '/');
require_once __DIR__ . '/src/Core/Autoloader.php';
\OjConceptLab\Core\Autoloader::register();
\OjConceptLab\Core\Plugin::boot();
register_activation_hook(__FILE__, [\OjConceptLab\Core\Plugin::class, 'activate']);
