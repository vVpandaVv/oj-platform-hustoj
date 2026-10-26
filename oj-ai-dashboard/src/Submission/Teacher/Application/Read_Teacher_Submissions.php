<?php
/**
 * Teacher submission read use cases.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Teacher\Application;

use InvalidArgumentException;
use OJAI\Dashboard\Security\Policy\Context_Scope;
use OJAI\Dashboard\Submission\Assignment\Application\Assignment_Failure;
use OJAI\Dashboard\Submission\Teacher\Port\Teacher_Submission_Query_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates Q13-Q15 inputs before entering the storage adapter.
 */
final class Read_Teacher_Submissions {
	public const DEFAULT_LIMIT  = 30;
	public const MAXIMUM_LIMIT  = 100;
	public const MAXIMUM_CURSOR = 512;

	/**
	 * Initialize teacher submissions dependencies and state.
	 *
	 * @param Teacher_Submission_Query_Port_Interface $submissions Submissions.
	 */
	public function __construct( private Teacher_Submission_Query_Port_Interface $submissions ) {
	}

	/**
	 * List assignment.
	 *
	 * @param string $assignment_id Distribution public identifier.
	 * @param string $context_type Context type.
	 * @param string $context_id Context id.
	 * @param string $context_revision Context revision.
	 * @param string $cursor Continuation cursor from the previous page.
	 * @param int    $limit Maximum number of entries requested.
	 * @return array{assignment:array<string,mixed>,items:array<int,array<string,mixed>>,next_cursor:string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function list_assignment(
		string $assignment_id,
		string $context_type,
		string $context_id,
		string $context_revision,
		string $cursor,
		int $limit
	): array {
		$this->public_id( $assignment_id );
		$this->context( $context_type, $context_id, $context_revision );
		$this->page( $cursor, $limit );
		$result = $this->submissions->list_assignment_submissions(
			$assignment_id,
			$context_type,
			$context_id,
			$context_revision,
			$cursor,
			$limit
		);
		if ( null === $result ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::NOT_FOUND );
		}

		return $result;
	}

	/**
	 * Detail.
	 *
	 * @param string $submission_id Submission public identifier.
	 * @param string $context_type Context type.
	 * @param string $context_id Context id.
	 * @param string $context_revision Context revision.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function detail( string $submission_id, string $context_type, string $context_id, string $context_revision ): array {
		$this->public_id( $submission_id );
		$this->context( $context_type, $context_id, $context_revision );
		$result = $this->submissions->find_submission( $submission_id, $context_type, $context_id, $context_revision );
		if ( null === $result ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::NOT_FOUND );
		}

		return $result;
	}

	/**
	 * History.
	 *
	 * @param string $submission_id Submission public identifier.
	 * @param string $context_type Context type.
	 * @param string $context_id Context id.
	 * @param string $context_revision Context revision.
	 * @param string $cursor Continuation cursor from the previous page.
	 * @param int    $limit Maximum number of entries requested.
	 * @return array{submission:array<string,mixed>,revisions:array<int,array<string,mixed>>,next_cursor:string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function history(
		string $submission_id,
		string $context_type,
		string $context_id,
		string $context_revision,
		string $cursor,
		int $limit
	): array {
		$this->public_id( $submission_id );
		$this->context( $context_type, $context_id, $context_revision );
		$this->page( $cursor, $limit );
		$result = $this->submissions->list_submission_history(
			$submission_id,
			$context_type,
			$context_id,
			$context_revision,
			$cursor,
			$limit
		);
		if ( null === $result ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::NOT_FOUND );
		}

		return $result;
	}

	/**
	 * Context.
	 *
	 * @param string $type Type.
	 * @param string $id Id.
	 * @param string $revision Revision.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function context( string $type, string $id, string $revision ): void {
		if ( ! in_array( $type, array( Context_Scope::TYPE_CLASS, Context_Scope::TYPE_GROUP ), true ) ) {
			throw new InvalidArgumentException( 'Teacher submission Context type is invalid.' );
		}
		foreach ( array( $id, $revision ) as $token ) {
			if ( '' === $token || strlen( $token ) > 191 || 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@\/-]*$/D', $token ) ) {
				throw new InvalidArgumentException( 'Teacher submission Context token is invalid.' );
			}
		}
	}

	/**
	 * Page.
	 *
	 * @param string $cursor Continuation cursor from the previous page.
	 * @param int    $limit Maximum number of entries requested.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function page( string $cursor, int $limit ): void {
		if ( strlen( $cursor ) > self::MAXIMUM_CURSOR || $limit < 1 || $limit > self::MAXIMUM_LIMIT ) {
			throw new InvalidArgumentException( 'Teacher submission page is invalid.' );
		}
	}

	/**
	 * Public id.
	 *
	 * @param string $value Value.
	 * @return void
	 */
	private function public_id( string $value ): void {
		new Public_Id( $value );
	}
}
