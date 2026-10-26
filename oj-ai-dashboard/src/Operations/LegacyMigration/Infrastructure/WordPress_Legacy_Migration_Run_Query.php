<?php
/**
 * WordPress legacy migration run query.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\LegacyMigration\Infrastructure;

use OJAI\Dashboard\Operations\LegacyMigration\Application\Legacy_Migration_Failure;
use OJAI\Dashboard\Operations\LegacyMigration\Port\Legacy_Migration_Run_Query_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Literal-table, single-statement snapshot adapter for Q29.
 */
final class WordPress_Legacy_Migration_Run_Query implements Legacy_Migration_Run_Query_Port_Interface {
	private const TABLE_RUNS  = 'oj_ai_import_runs';
	private const TABLE_ITEMS = 'oj_ai_import_items';

	/**
	 * Initialize WordPress legacy migration run query dependencies and state.
	 *
	 * @param object|null $database Database.
	 */
	public function __construct( private ?object $database = null ) {
	}

	/**
	 * Find record.
	 *
	 * @param string $run_id Run id.
	 * @return array|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function find_record( string $run_id ): ?array {
		if ( ! Public_Id::is_valid( $run_id ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::RUN_NOT_FOUND );
		}
		$sql = 'SELECT r.id AS run_database_id, r.public_id, r.created_at_utc, r.updated_at_utc, '
			. 'r.source_kind, r.source_version, r.source_fingerprint, r.state, r.preview_schema, '
			. 'r.preview_version, r.preview_json, r.preview_hash, r.approved_at_utc, r.checkpoint_schema, '
			. 'r.checkpoint_version, r.checkpoint_json, r.checkpoint_hash, r.item_total, r.success_total, '
			. 'r.failure_total, r.skipped_total, r.error_code, '
			. '(SELECT COUNT(*) FROM ' . self::TABLE_ITEMS . ' i0 WHERE i0.import_run_id = r.id) AS observed_item_total, '
			. '(SELECT COUNT(*) FROM ' . self::TABLE_ITEMS . " i1 WHERE i1.import_run_id = r.id AND i1.state = 'success') AS observed_success_total, "
			. '(SELECT COUNT(*) FROM ' . self::TABLE_ITEMS . " i2 WHERE i2.import_run_id = r.id AND i2.state = 'failure') AS observed_failure_total, "
			. '(SELECT COUNT(*) FROM ' . self::TABLE_ITEMS . " i3 WHERE i3.import_run_id = r.id AND i3.state = 'skipped') AS observed_skipped_total "
			. 'FROM ' . self::TABLE_RUNS . ' r WHERE r.public_id = %s LIMIT 1';
		return $this->row( $this->prepare( $sql, array( $run_id ) ) );
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
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE, $error );
		}
		if ( ! is_string( $prepared ) || '' === $prepared ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
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
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE, $error );
		}
		$this->assert_ok( $db );
		if ( null === $row ) {
			return null;
		}
		if ( ! is_array( $row ) || array_is_list( $row ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::RUN_DATA_INVALID );
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
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
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
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
	}
}
