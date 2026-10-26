<?php
/**
 * Student submission evaluation query use cases.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Application;

use InvalidArgumentException;
use OJAI\Dashboard\Security\Policy\Context_Scope;
use OJAI\Dashboard\Submission\Assessment\Port\Submission_Evaluation_Query_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Student submission evaluation query use cases.
 */
final class Read_Submission_Evaluations {
	public const DEFAULT_LIMIT  = 30;
	public const MAXIMUM_LIMIT  = 100;
	public const MAXIMUM_CURSOR = 191;

	public const STATES = array(
		'',
		'requested',
		'queued',
		'running',
		'review_required',
		'reviewed',
		'failed',
		'cancelled',
		'stale',
	);

	/**
	 * Unevaluated.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $context_type Context type.
	 * @param string $context_id Context id.
	 * @param string $context_revision Context revision.
	 * @param string $assignment_id Distribution public identifier.
	 * @param string $cursor Continuation cursor from the previous page.
	 * @param int    $limit Maximum number of entries requested.
	 * @return array{items:array<int,array<string,mixed>>,next_cursor:string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function unevaluated(
		int $actor_user_id,
		string $context_type,
		string $context_id,
		string $context_revision,
		string $assignment_id,
		string $cursor,
		int $limit
	): array {
		$this->assert_actor( $actor_user_id );
		$this->assert_context( $context_type, $context_id, $context_revision );
		$this->optional_public_id( $assignment_id );
		if ( strlen( $cursor ) > self::MAXIMUM_CURSOR || $limit < 1 || $limit > self::MAXIMUM_LIMIT ) {
			throw new InvalidArgumentException( 'Submission evaluation candidate page is invalid.' );
		}

		return $this->queries->list_unevaluated_context(
			$actor_user_id,
			$context_type,
			$context_id,
			$context_revision,
			$assignment_id,
			$cursor,
			$limit
		);
	}

	/**
	 * Initialize submission evaluations dependencies and state.
	 *
	 * @param Submission_Evaluation_Query_Port_Interface $queries Queries.
	 */
	public function __construct( private Submission_Evaluation_Query_Port_Interface $queries ) {
	}

	/**
	 * List submission evaluations.
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
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function list(
		int $actor_user_id,
		string $context_type,
		string $context_id,
		string $context_revision,
		string $assignment_id,
		string $state,
		string $cursor,
		int $limit
	): array {
		$this->assert_actor( $actor_user_id );
		$this->assert_context( $context_type, $context_id, $context_revision );
		$this->optional_public_id( $assignment_id );
		if ( ! in_array( $state, self::STATES, true ) ) {
			throw new InvalidArgumentException( 'Submission evaluation state filter is invalid.' );
		}
		if ( strlen( $cursor ) > self::MAXIMUM_CURSOR || $limit < 1 || $limit > self::MAXIMUM_LIMIT ) {
			throw new InvalidArgumentException( 'Submission evaluation page is invalid.' );
		}

		return $this->queries->list_context(
			$actor_user_id,
			$context_type,
			$context_id,
			$context_revision,
			$assignment_id,
			$state,
			$cursor,
			$limit
		);
	}

	/**
	 * Detail.
	 *
	 * @param string $evaluation_id Evaluation id.
	 * @param string $context_type Context type.
	 * @param string $context_id Context id.
	 * @param string $context_revision Context revision.
	 * @param string $result_id Result id.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function detail(
		string $evaluation_id,
		string $context_type,
		string $context_id,
		string $context_revision,
		string $result_id = ''
	): array {
		new Public_Id( $evaluation_id );
		$this->assert_context( $context_type, $context_id, $context_revision );
		$this->optional_public_id( $result_id );
		$result = $this->queries->find_context(
			$evaluation_id,
			$context_type,
			$context_id,
			$context_revision,
			$result_id
		);
		if ( null === $result ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::NOT_FOUND );
		}

		return $result;
	}

	/**
	 * Reject invalid actor.
	 *
	 * @param int $actor_user_id Authenticated actor from the access context.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_actor( int $actor_user_id ): void {
		if ( $actor_user_id < 1 ) {
			throw new InvalidArgumentException( 'Submission evaluation query requires an authenticated actor.' );
		}
	}

	/**
	 * Reject invalid context.
	 *
	 * @param string $type Type.
	 * @param string $id Id.
	 * @param string $revision Revision.
	 * @return void
	 */
	private function assert_context( string $type, string $id, string $revision ): void {
		new Context_Scope( $type, $id, Context_Scope::OPERATION_EVALUATE, null, $revision );
	}

	/**
	 * Optional public id.
	 *
	 * @param string $value Value.
	 * @return void
	 */
	private function optional_public_id( string $value ): void {
		if ( '' !== $value ) {
			new Public_Id( $value );
		}
	}
}
