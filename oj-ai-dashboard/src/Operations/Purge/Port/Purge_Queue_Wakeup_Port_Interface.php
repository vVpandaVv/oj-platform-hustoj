<?php
/**
 * Purge queue wakeup port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\Purge\Port;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Purge queue wakeup port interface.
 */
interface Purge_Queue_Wakeup_Port_Interface {
	/**
	 * Wake.
	 *
	 * @param string $job_id Job id.
	 * @return void
	 */
	public function wake( string $job_id ): void;
}
