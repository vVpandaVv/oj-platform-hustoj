<?php
/**
 * Teacher submission query boundary.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Teacher\Port;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads staff-only submission projections from add-on-owned tables.
 */
interface Teacher_Submission_Query_Port_Interface {
	/**
	 * List assignment submissions.
	 *
	 * @param string $assignment_id Distribution public identifier.
	 * @param string $context_type Context type.
	 * @param string $context_id Context id.
	 * @param string $context_revision Context revision.
	 * @param string $cursor Continuation cursor from the previous page.
	 * @param int    $limit Maximum number of entries requested.
	 * @return array{assignment:array<string,mixed>,items:array<int,array<string,mixed>>,next_cursor:string}|null
	 */
	public function list_assignment_submissions(
		string $assignment_id,
		string $context_type,
		string $context_id,
		string $context_revision,
		string $cursor,
		int $limit
	): ?array;

	/**
	 * Find submission.
	 *
	 * @param string $submission_id Submission public identifier.
	 * @param string $context_type Context type.
	 * @param string $context_id Context id.
	 * @param string $context_revision Context revision.
	 * @return array<string,mixed>|null
	 */
	public function find_submission(
		string $submission_id,
		string $context_type,
		string $context_id,
		string $context_revision
	): ?array;

	/**
	 * List submission history.
	 *
	 * @param string $submission_id Submission public identifier.
	 * @param string $context_type Context type.
	 * @param string $context_id Context id.
	 * @param string $context_revision Context revision.
	 * @param string $cursor Continuation cursor from the previous page.
	 * @param int    $limit Maximum number of entries requested.
	 * @return array{submission:array<string,mixed>,revisions:array<int,array<string,mixed>>,next_cursor:string}|null
	 */
	public function list_submission_history(
		string $submission_id,
		string $context_type,
		string $context_id,
		string $context_revision,
		string $cursor,
		int $limit
	): ?array;

	/**
	 * Authorization snapshot.
	 *
	 * @param string $submission_id Submission public identifier.
	 * @return array{submission_id:string,assignment_id:string,context_type:string,context_id:string,context_revision:string,current_revision:string,state:string,assignment_state:string,target_state:string}|null
	 */
	public function authorization_snapshot( string $submission_id ): ?array;
}
