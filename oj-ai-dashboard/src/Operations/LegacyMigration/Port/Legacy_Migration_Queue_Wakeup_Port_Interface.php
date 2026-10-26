<?php
/**
 * Legacy migration queue wakeup port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\LegacyMigration\Port;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Legacy migration queue wakeup port interface.
 */
interface Legacy_Migration_Queue_Wakeup_Port_Interface {
	/**
	 * Wake.
	 *
	 * @param string $run_id Run id.
	 * @return void
	 */
	public function wake( string $run_id ): void;
}
