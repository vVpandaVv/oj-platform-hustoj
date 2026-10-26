<?php
/**
 * Submission result withdrawal repository port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Port;

use OJAI\Dashboard\Submission\Assessment\Application\Submission_Result_Withdrawal_Result;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Submission result withdrawal repository port interface.
 */
interface Submission_Result_Withdrawal_Repository_Port_Interface {
	/**
	 * Find withdrawal receipt.
	 *
	 * @param string $command_storage_key Command storage key.
	 * @param string $request_digest Request digest.
	 * @return Submission_Result_Withdrawal_Result|null
	 */
	public function find_withdrawal_receipt( string $command_storage_key, string $request_digest ): ?Submission_Result_Withdrawal_Result;

	/**
	 * Find owned withdrawal snapshot.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $evaluation_id Evaluation id.
	 * @return array<string,mixed>|null
	 */
	public function find_owned_withdrawal_snapshot( int $actor_user_id, string $evaluation_id ): ?array;

	/**
	 * Append withdrawal.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Submission_Result_Withdrawal_Result
	 */
	public function append_withdrawal( array $record ): Submission_Result_Withdrawal_Result;
}
