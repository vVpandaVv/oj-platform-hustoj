<?php
/**
 * Student assignment repository port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Student\Port;

use OJAI\Dashboard\Submission\Student\Application\Autosave_Own_Submission_Result;
use OJAI\Dashboard\Submission\Student\Application\Start_Own_Submission_Result;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persists and projects only the authenticated learner's assignment rows.
 */
interface Student_Assignment_Repository_Port_Interface extends Student_Assignment_Access_Port_Interface {
	/**
	 * List for actor.
	 *
	 * @param int                                                                                                                                                $actor_user_id Authenticated actor from the access context.
	 * @param array<string,array{type:string,id:string,label:string,lifecycle_state:string,revision:string,attributes:array<string,bool|int|float|string|null>}> $contexts Contexts.
	 * @param string                                                                                                                                             $cursor Continuation cursor from the previous page.
	 * @param int                                                                                                                                                $limit Maximum number of entries requested.
	 * @param string                                                                                                                                             $now_utc Now utc.
	 * @return array{items:array<int,array<string,mixed>>,next_cursor:string}
	 */
	public function list_for_actor( int $actor_user_id, array $contexts, string $cursor, int $limit, string $now_utc ): array;

	/**
	 * Find for actor.
	 *
	 * @param string                                                                                                                               $assignment_id Distribution public identifier.
	 * @param int                                                                                                                                  $actor_user_id Authenticated actor from the access context.
	 * @param array{type:string,id:string,label:string,lifecycle_state:string,revision:string,attributes:array<string,bool|int|float|string|null>} $context Access or execution context for this operation.
	 * @param string                                                                                                                               $now_utc Now utc.
	 * @return array<string,mixed>|null
	 */
	public function find_for_actor( string $assignment_id, int $actor_user_id, array $context, string $now_utc ): ?array;

	/**
	 * Find submission for actor.
	 *
	 * @param string                                                                                                                               $submission_id Submission public identifier.
	 * @param int                                                                                                                                  $actor_user_id Authenticated actor from the access context.
	 * @param array{type:string,id:string,label:string,lifecycle_state:string,revision:string,attributes:array<string,bool|int|float|string|null>} $context Access or execution context for this operation.
	 * @param string                                                                                                                               $now_utc Now utc.
	 * @return array<string,mixed>|null
	 */
	public function find_submission_for_actor( string $submission_id, int $actor_user_id, array $context, string $now_utc ): ?array;

	/**
	 * Find start receipt.
	 *
	 * @param string $idempotency_storage_key Idempotency storage key.
	 * @param string $request_digest Request digest.
	 * @return Start_Own_Submission_Result|null
	 */
	public function find_start_receipt( string $idempotency_storage_key, string $request_digest ): ?Start_Own_Submission_Result;

	/**
	 * Start.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Start_Own_Submission_Result
	 */
	public function start( array $record ): Start_Own_Submission_Result;

	/**
	 * Find autosave receipt.
	 *
	 * @param string $idempotency_storage_key Idempotency storage key.
	 * @param string $request_digest Request digest.
	 * @return Autosave_Own_Submission_Result|null
	 */
	public function find_autosave_receipt( string $idempotency_storage_key, string $request_digest ): ?Autosave_Own_Submission_Result;

	/**
	 * Autosave.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Autosave_Own_Submission_Result
	 */
	public function autosave( array $record ): Autosave_Own_Submission_Result;
}
