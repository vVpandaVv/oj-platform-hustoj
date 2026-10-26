<?php
/**
 * Q22 report snapshot read boundary.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\Report\Port;

use OJAI\Dashboard\Reporting\Report\Domain\Report_Snapshot_Scope;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Q22 report snapshot read boundary.
 */
interface Report_Snapshot_Query_Port_Interface {
	/**
	 * Scope.
	 *
	 * @param string $snapshot_id Snapshot id.
	 * @return Report_Snapshot_Scope|null
	 */
	public function scope( string $snapshot_id ): ?Report_Snapshot_Scope;

	/**
	 * Find record.
	 *
	 * @param string $snapshot_id Snapshot id.
	 * @return array<string,mixed>|null
	 */
	public function find_record( string $snapshot_id ): ?array;
}
