<?php
/**
 * Plugin Name: OJ AI Dashboard
 * Plugin URI: https://codingpanda.kr/
 * Description: OJ Platform add-on for worksheet authoring, AI assessment, review, feedback, and reports.
 * Version: 1.2.15
 * Requires at least: 6.6
 * Requires PHP: 8.1
 * Requires Plugins: oj-platform
 * Author: Coding Panda
 * Author URI: https://codingpanda.kr/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: oj-ai-dashboard
 * Domain Path: /languages
 *
 * @package OJAI\Dashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'OJ_AI_DASHBOARD_VERSION', '1.2.15' );
define( 'OJ_AI_DASHBOARD_FILE', __FILE__ );
define( 'OJ_AI_DASHBOARD_DIR', plugin_dir_path( __FILE__ ) );
define( 'OJ_AI_DASHBOARD_URL', plugin_dir_url( __FILE__ ) );
define( 'OJ_AI_DASHBOARD_BASENAME', plugin_basename( __FILE__ ) );

$oj_ai_dashboard_vendor = OJ_AI_DASHBOARD_DIR . 'vendor/autoload.php';
if ( is_readable( $oj_ai_dashboard_vendor ) ) {
	require_once $oj_ai_dashboard_vendor;
}

require_once OJ_AI_DASHBOARD_DIR . 'src/Core/Autoloader.php';

$oj_ai_dashboard_autoloader = new OJAI\Dashboard\Core\Autoloader( OJ_AI_DASHBOARD_DIR . 'src' );
$oj_ai_dashboard_autoloader->register();

register_activation_hook( __FILE__, array( OJAI\Dashboard\Core\Activation::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( OJAI\Dashboard\Core\Deactivation::class, 'deactivate' ) );

OJAI\Dashboard\Core\Plugin::instance()->boot();
