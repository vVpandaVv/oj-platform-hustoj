<?php
/**
 * WordPress own submission pdf source query.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Student\Infrastructure;

use InvalidArgumentException;
use OJAI\Dashboard\Reporting\SubmissionPdf\Application\Submission_Pdf_Failure;
use OJAI\Dashboard\Reporting\SubmissionPdf\Port\Own_Submission_Pdf_Source_Query_Port_Interface;
use OJAI\Dashboard\Submission\Assignment\Application\Assignment_Failure;
use OJAI\Dashboard\Submission\Teacher\Port\Submission_Print_Source_Query_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves a learner's current official revision without exposing another learner's root.
 */
final class WordPress_Own_Submission_Pdf_Source_Query implements Own_Submission_Pdf_Source_Query_Port_Interface {
	private const TABLE_ASSIGNMENTS = 'oj_ai_assignments';
	private const TABLE_TARGETS     = 'oj_ai_assignment_targets';
	private const TABLE_SUBMISSIONS = 'oj_ai_submissions';

	/**
	 * Initialize WordPress own submission pdf source query dependencies and state.
	 *
	 * @param Submission_Print_Source_Query_Port_Interface $submission_sources Submission sources.
	 * @param object|null                                  $database Database.
	 */
	public function __construct(
		private Submission_Print_Source_Query_Port_Interface $submission_sources,
		private ?object $database = null
	) {
	}

	/**
	 * Find current official.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $submission_id Submission public identifier.
	 * @return array|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function find_current_official( int $actor_user_id, string $submission_id ): ?array {
		if ( $actor_user_id < 1 ) {
			throw new InvalidArgumentException( 'Own submission PDF actor is invalid.' );
		}
		new Public_Id( $submission_id );

		$sql = 'SELECT s.public_id AS submission_id, s.lock_version AS submission_lock_version, '
			. 's.state AS submission_state, s.official_revision_public_id, '
			. 'a.public_id AS assignment_id, a.state AS assignment_state, a.context_type, a.context_id, a.context_revision, '
			. 't.public_id AS target_id, t.state AS target_state FROM ' . self::TABLE_SUBMISSIONS . ' s '
			. 'INNER JOIN ' . self::TABLE_ASSIGNMENTS . ' a ON a.id = s.assignment_id '
			. 'INNER JOIN ' . self::TABLE_TARGETS . ' t ON t.assignment_id = a.id AND t.learner_user_id = s.learner_user_id '
			. 'WHERE s.public_id = %s AND s.learner_user_id = %d LIMIT 1';
		$row = $this->row( $this->prepare( $sql, array( $submission_id, $actor_user_id ) ) );
		if ( null === $row ) {
			return null;
		}

		$stored_submission_id = $this->public_id( $row['submission_id'] ?? null );
		if ( ! hash_equals( $submission_id, $stored_submission_id ) ) {
			$this->stored_invalid();
		}
		$assignment_state = $this->assignment_state( $row['assignment_state'] ?? null );
		$target_state     = $this->target_state( $row['target_state'] ?? null );
		if ( ! in_array( $assignment_state, array( 'open', 'closed' ), true ) || 'active' !== $target_state ) {
			return null;
		}
		$official_revision_id = $this->optional_public_id( $row['official_revision_public_id'] ?? null );
		if ( null === $official_revision_id ) {
			return null;
		}

		try {
			$submission = $this->submission_sources->find_official_revision( $official_revision_id );
		} catch ( Assignment_Failure $failure ) {
			throw Submission_Pdf_Failure::because(
				Assignment_Failure::STORAGE_UNAVAILABLE === $failure->reason()
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					? Submission_Pdf_Failure::STORAGE_UNAVAILABLE
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					: Submission_Pdf_Failure::STORED_DATA_INVALID
			);
		}
		if ( null === $submission ) {
			$this->stored_invalid();
		}

		$official                = $this->map( $submission['official_revision'] ?? null );
		$context                 = $this->map( $submission['context'] ?? null );
		$target                  = $this->map( $submission['target'] ?? null );
		$source                  = $this->map( $submission['source'] ?? null );
		$assignment_id           = $this->public_id( $row['assignment_id'] ?? null );
		$target_id               = $this->public_id( $row['target_id'] ?? null );
		$submission_state        = $this->submission_state( $row['submission_state'] ?? null );
		$submission_lock_version = $this->non_negative_integer( $row['submission_lock_version'] ?? null );
		$context_type            = $this->context_type( $row['context_type'] ?? null );
		$context_id              = $this->token( $row['context_id'] ?? null );
		$context_revision        = $this->token( $row['context_revision'] ?? null );
		if (
			! hash_equals( $stored_submission_id, (string) ( $submission['id'] ?? '' ) )
			|| ! hash_equals( $assignment_id, (string) ( $submission['assignment_id'] ?? '' ) )
			|| ! hash_equals( $assignment_state, (string) ( $submission['assignment_state'] ?? '' ) )
			|| ! hash_equals( $submission_state, (string) ( $submission['state'] ?? '' ) )
			|| $submission_lock_version !== ( $submission['lock_version'] ?? null )
			|| ! hash_equals( $official_revision_id, (string) ( $submission['official_revision_id'] ?? '' ) )
			|| ! hash_equals( $official_revision_id, (string) ( $official['id'] ?? '' ) )
			|| ! hash_equals( $context_type, (string) ( $context['type'] ?? '' ) )
			|| ! hash_equals( $context_id, (string) ( $context['id'] ?? '' ) )
			|| ! hash_equals( $context_revision, (string) ( $context['revision'] ?? '' ) )
			|| ! hash_equals( $target_id, (string) ( $target['id'] ?? '' ) )
			|| ! hash_equals( $target_state, (string) ( $target['state'] ?? '' ) )
			|| $actor_user_id !== ( $target['learner_user_id'] ?? null )
		) {
			$this->stored_invalid();
		}

		return array(
			'assignment_id'           => $assignment_id,
			'assignment_state'        => $assignment_state,
			'attachment_set_hash'     => $this->hash_value( $official['attachment_set_hash'] ?? null ),
			'context_type'            => $context_type,
			'context_id'              => $context_id,
			'context_revision'        => $context_revision,
			'official_revision_id'    => $official_revision_id,
			'official_revision_hash'  => $this->hash_value( $official['content_hash'] ?? null ),
			'publication_id'          => $this->public_id( $source['worksheet_publication_id'] ?? null ),
			'publication_hash'        => $this->hash_value( $source['publication_hash'] ?? null ),
			'submission_id'           => $stored_submission_id,
			'submission_lock_version' => $submission_lock_version,
			'submission_state'        => $submission_state,
			'target_id'               => $target_id,
			'target_state'            => $target_state,
		);
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
			throw Submission_Pdf_Failure::because( Submission_Pdf_Failure::STORAGE_UNAVAILABLE );
		}
		return $prepared;
	}

	/**
	 * Row.
	 *
	 * @param string $sql Sql.
	 * @return array<string,mixed>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function row( string $sql ): ?array {
		$database = $this->database();
		if ( property_exists( $database, 'last_error' ) ) {
			$database->last_error = '';
		}
		$row = $database->get_row( $sql, defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A' );
		if ( '' !== trim( (string) ( $database->last_error ?? '' ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Pdf_Failure::because( Submission_Pdf_Failure::STORAGE_UNAVAILABLE );
		}
		if ( null === $row ) {
			return null;
		}
		if ( ! is_array( $row ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Pdf_Failure::because( Submission_Pdf_Failure::STORAGE_UNAVAILABLE );
		}
		return $row;
	}

	/**
	 * Database.
	 *
	 * @return object
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function database(): object {
		$database = $this->database ?? ( $GLOBALS['wpdb'] ?? null );
		if ( ! is_object( $database ) || ! method_exists( $database, 'prepare' ) || ! method_exists( $database, 'get_row' ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Pdf_Failure::because( Submission_Pdf_Failure::STORAGE_UNAVAILABLE );
		}
		return $database;
	}

	/**
	 * Map WordPress own submission pdf source query.
	 *
	 * @param mixed $value Value.
	 * @return array<string,mixed>
	 */
	private function map( mixed $value ): array {
		if ( ! is_array( $value ) || array_is_list( $value ) ) {
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
		new Public_Id( $value );
		return $value;
	}

	/**
	 * Optional public id.
	 *
	 * @param mixed $value Value.
	 * @return string|null
	 */
	private function optional_public_id( mixed $value ): ?string {
		return null === $value ? null : $this->public_id( $value );
	}

	/**
	 * Hash value.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function hash_value( mixed $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $value ) ) {
			$this->stored_invalid();
		}
		return $value;
	}

	/**
	 * Token.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function token( mixed $value ): string {
		if ( ! is_string( $value ) || '' === $value || strlen( $value ) > 191 || 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@\/-]*$/D', $value ) ) {
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
	 * Assignment state.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function assignment_state( mixed $value ): string {
		if ( ! is_string( $value ) || ! in_array( $value, array( 'draft', 'open', 'closed', 'withdrawn' ), true ) ) {
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
		if ( ! is_string( $value ) || ! in_array( $value, array( 'active', 'withdrawn' ), true ) ) {
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
		$allowed = array( 'draft', 'conflict', 'submitted', 'auto_submitted', 'proxy_submitted', 'reopened', 'locked' );
		if ( ! is_string( $value ) || ! in_array( $value, $allowed, true ) ) {
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
		if ( is_int( $value ) && $value >= 0 ) {
			return $value;
		}
		if ( is_string( $value ) && 1 === preg_match( '/^(0|[1-9][0-9]*)$/D', $value ) ) {
			return (int) $value;
		}
		$this->stored_invalid();
	}

	/**
	 * Stored invalid.
	 *
	 * @return never
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function stored_invalid(): never {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
		throw Submission_Pdf_Failure::because( Submission_Pdf_Failure::STORED_DATA_INVALID );
	}
}
