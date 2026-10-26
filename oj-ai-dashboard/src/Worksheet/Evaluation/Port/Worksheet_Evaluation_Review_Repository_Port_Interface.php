<?php
/**
 * Worksheet evaluation review repository port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Worksheet\Evaluation\Port;

use OJAI\Dashboard\Worksheet\Evaluation\Application\Worksheet_Evaluation_Review_Result;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Worksheet evaluation review repository port interface.
 */
interface Worksheet_Evaluation_Review_Repository_Port_Interface {
	/**
	 * Find command receipt.
	 *
	 * @param string $command_storage_key Command storage key.
	 * @param string $request_digest Request digest.
	 * @return Worksheet_Evaluation_Review_Result|null
	 */
	public function find_command_receipt(
		string $command_storage_key,
		string $request_digest
	): ?Worksheet_Evaluation_Review_Result;

	/**
	 * Find owned snapshot.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $evaluation_id Evaluation id.
	 * @return array<string,mixed>|null
	 */
	public function find_owned_snapshot( int $actor_user_id, string $evaluation_id ): ?array;

	/**
	 * Append review.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Worksheet_Evaluation_Review_Result
	 */
	public function append_review( array $record ): Worksheet_Evaluation_Review_Result;
}
