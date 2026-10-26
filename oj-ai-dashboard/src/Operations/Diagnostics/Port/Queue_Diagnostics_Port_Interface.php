<?php
/**
 * Queue diagnostics port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\Diagnostics\Port;

use OJAI\Dashboard\Operations\Diagnostics\Domain\Queue_Diagnostics_Result;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Queue diagnostics port interface.
 */
interface Queue_Diagnostics_Port_Interface {
	/**
	 * Inspect.
	 *
	 * @return Queue_Diagnostics_Result
	 */
	public function inspect(): Queue_Diagnostics_Result;
}
