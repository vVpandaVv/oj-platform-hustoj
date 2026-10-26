<?php
/**
 * Worksheet evaluation repository port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Worksheet\Evaluation\Port;

use OJAI\Dashboard\Worksheet\Evaluation\Application\Enqueue_Worksheet_Evaluation_Result;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Worksheet evaluation repository port interface.
 */
interface Worksheet_Evaluation_Repository_Port_Interface {
	/**
	 * Find command receipt.
	 *
	 * @param string $command_storage_key Command storage key.
	 * @param string $request_digest Request digest.
	 * @return Enqueue_Worksheet_Evaluation_Result|null
	 */
	public function find_command_receipt(
		string $command_storage_key,
		string $request_digest
	): ?Enqueue_Worksheet_Evaluation_Result;

	/**
	 * Enqueue.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Enqueue_Worksheet_Evaluation_Result
	 */
	public function enqueue( array $record ): Enqueue_Worksheet_Evaluation_Result;
}
