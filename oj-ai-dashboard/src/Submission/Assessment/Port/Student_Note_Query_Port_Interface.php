<?php
/**
 * Student-note query boundary.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Port;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Student-note query boundary.
 */
interface Student_Note_Query_Port_Interface {
	/**
	 * List context.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $context_type Context type.
	 * @param string $context_id Context id.
	 * @param string $context_revision Context revision.
	 * @param string $assignment_id Distribution public identifier.
	 * @param string $state State.
	 * @param string $report_status Report status.
	 * @param string $cursor Continuation cursor from the previous page.
	 * @param int    $limit Maximum number of entries requested.
	 * @return array{items:array<int,array<string,mixed>>,next_cursor:string}
	 */
	public function list_context(
		int $actor_user_id,
		string $context_type,
		string $context_id,
		string $context_revision,
		string $assignment_id,
		string $state,
		string $report_status,
		string $cursor,
		int $limit
	): array;

	/**
	 * Find context.
	 *
	 * @param string $note_id Note id.
	 * @param string $context_type Context type.
	 * @param string $context_id Context id.
	 * @param string $context_revision Context revision.
	 * @return array<string,mixed>|null
	 */
	public function find_context(
		string $note_id,
		string $context_type,
		string $context_id,
		string $context_revision
	): ?array;

	/**
	 * Authorization snapshot.
	 *
	 * @param string $note_id Note id.
	 * @param string $context_type Context type.
	 * @param string $context_id Context id.
	 * @param string $context_revision Context revision.
	 * @return array{context_type:string,context_id:string,context_revision:string,current_revision:string,state:string,relation:string}|null
	 */
	public function authorization_snapshot(
		string $note_id,
		string $context_type,
		string $context_id,
		string $context_revision
	): ?array;
}
