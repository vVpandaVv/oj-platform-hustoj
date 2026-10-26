<?php
/**
 * Worksheet publication persistence port.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Authoring\Port;

use OJAI\Dashboard\Worksheet\Authoring\Application\Publish_Worksheet_Revision_Result;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Worksheet publication persistence port.
 */
interface Worksheet_Publication_Repository_Port_Interface {
	/**
	 * Find command receipt.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $idempotency_storage_key Idempotency storage key.
	 * @param string $request_digest Request digest.
	 * @return Publish_Worksheet_Revision_Result|null
	 */
	public function find_command_receipt(
		int $actor_user_id,
		string $idempotency_storage_key,
		string $request_digest
	): ?Publish_Worksheet_Revision_Result;

	/**
	 * Find approval evidence.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $worksheet_id Worksheet public identifier.
	 * @param string $revision_id Revision id.
	 * @return array<string,mixed>|null
	 */
	public function find_approval_evidence(
		int $actor_user_id,
		string $worksheet_id,
		string $revision_id
	): ?array;

	/**
	 * Publish revision.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Publish_Worksheet_Revision_Result
	 */
	public function publish_revision( array $record ): Publish_Worksheet_Revision_Result;
}
