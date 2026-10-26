<?php
/**
 * Module registration coordinator.
 *
 * @package OJAI\Dashboard
 */

namespace OJAI\Dashboard\Core;

use OJAI\Dashboard\Contracts\Module_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps module boot order in one place.
 */
final class Module_Manager {
	/**
	 * Registered modules.
	 *
	 * @var Module_Interface[]
	 */
	private $modules = array();

	/**
	 * Add a module to the boot queue.
	 *
	 * @param Module_Interface $module Module instance.
	 * @return void
	 */
	public function add( Module_Interface $module ) {
		$this->modules[] = $module;
	}

	/**
	 * Register every module in insertion order.
	 *
	 * @return void
	 */
	public function register() {
		foreach ( $this->modules as $module ) {
			$module->register();
		}
	}

	/**
	 * Return the number of registered modules.
	 *
	 * @return int
	 */
	public function count() {
		return count( $this->modules );
	}
}
