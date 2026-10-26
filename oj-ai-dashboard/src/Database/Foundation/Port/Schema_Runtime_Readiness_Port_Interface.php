<?php
/**
 * Runtime schema readiness port.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Database\Foundation\Port;

use OJAI\Dashboard\Database\Foundation\Schema_Runtime_Readiness_Result;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Verifies local schema readiness without taking a lock or writing state.
 */
interface Schema_Runtime_Readiness_Port_Interface {
	/**
	 * Verify schema runtime readiness port interface.
	 *
	 * @return Schema_Runtime_Readiness_Result
	 */
	public function verify(): Schema_Runtime_Readiness_Result;
}
