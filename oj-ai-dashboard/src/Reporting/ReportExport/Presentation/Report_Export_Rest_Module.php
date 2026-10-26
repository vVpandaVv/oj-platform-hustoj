<?php
/**
 * Report export rest module.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\ReportExport\Presentation;

use OJAI\Dashboard\Contracts\Module_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Report export rest module.
 */
final class Report_Export_Rest_Module implements Module_Interface {
	/**
	 * Initialize report export rest module dependencies and state.
	 *
	 * @param Report_Export_Rest_Controller $controller Controller.
	 */
	public function __construct( private Report_Export_Rest_Controller $controller ) {
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
