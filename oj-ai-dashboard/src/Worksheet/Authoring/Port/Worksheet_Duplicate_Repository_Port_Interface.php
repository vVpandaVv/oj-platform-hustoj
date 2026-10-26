<?php
/**
 * Worksheet duplicate repository port.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Authoring\Port;

use OJAI\Dashboard\Worksheet\Authoring\Application\Duplicate_Worksheet_Result;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Worksheet duplicate repository port.
 */
interface Worksheet_Duplicate_Repository_Port_Interface {
	/**
	 * Find duplicate receipt.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $idempotency_storage_key Idempotency storage key.
	 * @param string $request_digest Request digest.
	 * @return Duplicate_Worksheet_Result|null
	 */
	public function find_duplicate_receipt(
		int $actor_user_id,
		string $idempotency_storage_key,
		string $request_digest
	): ?Duplicate_Worksheet_Result;

	/**
	 * Find owned duplicate source.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $worksheet_id Worksheet public identifier.
	 * @return array<string,mixed>|null
	 */
	public function find_owned_duplicate_source( int $actor_user_id, string $worksheet_id ): ?array;

	/**
	 * Duplicate initial.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Duplicate_Worksheet_Result
	 */
	public function duplicate_initial( array $record ): Duplicate_Worksheet_Result;
}
