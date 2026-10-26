<?php
/**
 * WordPress report export repository.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\ReportExport\Infrastructure;

use InvalidArgumentException;
use JsonException;
use OJAI\Dashboard\Reporting\ReportExport\Application\Enqueue_Report_Export_Result;
use OJAI\Dashboard\Reporting\ReportExport\Application\Report_Export_Failure;
use OJAI\Dashboard\Reporting\ReportExport\Domain\Report_Export_Request_Snapshot;
use OJAI\Dashboard\Reporting\ReportExport\Port\Report_Export_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persists C46/C47/C69 receipts without owning report snapshot or artifact bytes.
 */
final class WordPress_Report_Export_Repository implements Report_Export_Repository_Port_Interface {
	private const TABLE_JOBS    = 'oj_ai_export_jobs';
	private const TABLE_REPORTS = 'oj_ai_report_snapshots';
	private const TABLE_AUDIT   = 'oj_ai_audit_events';
	private const TABLE_OUTBOX  = 'oj_ai_outbox_events';
	private const QUEUED_EVENT  = 'report_export.queued';
	private const REPLAY_EVENT  = 'report_export.enqueue_replayed';
	private const OUTBOX_SCHEMA = 'oj_ai.report_export_event';
	private const AUDIT_SCHEMA  = 'oj_ai.report_export_audit';

	/**
	 * Initialize WordPress report export repository dependencies and state.
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
	 * @return Enqueue_Report_Export_Result|null
	 */
	public function find_command_receipt( string $command_storage_key, string $request_digest ): ?Enqueue_Report_Export_Result {
		$this->storage_key( $command_storage_key );
		$this->hash( $request_digest );
		return $this->command_receipt( $command_storage_key, $request_digest, false );
	}

	/**
	 * Enqueue.
	 *
	 * @param array $record Record.
	 * @return Enqueue_Report_Export_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function enqueue( array $record ): Enqueue_Report_Export_Result {
		$this->assert_enqueue_record( $record );
		$receipt = $this->command_receipt( (string) $record['command_storage_key'], (string) $record['request_digest'], false );
		if ( $receipt instanceof Enqueue_Report_Export_Result ) {
			return $receipt;
		}
		$transaction = false;
		try {
			$this->query_or_fail( 'START TRANSACTION' );
			$transaction = true;
			$receipt     = $this->command_receipt( (string) $record['command_storage_key'], (string) $record['request_digest'], true );
			if ( $receipt instanceof Enqueue_Report_Export_Result ) {
				$this->query_or_fail( 'COMMIT' );
				return $receipt;
			}
			$report_snapshot_pk = $this->report_snapshot_pk( (string) $record['source_public_id'] );
			$semantic           = $this->semantic_receipt( $record, true );
			if ( $semantic instanceof Enqueue_Report_Export_Result ) {
				$this->insert_outbox( $record, $semantic, self::REPLAY_EVENT, true );
				$this->query_or_fail( 'COMMIT' );
				$transaction = false;
				return $semantic;
			}
			$this->insert_or_fail(
				self::TABLE_JOBS,
				array(
					'public_id'                => $record['job_public_id'],
					'lock_version'             => 0,
					'created_by_user_id'       => $record['actor_user_id'],
					'created_at_utc'           => $record['queued_at_utc'],
					'updated_by_user_id'       => $record['actor_user_id'],
					'updated_at_utc'           => $record['queued_at_utc'],
					'context_type'             => $record['context_type'],
					'context_id'               => $record['context_id'],
					'context_revision'         => $record['context_revision'],
					'report_snapshot_id'       => $report_snapshot_pk,
					'artifact_kind'            => $record['artifact_kind'],
					'source_type'              => 'report_snapshot',
					'source_public_id'         => $record['source_public_id'],
					'source_hash'              => $record['source_hash'],
					'request_contract_version' => Report_Export_Request_Snapshot::VERSION,
					'request_snapshot_schema'  => Report_Export_Request_Snapshot::SCHEMA,
					'request_snapshot_version' => Report_Export_Request_Snapshot::DATABASE_VERSION,
					'request_snapshot_json'    => $record['snapshot_json'],
					'request_snapshot_hash'    => $record['snapshot_hash'],
					'request_hash'             => $record['snapshot_hash'],
					'state'                    => 'queued',
					'requested_by_user_id'     => $record['actor_user_id'],
					'idempotency_key'          => $record['job_idempotency_key'],
					'active_identity_key'      => 'report-export-active:' . $record['semantic_hash'],
					'print_profile_version'    => $record['profile_version'],
					'available_at_utc'         => $record['queued_at_utc'],
					'queued_at_utc'            => $record['queued_at_utc'],
					'lease_token'              => null,
					'lease_expires_at_utc'     => null,
					'started_at_utc'           => null,
					'finished_at_utc'          => null,
					'result_file_public_id'    => null,
					'error_code'               => null,
				)
			);
			$result = $this->result_from_record( $record, false );
			$this->insert_audit( $record, $result );
			$this->insert_outbox( $record, $result, self::QUEUED_EVENT, false );
			$this->query_or_fail( 'COMMIT' );
			$transaction = false;
			return $result;
		} catch ( Report_Export_Failure $failure ) {
			if ( $transaction ) {
				$this->rollback();
			}
			throw $failure;
		} catch ( Throwable ) {
			if ( $transaction ) {
				$this->rollback();
			}
			$receipt = $this->recover_receipt( $record );
			if ( $receipt instanceof Enqueue_Report_Export_Result ) {
				return $receipt;
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Report_Export_Failure::because( Report_Export_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Report snapshot pk.
	 *
	 * @param string $snapshot_id Snapshot id.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function report_snapshot_pk( string $snapshot_id ): int {
		$row = $this->row(
			$this->prepare(
				'SELECT id FROM ' . self::TABLE_REPORTS . ' WHERE public_id = %s AND state = %s LIMIT 1 FOR UPDATE',
				array( $snapshot_id, 'ready' )
			)
		);
		if ( null === $row ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Report_Export_Failure::because( Report_Export_Failure::SOURCE_STALE );
		}
		return $this->positive_int( $row['id'] ?? null );
	}

	/**
	 * Command receipt.
	 *
	 * @param string $key Key.
	 * @param string $digest Digest.
	 * @param bool   $for_update For update.
	 * @return Enqueue_Report_Export_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function command_receipt( string $key, string $digest, bool $for_update ): ?Enqueue_Report_Export_Result {
		$sql = 'SELECT aggregate_type, aggregate_public_id, event_type, payload_schema, payload_version, payload_json, payload_hash FROM ' . self::TABLE_OUTBOX . ' WHERE idempotency_key = %s LIMIT 1' . ( $for_update ? ' FOR UPDATE' : '' );
		$row = $this->row( $this->prepare( $sql, array( $key ) ) );
		if ( null === $row ) {
			return null;
		}
		if (
			'report_export_job' !== ( $row['aggregate_type'] ?? null )
			|| ! in_array( $row['event_type'] ?? null, array( self::QUEUED_EVENT, self::REPLAY_EVENT ), true )
			|| self::OUTBOX_SCHEMA !== ( $row['payload_schema'] ?? null ) || 1 !== $this->positive_int( $row['payload_version'] ?? null )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Report_Export_Failure::because( Report_Export_Failure::STORAGE_UNAVAILABLE );
		}
		$payload = $this->decode( (string) $row['payload_json'], (string) $row['payload_hash'] );
		if ( ! hash_equals( $digest, (string) ( $payload['request_digest'] ?? '' ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Report_Export_Failure::because( Report_Export_Failure::IDEMPOTENCY_CONFLICT );
		}
		$receipt = $payload['receipt'] ?? null;
		if ( ! is_array( $receipt ) || array_is_list( $receipt ) || ! hash_equals( (string) $row['aggregate_public_id'], (string) ( $receipt['job_id'] ?? '' ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Report_Export_Failure::because( Report_Export_Failure::STORAGE_UNAVAILABLE );
		}
		return $this->result_from_receipt( $receipt, true );
	}

	/**
	 * Semantic receipt.
	 *
	 * @param array $record Record.
	 * @param bool  $for_update For update.
	 * @return Enqueue_Report_Export_Result|null
	 */
	private function semantic_receipt( array $record, bool $for_update ): ?Enqueue_Report_Export_Result {
		$sql = 'SELECT public_id, state, artifact_kind, source_public_id, source_hash, queued_at_utc FROM ' . self::TABLE_JOBS . ' WHERE active_identity_key = %s AND artifact_kind = %s AND state IN (%s,%s) ORDER BY id ASC LIMIT 1' . ( $for_update ? ' FOR UPDATE' : '' );
		$row = $this->row( $this->prepare( $sql, array( 'report-export-active:' . $record['semantic_hash'], $record['artifact_kind'], 'queued', 'running' ) ) );
		return null === $row ? null : new Enqueue_Report_Export_Result(
			(string) $row['public_id'],
			(string) $row['state'],
			(string) $row['artifact_kind'],
			(string) $row['source_public_id'],
			(string) $row['source_hash'],
			(string) $row['queued_at_utc'],
			true
		);
	}

	/**
	 * Insert outbox.
	 *
	 * @param array                        $record Record.
	 * @param Enqueue_Report_Export_Result $result Result.
	 * @param string                       $event Event.
	 * @param bool                         $receipt_only Receipt only.
	 * @return void
	 */
	private function insert_outbox( array $record, Enqueue_Report_Export_Result $result, string $event, bool $receipt_only ): void {
		$payload = $this->canonical->encode(
			array(
				'command_id'     => $record['command_id'],
				'event'          => $event,
				'request_digest' => $record['request_digest'],
				'receipt'        => array(
					'artifact_kind' => $result->artifact_kind(),
					'job_id'        => $result->job_id(),
					'queued_at_utc' => $result->queued_at_utc(),
					'snapshot_hash' => $result->snapshot_hash(),
					'snapshot_id'   => $result->snapshot_id(),
					'state'         => $result->state(),
				),
			)
		);
		$this->insert_or_fail(
			self::TABLE_OUTBOX,
			array(
				'public_id'            => $record['outbox_public_id'],
				'lock_version'         => 0,
				'created_by_user_id'   => $record['actor_user_id'],
				'created_at_utc'       => $record['queued_at_utc'],
				'updated_by_user_id'   => $record['actor_user_id'],
				'updated_at_utc'       => $record['queued_at_utc'],
				'aggregate_type'       => 'report_export_job',
				'aggregate_public_id'  => $result->job_id(),
				'event_type'           => $event,
				'idempotency_key'      => $record['command_storage_key'],
				'payload_schema'       => self::OUTBOX_SCHEMA,
				'payload_version'      => 1,
				'payload_json'         => $payload,
				'payload_hash'         => hash( 'sha256', $payload ),
				'available_at_utc'     => $record['queued_at_utc'],
				'published_at_utc'     => $receipt_only ? $record['queued_at_utc'] : null,
				'lease_token'          => null,
				'lease_expires_at_utc' => null,
				'attempt_count'        => 0,
				'last_error_code'      => null,
			)
		);
	}

	/**
	 * Insert audit.
	 *
	 * @param array                        $record Record.
	 * @param Enqueue_Report_Export_Result $result Result.
	 * @return void
	 */
	private function insert_audit( array $record, Enqueue_Report_Export_Result $result ): void {
		$previous = $this->scalar( 'SELECT event_hash FROM ' . self::TABLE_AUDIT . ' ORDER BY id DESC LIMIT 1 FOR UPDATE' );
		if ( null !== $previous ) {
			$this->hash( (string) $previous );
		}
		$action        = (string) $record['artifact_kind'] . '.queued';
		$metadata      = $this->canonical->encode(
			array(
				'artifact_kind'  => $record['artifact_kind'],
				'command_id'     => $record['command_id'],
				'profile'        => $record['profile_version'],
				'request_digest' => $record['request_digest'],
				'request_hash'   => $record['snapshot_hash'],
				'snapshot_hash'  => $record['source_hash'],
				'snapshot_id'    => $record['source_public_id'],
			)
		);
		$metadata_hash = hash( 'sha256', $metadata );
		$event_hash    = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'action_key'          => $action,
					'actor_user_id'       => $record['actor_user_id'],
					'after_digest'        => $metadata_hash,
					'before_digest'       => null,
					'context_id'          => $record['context_id'],
					'context_revision'    => $record['context_revision'],
					'context_type'        => $record['context_type'],
					'correlation_id'      => $record['correlation_id'],
					'metadata_hash'       => $metadata_hash,
					'object_public_id'    => $result->job_id(),
					'object_type'         => 'report_export_job',
					'occurred_at_utc'     => $record['queued_at_utc'],
					'previous_event_hash' => $previous,
				)
			)
		);
		$this->insert_or_fail(
			self::TABLE_AUDIT,
			array(
				'public_id'           => $record['audit_public_id'],
				'occurred_at_utc'     => $record['queued_at_utc'],
				'actor_user_id'       => $record['actor_user_id'],
				'action_key'          => $action,
				'object_type'         => 'report_export_job',
				'object_public_id'    => $result->job_id(),
				'context_type'        => $record['context_type'],
				'context_id'          => $record['context_id'],
				'context_revision'    => $record['context_revision'],
				'correlation_id'      => $record['correlation_id'],
				'before_digest'       => null,
				'after_digest'        => $metadata_hash,
				'metadata_schema'     => self::AUDIT_SCHEMA,
				'metadata_version'    => 1,
				'metadata_json'       => $metadata,
				'metadata_hash'       => $metadata_hash,
				'previous_event_hash' => $previous,
				'event_hash'          => $event_hash,
			)
		);
	}

	/**
	 * Reject invalid enqueue record.
	 *
	 * @param array $record Record.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_enqueue_record( array $record ): void {
		$expected = array( 'actor_user_id', 'artifact_kind', 'audit_public_id', 'command_id', 'command_storage_key', 'context_id', 'context_revision', 'context_type', 'correlation_id', 'job_idempotency_key', 'job_public_id', 'outbox_public_id', 'profile_version', 'queued_at_utc', 'request_digest', 'semantic_hash', 'snapshot_hash', 'snapshot_json', 'source_hash', 'source_public_id' );
		$actual   = array_keys( $record );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected || ! is_int( $record['actor_user_id'] ) || $record['actor_user_id'] < 1 ) {
			throw new InvalidArgumentException( 'Report export enqueue record is invalid.' );
		}
		foreach ( array( 'audit_public_id', 'job_public_id', 'outbox_public_id', 'source_public_id' ) as $key ) {
			new Public_Id( (string) $record[ $key ] );
		}
		foreach ( array( 'request_digest', 'semantic_hash', 'snapshot_hash', 'source_hash' ) as $key ) {
			$this->hash( (string) $record[ $key ] );
		}
		$this->storage_key( (string) $record['command_storage_key'] );
		$this->storage_key( (string) $record['job_idempotency_key'] );
		$this->utc( (string) $record['queued_at_utc'] );
		$this->context_token( (string) $record['context_id'] );
		$this->context_token( (string) $record['context_revision'] );
		if ( ! in_array( $record['context_type'], array( 'class', 'group' ), true ) || ! Report_Export_Request_Snapshot::supports( (string) $record['artifact_kind'], (string) $record['profile_version'] ) ) {
			throw new InvalidArgumentException( 'Report export enqueue contract is invalid.' );
		}
		try {
			$snapshot = Report_Export_Request_Snapshot::restore( (string) $record['snapshot_json'], (string) $record['snapshot_hash'], $this->canonical ); } catch ( InvalidArgumentException ) {
			throw new InvalidArgumentException( 'Report export enqueue snapshot is invalid.' );
			}
			$context = $snapshot->context();
			if (
			! hash_equals( (string) $record['source_public_id'], $snapshot->source_id() ) || ! hash_equals( (string) $record['source_hash'], $snapshot->source_hash() )
			|| ! hash_equals( (string) $record['artifact_kind'], $snapshot->artifact_kind() ) || ! hash_equals( (string) $record['profile_version'], $snapshot->profile() )
			|| ! hash_equals( (string) $record['context_type'], (string) $context['type'] ) || ! hash_equals( (string) $record['context_id'], (string) $context['id'] )
			|| ! hash_equals( (string) $record['context_revision'], (string) $context['revision'] )
			) {
				throw new InvalidArgumentException( 'Report export enqueue snapshot evidence drifted.' );
			}
	}

	/**
	 * Result from record.
	 *
	 * @param array $record Record.
	 * @param bool  $replayed Replayed.
	 * @return Enqueue_Report_Export_Result
	 */
	private function result_from_record( array $record, bool $replayed ): Enqueue_Report_Export_Result {
		return new Enqueue_Report_Export_Result( (string) $record['job_public_id'], 'queued', (string) $record['artifact_kind'], (string) $record['source_public_id'], (string) $record['source_hash'], (string) $record['queued_at_utc'], $replayed );
	}

	/**
	 * Result from receipt.
	 *
	 * @param array $receipt Receipt.
	 * @param bool  $replayed Replayed.
	 * @return Enqueue_Report_Export_Result
	 */
	private function result_from_receipt( array $receipt, bool $replayed ): Enqueue_Report_Export_Result {
		return new Enqueue_Report_Export_Result( (string) ( $receipt['job_id'] ?? '' ), (string) ( $receipt['state'] ?? '' ), (string) ( $receipt['artifact_kind'] ?? '' ), (string) ( $receipt['snapshot_id'] ?? '' ), (string) ( $receipt['snapshot_hash'] ?? '' ), (string) ( $receipt['queued_at_utc'] ?? '' ), $replayed );
	}

	/**
	 * Recover receipt.
	 *
	 * @param array $record Record.
	 * @return Enqueue_Report_Export_Result|null
	 */
	private function recover_receipt( array $record ): ?Enqueue_Report_Export_Result {
		try {
			return $this->command_receipt( (string) $record['command_storage_key'], (string) $record['request_digest'], false ) ?? $this->semantic_receipt( $record, false ); } catch ( Throwable ) {
			return null;
			}
	}

	/**
	 * Decode WordPress report export repository.
	 *
	 * @param string $json Json.
	 * @param string $hash Hash.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function decode( string $json, string $hash ): array {
		$this->hash( $hash );
		if ( ! hash_equals( $hash, hash( 'sha256', $json ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Report_Export_Failure::because( Report_Export_Failure::STORAGE_UNAVAILABLE );
		}
		try {
			$value = json_decode( $json, true, 48, JSON_THROW_ON_ERROR ); } catch ( JsonException ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Report_Export_Failure::because( Report_Export_Failure::STORAGE_UNAVAILABLE );
			}
			if ( ! is_array( $value ) || array_is_list( $value ) || ! hash_equals( $json, $this->canonical->encode( $value ) ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Report_Export_Failure::because( Report_Export_Failure::STORAGE_UNAVAILABLE );
			}
			return $value;
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
		$prepared = $this->db()->prepare( $sql, ...$values );
		if ( ! is_string( $prepared ) || '' === $prepared ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Report_Export_Failure::because( Report_Export_Failure::STORAGE_UNAVAILABLE );
		} return $prepared;
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
		} if ( ! is_array( $row ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Report_Export_Failure::because( Report_Export_Failure::STORAGE_UNAVAILABLE );
		} return $row;
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
	 * @param string $table Table.
	 * @param array  $data Data.
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
			throw Report_Export_Failure::because( Report_Export_Failure::STORAGE_UNAVAILABLE );
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
			throw Report_Export_Failure::because( Report_Export_Failure::STORAGE_UNAVAILABLE );
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
			$this->query( 'ROLLBACK' ); } catch ( Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Preserve the documented recovery or optional-operation outcome; the catch body explains the fallback.
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
			throw Report_Export_Failure::because( Report_Export_Failure::STORAGE_UNAVAILABLE );
		} foreach ( array( 'prepare', 'get_row', 'get_var', 'insert', 'query' ) as $method ) {
			if ( ! method_exists( $db, $method ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Report_Export_Failure::because( Report_Export_Failure::STORAGE_UNAVAILABLE );
			}
		} return $db;
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
			throw Report_Export_Failure::because( Report_Export_Failure::STORAGE_UNAVAILABLE );
		}
	}
	/**
	 * Hash.
	 *
	 * @param string $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function hash( string $value ): void {
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $value ) ) {
			throw new InvalidArgumentException( 'Report export hash is invalid.' );
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
			throw new InvalidArgumentException( 'Report export UTC value is invalid.' );
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
			throw new InvalidArgumentException( 'Report export idempotency key is invalid.' );
		}
	}
	/**
	 * Context token.
	 *
	 * @param string $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function context_token( string $value ): void {
		if ( '' === $value || strlen( $value ) > 191 || 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@\/-]*$/D', $value ) ) {
			throw new InvalidArgumentException( 'Report export Context token is invalid.' );
		}
	}
	/**
	 * Positive int.
	 *
	 * @param mixed $value Value.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function positive_int( mixed $value ): int {
		if ( is_int( $value ) && $value > 0 ) {
			return $value;
		} if ( is_string( $value ) && 1 === preg_match( '/^[1-9][0-9]*$/D', $value ) ) {
			return (int) $value;
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
		} throw Report_Export_Failure::because( Report_Export_Failure::STORAGE_UNAVAILABLE );
	}
}
