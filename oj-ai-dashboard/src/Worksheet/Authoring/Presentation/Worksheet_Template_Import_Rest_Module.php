<?php
/**
 * Worksheet template import REST module.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Authoring\Presentation;

use OJAI\Dashboard\Contracts\Module_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Worksheet template import REST module.
 */
final class Worksheet_Template_Import_Rest_Module implements Module_Interface {
	/**
	 * Registered.
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * Initialize worksheet template import rest module dependencies and state.
	 *
	 * @param Worksheet_Template_Import_Rest_Controller $controller Controller.
	 */
	public function __construct( private Worksheet_Template_Import_Rest_Controller $controller ) {
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
