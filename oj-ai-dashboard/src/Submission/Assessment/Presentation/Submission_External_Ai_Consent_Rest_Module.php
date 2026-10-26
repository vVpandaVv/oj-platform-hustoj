<?php
/**
 * Student submission external AI consent REST module.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Presentation;

use OJAI\Dashboard\Contracts\Module_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Student submission external AI consent REST module.
 */
final class Submission_External_Ai_Consent_Rest_Module implements Module_Interface {
	/**
	 * Initialize submission external ai consent rest module dependencies and state.
	 *
	 * @param Submission_External_Ai_Consent_Rest_Controller $controller Controller.
	 */
	public function __construct( private Submission_External_Ai_Consent_Rest_Controller $controller ) {
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
