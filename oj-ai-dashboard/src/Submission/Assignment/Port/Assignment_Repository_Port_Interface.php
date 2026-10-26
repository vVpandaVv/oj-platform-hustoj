<?php
/**
 * Assignment repository port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Submission\Assignment\Port;

use OJAI\Dashboard\Submission\Assignment\Application\Create_Assignment_Result;
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Assignment repository port interface.
 */
interface Assignment_Repository_Port_Interface {
	/**
	 * Find receipt.
	 *
	 * @param string $idempotency_storage_key Idempotency storage key.
	 * @param string $request_digest Request digest.
	 * @return Create_Assignment_Result|null
	 */
	public function find_receipt( string $idempotency_storage_key, string $request_digest ): ?Create_Assignment_Result;
	/**
	 * Create assignment repository port interface.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Create_Assignment_Result
	 */
	public function create( array $record ): Create_Assignment_Result;
	/**
	 * List context.
	 *
	 * @param string $context_type Context type.
	 * @param string $context_id Context id.
	 * @param string $context_revision Context revision.
	 * @param string $cursor Continuation cursor from the previous page.
	 * @param int    $limit Maximum number of entries requested.
	 * @return array{items:array<int,array<string,mixed>>,next_cursor:string}
	 */
	public function list_context( string $context_type, string $context_id, string $context_revision, string $cursor, int $limit ): array;
	/**
	 * Find context assignment.
	 *
	 * @param string $assignment_id Distribution public identifier.
	 * @param string $context_type Context type.
	 * @param string $context_id Context id.
	 * @param string $context_revision Context revision.
	 * @param string $cursor Continuation cursor from the previous page.
	 * @param int    $limit Maximum number of entries requested.
	 * @return array<string,mixed>|null
	 */
	public function find_context_assignment( string $assignment_id, string $context_type, string $context_id, string $context_revision, string $cursor, int $limit ): ?array;
	/**
	 * Authorization snapshot.
	 *
	 * @param string $assignment_id Distribution public identifier.
	 * @return array<string,mixed>|null
	 */
	public function authorization_snapshot( string $assignment_id ): ?array;
}
