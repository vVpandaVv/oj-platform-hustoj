<?php
/**
 * Schema repair state port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\SchemaRepair\Port;

use OJAI\Dashboard\Operations\SchemaRepair\Domain\Schema_Repair_State;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Schema repair state port interface.
 */
interface Schema_Repair_State_Port_Interface {
	/**
	 * Inspect.
	 *
	 * @return Schema_Repair_State
	 */
	public function inspect(): Schema_Repair_State;
}
