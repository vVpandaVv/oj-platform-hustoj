<?php
/**
 * Runtime readiness contract for presentation adapters.
 *
 * @package OJAI\Dashboard
 */

namespace OJAI\Dashboard\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exposes only whether the accepted platform Runtime is ready.
 */
interface Platform_Runtime_Readiness_Port_Interface {
	/**
	 * Whether every required public Runtime contract is connected.
	 *
	 * @return bool
	 */
	public function is_ready();
}
