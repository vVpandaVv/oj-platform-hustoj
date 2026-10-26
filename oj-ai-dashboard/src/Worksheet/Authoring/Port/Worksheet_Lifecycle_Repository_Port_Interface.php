<?php
/**
 * Worksheet root lifecycle persistence port.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Authoring\Port;

use OJAI\Dashboard\Worksheet\Authoring\Application\Worksheet_Lifecycle_Result;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Worksheet root lifecycle persistence port.
 */
interface Worksheet_Lifecycle_Repository_Port_Interface {
	/**
	 * Find command receipt.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $operation Operation.
	 * @param string $idempotency_storage_key Idempotency storage key.
	 * @param string $request_digest Request digest.
	 * @return Worksheet_Lifecycle_Result|null
	 */
	public function find_command_receipt(
		int $actor_user_id,
		string $operation,
		string $idempotency_storage_key,
		string $request_digest
	): ?Worksheet_Lifecycle_Result;

	/**
	 * Change state.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Worksheet_Lifecycle_Result
	 */
	public function change_state( array $record ): Worksheet_Lifecycle_Result;
}
