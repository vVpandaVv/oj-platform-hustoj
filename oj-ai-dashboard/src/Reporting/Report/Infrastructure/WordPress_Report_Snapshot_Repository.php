<?php
/**
 * Literal-table C45 report snapshot repository.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\Report\Infrastructure;

use InvalidArgumentException;
use JsonException;
use OJAI\Dashboard\Reporting\Report\Application\Create_Report_Snapshot;
use OJAI\Dashboard\Reporting\Report\Application\Create_Report_Snapshot_Result;
use OJAI\Dashboard\Reporting\Report\Application\Report_Snapshot_Failure;
use OJAI\Dashboard\Reporting\Report\Domain\Report_Filter;
use OJAI\Dashboard\Reporting\Report\Domain\Report_Snapshot_Profile;
use OJAI\Dashboard\Reporting\Report\Port\Report_Snapshot_Repository_Port_Interface;
use OJAI\Dashboard\Security\Policy\Context_Scope;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Literal-table C45 report snapshot repository.
 */
final class WordPress_Report_Snapshot_Repository implements Report_Snapshot_Repository_Port_Interface {
	private const TABLE_SNAPSHOTS = 'oj_ai_report_snapshots';
	private const TABLE_AUDIT     = 'oj_ai_audit_events';
	private const TABLE_OUTBOX    = 'oj_ai_outbox_events';
	private const CREATED_EVENT   = 'report.snapshot.created';
	private const OUTBOX_SCHEMA   = 'oj_ai.report_snapshot_event';
	private const AUDIT_SCHEMA    = 'oj_ai.report_snapshot_audit';

	/**
	 * Initialize WordPress report snapshot repository dependencies and state.
	 *
	 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
	 * @param object|null    $database Database.
	 */
	public function __construct( private Canonical_Json $canonical, private ?object $database = null ) {
	}

	/**
	 * Find command receipt.
	 *
	 * @param string $command_storage_key Command storage key.
	 * @param string $request_digest Request digest.
	 * @return Create_Report_Snapshot_Result|null
	 */
	public function find_command_receipt( string $command_storage_key, string $request_digest ): ?Create_Report_Snapshot_Result {
		$this->storage_key( $command_storage_key );
		$this->hash_value( $request_digest );
		return $this->command_receipt( $command_storage_key, $request_digest, false );
	}

	/**
	 * Create WordPress report snapshot repository.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Create_Report_Snapshot_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function create( array $record ): Create_Report_Snapshot_Result {
		$this->assert_record( $record );
		$receipt = $this->command_receipt( $record['command_storage_key'], $record['request_digest'], false );
		if ( $receipt instanceof Create_Report_Snapshot_Result ) {
			return $receipt;
		}

		$transaction = false;
		try {
			$this->query_or_fail( 'START TRANSACTION' );
			$transaction = true;
			$receipt     = $this->command_receipt( $record['command_storage_key'], $record['request_digest'], true );
			if ( $receipt instanceof Create_Report_Snapshot_Result ) {
				$this->query_or_fail( 'COMMIT' );
				$transaction = false;
				return $receipt;
			}

			$this->insert_or_fail(
				self::TABLE_SNAPSHOTS,
				array(
					'public_id'               => $record['snapshot_public_id'],
					'lock_version'            => 0,
					'created_by_user_id'      => $record['actor_user_id'],
					'created_at_utc'          => $record['generated_at_utc'],
					'updated_by_user_id'      => $record['actor_user_id'],
					'updated_at_utc'          => $record['generated_at_utc'],
					'context_type'            => $record['context_type'],
					'context_id'              => $record['context_id'],
					'context_revision'        => $record['context_revision'],
					'report_type'             => 'worksheet_progress',
					'subject_type'            => 'context',
					'subject_public_id'       => null,
					'state'                   => 'ready',
					'filter_schema'           => Report_Filter::SCHEMA_ID,
					'filter_version'          => Report_Snapshot_Profile::FILTER_DATABASE_VERSION,
					'filter_json'             => $record['filter_json'],
					'filter_hash'             => $record['filter_hash'],
					'source_watermark'        => $record['source_manifest_hash'],
					'source_manifest_schema'  => Report_Snapshot_Profile::SOURCE_MANIFEST_SCHEMA,
					'source_manifest_version' => Report_Snapshot_Profile::SOURCE_MANIFEST_DATABASE_VERSION,
					'source_manifest_json'    => $record['source_manifest_json'],
					'source_manifest_hash'    => $record['source_manifest_hash'],
					'projection_schema'       => Report_Snapshot_Profile::PROJECTION_SCHEMA,
					'projection_version'      => Report_Snapshot_Profile::PROJECTION_DATABASE_VERSION,
					'projection_json'         => $record['projection_json'],
					'projection_hash'         => $record['projection_hash'],
					'generated_by_user_id'    => $record['actor_user_id'],
					'generated_at_utc'        => $record['generated_at_utc'],
					'expires_at_utc'          => null,
				)
			);

			$result = $this->result_from_record( $record, false );
			$this->insert_audit( $record );
			$this->insert_outbox( $record, $result );
			$this->query_or_fail( 'COMMIT' );
			$transaction = false;
			return $result;
		} catch ( Report_Snapshot_Failure $failure ) {
			if ( $transaction ) {
				$this->rollback();
			}
			if ( Report_Snapshot_Failure::STORAGE_UNAVAILABLE === $failure->reason() ) {
				$recovered = $this->recover_receipt( $record );
				if ( $recovered instanceof Create_Report_Snapshot_Result ) {
					return $recovered;
				}
			}
			throw $failure;
		} catch ( Throwable ) {
			if ( $transaction ) {
				$this->rollback();
			}
			$recovered = $this->recover_receipt( $record );
			if ( $recovered instanceof Create_Report_Snapshot_Result ) {
				return $recovered;
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Report_Snapshot_Failure::because( Report_Snapshot_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Command receipt.
	 *
	 * @param string $key Key.
	 * @param string $digest Digest.
	 * @param bool   $for_update For update.
	 * @return Create_Report_Snapshot_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function command_receipt( string $key, string $digest, bool $for_update ): ?Create_Report_Snapshot_Result {
		$sql = 'SELECT aggregate_public_id, event_type, payload_schema, payload_version, payload_json, payload_hash FROM '
			. self::TABLE_OUTBOX . ' WHERE idempotency_key = %s LIMIT 1';
		if ( $for_update ) {
			$sql .= ' FOR UPDATE';
		}
		$row = $this->row( $this->prepare( $sql, array( $key ) ) );
		if ( null === $row ) {
			return null;
		}
		if (
			self::CREATED_EVENT !== ( $row['event_type'] ?? null )
			|| self::OUTBOX_SCHEMA !== ( $row['payload_schema'] ?? null )
			|| 1 !== $this->positive_integer( $row['payload_version'] ?? null )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Report_Snapshot_Failure::because( Report_Snapshot_Failure::STORAGE_UNAVAILABLE );
		}
		$payload = $this->decode_stored( (string) ( $row['payload_json'] ?? '' ), (string) ( $row['payload_hash'] ?? '' ) );
		if ( ! hash_equals( $digest, (string) ( $payload['request_digest'] ?? '' ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Report_Snapshot_Failure::because( Report_Snapshot_Failure::IDEMPOTENCY_CONFLICT );
		}
		$receipt = $payload['receipt'] ?? null;
		if ( ! is_array( $receipt ) || array_is_list( $receipt ) || ! hash_equals( (string) ( $row['aggregate_public_id'] ?? '' ), (string) ( $receipt['snapshot_id'] ?? '' ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Report_Snapshot_Failure::because( Report_Snapshot_Failure::STORAGE_UNAVAILABLE );
		}
		$actual   = array_keys( $receipt );
		$expected = array(
			'filter_hash',
			'generated_at_utc',
			'projection_hash',
			'row_count',
			'snapshot_hash',
			'snapshot_id',
			'source_manifest_hash',
			'state',
		);
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Report_Snapshot_Failure::because( Report_Snapshot_Failure::STORAGE_UNAVAILABLE );
		}
		try {
			return $this->result_from_receipt( $receipt, true );
		} catch ( Throwable ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Report_Snapshot_Failure::because( Report_Snapshot_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Insert audit.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return void
	 */
	private function insert_audit( array $record ): void {
		$previous = $this->scalar( 'SELECT event_hash FROM ' . self::TABLE_AUDIT . ' ORDER BY id DESC LIMIT 1 FOR UPDATE' );
		if ( null !== $previous ) {
			$this->hash_value( (string) $previous );
		}
		$metadata_json = $this->canonical->encode(
			array(
				'command_id'             => $record['command_id'],
				'context_actor_relation' => $record['context_actor_relation'],
				'filter_hash'            => $record['filter_hash'],
				'projection_hash'        => $record['projection_hash'],
				'request_hash'           => $record['request_hash'],
				'row_count'              => $record['row_count'],
				'snapshot_hash'          => $record['snapshot_hash'],
				'source_manifest_hash'   => $record['source_manifest_hash'],
			)
		);
		$metadata_hash = hash( 'sha256', $metadata_json );
		$event_hash    = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'action_key'          => self::CREATED_EVENT,
					'actor_user_id'       => $record['actor_user_id'],
					'after_digest'        => $record['snapshot_hash'],
					'before_digest'       => null,
					'correlation_id'      => $record['correlation_id'],
					'metadata_hash'       => $metadata_hash,
					'object_public_id'    => $record['snapshot_public_id'],
					'object_type'         => 'report_snapshot',
					'occurred_at_utc'     => $record['generated_at_utc'],
					'previous_event_hash' => $previous,
				)
			)
		);
		$this->insert_or_fail(
			self::TABLE_AUDIT,
			array(
				'public_id'           => $record['audit_public_id'],
				'occurred_at_utc'     => $record['generated_at_utc'],
				'actor_user_id'       => $record['actor_user_id'],
				'action_key'          => self::CREATED_EVENT,
				'object_type'         => 'report_snapshot',
				'object_public_id'    => $record['snapshot_public_id'],
				'context_type'        => $record['context_type'],
				'context_id'          => $record['context_id'],
				'context_revision'    => $record['context_revision'],
				'correlation_id'      => $record['correlation_id'],
				'before_digest'       => null,
				'after_digest'        => $record['snapshot_hash'],
				'metadata_schema'     => self::AUDIT_SCHEMA,
				'metadata_version'    => 1,
				'metadata_json'       => $metadata_json,
				'metadata_hash'       => $metadata_hash,
				'previous_event_hash' => $previous,
				'event_hash'          => $event_hash,
			)
		);
	}

	/**
	 * Insert outbox.
	 *
	 * @param array<string,mixed>           $record Record.
	 * @param Create_Report_Snapshot_Result $result Result.
	 * @return void
	 */
	private function insert_outbox( array $record, Create_Report_Snapshot_Result $result ): void {
		$payload_json = $this->canonical->encode(
			array(
				'command_id'     => $record['command_id'],
				'event'          => self::CREATED_EVENT,
				'request_digest' => $record['request_digest'],
				'receipt'        => array(
					'snapshot_id'          => $result->snapshot_id(),
					'state'                => $result->state(),
					'snapshot_hash'        => $result->snapshot_hash(),
					'filter_hash'          => $result->filter_hash(),
					'source_manifest_hash' => $result->source_manifest_hash(),
					'projection_hash'      => $result->projection_hash(),
					'row_count'            => $result->row_count(),
					'generated_at_utc'     => $result->generated_at_utc(),
				),
			)
		);
		$this->insert_or_fail(
			self::TABLE_OUTBOX,
			array(
				'public_id'            => $record['outbox_public_id'],
				'lock_version'         => 0,
				'created_by_user_id'   => $record['actor_user_id'],
				'created_at_utc'       => $record['generated_at_utc'],
				'updated_by_user_id'   => $record['actor_user_id'],
				'updated_at_utc'       => $record['generated_at_utc'],
				'aggregate_type'       => 'report_snapshot',
				'aggregate_public_id'  => $result->snapshot_id(),
				'event_type'           => self::CREATED_EVENT,
				'idempotency_key'      => $record['command_storage_key'],
				'payload_schema'       => self::OUTBOX_SCHEMA,
				'payload_version'      => 1,
				'payload_json'         => $payload_json,
				'payload_hash'         => hash( 'sha256', $payload_json ),
				'available_at_utc'     => $record['generated_at_utc'],
				'published_at_utc'     => null,
				'lease_token'          => null,
				'lease_expires_at_utc' => null,
				'attempt_count'        => 0,
				'last_error_code'      => null,
			)
		);
	}

	/**
	 * Reject invalid record.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_record( array $record ): void {
		$expected = array(
			'actor_user_id',
			'audit_public_id',
			'command_id',
			'command_storage_key',
			'context_actor_relation',
			'context_id',
			'context_revision',
			'context_type',
			'correlation_id',
			'filter_hash',
			'filter_json',
			'generated_at_utc',
			'outbox_public_id',
			'projection_hash',
			'projection_json',
			'request_digest',
			'request_hash',
			'row_count',
			'snapshot_hash',
			'snapshot_public_id',
			'source_manifest_hash',
			'source_manifest_json',
		);
		$actual   = array_keys( $record );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected || ! is_int( $record['actor_user_id'] ) || $record['actor_user_id'] < 1 || ! is_int( $record['row_count'] ) || $record['row_count'] < 0 || $record['row_count'] > Create_Report_Snapshot::MAXIMUM_ROWS ) {
			throw new InvalidArgumentException( 'Report snapshot persistence record is invalid.' );
		}
		foreach ( array( 'audit_public_id', 'command_id', 'outbox_public_id', 'snapshot_public_id' ) as $key ) {
			new Public_Id( (string) $record[ $key ] );
		}
		foreach ( array( 'filter_hash', 'projection_hash', 'request_digest', 'request_hash', 'snapshot_hash', 'source_manifest_hash' ) as $key ) {
			$this->hash_value( (string) $record[ $key ] );
		}
		$this->storage_key( (string) $record['command_storage_key'] );
		$this->utc( (string) $record['generated_at_utc'] );
		$this->safe_token( (string) $record['correlation_id'], 1, 100 );
		$this->safe_token( (string) $record['context_actor_relation'], 1, 80 );
		new Context_Scope( (string) $record['context_type'], (string) $record['context_id'], Context_Scope::OPERATION_REPORT, null, (string) $record['context_revision'] );

		$filter     = $this->decode_input( (string) $record['filter_json'], (string) $record['filter_hash'] );
		$manifest   = $this->decode_input( (string) $record['source_manifest_json'], (string) $record['source_manifest_hash'] );
		$projection = $this->decode_input( (string) $record['projection_json'], (string) $record['projection_hash'] );
		if (
			Report_Filter::SCHEMA_ID !== ( $filter['schema_id'] ?? null ) || Report_Filter::VERSION !== ( $filter['schema_version'] ?? null )
			|| Report_Snapshot_Profile::SOURCE_MANIFEST_SCHEMA !== ( $manifest['schema_id'] ?? null ) || Report_Snapshot_Profile::SOURCE_MANIFEST_VERSION !== ( $manifest['schema_version'] ?? null )
			|| Report_Snapshot_Profile::PROJECTION_SCHEMA !== ( $projection['schema_id'] ?? null ) || Report_Snapshot_Profile::PROJECTION_VERSION !== ( $projection['schema_version'] ?? null )
			|| ! is_array( $manifest['entries'] ?? null ) || ! array_is_list( $manifest['entries'] ) || count( $manifest['entries'] ) !== $record['row_count']
			|| ! is_array( $projection['items'] ?? null ) || ! array_is_list( $projection['items'] ) || count( $projection['items'] ) !== $record['row_count']
			|| ! hash_equals( (string) ( $projection['filter_hash'] ?? '' ), (string) $record['filter_hash'] )
			|| ! hash_equals( (string) ( $projection['source_manifest_hash'] ?? '' ), (string) $record['source_manifest_hash'] )
		) {
			throw new InvalidArgumentException( 'Report snapshot profile linkage is invalid.' );
		}
		$identity_json = $this->canonical->encode(
			array(
				'schema_id'            => Report_Snapshot_Profile::IDENTITY_SCHEMA,
				'schema_version'       => Report_Snapshot_Profile::IDENTITY_VERSION,
				'snapshot_id'          => $record['snapshot_public_id'],
				'filter_hash'          => $record['filter_hash'],
				'source_manifest_hash' => $record['source_manifest_hash'],
				'projection_hash'      => $record['projection_hash'],
			)
		);
		if ( ! hash_equals( (string) $record['snapshot_hash'], hash( 'sha256', $identity_json ) ) ) {
			throw new InvalidArgumentException( 'Report snapshot identity hash is invalid.' );
		}
	}

	/**
	 * Result from record.
	 *
	 * @param array<string,mixed> $record Record.
	 * @param bool                $replayed Replayed.
	 * @return Create_Report_Snapshot_Result
	 */
	private function result_from_record( array $record, bool $replayed ): Create_Report_Snapshot_Result {
		return new Create_Report_Snapshot_Result(
			(string) $record['snapshot_public_id'],
			'ready',
			(string) $record['snapshot_hash'],
			(string) $record['filter_hash'],
			(string) $record['source_manifest_hash'],
			(string) $record['projection_hash'],
			(int) $record['row_count'],
			(string) $record['generated_at_utc'],
			$replayed
		);
	}

	/**
	 * Result from receipt.
	 *
	 * @param array<string,mixed> $receipt Receipt.
	 * @param bool                $replayed Replayed.
	 * @return Create_Report_Snapshot_Result
	 */
	private function result_from_receipt( array $receipt, bool $replayed ): Create_Report_Snapshot_Result {
		return new Create_Report_Snapshot_Result(
			(string) ( $receipt['snapshot_id'] ?? '' ),
			(string) ( $receipt['state'] ?? '' ),
			(string) ( $receipt['snapshot_hash'] ?? '' ),
			(string) ( $receipt['filter_hash'] ?? '' ),
			(string) ( $receipt['source_manifest_hash'] ?? '' ),
			(string) ( $receipt['projection_hash'] ?? '' ),
			$this->non_negative_integer( $receipt['row_count'] ?? null ),
			(string) ( $receipt['generated_at_utc'] ?? '' ),
			$replayed
		);
	}

	/**
	 * Recover receipt.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Create_Report_Snapshot_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function recover_receipt( array $record ): ?Create_Report_Snapshot_Result {
		try {
			return $this->command_receipt( (string) $record['command_storage_key'], (string) $record['request_digest'], false );
		} catch ( Report_Snapshot_Failure $failure ) {
			throw $failure;
		} catch ( Throwable ) {
			return null;
		}
	}

	/**
	 * Decode input.
	 *
	 * @param string $json Json.
	 * @param string $hash Hash.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function decode_input( string $json, string $hash ): array {
		$this->hash_value( $hash );
		if ( ! hash_equals( $hash, hash( 'sha256', $json ) ) ) {
			throw new InvalidArgumentException( 'Report snapshot input JSON hash is invalid.' );
		}
		try {
			$value = json_decode( $json, true, 128, JSON_THROW_ON_ERROR );
		} catch ( JsonException ) {
			throw new InvalidArgumentException( 'Report snapshot input JSON is invalid.' );
		}
		if ( ! is_array( $value ) || array_is_list( $value ) || ! hash_equals( $json, $this->canonical->encode( $value ) ) ) {
			throw new InvalidArgumentException( 'Report snapshot input JSON is not canonical.' );
		}
		return $value;
	}

	/**
	 * Decode stored.
	 *
	 * @param string $json Json.
	 * @param string $hash Hash.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function decode_stored( string $json, string $hash ): array {
		try {
			return $this->decode_input( $json, $hash );
		} catch ( Throwable ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Report_Snapshot_Failure::because( Report_Snapshot_Failure::STORAGE_UNAVAILABLE );
		}
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
		$prepared = $this->db()->prepare( $sql, ...$values );
		if ( ! is_string( $prepared ) || '' === $prepared ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Report_Snapshot_Failure::because( Report_Snapshot_Failure::STORAGE_UNAVAILABLE );
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
		$row = $db->get_row( $sql, defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A' );
		$this->assert_ok( $db );
		if ( null === $row ) {
			return null;
		}
		if ( ! is_array( $row ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Report_Snapshot_Failure::because( Report_Snapshot_Failure::STORAGE_UNAVAILABLE );
		}
		return $row;
	}

	/**
	 * Scalar.
	 *
	 * @param string $sql Sql.
	 * @return mixed
	 */
	private function scalar( string $sql ): mixed {
		$db = $this->db();
		$this->reset( $db );
		$value = $db->get_var( $sql );
		$this->assert_ok( $db );
		return $value;
	}

	/**
	 * Insert or fail.
	 *
	 * @param string              $table Table.
	 * @param array<string,mixed> $data Data.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function insert_or_fail( string $table, array $data ): void {
		$db = $this->db();
		$this->reset( $db );
		$written = $db->insert( $table, $data );
		$this->assert_ok( $db );
		if ( 1 !== $written ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Report_Snapshot_Failure::because( Report_Snapshot_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Query or fail.
	 *
	 * @param string $sql Sql.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function query_or_fail( string $sql ): void {
		if ( false === $this->query( $sql ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Report_Snapshot_Failure::because( Report_Snapshot_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Query.
	 *
	 * @param string $sql Sql.
	 * @return mixed
	 */
	private function query( string $sql ): mixed {
		$db = $this->db();
		$this->reset( $db );
		$result = $db->query( $sql );
		$this->assert_ok( $db );
		return $result;
	}

	/**
	 * Rollback.
	 *
	 * @return void
	 */
	private function rollback(): void {
		try {
			$this->query( 'ROLLBACK' );
		} catch ( Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Preserve the documented recovery or optional-operation outcome; the catch body explains the fallback.
			// Best-effort recovery must not replace the primary failure.
 // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Best-effort recovery must not replace the primary failure.
		}
	}

	/**
	 * Db.
	 *
	 * @return object
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function db(): object {
		$db = $this->database ?? ( $GLOBALS['wpdb'] ?? null );
		if ( ! is_object( $db ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Report_Snapshot_Failure::because( Report_Snapshot_Failure::STORAGE_UNAVAILABLE );
		}
		foreach ( array( 'prepare', 'get_row', 'get_var', 'insert', 'query' ) as $method ) {
			if ( ! method_exists( $db, $method ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Report_Snapshot_Failure::because( Report_Snapshot_Failure::STORAGE_UNAVAILABLE );
			}
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
			throw Report_Snapshot_Failure::because( Report_Snapshot_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Hash value.
	 *
	 * @param string $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function hash_value( string $value ): void {
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $value ) ) {
			throw new InvalidArgumentException( 'Report snapshot hash is invalid.' );
		}
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
			throw new InvalidArgumentException( 'Report snapshot UTC value is invalid.' );
		}
	}

	/**
	 * Storage key.
	 *
	 * @param string $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function storage_key( string $value ): void {
		if ( '' === $value || strlen( $value ) > 191 || 1 !== preg_match( '/^[a-z0-9][a-z0-9:._-]*$/D', $value ) ) {
			throw new InvalidArgumentException( 'Report snapshot idempotency key is invalid.' );
		}
	}

	/**
	 * Safe token.
	 *
	 * @param string $value Value.
	 * @param int    $minimum Minimum.
	 * @param int    $maximum Maximum.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function safe_token( string $value, int $minimum, int $maximum ): void {
		if ( strlen( $value ) < $minimum || strlen( $value ) > $maximum || 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@-]*$/D', $value ) ) {
			throw new InvalidArgumentException( 'Report snapshot token is invalid.' );
		}
	}

	/**
	 * Positive integer.
	 *
	 * @param mixed $value Value.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function positive_integer( mixed $value ): int {
		$value = $this->non_negative_integer( $value );
		if ( $value < 1 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Report_Snapshot_Failure::because( Report_Snapshot_Failure::STORAGE_UNAVAILABLE );
		}
		return $value;
	}

	/**
	 * Non negative integer.
	 *
	 * @param mixed $value Value.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function non_negative_integer( mixed $value ): int {
		if ( is_string( $value ) && 1 === preg_match( '/^(?:0|[1-9][0-9]*)$/D', $value ) ) {
			$value = (int) $value;
		}
		if ( ! is_int( $value ) || $value < 0 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Report_Snapshot_Failure::because( Report_Snapshot_Failure::STORAGE_UNAVAILABLE );
		}
		return $value;
	}
}
