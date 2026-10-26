<?php
/**
 * Contract for plugin modules.
 *
 * @package OJAI\Dashboard
 */

namespace OJAI\Dashboard\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A bootable plugin module.
 */
interface Module_Interface {
	/**
	 * Register hooks and services for the module.
	 *
	 * @return void
	 */
	public function register();
}
