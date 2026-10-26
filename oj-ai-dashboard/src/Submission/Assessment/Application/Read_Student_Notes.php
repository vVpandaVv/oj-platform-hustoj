<?php
/**
 * Teacher-only student-note query use cases.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Application;

use InvalidArgumentException;
use OJAI\Dashboard\Security\Policy\Context_Scope;
use OJAI\Dashboard\Submission\Assessment\Port\Student_Note_Query_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Teacher-only student-note query use cases.
 */
final class Read_Student_Notes {
	public const DEFAULT_LIMIT  = 30;
	public const MAXIMUM_LIMIT  = 100;
	public const MAXIMUM_CURSOR = 191;

	public const STATES = array(
		'',
		'ai_draft',
		'teacher_draft',
		'approved',
		'rejected',
		'superseded',
	);

	public const REPORT_STATUSES = array( '', 'included', 'excluded' );

	/**
	 * Initialize student notes dependencies and state.
	 *
	 * @param Student_Note_Query_Port_Interface $queries Queries.
	 */
	public function __construct( private Student_Note_Query_Port_Interface $queries ) {
	}

	/**
	 * List student notes.
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
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function list(
		int $actor_user_id,
		string $context_type,
		string $context_id,
		string $context_revision,
		string $assignment_id,
		string $state,
		string $report_status,
		string $cursor,
		int $limit
	): array {
		if ( $actor_user_id < 1 ) {
			throw new InvalidArgumentException( 'Student-note query requires an authenticated actor.' );
		}
		$this->assert_context( $context_type, $context_id, $context_revision );
		$this->optional_public_id( $assignment_id );
		if ( ! in_array( $state, self::STATES, true ) ) {
			throw new InvalidArgumentException( 'Student-note state filter is invalid.' );
		}
		if ( ! in_array( $report_status, self::REPORT_STATUSES, true ) ) {
			throw new InvalidArgumentException( 'Student-note report filter is invalid.' );
		}
		if ( strlen( $cursor ) > self::MAXIMUM_CURSOR || $limit < 1 || $limit > self::MAXIMUM_LIMIT ) {
			throw new InvalidArgumentException( 'Student-note page is invalid.' );
		}

		return $this->queries->list_context(
			$actor_user_id,
			$context_type,
			$context_id,
			$context_revision,
			$assignment_id,
			$state,
			$report_status,
			$cursor,
			$limit
		);
	}

	/**
	 * Detail.
	 *
	 * @param string $note_id Note id.
	 * @param string $context_type Context type.
	 * @param string $context_id Context id.
	 * @param string $context_revision Context revision.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function detail(
		string $note_id,
		string $context_type,
		string $context_id,
		string $context_revision
	): array {
		new Public_Id( $note_id );
		$this->assert_context( $context_type, $context_id, $context_revision );
		$result = $this->queries->find_context( $note_id, $context_type, $context_id, $context_revision );
		if ( null === $result ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::NOT_FOUND );
		}
		return $result;
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
		new Context_Scope( $type, $id, Context_Scope::OPERATION_REPORT, null, $revision );
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
