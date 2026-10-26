<?php
/**
 * Assignment lifecycle rest module.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Submission\Assignment\Presentation;

use OJAI\Dashboard\Contracts\Module_Interface;
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Assignment lifecycle rest module.
 */
final class Assignment_Lifecycle_Rest_Module implements Module_Interface {
	/**
	 * Registered.
	 *
	 * @var bool
	 */
	private bool $registered = false;
	/**
	 * Initialize assignment lifecycle rest module dependencies and state.
	 *
	 * @param Assignment_Lifecycle_Rest_Controller $controller Controller.
	 */
	public function __construct( private Assignment_Lifecycle_Rest_Controller $controller ) {
	}
	/**
	 * Register.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( $this->registered ) {
			return;
		}
		$this->registered = true;
		if ( function_exists( 'add_action' ) ) {
			add_action( 'rest_api_init', array( $this->controller, 'register_routes' ) );
		}
	}
}
