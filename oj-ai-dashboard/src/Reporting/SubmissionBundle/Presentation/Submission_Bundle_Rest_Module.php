<?php
/**
 * Submission bundle rest module.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\SubmissionBundle\Presentation;

use OJAI\Dashboard\Contracts\Module_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Submission bundle rest module.
 */
final class Submission_Bundle_Rest_Module implements Module_Interface {
	/**
	 * Initialize submission bundle rest module dependencies and state.
	 *
	 * @param Submission_Bundle_Rest_Controller $controller Controller.
	 */
	public function __construct( private Submission_Bundle_Rest_Controller $controller ) {
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
