<?php
/**
 * Teaching Context REST module.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Context\Presentation;

use OJAI\Dashboard\Contracts\Module_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the Q01 controller on the WordPress REST lifecycle.
 */
final class Context_Rest_Module implements Module_Interface {
	/**
	 * Controller.
	 *
	 * @var Context_Rest_Controller
	 */
	private Context_Rest_Controller $controller;
	/**
	 * Registered.
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * Initialize context rest module dependencies and state.
	 *
	 * @param Context_Rest_Controller $controller Controller.
	 */
	public function __construct( Context_Rest_Controller $controller ) {
		$this->controller = $controller;
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
