<?php
/**
 * WordPress own worksheet pdf source query.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Student\Infrastructure;

use InvalidArgumentException;
use OJAI\Dashboard\Reporting\WorksheetPdf\Application\Worksheet_Pdf_Failure;
use OJAI\Dashboard\Reporting\WorksheetPdf\Port\Own_Worksheet_Pdf_Source_Query_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Literal-table, self-only assignment binding query used before and during C51.
 */
final class WordPress_Own_Worksheet_Pdf_Source_Query implements Own_Worksheet_Pdf_Source_Query_Port_Interface {
	private const TABLE_ASSIGNMENTS = 'oj_ai_assignments';
	private const TABLE_TARGETS     = 'oj_ai_assignment_targets';

	/**
	 * Initialize WordPress own worksheet pdf source query dependencies and state.
	 *
	 * @param object|null $database Database.
	 */
	public function __construct( private ?object $database = null ) {
	}

	/**
	 * Find available.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $assignment_id Distribution public identifier.
	 * @param string $now_utc Now utc.
	 * @return array|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function find_available( int $actor_user_id, string $assignment_id, string $now_utc ): ?array {
		if ( $actor_user_id < 1 ) {
			throw new InvalidArgumentException( 'Own worksheet PDF actor is invalid.' );
		}
		new Public_Id( $assignment_id );
		$this->utc( $now_utc );

		$sql = 'SELECT a.public_id AS assignment_id, a.lock_version AS assignment_lock_version, '
			. 'a.context_type, a.context_id, a.context_revision, a.state AS assignment_state, '
			. 'a.worksheet_publication_public_id, a.publication_hash, a.opens_at_utc, a.due_at_utc, a.closes_at_utc, '
			. 't.public_id AS target_id, t.state AS target_state FROM ' . self::TABLE_ASSIGNMENTS . ' a '
			. 'INNER JOIN ' . self::TABLE_TARGETS . ' t ON t.assignment_id = a.id '
			. 'WHERE a.public_id = %s AND t.learner_user_id = %d LIMIT 1';
		$row = $this->row( $this->prepare( $sql, array( $assignment_id, $actor_user_id ) ) );
		if ( null === $row ) {
			return null;
		}

		$stored_assignment_id = $this->public_id( $row['assignment_id'] ?? null );
		if ( ! hash_equals( $assignment_id, $stored_assignment_id ) ) {
			$this->stored_invalid();
		}
		$assignment_state = is_string( $row['assignment_state'] ?? null ) ? $row['assignment_state'] : '';
		$target_state     = is_string( $row['target_state'] ?? null ) ? $row['target_state'] : '';
		if ( ! in_array( $assignment_state, array( 'open', 'closed' ), true ) || 'active' !== $target_state ) {
			return null;
		}

		$opens_at  = $this->optional_utc( $row['opens_at_utc'] ?? null );
		$due_at    = $this->required_utc( $row['due_at_utc'] ?? null );
		$closes_at = $this->required_utc( $row['closes_at_utc'] ?? null );
		if ( ( null !== $opens_at && $opens_at > $due_at ) || $due_at > $closes_at ) {
			$this->stored_invalid();
		}
		if ( 'open' === $assignment_state && ( ( null !== $opens_at && $now_utc < $opens_at ) || $now_utc >= $closes_at ) ) {
			return null;
		}

		return array(
			'assignment_id'            => $stored_assignment_id,
			'assignment_state'         => $assignment_state,
			'assignment_lock_version'  => $this->non_negative_integer( $row['assignment_lock_version'] ?? null ),
			'context_type'             => $this->context_type( $row['context_type'] ?? null ),
			'context_id'               => $this->token( $row['context_id'] ?? null ),
			'binding_context_revision' => $this->token( $row['context_revision'] ?? null ),
			'publication_id'           => $this->public_id( $row['worksheet_publication_public_id'] ?? null ),
			'publication_hash'         => $this->hash_value( $row['publication_hash'] ?? null ),
			'target_id'                => $this->public_id( $row['target_id'] ?? null ),
			'target_state'             => $target_state,
		);
	}

	/**
	 * Prepare.
	 *
	 * @param string $sql Sql.
	 * @param array  $values Values.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function prepare( string $sql, array $values ): string {
		$prepared = $this->database()->prepare( $sql, ...$values );
		if ( ! is_string( $prepared ) || '' === $prepared ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Pdf_Failure::because( Worksheet_Pdf_Failure::STORAGE_UNAVAILABLE );
		}
		return $prepared;
	}

	/**
	 * Row.
	 *
	 * @param string $sql Sql.
	 * @return array|null
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
			throw Worksheet_Pdf_Failure::because( Worksheet_Pdf_Failure::STORAGE_UNAVAILABLE );
		}
		if ( null === $row ) {
			return null;
		}
		if ( ! is_array( $row ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Pdf_Failure::because( Worksheet_Pdf_Failure::STORAGE_UNAVAILABLE );
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
			throw Worksheet_Pdf_Failure::because( Worksheet_Pdf_Failure::STORAGE_UNAVAILABLE );
		}
		return $database;
	}

	/**
	 * Public id.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function public_id( $value ): string {
		if ( ! is_string( $value ) ) {
			$this->stored_invalid();
		}
		new Public_Id( $value );
		return $value;
	}

	/**
	 * Hash value.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function hash_value( $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $value ) ) {
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
	private function context_type( $value ): string {
		if ( ! is_string( $value ) || ! in_array( $value, array( 'class', 'group' ), true ) ) {
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
	private function token( $value ): string {
		if ( ! is_string( $value ) || '' === $value || strlen( $value ) > 191 || 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@\/-]*$/D', $value ) ) {
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
	private function non_negative_integer( $value ): int {
		if ( is_int( $value ) && $value >= 0 ) {
			return $value;
		}
		if ( is_string( $value ) && 1 === preg_match( '/^(0|[1-9][0-9]*)$/D', $value ) ) {
			return (int) $value;
		}
		$this->stored_invalid();
	}

	/**
	 * Optional utc.
	 *
	 * @param mixed $value Value.
	 * @return string|null
	 */
	private function optional_utc( $value ): ?string {
		if ( null === $value ) {
			return null;
		}
		return $this->required_utc( $value );
	}

	/**
	 * Required utc.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function required_utc( $value ): string {
		if ( ! is_string( $value ) ) {
			$this->stored_invalid();
		}
		$this->utc( $value );
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
			throw new InvalidArgumentException( 'Own worksheet PDF UTC value is invalid.' );
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
		throw Worksheet_Pdf_Failure::because( Worksheet_Pdf_Failure::STORED_DATA_INVALID );
	}
}
