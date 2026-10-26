<?php
/**
 * WordPress legacy migration execution repository.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\LegacyMigration\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use OJAI\Dashboard\Database\Foundation\Infrastructure\WordPress_Schema_Lock;
use OJAI\Dashboard\Database\Foundation\Port\Schema_Lock_Port_Interface;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Approve_Legacy_Migration;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Execute_Legacy_Migration;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Execute_Legacy_Migration_Result;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Legacy_Migration_Failure;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Approved_Legacy_Migration_Run;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Migration_Plan;
use OJAI\Dashboard\Operations\LegacyMigration\Port\Legacy_Migration_Execution_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns the atomic approved-to-running transition and its durable C62 receipt.
 */
final class WordPress_Legacy_Migration_Execution_Repository implements Legacy_Migration_Execution_Repository_Port_Interface {
	private const TABLE_RUNS       = 'oj_ai_import_runs';
	private const TABLE_AUDIT      = 'oj_ai_audit_events';
	private const TABLE_OUTBOX     = 'oj_ai_outbox_events';
	private const EVENT_STARTED    = 'legacy_migration.execution_started';
	private const AGGREGATE_TYPE   = 'legacy_migration_run';
	private const OUTBOX_SCHEMA    = 'oj_ai.legacy_migration_execution_event';
	private const AUDIT_SCHEMA     = 'oj_ai.legacy_migration_execution_audit';
	private const LOCK_NAME_PREFIX = 'oj_ai_dashboard_legacy_migration_';

	/**
	 * Initialize WordPress legacy migration execution repository dependencies and state.
	 *
	 * @param Canonical_Json                  $canonical Canonical JSON encoder used for stable digests.
	 * @param object|null                     $database Database.
	 * @param Schema_Lock_Port_Interface|null $lock Lock.
	 */
	public function __construct(
		private Canonical_Json $canonical,
		private ?object $database = null,
		private ?Schema_Lock_Port_Interface $lock = null
	) {
	}

	/**
	 * Started receipt.
	 *
	 * @param string $storage_key Storage key.
	 * @param string $request_digest Request digest.
	 * @return Execute_Legacy_Migration_Result|null
	 */
	public function started_receipt( string $storage_key, string $request_digest ): ?Execute_Legacy_Migration_Result {
		return $this->command_receipt( $storage_key, $request_digest, false );
	}

	/**
	 * Approved run.
	 *
	 * @param string $run_id Run id.
	 * @return Approved_Legacy_Migration_Run
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function approved_run( string $run_id ): Approved_Legacy_Migration_Run {
		new Public_Id( $run_id );
		$row = $this->run_row( $run_id, false );
		if ( null === $row ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::RUN_NOT_FOUND );
		}
		return $this->approved_from_row( $row );
	}

	/**
	 * Start.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Execute_Legacy_Migration_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function start( array $record ): Execute_Legacy_Migration_Result {
		$this->assert_record( $record );
		$receipt = $this->command_receipt( $record['command_storage_key'], $record['request_digest'], false );
		if ( $receipt instanceof Execute_Legacy_Migration_Result ) {
			return $receipt;
		}

		$lock          = null;
		$lock_name     = self::LOCK_NAME_PREFIX . $record['site_id'];
		$lock_acquired = false;
		$transaction   = false;
		try {
			$lock = $this->lock();
			if ( ! $lock->acquire( $lock_name, 0 ) ) {
				throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::RUN_STATE_CONFLICT );
			}
			$lock_acquired = true;
			$this->query_or_fail( 'START TRANSACTION' );
			$transaction = true;
			$receipt     = $this->command_receipt( $record['command_storage_key'], $record['request_digest'], true );
			if ( $receipt instanceof Execute_Legacy_Migration_Result ) {
				$this->query_or_fail( 'COMMIT' );
				$transaction = false;
				return $receipt;
			}

			$row = $this->run_row( (string) $record['run_public_id'], true );
			if ( null === $row ) {
				throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::RUN_NOT_FOUND );
			}
			$run = $this->approved_from_row( $row );
			if (
				$run->database_id() !== $record['run_database_id']
				|| $run->lock_version() !== $record['expected_lock_version']
				|| ! hash_equals( $run->source_fingerprint(), (string) $record['source_fingerprint'] )
				|| ! hash_equals( $run->preview_hash(), (string) $record['preview_hash'] )
				|| ! hash_equals( $run->plan_hash(), (string) $record['plan_hash'] )
				|| $run->item_total() !== $record['item_total']
			) {
				throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::RUN_STATE_CONFLICT );
			}

			if ( null !== $this->running_competitor( $run->public_id() ) ) {
				throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::RUN_STATE_CONFLICT );
			}

			$this->update_run( $record );
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
			$winner = $this->recover_receipt( $record );
			if ( $winner instanceof Execute_Legacy_Migration_Result ) {
				return $winner;
			}
			throw $failure;
		} catch ( Throwable $error ) {
			if ( $transaction ) {
				$this->rollback();
			}
			$winner = $this->recover_receipt( $record );
			if ( $winner instanceof Execute_Legacy_Migration_Result ) {
				return $winner;
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE, $error );
		} finally {
			if ( $lock_acquired && $lock instanceof Schema_Lock_Port_Interface ) {
				try {
					$lock->release( $lock_name );
				} catch ( Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Preserve the documented recovery or optional-operation outcome; the catch body explains the fallback.
					// This optional step must not replace the surrounding operation outcome.
 // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- This optional step must not replace the surrounding operation outcome.
				}
			}
		}
	}

	/**
	 * Run row.
	 *
	 * @param string $run_id Run id.
	 * @param bool   $for_update For update.
	 * @return array<string,mixed>|null
	 */
	private function run_row( string $run_id, bool $for_update ): ?array {
		$sql = 'SELECT id, public_id, lock_version, source_kind, source_version, source_fingerprint, state, preview_schema, preview_version, preview_json, preview_hash, item_total FROM ' . self::TABLE_RUNS . ' WHERE public_id = %s LIMIT 1';
		if ( $for_update ) {
			$sql .= ' FOR UPDATE';
		}
		return $this->row( $this->prepare( $sql, array( $run_id ) ) );
	}

	/**
	 * Running competitor.
	 *
	 * @param string $run_id Run id.
	 * @return array<string,mixed>|null
	 */
	private function running_competitor( string $run_id ): ?array {
		$sql = 'SELECT public_id FROM ' . self::TABLE_RUNS
			. ' WHERE state = %s AND public_id <> %s ORDER BY updated_at_utc ASC, id ASC LIMIT 1 FOR UPDATE';
		return $this->row( $this->prepare( $sql, array( 'running', $run_id ) ) );
	}

	/**
	 * Approved from row.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return Approved_Legacy_Migration_Run
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function approved_from_row( array $row ): Approved_Legacy_Migration_Run {
		if ( 'approved' !== ( $row['state'] ?? null ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::RUN_STATE_CONFLICT );
		}
		if (
			Approve_Legacy_Migration::PREVIEW_SCHEMA !== ( $row['preview_schema'] ?? null )
			|| Approve_Legacy_Migration::PREVIEW_DATABASE_VERSION !== $this->positive_integer( $row['preview_version'] ?? null )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}

		$preview = $this->decode_stored( (string) ( $row['preview_json'] ?? '' ), (string) ( $row['preview_hash'] ?? '' ) );
		$this->exact_keys( $preview, array( 'plan', 'profile', 'state' ) );
		if (
			Approve_Legacy_Migration::PREVIEW_PROFILE !== ( $preview['profile'] ?? null )
			|| 'approved' !== ( $preview['state'] ?? null )
			|| ! is_array( $preview['plan'] ?? null )
			|| array_is_list( $preview['plan'] )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		$plan = $preview['plan'];
		( new Legacy_Migration_Plan( $this->canonical ) )->assert_valid( $plan );
		$source = $plan['source'] ?? null;
		$counts = $plan['counts'] ?? null;
		if ( ! is_array( $source ) || ! is_array( $counts ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		$source_profile = (string) ( $source['profile'] ?? '' );
		$source_kind    = strstr( $source_profile, '@', true );
		if (
			! is_string( $source_kind )
			|| ! hash_equals( (string) ( $row['source_kind'] ?? '' ), $source_kind )
			|| ! hash_equals( (string) ( $row['source_version'] ?? '' ), (string) ( $source['version'] ?? '' ) )
			|| ! hash_equals( (string) ( $row['source_fingerprint'] ?? '' ), (string) ( $source['fingerprint'] ?? '' ) )
			|| (int) ( $row['item_total'] ?? -1 ) !== ( $counts['migration_candidates'] ?? null )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}

		return new Approved_Legacy_Migration_Run(
			$this->positive_integer( $row['id'] ?? null ),
			(string) ( $row['public_id'] ?? '' ),
			$this->non_negative_integer( $row['lock_version'] ?? null ),
			$source_profile,
			(string) ( $row['source_version'] ?? '' ),
			(string) ( $row['source_fingerprint'] ?? '' ),
			(string) ( $row['preview_hash'] ?? '' ),
			(string) ( $plan['plan_hash'] ?? '' ),
			$plan,
			(int) ( $row['item_total'] ?? -1 )
		);
	}

	/**
	 * Update run.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function update_run( array $record ): void {
		$database = $this->database();
		$this->reset_error( $database );
		$result = $database->update(
			self::TABLE_RUNS,
			array(
				'lock_version'       => $record['expected_lock_version'] + 1,
				'updated_by_user_id' => $record['actor_user_id'],
				'updated_at_utc'     => $record['started_at_utc'],
				'state'              => 'running',
				'checkpoint_schema'  => Execute_Legacy_Migration::CHECKPOINT_SCHEMA,
				'checkpoint_version' => Execute_Legacy_Migration::CHECKPOINT_VERSION,
				'checkpoint_json'    => $record['checkpoint_json'],
				'checkpoint_hash'    => $record['checkpoint_hash'],
				'error_code'         => null,
			),
			array(
				'id'           => $record['run_database_id'],
				'lock_version' => $record['expected_lock_version'],
				'state'        => 'approved',
			)
		);
		if ( false === $result || '' !== trim( (string) ( $database->last_error ?? '' ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		if ( 1 !== $result ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::RUN_STATE_CONFLICT );
		}
	}

	/**
	 * Command receipt.
	 *
	 * @param string $key Key.
	 * @param string $digest Digest.
	 * @param bool   $for_update For update.
	 * @return Execute_Legacy_Migration_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function command_receipt( string $key, string $digest, bool $for_update ): ?Execute_Legacy_Migration_Result {
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
			self::EVENT_STARTED !== ( $row['event_type'] ?? null )
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
		$this->exact_keys(
			$receipt,
			array( 'checkpoint', 'checkpoint_hash', 'item_total', 'plan_hash', 'run_id', 'source_fingerprint', 'started_at_utc', 'state' )
		);
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
				'checkpoint_hash'    => $record['checkpoint_hash'],
				'command_id'         => $record['command_id'],
				'item_total'         => $record['item_total'],
				'plan_hash'          => $record['plan_hash'],
				'request_digest'     => $record['request_digest'],
				'source_fingerprint' => $record['source_fingerprint'],
			)
		);
		$metadata_hash = hash( 'sha256', $metadata_json );
		$event_hash    = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'action_key'          => self::EVENT_STARTED,
					'actor_user_id'       => $record['actor_user_id'],
					'after_digest'        => $record['checkpoint_hash'],
					'before_digest'       => $record['preview_hash'],
					'correlation_id'      => $record['correlation_id'],
					'metadata_hash'       => $metadata_hash,
					'object_public_id'    => $record['run_public_id'],
					'object_type'         => self::AGGREGATE_TYPE,
					'occurred_at_utc'     => $record['started_at_utc'],
					'previous_event_hash' => $previous,
				)
			)
		);
		$this->insert_or_fail(
			self::TABLE_AUDIT,
			array(
				'public_id'           => $record['audit_public_id'],
				'occurred_at_utc'     => $record['started_at_utc'],
				'actor_user_id'       => $record['actor_user_id'],
				'action_key'          => self::EVENT_STARTED,
				'object_type'         => self::AGGREGATE_TYPE,
				'object_public_id'    => $record['run_public_id'],
				'context_type'        => null,
				'context_id'          => null,
				'context_revision'    => null,
				'correlation_id'      => $record['correlation_id'],
				'before_digest'       => $record['preview_hash'],
				'after_digest'        => $record['checkpoint_hash'],
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
	 * @param Execute_Legacy_Migration_Result $result Result.
	 * @return void
	 */
	private function insert_outbox( array $record, Execute_Legacy_Migration_Result $result ): void {
		$payload_json = $this->canonical->encode(
			array(
				'command_id'     => $record['command_id'],
				'event'          => self::EVENT_STARTED,
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
				'created_at_utc'       => $record['started_at_utc'],
				'updated_by_user_id'   => $record['actor_user_id'],
				'updated_at_utc'       => $record['started_at_utc'],
				'aggregate_type'       => self::AGGREGATE_TYPE,
				'aggregate_public_id'  => $record['run_public_id'],
				'event_type'           => self::EVENT_STARTED,
				'idempotency_key'      => $record['command_storage_key'],
				'payload_schema'       => self::OUTBOX_SCHEMA,
				'payload_version'      => 1,
				'payload_json'         => $payload_json,
				'payload_hash'         => hash( 'sha256', $payload_json ),
				'available_at_utc'     => $record['started_at_utc'],
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
		$this->exact_keys(
			$record,
			array(
				'actor_user_id',
				'audit_public_id',
				'checkpoint',
				'checkpoint_hash',
				'checkpoint_json',
				'command_id',
				'command_storage_key',
				'correlation_id',
				'expected_lock_version',
				'item_total',
				'outbox_public_id',
				'plan_hash',
				'preview_hash',
				'request_digest',
				'run_database_id',
				'run_public_id',
				'site_id',
				'source_fingerprint',
				'started_at_utc',
			)
		);
		if (
			! is_int( $record['actor_user_id'] ) || $record['actor_user_id'] < 1
			|| ! is_int( $record['run_database_id'] ) || $record['run_database_id'] < 1
			|| ! is_int( $record['expected_lock_version'] ) || $record['expected_lock_version'] < 0
			|| ! is_int( $record['item_total'] ) || $record['item_total'] < 0
			|| ! is_string( $record['site_id'] ) || 1 !== preg_match( '/^[1-9][0-9]{0,18}$/D', $record['site_id'] )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		foreach ( array( 'audit_public_id', 'command_id', 'outbox_public_id', 'run_public_id' ) as $key ) {
			new Public_Id( (string) $record[ $key ] );
		}
		foreach ( array( 'checkpoint_hash', 'plan_hash', 'preview_hash', 'request_digest', 'source_fingerprint' ) as $key ) {
			$this->hash_value( (string) $record[ $key ] );
		}
		$this->storage_key( (string) $record['command_storage_key'] );
		$this->safe_token( (string) $record['correlation_id'], 8, 128 );
		$this->utc( (string) $record['started_at_utc'] );
		if ( ! is_array( $record['checkpoint'] ) || array_is_list( $record['checkpoint'] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		$this->assert_checkpoint( $record['checkpoint'] );
		if ( ! hash_equals( (string) $record['source_fingerprint'], (string) $record['checkpoint']['source']['fingerprint'] ) || ! hash_equals( (string) $record['plan_hash'], (string) $record['checkpoint']['plan_hash'] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		if (
			! hash_equals( (string) $record['checkpoint_hash'], hash( 'sha256', (string) $record['checkpoint_json'] ) )
			|| ! hash_equals( (string) $record['checkpoint_json'], $this->canonical->encode( $record['checkpoint'] ) )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Reject invalid checkpoint.
	 *
	 * @param array<string,mixed> $checkpoint Checkpoint.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_checkpoint( array $checkpoint ): void {
		$this->exact_keys( $checkpoint, array( 'batch_size', 'cursor', 'phase', 'plan_hash', 'profile', 'source', 'totals' ) );
		$source = $checkpoint['source'] ?? null;
		$cursor = $checkpoint['cursor'] ?? null;
		$totals = $checkpoint['totals'] ?? null;
		if ( ! is_array( $source ) || ! is_array( $cursor ) || ! is_array( $totals ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		$this->exact_keys( $source, array( 'fingerprint', 'profile' ) );
		$this->exact_keys( $cursor, array( 'after_source_id', 'next_item_no', 'table_index' ) );
		$this->exact_keys( $totals, array( 'failure', 'skipped', 'success' ) );
		if (
			Execute_Legacy_Migration::CHECKPOINT_PROFILE !== ( $checkpoint['profile'] ?? null )
			|| 'running' !== ( $checkpoint['phase'] ?? null )
			|| Execute_Legacy_Migration::BATCH_SIZE !== ( $checkpoint['batch_size'] ?? null )
			|| 0 !== ( $cursor['table_index'] ?? null )
			|| 0 !== ( $cursor['after_source_id'] ?? null )
			|| 1 !== ( $cursor['next_item_no'] ?? null )
			|| 0 !== ( $totals['success'] ?? null )
			|| 0 !== ( $totals['failure'] ?? null )
			|| 0 !== ( $totals['skipped'] ?? null )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', (string) ( $checkpoint['plan_hash'] ?? '' ) )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', (string) ( $source['fingerprint'] ?? '' ) )
			|| 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@\/-]{7,127}$/D', (string) ( $source['profile'] ?? '' ) )
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
	 * @return Execute_Legacy_Migration_Result
	 */
	private function result_from_record( array $record, bool $replayed ): Execute_Legacy_Migration_Result {
		return new Execute_Legacy_Migration_Result(
			(string) $record['run_public_id'],
			'running',
			(string) $record['source_fingerprint'],
			(string) $record['plan_hash'],
			(string) $record['checkpoint_hash'],
			$record['checkpoint'],
			(int) $record['item_total'],
			(string) $record['started_at_utc'],
			$replayed
		);
	}

	/**
	 * Result from receipt.
	 *
	 * @param array<string,mixed> $receipt Receipt.
	 * @param bool                $replayed Replayed.
	 * @return Execute_Legacy_Migration_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function result_from_receipt( array $receipt, bool $replayed ): Execute_Legacy_Migration_Result {
		$checkpoint = $receipt['checkpoint'] ?? null;
		if ( ! is_array( $checkpoint ) || array_is_list( $checkpoint ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		$this->assert_checkpoint( $checkpoint );
		if ( ! hash_equals( (string) ( $receipt['checkpoint_hash'] ?? '' ), hash( 'sha256', $this->canonical->encode( $checkpoint ) ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		return new Execute_Legacy_Migration_Result(
			(string) ( $receipt['run_id'] ?? '' ),
			(string) ( $receipt['state'] ?? '' ),
			(string) ( $receipt['source_fingerprint'] ?? '' ),
			(string) ( $receipt['plan_hash'] ?? '' ),
			(string) ( $receipt['checkpoint_hash'] ?? '' ),
			$checkpoint,
			(int) ( $receipt['item_total'] ?? -1 ),
			(string) ( $receipt['started_at_utc'] ?? '' ),
			$replayed
		);
	}

	/**
	 * Receipt payload.
	 *
	 * @param Execute_Legacy_Migration_Result $result Result.
	 * @return array<string,mixed>
	 */
	private function receipt_payload( Execute_Legacy_Migration_Result $result ): array {
		return array(
			'run_id'             => $result->run_id(),
			'state'              => $result->state(),
			'source_fingerprint' => $result->source_fingerprint(),
			'plan_hash'          => $result->plan_hash(),
			'checkpoint_hash'    => $result->checkpoint_hash(),
			'checkpoint'         => $result->checkpoint(),
			'item_total'         => $result->item_total(),
			'started_at_utc'     => $result->started_at_utc(),
		);
	}

	/**
	 * Recover receipt.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Execute_Legacy_Migration_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function recover_receipt( array $record ): ?Execute_Legacy_Migration_Result {
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
	 * Lock.
	 *
	 * @return Schema_Lock_Port_Interface
	 */
	private function lock(): Schema_Lock_Port_Interface {
		return $this->lock ??= new WordPress_Schema_Lock( $this->database() );
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
		if ( ! is_array( $row ) || array_is_list( $row ) ) {
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
	 * Exact keys.
	 *
	 * @param array<string,mixed> $value Value.
	 * @param array<int,string>   $expected Expected.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function exact_keys( array $value, array $expected ): void {
		$actual = array_keys( $value );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected ) {
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
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function positive_integer( $value ): int {
		if ( is_int( $value ) && $value > 0 ) {
			return $value;
		}
		if ( is_string( $value ) && 1 === preg_match( '/^[1-9][0-9]*$/D', $value ) ) {
			return (int) $value;
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
		throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
	}

	/**
	 * Non negative integer.
	 *
	 * @param mixed $value Value.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function non_negative_integer( $value ): int {
		if ( is_int( $value ) && $value >= 0 ) {
			return $value;
		}
		if ( is_string( $value ) && 1 === preg_match( '/^(?:0|[1-9][0-9]*)$/D', $value ) ) {
			return (int) $value;
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
		throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
	}
}
