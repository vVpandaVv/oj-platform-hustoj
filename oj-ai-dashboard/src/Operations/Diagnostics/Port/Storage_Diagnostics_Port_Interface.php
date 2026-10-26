<?php
/**
 * Private storage diagnostics port.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\Diagnostics\Port;

use OJAI\Dashboard\Operations\Diagnostics\Domain\Storage_Diagnostics_Result;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Private storage diagnostics port.
 */
interface Storage_Diagnostics_Port_Interface {
	/**
	 * Inspect.
	 *
	 * @return Storage_Diagnostics_Result
	 */
	public function inspect(): Storage_Diagnostics_Result;
}
