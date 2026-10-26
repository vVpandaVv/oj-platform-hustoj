<?php
/**
 * Report export repository port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\ReportExport\Port;

use OJAI\Dashboard\Reporting\ReportExport\Application\Enqueue_Report_Export_Result;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Report export repository port interface.
 */
interface Report_Export_Repository_Port_Interface {
	/**
	 * Find command receipt.
	 *
	 * @param string $command_storage_key Command storage key.
	 * @param string $request_digest Request digest.
	 * @return Enqueue_Report_Export_Result|null
	 */
	public function find_command_receipt( string $command_storage_key, string $request_digest ): ?Enqueue_Report_Export_Result;
	/**
	 * Enqueue.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Enqueue_Report_Export_Result
	 */
	public function enqueue( array $record ): Enqueue_Report_Export_Result;
}
