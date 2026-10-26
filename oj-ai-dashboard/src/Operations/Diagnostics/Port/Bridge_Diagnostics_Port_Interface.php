<?php
/**
 * Bridge diagnostics port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\Diagnostics\Port;

use OJAI\Dashboard\Operations\Diagnostics\Domain\Bridge_Diagnostics_Result;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bridge diagnostics port interface.
 */
interface Bridge_Diagnostics_Port_Interface {
	/**
	 * Inspect.
	 *
	 * @return Bridge_Diagnostics_Result
	 */
	public function inspect(): Bridge_Diagnostics_Result;
}
