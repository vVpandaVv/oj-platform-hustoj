<?php
/**
 * Worksheet approval repository boundary.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Authoring\Port;

use OJAI\Dashboard\Worksheet\Authoring\Application\Approve_Worksheet_Revision_Result;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Worksheet approval repository boundary.
 */
interface Worksheet_Approval_Repository_Port_Interface {
	/**
	 * Find command receipt.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $idempotency_storage_key Idempotency storage key.
	 * @param string $request_digest Request digest.
	 * @return Approve_Worksheet_Revision_Result|null
	 */
	public function find_command_receipt(
		int $actor_user_id,
		string $idempotency_storage_key,
		string $request_digest
	): ?Approve_Worksheet_Revision_Result;

	/**
	 * Approve revision.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Approve_Worksheet_Revision_Result
	 */
	public function approve_revision( array $record ): Approve_Worksheet_Revision_Result;
}
