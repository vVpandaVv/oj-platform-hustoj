<?php
/**
 * WordPress legacy migration approval repository.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\LegacyMigration\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Approve_Legacy_Migration;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Approve_Legacy_Migration_Result;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Legacy_Migration_Failure;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Migration_Plan;
use OJAI\Dashboard\Operations\LegacyMigration\Port\Legacy_Migration_Approval_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WordPress legacy migration approval repository.
 */
final class WordPress_Legacy_Migration_Approval_Repository implements Legacy_Migration_Approval_Repository_Port_Interface {
	private const TABLE_RUNS     = 'oj_ai_import_runs';
	private const TABLE_AUDIT    = 'oj_ai_audit_events';
	private const TABLE_OUTBOX   = 'oj_ai_outbox_events';
	private const EVENT_APPROVED = 'legacy_migration.approved';
	private const AGGREGATE_TYPE = 'legacy_migration_run';
	private const OUTBOX_SCHEMA  = 'oj_ai.legacy_migration_approval_event';
	private const AUDIT_SCHEMA   = 'oj_ai.legacy_migration_approval_audit';

	/**
	 * Initialize WordPress legacy migration approval repository dependencies and state.
	 *
	 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
	 * @param object|null    $database Database.
	 */
	public function __construct( private Canonical_Json $canonical, private ?object $database = null ) {
	}

	/**
	 * Approve.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Approve_Legacy_Migration_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function approve( array $record ): Approve_Legacy_Migration_Result {
		$this->assert_record( $record );
		$receipt = $this->command_receipt( $record['command_storage_key'], $record['request_digest'], false );
		if ( $receipt instanceof Approve_Legacy_Migration_Result ) {
			return $receipt;
		}

		$transaction = false;
		try {
			$this->query_or_fail( 'START TRANSACTION' );
			$transaction = true;
			$receipt     = $this->command_receipt( $record['command_storage_key'], $record['request_digest'], true );
			if ( $receipt instanceof Approve_Legacy_Migration_Result ) {
				$this->query_or_fail( 'COMMIT' );
				$transaction = false;
				return $receipt;
			}

			$this->insert_run( $record );
			$result = $this->result_from_record( $record, false );
			$this->insert_audit( $record );
			$this->insert_outbox( $record, $result );
			$this->query_or_fail( 'COMMIT' );
			$transaction = false;

			return $result;
		} catch ( Legacy_Migration_Failure $failure ) {
			if ( $transaction ) {
				$this->rollback();
			}
			if ( Legacy_Migration_Failure::STORAGE_UNAVAILABLE === $failure->reason() ) {
				$winner = $this->recover_receipt( $record );
				if ( $winner instanceof Approve_Legacy_Migration_Result ) {
					return $winner;
				}
			}
			throw $failure;
		} catch ( Throwable ) {
			if ( $transaction ) {
				$this->rollback();
			}
			$winner = $this->recover_receipt( $record );
			if ( $winner instanceof Approve_Legacy_Migration_Result ) {
				return $winner;
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Insert run.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return void
	 */
	private function insert_run( array $record ): void {
		$this->insert_or_fail(
			self::TABLE_RUNS,
			array(
				'public_id'           => $record['run_public_id'],
				'lock_version'        => 0,
				'created_by_user_id'  => $record['actor_user_id'],
				'created_at_utc'      => $record['approved_at_utc'],
				'updated_by_user_id'  => $record['actor_user_id'],
				'updated_at_utc'      => $record['approved_at_utc'],
				'source_kind'         => $record['source_kind'],
				'source_version'      => $record['source_version'],
				'source_fingerprint'  => $record['source_fingerprint'],
				'state'               => 'approved',
				'preview_schema'      => Approve_Legacy_Migration::PREVIEW_SCHEMA,
				'preview_version'     => Approve_Legacy_Migration::PREVIEW_DATABASE_VERSION,
				'preview_json'        => $record['preview_json'],
				'preview_hash'        => $record['preview_hash'],
				'approved_by_user_id' => $record['actor_user_id'],
				'approved_at_utc'     => $record['approved_at_utc'],
				'idempotency_key'     => $record['command_storage_key'],
				'checkpoint_schema'   => null,
				'checkpoint_version'  => null,
				'checkpoint_json'     => null,
				'checkpoint_hash'     => null,
				'item_total'          => $record['item_total'],
				'success_total'       => 0,
				'failure_total'       => 0,
				'skipped_total'       => 0,
				'error_code'          => null,
			)
		);
	}

	/**
	 * Command receipt.
	 *
	 * @param string $key Key.
	 * @param string $digest Digest.
	 * @param bool   $for_update For update.
	 * @return Approve_Legacy_Migration_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function command_receipt( string $key, string $digest, bool $for_update ): ?Approve_Legacy_Migration_Result {
		$this->storage_key( $key );
		$this->hash_value( $digest );
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
			self::EVENT_APPROVED !== ( $row['event_type'] ?? null )
			|| self::OUTBOX_SCHEMA !== ( $row['payload_schema'] ?? null )
			|| 1 !== $this->positive_integer( $row['payload_version'] ?? null )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		$payload = $this->decode_stored( (string) ( $row['payload_json'] ?? '' ), (string) ( $row['payload_hash'] ?? '' ) );
		if ( ! hash_equals( $digest, (string) ( $payload['request_digest'] ?? '' ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::IDEMPOTENCY_CONFLICT );
		}
		$receipt = $payload['receipt'] ?? null;
		if (
			! is_array( $receipt )
			|| array_is_list( $receipt )
			|| ! hash_equals( (string) ( $row['aggregate_public_id'] ?? '' ), (string) ( $receipt['run_id'] ?? '' ) )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		$expected = array(
			'approved_at_utc',
			'item_total',
			'plan_hash',
			'preview_hash',
			'run_id',
			'source_fingerprint',
			'source_profile',
			'source_version',
			'state',
		);
		$actual   = array_keys( $receipt );
		sort( $expected, SORT_STRING );
		sort( $actual, SORT_STRING );
		if ( $actual !== $expected ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}

		try {
			return $this->result_from_receipt( $receipt, true );
		} catch ( Throwable $error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE, $error );
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
				'command_id'         => $record['command_id'],
				'item_total'         => $record['item_total'],
				'plan_hash'          => $record['plan_hash'],
				'preview_hash'       => $record['preview_hash'],
				'preview_token_hash' => $record['preview_token_hash'],
				'request_digest'     => $record['request_digest'],
				'source_fingerprint' => $record['source_fingerprint'],
				'source_profile'     => $record['source_profile'],
			)
		);
		$metadata_hash = hash( 'sha256', $metadata_json );
		$event_hash    = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'action_key'          => self::EVENT_APPROVED,
					'actor_user_id'       => $record['actor_user_id'],
					'after_digest'        => $record['preview_hash'],
					'before_digest'       => null,
					'correlation_id'      => $record['correlation_id'],
					'metadata_hash'       => $metadata_hash,
					'object_public_id'    => $record['run_public_id'],
					'object_type'         => self::AGGREGATE_TYPE,
					'occurred_at_utc'     => $record['approved_at_utc'],
					'previous_event_hash' => $previous,
				)
			)
		);
		$this->insert_or_fail(
			self::TABLE_AUDIT,
			array(
				'public_id'           => $record['audit_public_id'],
				'occurred_at_utc'     => $record['approved_at_utc'],
				'actor_user_id'       => $record['actor_user_id'],
				'action_key'          => self::EVENT_APPROVED,
				'object_type'         => self::AGGREGATE_TYPE,
				'object_public_id'    => $record['run_public_id'],
				'context_type'        => null,
				'context_id'          => null,
				'context_revision'    => null,
				'correlation_id'      => $record['correlation_id'],
				'before_digest'       => null,
				'after_digest'        => $record['preview_hash'],
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
	 * @param array<string,mixed>             $record Record.
	 * @param Approve_Legacy_Migration_Result $result Result.
	 * @return void
	 */
	private function insert_outbox( array $record, Approve_Legacy_Migration_Result $result ): void {
		$payload_json = $this->canonical->encode(
			array(
				'command_id'     => $record['command_id'],
				'event'          => self::EVENT_APPROVED,
				'request_digest' => $record['request_digest'],
				'receipt'        => $this->receipt_payload( $result ),
			)
		);
		$this->insert_or_fail(
			self::TABLE_OUTBOX,
			array(
				'public_id'            => $record['outbox_public_id'],
				'lock_version'         => 0,
				'created_by_user_id'   => $record['actor_user_id'],
				'created_at_utc'       => $record['approved_at_utc'],
				'updated_by_user_id'   => $record['actor_user_id'],
				'updated_at_utc'       => $record['approved_at_utc'],
				'aggregate_type'       => self::AGGREGATE_TYPE,
				'aggregate_public_id'  => $record['run_public_id'],
				'event_type'           => self::EVENT_APPROVED,
				'idempotency_key'      => $record['command_storage_key'],
				'payload_schema'       => self::OUTBOX_SCHEMA,
				'payload_version'      => 1,
				'payload_json'         => $payload_json,
				'payload_hash'         => hash( 'sha256', $payload_json ),
				'available_at_utc'     => $record['approved_at_utc'],
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
			'approved_at_utc',
			'audit_public_id',
			'command_id',
			'command_storage_key',
			'correlation_id',
			'item_total',
			'outbox_public_id',
			'plan_hash',
			'preview_hash',
			'preview_json',
			'preview_token_hash',
			'request_digest',
			'run_public_id',
			'source_fingerprint',
			'source_kind',
			'source_profile',
			'source_version',
		);
		$actual   = array_keys( $record );
		sort( $expected, SORT_STRING );
		sort( $actual, SORT_STRING );
		if (
			$actual !== $expected
			|| ! is_int( $record['actor_user_id'] )
			|| $record['actor_user_id'] < 1
			|| ! is_int( $record['item_total'] )
			|| $record['item_total'] < 0
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		foreach ( array( 'audit_public_id', 'command_id', 'outbox_public_id', 'run_public_id' ) as $key ) {
			new Public_Id( (string) $record[ $key ] );
		}
		foreach ( array( 'plan_hash', 'preview_hash', 'preview_token_hash', 'request_digest', 'source_fingerprint' ) as $key ) {
			$this->hash_value( (string) $record[ $key ] );
		}
		$this->storage_key( (string) $record['command_storage_key'] );
		$this->utc( (string) $record['approved_at_utc'] );
		$this->safe_token( (string) $record['correlation_id'], 8, 128 );
		$this->safe_token( (string) $record['source_kind'], 3, 64 );
		if (
			1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@\/-]{7,127}$/D', (string) $record['source_profile'] )
			|| 1 !== preg_match( '/^[0-9]+\.[0-9]+\.[0-9]+$/D', (string) $record['source_version'] )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}

		$preview               = $this->decode_stored( (string) $record['preview_json'], (string) $record['preview_hash'] );
		$preview_keys          = array_keys( $preview );
		$expected_preview_keys = array( 'plan', 'profile', 'state' );
		sort( $preview_keys, SORT_STRING );
		if (
			$preview_keys !== $expected_preview_keys
			|| Approve_Legacy_Migration::PREVIEW_PROFILE !== ( $preview['profile'] ?? null )
			|| 'approved' !== ( $preview['state'] ?? null )
			|| ! is_array( $preview['plan'] ?? null )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		$plan = $preview['plan'];
		( new Legacy_Migration_Plan( $this->canonical ) )->assert_valid( $plan );
		if (
			! hash_equals( (string) $record['plan_hash'], (string) ( $plan['plan_hash'] ?? '' ) )
			|| ! hash_equals( (string) $record['source_profile'], (string) ( $plan['source']['profile'] ?? '' ) )
			|| ! hash_equals( (string) $record['source_version'], (string) ( $plan['source']['version'] ?? '' ) )
			|| ! hash_equals( (string) $record['source_fingerprint'], (string) ( $plan['source']['fingerprint'] ?? '' ) )
			|| $record['item_total'] !== ( $plan['counts']['migration_candidates'] ?? null )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Result from record.
	 *
	 * @param array<string,mixed> $record Record.
	 * @param bool                $replayed Replayed.
	 * @return Approve_Legacy_Migration_Result
	 */
	private function result_from_record( array $record, bool $replayed ): Approve_Legacy_Migration_Result {
		return new Approve_Legacy_Migration_Result(
			(string) $record['run_public_id'],
			'approved',
			(string) $record['source_profile'],
			(string) $record['source_version'],
			(string) $record['source_fingerprint'],
			(string) $record['plan_hash'],
			(string) $record['preview_hash'],
			(int) $record['item_total'],
			(string) $record['approved_at_utc'],
			$replayed
		);
	}

	/**
	 * Result from receipt.
	 *
	 * @param array<string,mixed> $receipt Receipt.
	 * @param bool                $replayed Replayed.
	 * @return Approve_Legacy_Migration_Result
	 */
	private function result_from_receipt( array $receipt, bool $replayed ): Approve_Legacy_Migration_Result {
		return new Approve_Legacy_Migration_Result(
			(string) ( $receipt['run_id'] ?? '' ),
			(string) ( $receipt['state'] ?? '' ),
			(string) ( $receipt['source_profile'] ?? '' ),
			(string) ( $receipt['source_version'] ?? '' ),
			(string) ( $receipt['source_fingerprint'] ?? '' ),
			(string) ( $receipt['plan_hash'] ?? '' ),
			(string) ( $receipt['preview_hash'] ?? '' ),
			(int) ( $receipt['item_total'] ?? -1 ),
			(string) ( $receipt['approved_at_utc'] ?? '' ),
			$replayed
		);
	}

	/**
	 * Receipt payload.
	 *
	 * @param Approve_Legacy_Migration_Result $result Result.
	 * @return array<string,mixed>
	 */
	private function receipt_payload( Approve_Legacy_Migration_Result $result ): array {
		return array(
			'run_id'             => $result->run_id(),
			'state'              => $result->state(),
			'source_profile'     => $result->source_profile(),
			'source_version'     => $result->source_version(),
			'source_fingerprint' => $result->source_fingerprint(),
			'plan_hash'          => $result->plan_hash(),
			'preview_hash'       => $result->preview_hash(),
			'item_total'         => $result->item_total(),
			'approved_at_utc'    => $result->approved_at_utc(),
		);
	}

	/**
	 * Recover receipt.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Approve_Legacy_Migration_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function recover_receipt( array $record ): ?Approve_Legacy_Migration_Result {
		try {
			return $this->command_receipt( (string) $record['command_storage_key'], (string) $record['request_digest'], false );
		} catch ( Legacy_Migration_Failure $failure ) {
			if ( Legacy_Migration_Failure::IDEMPOTENCY_CONFLICT === $failure->reason() ) {
				throw $failure;
			}
			return null;
		} catch ( Throwable ) {
			return null;
		}
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
		$this->hash_value( $hash );
		if ( ! hash_equals( $hash, hash( 'sha256', $json ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		try {
			$value = json_decode( $json, true, 64, JSON_THROW_ON_ERROR );
		} catch ( JsonException $error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE, $error );
		}
		if ( ! is_array( $value ) || array_is_list( $value ) || ! hash_equals( $json, $this->canonical->encode( $value ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		return $value;
	}

	/**
	 * Database.
	 *
	 * @return object
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function database(): object {
		$database = $this->database ?? ( $GLOBALS['wpdb'] ?? null );
		if ( ! is_object( $database ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		foreach ( array( 'prepare', 'get_row', 'get_var', 'insert', 'query' ) as $method ) {
			if ( ! method_exists( $database, $method ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
			}
		}
		return $database;
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
		$database = $this->database();
		$this->reset_error( $database );
		$row = $database->get_row( $sql, defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A' );
		$this->assert_read( $database );
		if ( null === $row ) {
			return null;
		}
		if ( ! is_array( $row ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		return $row;
	}

	/**
	 * Scalar.
	 *
	 * @param string $sql Sql.
	 * @return mixed
	 */
	private function scalar( string $sql ) {
		$database = $this->database();
		$this->reset_error( $database );
		$value = $database->get_var( $sql );
		$this->assert_read( $database );
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
		$database = $this->database();
		$this->reset_error( $database );
		if ( false === $database->insert( $table, $data ) || '' !== trim( (string) ( $database->last_error ?? '' ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
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
		$database = $this->database();
		$this->reset_error( $database );
		if ( false === $database->query( $sql ) || '' !== trim( (string) ( $database->last_error ?? '' ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Rollback.
	 *
	 * @return void
	 */
	private function rollback(): void {
		try {
			$this->database()->query( 'ROLLBACK' );
		} catch ( Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Preserve the documented recovery or optional-operation outcome; the catch body explains the fallback.
			// Best-effort recovery must not replace the primary failure.
 // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Best-effort recovery must not replace the primary failure.
		}
	}

	/**
	 * Reset error.
	 *
	 * @param object $database Database.
	 * @return void
	 */
	private function reset_error( object $database ): void {
		if ( property_exists( $database, 'last_error' ) ) {
			$database->last_error = '';
		}
	}

	/**
	 * Reject invalid read.
	 *
	 * @param object $database Database.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_read( object $database ): void {
		if ( '' !== trim( (string) ( $database->last_error ?? '' ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
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
		if ( 1 !== preg_match( '/^command:[a-f0-9]{64}$/D', $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
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
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
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
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		$time = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new DateTimeZone( 'UTC' ) );
		if ( ! $time instanceof DateTimeImmutable || $time->format( 'Y-m-d H:i:s' ) !== $value ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
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
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Positive integer.
	 *
	 * @param mixed $value Value.
	 * @return int
	 */
	private function positive_integer( $value ): int {
		if ( is_int( $value ) && $value > 0 ) {
			return $value;
		}
		if ( is_string( $value ) && 1 === preg_match( '/^[1-9][0-9]*$/D', $value ) ) {
			return (int) $value;
		}
		return 0;
	}
}
