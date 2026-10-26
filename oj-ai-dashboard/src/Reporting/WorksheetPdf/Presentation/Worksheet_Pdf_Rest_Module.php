<?php
/**
 * Worksheet pdf rest module.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Reporting\WorksheetPdf\Presentation;

use OJAI\Dashboard\Contracts\Module_Interface;
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Worksheet pdf rest module.
 */
final class Worksheet_Pdf_Rest_Module implements Module_Interface {
	/**
	 * Initialize worksheet pdf rest module dependencies and state.
	 *
	 * @param Worksheet_Pdf_Rest_Controller $controller Controller.
	 */
	public function __construct( private Worksheet_Pdf_Rest_Controller $controller ) {
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
