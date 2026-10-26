<?php
/**
 * Submission result release repository port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Port;

use OJAI\Dashboard\Submission\Assessment\Application\Submission_Result_Release_Result;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Submission result release repository port interface.
 */
interface Submission_Result_Release_Repository_Port_Interface {
	/**
	 * Find command receipt.
	 *
	 * @param string $command_storage_key Command storage key.
	 * @param string $request_digest Request digest.
	 * @return Submission_Result_Release_Result|null
	 */
	public function find_command_receipt( string $command_storage_key, string $request_digest ): ?Submission_Result_Release_Result;

	/**
	 * Find owned snapshot.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $evaluation_id Evaluation id.
	 * @return array<string,mixed>|null
	 */
	public function find_owned_snapshot( int $actor_user_id, string $evaluation_id ): ?array;

	/**
	 * Append release.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Submission_Result_Release_Result
	 */
	public function append_release( array $record ): Submission_Result_Release_Result;
}
