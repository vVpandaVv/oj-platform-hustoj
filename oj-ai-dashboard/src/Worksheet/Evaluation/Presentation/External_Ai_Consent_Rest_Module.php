<?php
/**
 * External AI consent REST module.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Evaluation\Presentation;

use OJAI\Dashboard\Contracts\Module_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * External AI consent REST module.
 */
final class External_Ai_Consent_Rest_Module implements Module_Interface {
	/**
	 * Initialize external ai consent rest module dependencies and state.
	 *
	 * @param External_Ai_Consent_Rest_Controller $controller Controller.
	 */
	public function __construct( private External_Ai_Consent_Rest_Controller $controller ) {
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
