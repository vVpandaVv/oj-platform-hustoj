<?php
/**
 * Submission evaluation control repository port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Submission\Assessment\Port;

use OJAI\Dashboard\Submission\Assessment\Application\Submission_Evaluation_Control_Result;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Submission evaluation control repository port interface.
 */
interface Submission_Evaluation_Control_Repository_Port_Interface {
	/**
	 * Find command receipt.
	 *
	 * @param string $command_storage_key Command storage key.
	 * @param string $request_digest Request digest.
	 * @param string $operation Operation.
	 * @return Submission_Evaluation_Control_Result|null
	 */
	public function find_command_receipt(
		string $command_storage_key,
		string $request_digest,
		string $operation
	): ?Submission_Evaluation_Control_Result;

	/**
	 * Find snapshot.
	 *
	 * @param string $evaluation_id Evaluation id.
	 * @return array<string,mixed>|null
	 */
	public function find_snapshot( string $evaluation_id ): ?array;

	/**
	 * Retry.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Submission_Evaluation_Control_Result
	 */
	public function retry( array $record ): Submission_Evaluation_Control_Result;

	/**
	 * Cancel.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Submission_Evaluation_Control_Result
	 */
	public function cancel( array $record ): Submission_Evaluation_Control_Result;
}
