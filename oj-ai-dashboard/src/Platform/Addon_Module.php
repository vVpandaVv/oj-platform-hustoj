<?php
/**
 * OJ Platform add-on integration module.
 *
 * @package OJAI\Dashboard
 */

namespace OJAI\Dashboard\Platform;

use OJAI\Dashboard\Contracts\Module_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds the registration hook without booting the legacy Runtime.
 */
final class Addon_Module implements Module_Interface {
	/**
	 * Registration coordinator.
	 *
	 * @var Addon_Integration
	 */
	private $integration;

	/**
	 * Initialize addon module dependencies and state.
	 *
	 * @param Addon_Integration $integration Registration coordinator.
	 */
	public function __construct( Addon_Integration $integration ) {
		$this->integration = $integration;
	}

	/**
	 * Register the public Addon API hook.
	 *
	 * @return void
	 */
	public function register() {
		$this->integration->register_hooks();
	}
}
