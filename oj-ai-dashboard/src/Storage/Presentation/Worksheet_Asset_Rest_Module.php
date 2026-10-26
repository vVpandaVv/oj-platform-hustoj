<?php
/**
 * Worksheet asset REST module.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Storage\Presentation;

use OJAI\Dashboard\Contracts\Module_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Worksheet asset REST module.
 */
final class Worksheet_Asset_Rest_Module implements Module_Interface {
	/**
	 * Controller.
	 *
	 * @var Worksheet_Asset_Rest_Controller
	 */
	private Worksheet_Asset_Rest_Controller $controller;
	/**
	 * Registered.
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * Initialize worksheet asset rest module dependencies and state.
	 *
	 * @param Worksheet_Asset_Rest_Controller $controller Controller.
	 */
	public function __construct( Worksheet_Asset_Rest_Controller $controller ) {
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
