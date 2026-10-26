<?php
/**
 * Export rest module.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Reporting\Export\Presentation;

use OJAI\Dashboard\Contracts\Module_Interface;
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Export rest module.
 */
final class Export_Rest_Module implements Module_Interface {
	/**
	 * Initialize export rest module dependencies and state.
	 *
	 * @param Export_Rest_Controller $controller Controller.
	 */
	public function __construct( private Export_Rest_Controller $controller ) {
	}
	/**
	 * Register.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this->controller, 'register_routes' ) );
	}
}
