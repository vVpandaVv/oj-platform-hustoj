<?php
/**
 * Database named-lock port.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Database\Foundation\Port;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serializes schema changes across requests and processes.
 */
interface Schema_Lock_Port_Interface {
	/**
	 * Acquire.
	 *
	 * @param string $name Name.
	 * @param int    $timeout_seconds Timeout seconds.
	 * @return bool
	 */
	public function acquire( string $name, int $timeout_seconds ): bool;

	/**
	 * Release.
	 *
	 * @param string $name Name.
	 * @return void
	 */
	public function release( string $name ): void;
}
