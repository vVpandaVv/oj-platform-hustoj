<?php
/**
 * WordPress target page adapter for C30 assignment evaluation.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Infrastructure;

use InvalidArgumentException;
use OJAI\Dashboard\Submission\Assessment\Application\Assignment_Evaluation_Failure;
use OJAI\Dashboard\Submission\Assessment\Port\Assignment_Evaluation_Batch_Source_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Infrastructure\Signed_Cursor_Codec;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Paginates the immutable target snapshot while applying one official-source cutoff.
 */
final class WordPress_Assignment_Evaluation_Batch_Source implements Assignment_Evaluation_Batch_Source_Port_Interface {
	private const TABLE_ASSIGNMENTS = 'oj_ai_assignments';
	private const TABLE_TARGETS     = 'oj_ai_assignment_targets';
	private const TABLE_SUBMISSIONS = 'oj_ai_submissions';
	private const TABLE_REVISIONS   = 'oj_ai_submission_revisions';
	private const TABLE_EVALUATIONS = 'oj_ai_submission_evaluations';
	private const CURSOR_KIND       = 'ae_batch';

	/**
	 * Initialize WordPress assignment evaluation batch source dependencies and state.
	 *
	 * @param Signed_Cursor_Codec $cursors Cursors.
	 * @param object|null         $database Database.
	 */
	public function __construct(
		private Signed_Cursor_Codec $cursors,
		private ?object $database = null
	) {
	}

	/**
	 * Page.
	 *
	 * @param string $assignment_id Distribution public identifier.
	 * @param string $context_type Context type.
	 * @param string $context_id Context id.
	 * @param string $context_revision Context revision.
	 * @param string $source_cutoff_utc Source cutoff utc.
	 * @param string $item_cursor Item cursor.
	 * @param int    $limit Maximum number of entries requested.
	 * @return array<string,mixed>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function page(
		string $assignment_id,
		string $context_type,
		string $context_id,
		string $context_revision,
		string $source_cutoff_utc,
		string $item_cursor,
		int $limit
	): ?array {
		new Public_Id( $assignment_id );
		$this->context( $context_type, $context_id, $context_revision );
		$this->utc( $source_cutoff_utc );
		if ( $limit < 1 || $limit > 100 ) {
			throw new InvalidArgumentException( 'Assignment evaluation page limit is invalid.' );
		}

		$assignment = $this->get_row(
			$this->prepare(
				'SELECT id AS assignment_pk, public_id AS assignment_id, state AS assignment_state, schedule_version, context_type, context_id, context_revision '
					. 'FROM ' . self::TABLE_ASSIGNMENTS . ' WHERE public_id = %s AND context_type = %s AND context_id = %s AND context_revision = %s LIMIT 1',
				array( $assignment_id, $context_type, $context_id, $context_revision )
			)
		);
		if ( null === $assignment ) {
			return null;
		}
		$assignment_pk        = $this->positive_integer( $assignment['assignment_pk'] ?? null );
		$stored_assignment_id = $this->public_id( $assignment['assignment_id'] ?? null );
		$state                = $this->assignment_state( $assignment['assignment_state'] ?? null );
		$schedule_version     = $this->positive_integer( $assignment['schedule_version'] ?? null );
		if ( ! hash_equals( $assignment_id, $stored_assignment_id ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Evaluation_Failure::because( Assignment_Evaluation_Failure::STORED_DATA_INVALID );
		}

		$values       = array( $assignment_pk );
		$after        = '';
		$cursor_value = $this->cursors->decode( $item_cursor, self::CURSOR_KIND );
		if ( array() !== $cursor_value ) {
			$this->exact_keys( $cursor_value, array( 'i', 's', 'v' ) );
			if (
				! hash_equals( $this->cursor_scope( $assignment_id, $source_cutoff_utc ), (string) ( $cursor_value['s'] ?? '' ) )
				|| $schedule_version !== $this->positive_integer( $cursor_value['v'] ?? null )
			) {
				throw new InvalidArgumentException( 'Assignment evaluation cursor scope does not match.' );
			}
			$after_id = $this->public_id( $cursor_value['i'] ?? null );
			$after    = ' AND t.public_id > %s';
			$values[] = $after_id;
		}
		$values[] = $limit + 1;
		$sql      = 'SELECT t.public_id AS target_id, t.state AS target_state, '
			. 's.public_id AS submission_id, s.lock_version AS submission_lock_version, s.state AS submission_state, '
			. 's.official_revision_public_id, s.submitted_at_utc, r.content_hash AS official_revision_hash, '
			. 'EXISTS (SELECT 1 FROM ' . self::TABLE_EVALUATIONS . ' e '
			. 'WHERE e.submission_revision_public_id = s.official_revision_public_id '
			. 'AND e.submission_revision_hash = r.content_hash) AS has_evaluation, '
			. '(SELECT e.public_id FROM ' . self::TABLE_EVALUATIONS . ' e '
			. "WHERE e.submission_revision_public_id = s.official_revision_public_id AND e.submission_revision_hash = r.content_hash AND e.state = 'failed' "
			. 'AND NOT EXISTS (SELECT 1 FROM ' . self::TABLE_EVALUATIONS . ' protected '
			. "WHERE protected.submission_revision_public_id = s.official_revision_public_id AND protected.submission_revision_hash = r.content_hash AND protected.state <> 'failed') "
			. 'ORDER BY e.id DESC LIMIT 1) AS failed_evaluation_id '
			. 'FROM ' . self::TABLE_TARGETS . ' t '
			. 'LEFT JOIN ' . self::TABLE_SUBMISSIONS . ' s ON s.assignment_id = t.assignment_id AND s.learner_user_id = t.learner_user_id '
			. 'LEFT JOIN ' . self::TABLE_REVISIONS . ' r ON r.submission_id = s.id AND r.public_id = s.official_revision_public_id '
			. 'WHERE t.assignment_id = %d' . $after . ' ORDER BY t.public_id ASC LIMIT %d';
		$rows     = $this->get_results( $this->prepare( $sql, $values ) );
		$has_more = count( $rows ) > $limit;
		$rows     = array_slice( $rows, 0, $limit );
		$items    = array_map( fn ( array $row ): array => $this->item( $row ), $rows );

		$next_cursor = '';
		if ( $has_more && array() !== $items ) {
			$last        = $items[ count( $items ) - 1 ];
			$next_cursor = $this->cursors->encode(
				self::CURSOR_KIND,
				array(
					'i' => $last['target_id'],
					's' => $this->cursor_scope( $assignment_id, $source_cutoff_utc ),
					'v' => $schedule_version,
				)
			);
		}

		return array(
			'assignment_id'     => $stored_assignment_id,
			'context_id'        => $this->token( $assignment['context_id'] ?? null, 191 ),
			'context_revision'  => $this->token( $assignment['context_revision'] ?? null, 191 ),
			'context_type'      => $this->context_type( $assignment['context_type'] ?? null ),
			'current_revision'  => (string) $schedule_version,
			'items'             => $items,
			'next_cursor'       => $next_cursor,
			'source_cutoff_utc' => $source_cutoff_utc,
			'state'             => $state,
		);
	}

	/**
	 * Item.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function item( array $row ): array {
		$target_id    = $this->public_id( $row['target_id'] ?? null );
		$target_state = $this->target_state( $row['target_state'] ?? null );
		if ( ! in_array( $row['has_evaluation'] ?? null, array( 0, 1, '0', '1' ), true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Stable application failure, not UI output.
			throw Assignment_Evaluation_Failure::because( Assignment_Evaluation_Failure::STORED_DATA_INVALID );
		}
		$has_evaluation = 1 === (int) $row['has_evaluation'];
		$failed_id      = null === ( $row['failed_evaluation_id'] ?? null ) ? null : $this->public_id( $row['failed_evaluation_id'] );
		if ( null !== $failed_id && ! $has_evaluation ) {
			$this->stored_invalid();
		}
		if ( null === ( $row['submission_id'] ?? null ) ) {
			foreach ( array( 'submission_lock_version', 'submission_state', 'official_revision_public_id', 'official_revision_hash', 'submitted_at_utc' ) as $field ) {
				if ( null !== ( $row[ $field ] ?? null ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					throw Assignment_Evaluation_Failure::because( Assignment_Evaluation_Failure::STORED_DATA_INVALID );
				}
			}
			return array(
				'has_evaluation'          => false,
				'official_revision_hash'  => null,
				'official_revision_id'    => null,
				'submission_id'           => null,
				'submission_lock_version' => null,
				'submission_state'        => null,
				'submitted_at_utc'        => null,
				'target_id'               => $target_id,
				'target_state'            => $target_state,
			);
		}

		$submission_id = $this->public_id( $row['submission_id'] );
		$revision_id   = null === ( $row['official_revision_public_id'] ?? null ) ? null : $this->public_id( $row['official_revision_public_id'] );
		$revision_hash = $row['official_revision_hash'] ?? null;
		if ( null === $revision_id && null !== $revision_hash ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Evaluation_Failure::because( Assignment_Evaluation_Failure::STORED_DATA_INVALID );
		}
		if ( null !== $revision_hash && ( ! is_string( $revision_hash ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $revision_hash ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Evaluation_Failure::because( Assignment_Evaluation_Failure::STORED_DATA_INVALID );
		}
		$submitted_at = $row['submitted_at_utc'] ?? null;
		if ( null !== $submitted_at ) {
			if ( ! is_string( $submitted_at ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Assignment_Evaluation_Failure::because( Assignment_Evaluation_Failure::STORED_DATA_INVALID );
			}
			$this->stored_utc( $submitted_at );
		}

		return array(
			'failed_evaluation_id'    => $failed_id,
			'has_evaluation'          => $has_evaluation,
			'official_revision_hash'  => $revision_hash,
			'official_revision_id'    => $revision_id,
			'submission_id'           => $submission_id,
			'submission_lock_version' => $this->non_negative_integer( $row['submission_lock_version'] ?? null ),
			'submission_state'        => $this->submission_state( $row['submission_state'] ?? null ),
			'submitted_at_utc'        => $submitted_at,
			'target_id'               => $target_id,
			'target_state'            => $target_state,
		);
	}

	/**
	 * Cursor scope.
	 *
	 * @param string $assignment_id Distribution public identifier.
	 * @param string $source_cutoff_utc Source cutoff utc.
	 * @return string
	 */
	private function cursor_scope( string $assignment_id, string $source_cutoff_utc ): string {
		return substr( hash( 'sha256', $assignment_id . '|' . $source_cutoff_utc ), 0, 24 );
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
		if ( ! in_array( $type, array( 'class', 'group' ), true ) ) {
			throw new InvalidArgumentException( 'Assignment evaluation Context type is invalid.' );
		}
		foreach ( array( $id, $revision ) as $value ) {
			if ( '' === $value || strlen( $value ) > 191 || 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@\/-]*$/D', $value ) ) {
				throw new InvalidArgumentException( 'Assignment evaluation Context token is invalid.' );
			}
		}
	}

	/**
	 * Reject unexpected keys before treating an input array as a validated object.
	 *
	 * @param array<array-key,mixed> $value Unvalidated input fields.
	 * @param array<int,string>      $expected Required field names.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function exact_keys( array $value, array $expected ): void {
		if ( array_is_list( $value ) ) {
			throw new InvalidArgumentException( 'Assignment evaluation cursor must be an object.' );
		}
		$actual = array_keys( $value );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected ) {
			throw new InvalidArgumentException( 'Assignment evaluation cursor fields are invalid.' );
		}
	}

	/**
	 * Assignment state.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function assignment_state( mixed $value ): string {
		if ( ! is_string( $value ) || ! in_array( $value, array( 'draft', 'scheduled', 'open', 'closed', 'withdrawn' ), true ) ) {
			$this->stored_invalid();
		}
		return $value;
	}
	/**
	 * Submission state.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function submission_state( mixed $value ): string {
		if ( ! is_string( $value ) || ! in_array( $value, array( 'draft', 'conflict', 'submitted', 'auto_submitted', 'proxy_submitted', 'reopened', 'locked' ), true ) ) {
			$this->stored_invalid();
		}
		return $value;
	}
	/**
	 * Target state.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function target_state( mixed $value ): string {
		if ( ! is_string( $value ) || ! in_array( $value, array( 'active', 'revoked' ), true ) ) {
			$this->stored_invalid();
		}
		return $value;
	}
	/**
	 * Context type.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function context_type( mixed $value ): string {
		if ( ! is_string( $value ) || ! in_array( $value, array( 'class', 'group' ), true ) ) {
			$this->stored_invalid();
		}
		return $value;
	}
	/**
	 * Public id.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function public_id( mixed $value ): string {
		if ( ! is_string( $value ) ) {
			$this->stored_invalid();
		}
		try {
			new Public_Id( $value );
		} catch ( InvalidArgumentException ) {
			$this->stored_invalid();
		}
		return $value;
	}
	/**
	 * Token.
	 *
	 * @param mixed $value Value.
	 * @param int   $maximum Maximum.
	 * @return string
	 */
	private function token( mixed $value, int $maximum ): string {
		if ( ! is_string( $value ) || '' === $value || strlen( $value ) > $maximum || 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@\/-]*$/D', $value ) ) {
			$this->stored_invalid();
		}
		return $value;
	}
	/**
	 * Positive integer.
	 *
	 * @param mixed $value Value.
	 * @return int
	 */
	private function positive_integer( mixed $value ): int {
		if ( is_string( $value ) && 1 === preg_match( '/^[1-9][0-9]*$/D', $value ) ) {
			$value = (int) $value;
		}
		if ( ! is_int( $value ) || $value < 1 ) {
			$this->stored_invalid();
		}
		return $value;
	}
	/**
	 * Non negative integer.
	 *
	 * @param mixed $value Value.
	 * @return int
	 */
	private function non_negative_integer( mixed $value ): int {
		if ( is_string( $value ) && 1 === preg_match( '/^(?:0|[1-9][0-9]*)$/D', $value ) ) {
			$value = (int) $value;
		}
		if ( ! is_int( $value ) || $value < 0 ) {
			$this->stored_invalid();
		}
		return $value;
	}
	/**
	 * Utc.
	 *
	 * @param string $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function utc( string $value ): void {
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value ) ) {
			throw new InvalidArgumentException( 'Assignment evaluation cutoff is invalid.' );
		}
	}
	/**
	 * Stored utc.
	 *
	 * @param string $value Value.
	 * @return void
	 */
	private function stored_utc( string $value ): void {
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value ) ) {
			$this->stored_invalid();
		}
	}
	/**
	 * Stored invalid.
	 *
	 * @return never
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function stored_invalid(): never {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
		throw Assignment_Evaluation_Failure::because( Assignment_Evaluation_Failure::STORED_DATA_INVALID );
	}

	/**
	 * Prepare.
	 *
	 * @param string           $sql Sql.
	 * @param array<int,mixed> $values Values.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function prepare( string $sql, array $values ): string {
		$prepared = $this->database()->prepare( $sql, ...$values );
		if ( ! is_string( $prepared ) || '' === $prepared ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Evaluation_Failure::because( Assignment_Evaluation_Failure::STORAGE_UNAVAILABLE );
		}
		return $prepared;
	}
	/**
	 * Get row.
	 *
	 * @param string $sql Sql.
	 * @return array<string,mixed>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function get_row( string $sql ): ?array {
		$db = $this->database();
		$this->reset_error( $db );
		$row = $db->get_row( $sql, defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A' );
		$this->assert_read( $db );
		if ( null === $row ) {
			return null;
		}
		if ( ! is_array( $row ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Evaluation_Failure::because( Assignment_Evaluation_Failure::STORAGE_UNAVAILABLE );
		}
		return $row;
	}
	/**
	 * Get results.
	 *
	 * @param string $sql Sql.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function get_results( string $sql ): array {
		$db = $this->database();
		$this->reset_error( $db );
		$rows = $db->get_results( $sql, defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A' );
		$this->assert_read( $db );
		if ( ! is_array( $rows ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Evaluation_Failure::because( Assignment_Evaluation_Failure::STORAGE_UNAVAILABLE );
		}
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Assignment_Evaluation_Failure::because( Assignment_Evaluation_Failure::STORAGE_UNAVAILABLE );
			}
		}
		return $rows;
	}
	/**
	 * Database.
	 *
	 * @return object
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function database(): object {
		$db = $this->database ?? ( $GLOBALS['wpdb'] ?? null );
		if ( ! is_object( $db ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Evaluation_Failure::because( Assignment_Evaluation_Failure::STORAGE_UNAVAILABLE );
		}
		foreach ( array( 'prepare', 'get_row', 'get_results' ) as $method ) {
			if ( ! method_exists( $db, $method ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Assignment_Evaluation_Failure::because( Assignment_Evaluation_Failure::STORAGE_UNAVAILABLE );
			}
		}
		return $db;
	}
	/**
	 * Reset error.
	 *
	 * @param object $db Db.
	 * @return void
	 */
	private function reset_error( object $db ): void {
		if ( property_exists( $db, 'last_error' ) ) {
			$db->last_error = '';
		}
	}
	/**
	 * Reject invalid read.
	 *
	 * @param object $db Db.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_read( object $db ): void {
		if ( '' !== trim( (string) ( $db->last_error ?? '' ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Evaluation_Failure::because( Assignment_Evaluation_Failure::STORAGE_UNAVAILABLE );
		}
	}
}
