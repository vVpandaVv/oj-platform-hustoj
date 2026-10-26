<?php
/**
 * Worksheet package repository port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Reporting\WorksheetPackage\Port;

use OJAI\Dashboard\Reporting\WorksheetPackage\Application\Enqueue_Worksheet_Package_Result;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Worksheet package repository port interface.
 */
interface Worksheet_Package_Repository_Port_Interface {
	/**
	 * Find command receipt.
	 *
	 * @param string $command_storage_key Command storage key.
	 * @param string $request_digest Request digest.
	 * @return Enqueue_Worksheet_Package_Result|null
	 */
	public function find_command_receipt( string $command_storage_key, string $request_digest ): ?Enqueue_Worksheet_Package_Result;
	/**
	 * Enqueue.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Enqueue_Worksheet_Package_Result
	 */
	public function enqueue( array $record ): Enqueue_Worksheet_Package_Result;
}
