<?php
/**
 * Legacy migration run query port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\LegacyMigration\Port;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only Q29 boundary for one migration run and its item aggregates.
 */
interface Legacy_Migration_Run_Query_Port_Interface {
	/**
	 * Find record.
	 *
	 * @param string $run_id Run id.
	 * @return array<string,mixed>|null
	 */
	public function find_record( string $run_id ): ?array;
}
