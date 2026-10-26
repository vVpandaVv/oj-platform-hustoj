<?php
/**
 * Student attachment removal port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Student\Port;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persists C25 removal and its durable idempotency receipt.
 */
interface Student_Attachment_Removal_Port_Interface {
	/**
	 * Find removal receipt.
	 *
	 * @param string $submission_id Submission public identifier.
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $storage_key Storage key.
	 * @param string $request_digest Request digest.
	 * @return array<string,mixed>|null
	 */
	public function find_removal_receipt(
		string $submission_id,
		int $actor_user_id,
		string $storage_key,
		string $request_digest
	): ?array;

	/**
	 * Remove staged.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return array<string,mixed>
	 */
	public function remove_staged( array $record ): array;
}
