<?php
/**
 * Submission feedback repository port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Submission\Assessment\Port;

use OJAI\Dashboard\Submission\Assessment\Application\Generate_Submission_Feedback_Result;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Submission feedback repository port interface.
 */
interface Submission_Feedback_Repository_Port_Interface {
	/**
	 * Find command receipt.
	 *
	 * @param string $command_storage_key Command storage key.
	 * @param string $request_digest Request digest.
	 * @return Generate_Submission_Feedback_Result|null
	 */
	public function find_command_receipt( string $command_storage_key, string $request_digest ): ?Generate_Submission_Feedback_Result;
	/**
	 * Find owned source.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $evaluation_id Evaluation id.
	 * @return array<string,mixed>|null
	 */
	public function find_owned_source( int $actor_user_id, string $evaluation_id ): ?array;
	/**
	 * Enqueue.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Generate_Submission_Feedback_Result
	 */
	public function enqueue( array $record ): Generate_Submission_Feedback_Result;
}
