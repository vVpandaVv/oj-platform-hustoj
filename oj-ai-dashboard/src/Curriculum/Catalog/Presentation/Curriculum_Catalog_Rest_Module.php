<?php
/**
 * Curriculum catalog REST module.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Curriculum\Catalog\Presentation;

use OJAI\Dashboard\Contracts\Module_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Curriculum catalog REST module.
 */
final class Curriculum_Catalog_Rest_Module implements Module_Interface {
	/**
	 * Initialize curriculum catalog rest module dependencies and state.
	 *
	 * @param Curriculum_Catalog_Rest_Controller $controller Controller.
	 */
	public function __construct( private Curriculum_Catalog_Rest_Controller $controller ) {
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
