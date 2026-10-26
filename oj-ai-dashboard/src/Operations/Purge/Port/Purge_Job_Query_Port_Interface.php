<?php
/**
 * Purge job query port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\Purge\Port;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only A-DS08-Q36 boundary for one purge job and its current hold count.
 */
interface Purge_Job_Query_Port_Interface {
	/**
	 * Find record.
	 *
	 * @param string $job_id Job id.
	 * @return array<string,mixed>|null
	 */
	public function find_record( string $job_id ): ?array;
}
