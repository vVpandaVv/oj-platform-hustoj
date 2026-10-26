<?php
/**
 * Student submission evaluation query boundary.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Port;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Student submission evaluation query boundary.
 */
interface Submission_Evaluation_Query_Port_Interface {
	/**
	 * List unevaluated context.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $context_type Context type.
	 * @param string $context_id Context id.
	 * @param string $context_revision Context revision.
	 * @param string $assignment_id Distribution public identifier.
	 * @param string $cursor Continuation cursor from the previous page.
	 * @param int    $limit Maximum number of entries requested.
	 * @return array{items:array<int,array<string,mixed>>,next_cursor:string}
	 */
	public function list_unevaluated_context(
		int $actor_user_id,
		string $context_type,
		string $context_id,
		string $context_revision,
		string $assignment_id,
		string $cursor,
		int $limit
	): array;

	/**
	 * List context.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $context_type Context type.
	 * @param string $context_id Context id.
	 * @param string $context_revision Context revision.
	 * @param string $assignment_id Distribution public identifier.
	 * @param string $state State.
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
		string $cursor,
		int $limit
	): array;

	/**
	 * Find context.
	 *
	 * @param string $evaluation_id Evaluation id.
	 * @param string $context_type Context type.
	 * @param string $context_id Context id.
	 * @param string $context_revision Context revision.
	 * @param string $result_id Result id.
	 * @return array<string,mixed>|null
	 */
	public function find_context(
		string $evaluation_id,
		string $context_type,
		string $context_id,
		string $context_revision,
		string $result_id = ''
	): ?array;

	/**
	 * Authorization snapshot.
	 *
	 * @param string $evaluation_id Evaluation id.
	 * @param string $context_type Context type.
	 * @param string $context_id Context id.
	 * @param string $context_revision Context revision.
	 * @return array{context_type:string,context_id:string,context_revision:string,current_revision:string,state:string,relation:string}|null
	 */
	public function authorization_snapshot(
		string $evaluation_id,
		string $context_type,
		string $context_id,
		string $context_revision
	): ?array;
}
