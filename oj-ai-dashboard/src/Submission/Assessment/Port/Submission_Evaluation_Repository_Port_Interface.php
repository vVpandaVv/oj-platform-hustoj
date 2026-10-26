<?php
/**
 * Submission evaluation repository port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Submission\Assessment\Port;

use OJAI\Dashboard\Submission\Assessment\Application\Enqueue_Submission_Evaluation_Result;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Submission evaluation repository port interface.
 */
interface Submission_Evaluation_Repository_Port_Interface {
	/**
	 * Find command receipt.
	 *
	 * @param string $command_storage_key Command storage key.
	 * @param string $request_digest Request digest.
	 * @return Enqueue_Submission_Evaluation_Result|null
	 */
	public function find_command_receipt( string $command_storage_key, string $request_digest ): ?Enqueue_Submission_Evaluation_Result;
	/**
	 * Enqueue.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Enqueue_Submission_Evaluation_Result
	 */
	public function enqueue( array $record ): Enqueue_Submission_Evaluation_Result;
}
