<?php
/**
 * Purge inventory port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\Purge\Port;

use OJAI\Dashboard\Operations\Purge\Domain\Purge_Inventory_Snapshot;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Purge inventory port interface.
 */
interface Purge_Inventory_Port_Interface {
	/**
	 * Inspect.
	 *
	 * @return Purge_Inventory_Snapshot
	 */
	public function inspect(): Purge_Inventory_Snapshot;
}
