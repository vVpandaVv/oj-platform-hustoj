<?php
/**
 * C45 immutable report snapshot persistence boundary.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\Report\Port;

use OJAI\Dashboard\Reporting\Report\Application\Create_Report_Snapshot_Result;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * C45 immutable report snapshot persistence boundary.
 */
interface Report_Snapshot_Repository_Port_Interface {
	/**
	 * Find command receipt.
	 *
	 * @param string $command_storage_key Command storage key.
	 * @param string $request_digest Request digest.
	 * @return Create_Report_Snapshot_Result|null
	 */
	public function find_command_receipt( string $command_storage_key, string $request_digest ): ?Create_Report_Snapshot_Result;

	/**
	 * Create report snapshot repository port interface.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Create_Report_Snapshot_Result
	 */
	public function create( array $record ): Create_Report_Snapshot_Result;
}
