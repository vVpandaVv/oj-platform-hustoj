<?php
/**
 * Ai routing diagnostics port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\Diagnostics\Port;

use OJAI\Dashboard\Operations\Diagnostics\Domain\Ai_Routing_Diagnostics_Result;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ai routing diagnostics port interface.
 */
interface Ai_Routing_Diagnostics_Port_Interface {
	/**
	 * Inspect.
	 *
	 * @return Ai_Routing_Diagnostics_Result
	 */
	public function inspect(): Ai_Routing_Diagnostics_Result;
}
