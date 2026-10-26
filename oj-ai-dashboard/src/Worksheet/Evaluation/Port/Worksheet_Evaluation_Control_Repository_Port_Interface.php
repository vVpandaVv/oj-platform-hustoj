<?php
/**
 * Worksheet evaluation control repository port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Worksheet\Evaluation\Port;

use OJAI\Dashboard\Worksheet\Evaluation\Application\Worksheet_Evaluation_Control_Result;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Worksheet evaluation control repository port interface.
 */
interface Worksheet_Evaluation_Control_Repository_Port_Interface {
	/**
	 * Find command receipt.
	 *
	 * @param string $command_storage_key Command storage key.
	 * @param string $request_digest Request digest.
	 * @param string $operation Operation.
	 * @return Worksheet_Evaluation_Control_Result|null
	 */
	public function find_command_receipt(
		string $command_storage_key,
		string $request_digest,
		string $operation
	): ?Worksheet_Evaluation_Control_Result;

	/**
	 * Find owned snapshot.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $evaluation_id Evaluation id.
	 * @return array<string,mixed>|null
	 */
	public function find_owned_snapshot( int $actor_user_id, string $evaluation_id ): ?array;

	/**
	 * Retry.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Worksheet_Evaluation_Control_Result
	 */
	public function retry( array $record ): Worksheet_Evaluation_Control_Result;

	/**
	 * Cancel.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Worksheet_Evaluation_Control_Result
	 */
	public function cancel( array $record ): Worksheet_Evaluation_Control_Result;
}
