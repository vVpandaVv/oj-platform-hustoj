<?php
/**
 * Plugin Name: OJ Research
 * Plugin URI: https://judge.codingpanda.kr
 * Description: OJ Platform에서 두 집단 수업 연구, 수준별 AI 통제, 성취 분석과 논문용 보고서를 제공합니다.
 * Version: 1.0.0
 * Requires at least: 6.6
 * Requires PHP: 8.1
 * Requires Plugins: oj-platform
 * Author: Coding Panda
 * Text Domain: oj-research
 */
declare(strict_types=1);
defined('ABSPATH') || exit;

define('OJ_RESEARCH_VERSION', '1.0.0');
define('OJ_RESEARCH_FILE', __FILE__);
require_once __DIR__ . '/src/Core/Autoloader.php';
\CodingPanda\OjResearch\Core\Autoloader::register(__DIR__);
(new \CodingPanda\OjResearch\Core\Plugin(__FILE__))->boot();
