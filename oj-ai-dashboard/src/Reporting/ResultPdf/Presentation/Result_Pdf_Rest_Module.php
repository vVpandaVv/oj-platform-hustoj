<?php
/**
 * Result pdf rest module.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\ResultPdf\Presentation;

use OJAI\Dashboard\Contracts\Module_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Result pdf rest module.
 */
final class Result_Pdf_Rest_Module implements Module_Interface {
	/**
	 * Initialize result pdf rest module dependencies and state.
	 *
	 * @param Result_Pdf_Rest_Controller $controller Controller.
	 */
	public function __construct( private Result_Pdf_Rest_Controller $controller ) {
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
