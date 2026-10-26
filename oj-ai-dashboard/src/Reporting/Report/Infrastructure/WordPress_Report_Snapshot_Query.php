<?php
/**
 * Literal-table Q22 report snapshot query adapter.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\Report\Infrastructure;

use OJAI\Dashboard\Reporting\Report\Application\Report_Snapshot_Query_Failure;
use OJAI\Dashboard\Reporting\Report\Domain\Report_Snapshot_Profile;
use OJAI\Dashboard\Reporting\Report\Domain\Report_Snapshot_Scope;
use OJAI\Dashboard\Reporting\Report\Port\Report_Snapshot_Query_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Literal-table Q22 report snapshot query adapter.
 */
final class WordPress_Report_Snapshot_Query implements Report_Snapshot_Query_Port_Interface {
	private const TABLE_SNAPSHOTS = 'oj_ai_report_snapshots';

	/**
	 * Initialize WordPress report snapshot query dependencies and state.
	 *
	 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
	 * @param object|null    $database Database.
	 */
	public function __construct( private Canonical_Json $canonical, private ?object $database = null ) {
	}

	/**
	 * Scope.
	 *
	 * @param string $snapshot_id Snapshot id.
	 * @return Report_Snapshot_Scope|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function scope( string $snapshot_id ): ?Report_Snapshot_Scope {
		$this->snapshot_id( $snapshot_id );
		$sql = 'SELECT public_id, report_type, subject_type, state, context_type, context_id, context_revision, '
			. 'filter_hash, source_manifest_hash, projection_hash FROM ' . self::TABLE_SNAPSHOTS
			. ' WHERE public_id = %s LIMIT 1';
		$row = $this->row( $this->prepare( $sql, array( $snapshot_id ) ) );
		if ( null === $row ) {
			return null;
		}

		try {
			if (
				$snapshot_id !== ( $row['public_id'] ?? null )
				|| 'worksheet_progress' !== ( $row['report_type'] ?? null )
				|| 'context' !== ( $row['subject_type'] ?? null )
			) {
				throw Report_Snapshot_Query_Failure::because( Report_Snapshot_Query_Failure::STORED_DATA_INVALID );
			}
			$filter_hash     = $this->hash_value( $row['filter_hash'] ?? null );
			$source_hash     = $this->hash_value( $row['source_manifest_hash'] ?? null );
			$projection_hash = $this->hash_value( $row['projection_hash'] ?? null );
			$identity_json   = $this->canonical->encode(
				array(
					'schema_id'            => Report_Snapshot_Profile::IDENTITY_SCHEMA,
					'schema_version'       => Report_Snapshot_Profile::IDENTITY_VERSION,
					'snapshot_id'          => $snapshot_id,
					'filter_hash'          => $filter_hash,
					'source_manifest_hash' => $source_hash,
					'projection_hash'      => $projection_hash,
				)
			);
			return new Report_Snapshot_Scope(
				$snapshot_id,
				$this->string_value( $row['state'] ?? null ),
				hash( 'sha256', $identity_json ),
				$this->string_value( $row['context_type'] ?? null ),
				$this->string_value( $row['context_id'] ?? null ),
				$this->string_value( $row['context_revision'] ?? null )
			);
		} catch ( Report_Snapshot_Query_Failure $failure ) {
			throw $failure;
		} catch ( Throwable ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Report_Snapshot_Query_Failure::because( Report_Snapshot_Query_Failure::STORED_DATA_INVALID );
		}
	}

	/**
	 * Find record.
	 *
	 * @param string $snapshot_id Snapshot id.
	 * @return array|null
	 */
	public function find_record( string $snapshot_id ): ?array {
		$this->snapshot_id( $snapshot_id );
		$sql = 'SELECT public_id, report_type, subject_type, subject_public_id, state, context_type, context_id, context_revision, '
			. 'filter_schema, filter_version, filter_json, filter_hash, source_watermark, source_manifest_schema, '
			. 'source_manifest_version, source_manifest_json, source_manifest_hash, projection_schema, projection_version, '
			. 'projection_json, projection_hash, generated_by_user_id, generated_at_utc, expires_at_utc FROM '
			. self::TABLE_SNAPSHOTS . ' WHERE public_id = %s LIMIT 1';
		return $this->row( $this->prepare( $sql, array( $snapshot_id ) ) );
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
		} catch ( Throwable ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Report_Snapshot_Query_Failure::because( Report_Snapshot_Query_Failure::STORAGE_UNAVAILABLE );
		}
		if ( ! is_string( $prepared ) || '' === $prepared ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Report_Snapshot_Query_Failure::because( Report_Snapshot_Query_Failure::STORAGE_UNAVAILABLE );
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
		} catch ( Throwable ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Report_Snapshot_Query_Failure::because( Report_Snapshot_Query_Failure::STORAGE_UNAVAILABLE );
		}
		$this->assert_ok( $db );
		if ( null === $row ) {
			return null;
		}
		if ( ! is_array( $row ) || array_is_list( $row ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Report_Snapshot_Query_Failure::because( Report_Snapshot_Query_Failure::STORED_DATA_INVALID );
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
			throw Report_Snapshot_Query_Failure::because( Report_Snapshot_Query_Failure::STORAGE_UNAVAILABLE );
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
			throw Report_Snapshot_Query_Failure::because( Report_Snapshot_Query_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Snapshot id.
	 *
	 * @param string $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function snapshot_id( string $value ): void {
		try {
			new Public_Id( $value );
		} catch ( Throwable ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Report_Snapshot_Query_Failure::because( Report_Snapshot_Query_Failure::NOT_FOUND );
		}
	}

	/**
	 * Hash value.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function hash_value( mixed $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Report_Snapshot_Query_Failure::because( Report_Snapshot_Query_Failure::STORED_DATA_INVALID );
		}
		return $value;
	}

	/**
	 * String value.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function string_value( mixed $value ): string {
		if ( ! is_string( $value ) || '' === $value ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Report_Snapshot_Query_Failure::because( Report_Snapshot_Query_Failure::STORED_DATA_INVALID );
		}
		return $value;
	}
}
