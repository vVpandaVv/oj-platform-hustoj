<?php
/**
 * Plugin Name: OJ ML Playgrounds
 * Plugin URI: https://github.com/itmir913/ml-playgrounds
 * Description: OJ Platform add-on for interactive machine-learning assignments and results.
 * Version: 1.4.2
 * Requires at least: 6.6
 * Requires PHP: 8.3
 * Requires Plugins: oj-platform
 * Author: Coding Panda
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: oj-ml-playgrounds
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'OJ_ML_PLAYGROUNDS_VERSION', '1.4.2' );
define( 'OJ_ML_PLAYGROUNDS_FILE', __FILE__ );
define( 'OJ_ML_PLAYGROUNDS_DIR', plugin_dir_path( __FILE__ ) );
define( 'OJ_ML_PLAYGROUNDS_URL', plugin_dir_url( __FILE__ ) );
define( 'OJ_ML_PLAYGROUNDS_BASENAME', plugin_basename( __FILE__ ) );

$oj_ml_playgrounds_vendor = OJ_ML_PLAYGROUNDS_DIR . 'vendor/autoload.php';
if ( is_readable( $oj_ml_playgrounds_vendor ) ) {
	require_once $oj_ml_playgrounds_vendor;
}

require_once OJ_ML_PLAYGROUNDS_DIR . 'src/Core/Autoloader.php';

$oj_ml_playgrounds_autoloader = new OJML\Playgrounds\Core\Autoloader( OJ_ML_PLAYGROUNDS_DIR . 'src' );
$oj_ml_playgrounds_autoloader->register();

$oj_ml_playgrounds_composition_root = new OJML\Playgrounds\Core\Composition_Root();
$oj_ml_playgrounds_composition_root->register_hooks();
( new OJML\Playgrounds\Ops\Bootstrap( $oj_ml_playgrounds_composition_root ) )->register();

register_deactivation_hook( OJ_ML_PLAYGROUNDS_FILE, static function () {
	wp_clear_scheduled_hook( 'oj_ml_recover' );
} );

register_activation_hook(
	OJ_ML_PLAYGROUNDS_FILE,
	array( OJML\Playgrounds\Database\Installer::class, 'install' )
);


OJML\Playgrounds\Core\Plugin::instance()->boot();
