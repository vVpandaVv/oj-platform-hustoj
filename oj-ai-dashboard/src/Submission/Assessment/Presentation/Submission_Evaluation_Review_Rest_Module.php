<?php
/**
 * Submission evaluation review rest module.
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
 * Submission evaluation review rest module.
 */
final class Submission_Evaluation_Review_Rest_Module implements Module_Interface {
	/**
	 * Registered.
	 *
	 * @var bool
	 */
	private bool $registered = false;
	/**
	 * Initialize submission evaluation review rest module dependencies and state.
	 *
	 * @param Submission_Evaluation_Review_Rest_Controller $controller Controller.
	 */
	public function __construct( private Submission_Evaluation_Review_Rest_Controller $controller ) {
	}
	/**
	 * Register.
	 *
	 * @return void
	 */
	public function register() {
		if ( $this->registered ) {
			return;
		}
		$this->registered = true;
		add_action( 'rest_api_init', array( $this->controller, 'register_routes' ) );
	}
}
