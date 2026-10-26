<?php
/**
 * Result pdf repository port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\ResultPdf\Port;

use OJAI\Dashboard\Reporting\ResultPdf\Application\Enqueue_Result_Pdf_Result;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Result pdf repository port interface.
 */
interface Result_Pdf_Repository_Port_Interface {
	/**
	 * Find command receipt.
	 *
	 * @param string $command_storage_key Command storage key.
	 * @param string $request_digest Request digest.
	 * @return Enqueue_Result_Pdf_Result|null
	 */
	public function find_command_receipt( string $command_storage_key, string $request_digest ): ?Enqueue_Result_Pdf_Result;

	/**
	 * Enqueue.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Enqueue_Result_Pdf_Result
	 */
	public function enqueue( array $record ): Enqueue_Result_Pdf_Result;
}
