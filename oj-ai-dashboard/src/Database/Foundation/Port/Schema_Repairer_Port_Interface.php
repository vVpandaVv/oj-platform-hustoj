<?php
/**
 * Explicit forward schema repair use-case port.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Database\Foundation\Port;

use OJAI\Dashboard\Database\Foundation\Schema_Installation_Result;
use OJAI\Dashboard\Database\Foundation\Schema_Repair_Authorization;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Explicit forward schema repair use-case port.
 */
interface Schema_Repairer_Port_Interface {
	/**
	 * Repair.
	 *
	 * @param Schema_Repair_Authorization $authorization Authorization.
	 * @return Schema_Installation_Result
	 */
	public function repair( Schema_Repair_Authorization $authorization ): Schema_Installation_Result;
}
