<?php
/**
 * Submission zip rest module.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\ZipImport\Presentation;

use OJAI\Dashboard\Contracts\Module_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Submission zip rest module.
 */
final class Submission_Zip_Rest_Module implements Module_Interface {
	/**
	 * Registered.
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * Initialize submission zip rest module dependencies and state.
	 *
	 * @param Submission_Zip_Rest_Controller $controller Controller.
	 */
	public function __construct( private Submission_Zip_Rest_Controller $controller ) {
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
