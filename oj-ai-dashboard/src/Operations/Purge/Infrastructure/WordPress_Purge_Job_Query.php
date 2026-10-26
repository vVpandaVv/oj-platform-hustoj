<?php
/**
 * WordPress purge job query.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\Purge\Infrastructure;

use OJAI\Dashboard\Operations\Purge\Application\Purge_Failure;
use OJAI\Dashboard\Operations\Purge\Port\Purge_Job_Query_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Literal-table, single-statement snapshot adapter for Q36.
 */
final class WordPress_Purge_Job_Query implements Purge_Job_Query_Port_Interface {
	private const TABLE_JOBS          = 'oj_ai_purge_jobs';
	private const TABLE_PRIVATE_FILES = 'oj_ai_private_files';

	/**
	 * Initialize WordPress purge job query dependencies and state.
	 *
	 * @param object|null $database Database.
	 */
	public function __construct( private ?object $database = null ) {
	}

	/**
	 * Find record.
	 *
	 * @param string $job_id Job id.
	 * @return array|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function find_record( string $job_id ): ?array {
		if ( ! Public_Id::is_valid( $job_id ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Purge_Failure::because( Purge_Failure::JOB_NOT_FOUND );
		}
		$sql = 'SELECT j.id AS job_database_id, j.public_id, j.lock_version, j.created_at_utc, j.updated_at_utc, '
			. 'j.scope_type, j.scope_public_id, j.state, j.preview_schema, j.preview_version, j.preview_json, '
			. 'j.preview_hash, j.approved_at_utc, j.checkpoint_schema, j.checkpoint_version, j.checkpoint_json, '
			. 'j.checkpoint_hash, j.completed_at_utc, j.error_code, '
			. '(SELECT COUNT(*) FROM ' . self::TABLE_PRIVATE_FILES . ' f WHERE f.purged_at_utc IS NULL '
			. 'AND f.legal_hold_until_utc IS NOT NULL) AS current_legal_hold_count '
			. 'FROM ' . self::TABLE_JOBS . ' j WHERE j.public_id = %s LIMIT 1';
		return $this->row( $this->prepare( $sql, array( $job_id ) ) );
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
		try {
			$prepared = $this->db()->prepare( $sql, ...$values );
		} catch ( Throwable $error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Purge_Failure::because( Purge_Failure::STORAGE_UNAVAILABLE, $error );
		}
		if ( ! is_string( $prepared ) || '' === $prepared ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Purge_Failure::because( Purge_Failure::STORAGE_UNAVAILABLE );
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
		$db = $this->db();
		$this->reset( $db );
		try {
			$row = $db->get_row( $sql, defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A' );
		} catch ( Throwable $error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Purge_Failure::because( Purge_Failure::STORAGE_UNAVAILABLE, $error );
		}
		$this->assert_ok( $db );
		if ( null === $row ) {
			return null;
		}
		if ( ! is_array( $row ) || array_is_list( $row ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Purge_Failure::because( Purge_Failure::JOB_DATA_INVALID );
		}
		return $row;
	}

	/**
	 * Db.
	 *
	 * @return object
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function db(): object {
		$db = $this->database ?? ( $GLOBALS['wpdb'] ?? null );
		if ( ! is_object( $db ) || ! method_exists( $db, 'prepare' ) || ! method_exists( $db, 'get_row' ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Purge_Failure::because( Purge_Failure::STORAGE_UNAVAILABLE );
		}
		return $db;
	}

	/**
	 * Reset.
	 *
	 * @param object $db Db.
	 * @return void
	 */
	private function reset( object $db ): void {
		if ( property_exists( $db, 'last_error' ) ) {
			$db->last_error = '';
		}
	}

	/**
	 * Reject invalid ok.
	 *
	 * @param object $db Db.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_ok( object $db ): void {
		if ( '' !== trim( (string) ( $db->last_error ?? '' ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Purge_Failure::because( Purge_Failure::STORAGE_UNAVAILABLE );
		}
	}
}
