<?php
/**
 * WordPress purge operation repository.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\Purge\Infrastructure;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use OJAI\Dashboard\Database\Foundation\Infrastructure\WordPress_Schema_Lock;
use OJAI\Dashboard\Database\Foundation\Installation_State;
use OJAI\Dashboard\Database\Foundation\Port\Schema_Lock_Port_Interface;
use OJAI\Dashboard\Database\Foundation\Schema_Catalog;
use OJAI\Dashboard\Operations\Purge\Application\Cancel_Purge_Result;
use OJAI\Dashboard\Operations\Purge\Application\Execute_Purge;
use OJAI\Dashboard\Operations\Purge\Application\Execute_Purge_Result;
use OJAI\Dashboard\Operations\Purge\Application\Purge_Failure;
use OJAI\Dashboard\Operations\Purge\Domain\Purge_Batch_Result;
use OJAI\Dashboard\Operations\Purge\Domain\Purge_Job_Snapshot;
use OJAI\Dashboard\Operations\Purge\Domain\Purge_Target_Catalog;
use OJAI\Dashboard\Operations\Purge\Port\Purge_Operation_Repository_Port_Interface;
use OJAI\Dashboard\Storage\Port\Private_File_Store_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Literal-table, checkpointed implementation of C73 and C74.
 */
final class WordPress_Purge_Operation_Repository implements Purge_Operation_Repository_Port_Interface {
	private const TABLE_INSTALLATIONS     = 'oj_ai_installations';
	private const TABLE_JOBS              = 'oj_ai_purge_jobs';
	private const TABLE_AUDIT             = 'oj_ai_audit_events';
	private const TABLE_OUTBOX            = 'oj_ai_outbox_events';
	private const TABLE_PRIVATE_FILES     = 'oj_ai_private_files';
	private const AGGREGATE_TYPE          = 'purge_job';
	private const EVENT_STARTED           = 'purge.started';
	private const EVENT_CANCELLED         = 'purge.cancelled';
	private const EVENT_COMPLETED         = 'purge.completed';
	private const EVENT_FAILED            = 'purge.failed';
	private const START_OUTBOX_SCHEMA     = 'oj_ai.purge_execution_event';
	private const CANCEL_OUTBOX_SCHEMA    = 'oj_ai.purge_cancellation_event';
	private const LIFECYCLE_OUTBOX_SCHEMA = 'oj_ai.purge_lifecycle_event';
	private const START_AUDIT_SCHEMA      = 'oj_ai.purge_execution_audit';
	private const CANCEL_AUDIT_SCHEMA     = 'oj_ai.purge_cancellation_audit';
	private const LIFECYCLE_AUDIT_SCHEMA  = 'oj_ai.purge_lifecycle_audit';
	private const APPROVAL_AUDIT_SCHEMA   = 'oj_ai.purge_approval_audit';
	private const LOCK_TIMEOUT_SECONDS    = 30;

	/**
	 * Monotonic.
	 *
	 * @var Closure
	 */
	private Closure $monotonic;

	/**
	 * Initialize WordPress purge operation repository dependencies and state.
	 *
	 * @param Canonical_Json                    $canonical Canonical JSON encoder used for stable digests.
	 * @param Purge_Target_Catalog              $targets Targets.
	 * @param Private_File_Store_Port_Interface $files Files.
	 * @param Public_Id_Generator               $ids Ids.
	 * @param object|null                       $database Database.
	 * @param Schema_Lock_Port_Interface|null   $lock Lock.
	 * @param callable|null                     $monotonic Monotonic.
	 */
	public function __construct(
		private Canonical_Json $canonical,
		private Purge_Target_Catalog $targets,
		private Private_File_Store_Port_Interface $files,
		private Public_Id_Generator $ids,
		private ?object $database = null,
		private ?Schema_Lock_Port_Interface $lock = null,
		?callable $monotonic = null
	) {
		$this->monotonic = null === $monotonic
			? static fn (): float => microtime( true )
			: Closure::fromCallable( $monotonic );
	}

	/**
	 * Execution receipt.
	 *
	 * @param string $storage_key Storage key.
	 * @param string $request_digest Request digest.
	 * @return Execute_Purge_Result|null
	 */
	public function execution_receipt( string $storage_key, string $request_digest ): ?Execute_Purge_Result {
		$receipt = $this->command_receipt( $storage_key, $request_digest, self::EVENT_STARTED, self::START_OUTBOX_SCHEMA, false );
		return is_array( $receipt ) ? $this->execution_result( $receipt, true ) : null;
	}

	/**
	 * Cancellation receipt.
	 *
	 * @param string $storage_key Storage key.
	 * @param string $request_digest Request digest.
	 * @return Cancel_Purge_Result|null
	 */
	public function cancellation_receipt( string $storage_key, string $request_digest ): ?Cancel_Purge_Result {
		$receipt = $this->command_receipt( $storage_key, $request_digest, self::EVENT_CANCELLED, self::CANCEL_OUTBOX_SCHEMA, false );
		return is_array( $receipt ) ? $this->cancellation_result( $receipt, true ) : null;
	}

	/**
	 * Job.
	 *
	 * @param string $job_id Job id.
	 * @return Purge_Job_Snapshot
	 */
	public function job( string $job_id ): Purge_Job_Snapshot {
		return $this->job_snapshot( $job_id, false );
	}

	/**
	 * Start.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Execute_Purge_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function start( array $record ): Execute_Purge_Result {
		$this->assert_start_record( $record );
		$receipt = $this->execution_receipt( $record['command_storage_key'], $record['request_digest'] );
		if ( $receipt instanceof Execute_Purge_Result ) {
			return $receipt;
		}

		$lock_name   = $this->acquire_lock( (string) $record['site_id'] );
		$transaction = false;
		try {
			$this->query_or_fail( 'START TRANSACTION' );
			$transaction = true;
			$stored      = $this->command_receipt( $record['command_storage_key'], $record['request_digest'], self::EVENT_STARTED, self::START_OUTBOX_SCHEMA, true );
			if ( is_array( $stored ) ) {
				$this->query_or_fail( 'COMMIT' );
				$transaction = false;
				return $this->execution_result( $stored, true );
			}

			$job = $this->job_snapshot( (string) $record['job_public_id'], true );
			if (
				'approved' !== $job->state()
				|| $job->database_id() !== $record['expected_job_database_id']
				|| $job->lock_version() !== $record['expected_job_lock_version']
				|| $job->installation_database_id() !== $record['expected_installation_database_id']
				|| $job->installation_lock_version() !== $record['expected_installation_lock_version']
				|| ! hash_equals( $job->installation_state(), (string) $record['expected_installation_state'] )
				|| ! hash_equals( $job->preview_json_hash(), (string) $record['expected_preview_json_hash'] )
				|| ! hash_equals( $job->preview_hash(), (string) $record['preview_hash'] )
			) {
				throw Purge_Failure::because( Purge_Failure::JOB_STATE_CONFLICT );
			}

			$job_update = $this->prepare(
				'UPDATE ' . self::TABLE_JOBS . ' SET lock_version = lock_version + 1, updated_by_user_id = %d, updated_at_utc = %s, state = %s, checkpoint_schema = %s, checkpoint_version = %d, checkpoint_json = %s, checkpoint_hash = %s, error_code = NULL WHERE id = %d AND public_id = %s AND lock_version = %d AND state = %s AND preview_hash = %s',
				array(
					$record['actor_user_id'],
					$record['started_at_utc'],
					'running',
					Execute_Purge::CHECKPOINT_SCHEMA,
					Execute_Purge::CHECKPOINT_VERSION,
					$record['checkpoint_json'],
					$record['checkpoint_hash'],
					$record['expected_job_database_id'],
					$record['job_public_id'],
					$record['expected_job_lock_version'],
					'approved',
					$record['expected_preview_json_hash'],
				)
			);
			if ( 1 !== $this->query_affected( $job_update ) ) {
				throw Purge_Failure::because( Purge_Failure::JOB_STATE_CONFLICT );
			}

			$installation_update = $this->prepare(
				'UPDATE ' . self::TABLE_INSTALLATIONS . ' SET lock_version = lock_version + 1, updated_by_user_id = %d, updated_at_utc = %s, state = %s WHERE id = %d AND lock_version = %d AND state = %s',
				array(
					$record['actor_user_id'],
					$record['started_at_utc'],
					Installation_State::MAINTENANCE_REQUIRED,
					$record['expected_installation_database_id'],
					$record['expected_installation_lock_version'],
					$record['expected_installation_state'],
				)
			);
			if ( 1 !== $this->query_affected( $installation_update ) ) {
				throw Purge_Failure::because( Purge_Failure::MAINTENANCE_UNAVAILABLE );
			}

			$result = new Execute_Purge_Result(
				(string) $record['job_public_id'],
				'running',
				(string) $record['preview_hash'],
				$record['checkpoint'],
				(string) $record['checkpoint_hash'],
				(string) $record['started_at_utc'],
				false
			);
			$this->append_audit(
				self::EVENT_STARTED,
				self::START_AUDIT_SCHEMA,
				(string) $record['audit_public_id'],
				(int) $record['actor_user_id'],
				(string) $record['job_public_id'],
				(string) $record['correlation_id'],
				(string) $record['preview_hash'],
				(string) $record['checkpoint_hash'],
				(string) $record['started_at_utc'],
				array(
					'command_id'     => $record['command_id'],
					'preview_hash'   => $record['preview_hash'],
					'request_digest' => $record['request_digest'],
					'scope_hash'     => $job->scope_hash(),
				)
			);
			$this->insert_command_outbox(
				self::EVENT_STARTED,
				self::START_OUTBOX_SCHEMA,
				(string) $record['outbox_public_id'],
				(int) $record['actor_user_id'],
				(string) $record['job_public_id'],
				(string) $record['command_storage_key'],
				(string) $record['command_id'],
				(string) $record['request_digest'],
				$this->execution_receipt_payload( $result ),
				(string) $record['started_at_utc']
			);
			$this->query_or_fail( 'COMMIT' );
			$transaction = false;
			return $result;
		} catch ( Purge_Failure $failure ) {
			if ( $transaction ) {
				$this->rollback();
			}
			if ( Purge_Failure::STORAGE_UNAVAILABLE === $failure->reason() ) {
				$winner = $this->execution_receipt( (string) $record['command_storage_key'], (string) $record['request_digest'] );
				if ( $winner instanceof Execute_Purge_Result ) {
					return $winner;
				}
			}
			throw $failure;
		} catch ( Throwable $error ) {
			if ( $transaction ) {
				$this->rollback();
			}
			$winner = $this->execution_receipt( (string) $record['command_storage_key'], (string) $record['request_digest'] );
			if ( $winner instanceof Execute_Purge_Result ) {
				return $winner;
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Purge_Failure::because( Purge_Failure::STORAGE_UNAVAILABLE, $error );
		} finally {
			$this->release_lock( $lock_name );
		}
	}

	/**
	 * Cancel.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Cancel_Purge_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function cancel( array $record ): Cancel_Purge_Result {
		$this->assert_cancel_record( $record );
		$receipt = $this->cancellation_receipt( $record['command_storage_key'], $record['request_digest'] );
		if ( $receipt instanceof Cancel_Purge_Result ) {
			return $receipt;
		}

		$lock_name   = $this->acquire_lock( (string) $record['site_id'] );
		$transaction = false;
		try {
			$this->query_or_fail( 'START TRANSACTION' );
			$transaction = true;
			$stored      = $this->command_receipt( $record['command_storage_key'], $record['request_digest'], self::EVENT_CANCELLED, self::CANCEL_OUTBOX_SCHEMA, true );
			if ( is_array( $stored ) ) {
				$this->query_or_fail( 'COMMIT' );
				$transaction = false;
				return $this->cancellation_result( $stored, true );
			}

			$job = $this->job_snapshot( (string) $record['job_public_id'], true );
			if (
				! hash_equals( $job->state(), (string) $record['expected_state'] )
				|| ! in_array( $job->state(), array( 'approved', 'running' ), true )
				|| $job->database_id() !== $record['expected_job_database_id']
				|| $job->lock_version() !== $record['expected_job_lock_version']
				|| $job->installation_database_id() !== $record['expected_installation_database_id']
				|| $job->installation_lock_version() !== $record['expected_installation_lock_version']
				|| ! hash_equals( $job->preview_json_hash(), (string) $record['expected_preview_json_hash'] )
				|| ! hash_equals( $job->preview_hash(), (string) $record['preview_hash'] )
			) {
				throw Purge_Failure::because( Purge_Failure::JOB_STATE_CONFLICT );
			}

			$checkpoint           = $job->checkpoint();
			$checkpoint_hash      = $job->checkpoint_hash();
			$maintenance_retained = Installation_State::MAINTENANCE_REQUIRED === $job->installation_state();
			$ledger_mutations     = 3;
			if ( 'running' === $job->state() ) {
				if ( ! is_array( $checkpoint ) || null === $checkpoint_hash ) {
					throw Purge_Failure::because( Purge_Failure::CHECKPOINT_INVALID );
				}
				$this->assert_checkpoint( $checkpoint, $job->preview_hash() );
				$totals               = $checkpoint['totals'];
				$has_deletions        = 0 !== $totals['deleted_rows'] || 0 !== $totals['deleted_files'] || 0 !== $totals['deleted_bytes'];
				$previous_state       = (string) $checkpoint['maintenance']['previous_installation_state'];
				$maintenance_retained = $has_deletions || Installation_State::MAINTENANCE_REQUIRED === $previous_state;
				if ( ! $has_deletions && Installation_State::MAINTENANCE_REQUIRED !== $previous_state ) {
					$installation_update = $this->prepare(
						'UPDATE ' . self::TABLE_INSTALLATIONS . ' SET lock_version = lock_version + 1, updated_by_user_id = %d, updated_at_utc = %s, state = %s WHERE id = %d AND lock_version = %d AND state = %s',
						array(
							$record['actor_user_id'],
							$record['cancelled_at_utc'],
							$previous_state,
							$record['expected_installation_database_id'],
							$record['expected_installation_lock_version'],
							Installation_State::MAINTENANCE_REQUIRED,
						)
					);
					if ( 1 !== $this->query_affected( $installation_update ) ) {
						throw Purge_Failure::because( Purge_Failure::MAINTENANCE_UNAVAILABLE );
					}
					$ledger_mutations = 4;
				}
				$checkpoint['phase'] = 'cancelled';
				$checkpoint_json     = $this->canonical->encode( $checkpoint );
				$checkpoint_hash     = hash( 'sha256', $checkpoint_json );
			} else {
				$checkpoint_json = null;
			}

			if ( null !== $checkpoint_json ) {
				$job_update = $this->prepare(
					'UPDATE ' . self::TABLE_JOBS . ' SET lock_version = lock_version + 1, updated_by_user_id = %d, updated_at_utc = %s, state = %s, checkpoint_json = %s, checkpoint_hash = %s, completed_at_utc = %s, error_code = NULL WHERE id = %d AND public_id = %s AND lock_version = %d AND state = %s AND preview_hash = %s',
					array(
						$record['actor_user_id'],
						$record['cancelled_at_utc'],
						'cancelled',
						$checkpoint_json,
						$checkpoint_hash,
						$record['cancelled_at_utc'],
						$record['expected_job_database_id'],
						$record['job_public_id'],
						$record['expected_job_lock_version'],
						$record['expected_state'],
						$record['expected_preview_json_hash'],
					)
				);
			} else {
				$job_update = $this->prepare(
					'UPDATE ' . self::TABLE_JOBS . ' SET lock_version = lock_version + 1, updated_by_user_id = %d, updated_at_utc = %s, state = %s, checkpoint_schema = NULL, checkpoint_version = NULL, checkpoint_json = NULL, checkpoint_hash = NULL, completed_at_utc = %s, error_code = NULL WHERE id = %d AND public_id = %s AND lock_version = %d AND state = %s AND preview_hash = %s',
					array(
						$record['actor_user_id'],
						$record['cancelled_at_utc'],
						'cancelled',
						$record['cancelled_at_utc'],
						$record['expected_job_database_id'],
						$record['job_public_id'],
						$record['expected_job_lock_version'],
						$record['expected_state'],
						$record['expected_preview_json_hash'],
					)
				);
			}
			if ( 1 !== $this->query_affected( $job_update ) ) {
				throw Purge_Failure::because( Purge_Failure::JOB_STATE_CONFLICT );
			}

			$result = new Cancel_Purge_Result(
				(string) $record['job_public_id'],
				'cancelled',
				(string) $record['preview_hash'],
				$checkpoint,
				$checkpoint_hash,
				(string) $record['cancelled_at_utc'],
				$maintenance_retained,
				$ledger_mutations,
				false
			);
			$this->append_audit(
				self::EVENT_CANCELLED,
				self::CANCEL_AUDIT_SCHEMA,
				(string) $record['audit_public_id'],
				(int) $record['actor_user_id'],
				(string) $record['job_public_id'],
				(string) $record['correlation_id'],
				$job->checkpoint_hash() ?? $job->preview_hash(),
				$checkpoint_hash ?? $job->preview_hash(),
				(string) $record['cancelled_at_utc'],
				array(
					'command_id'           => $record['command_id'],
					'maintenance_retained' => $maintenance_retained,
					'preview_hash'         => $record['preview_hash'],
					'request_digest'       => $record['request_digest'],
				)
			);
			$this->insert_command_outbox(
				self::EVENT_CANCELLED,
				self::CANCEL_OUTBOX_SCHEMA,
				(string) $record['outbox_public_id'],
				(int) $record['actor_user_id'],
				(string) $record['job_public_id'],
				(string) $record['command_storage_key'],
				(string) $record['command_id'],
				(string) $record['request_digest'],
				$this->cancellation_receipt_payload( $result, $ledger_mutations ),
				(string) $record['cancelled_at_utc']
			);
			$this->query_or_fail( 'COMMIT' );
			$transaction = false;
			return $result;
		} catch ( Purge_Failure $failure ) {
			if ( $transaction ) {
				$this->rollback();
			}
			if ( Purge_Failure::STORAGE_UNAVAILABLE === $failure->reason() ) {
				$winner = $this->cancellation_receipt( (string) $record['command_storage_key'], (string) $record['request_digest'] );
				if ( $winner instanceof Cancel_Purge_Result ) {
					return $winner;
				}
			}
			throw $failure;
		} catch ( Throwable $error ) {
			if ( $transaction ) {
				$this->rollback();
			}
			$winner = $this->cancellation_receipt( (string) $record['command_storage_key'], (string) $record['request_digest'] );
			if ( $winner instanceof Cancel_Purge_Result ) {
				return $winner;
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Purge_Failure::because( Purge_Failure::STORAGE_UNAVAILABLE, $error );
		} finally {
			$this->release_lock( $lock_name );
		}
	}

	/**
	 * Process batch.
	 *
	 * @param string $job_id Job id.
	 * @param string $site_id Site id.
	 * @param string $processed_at_utc Processed at utc.
	 * @return Purge_Batch_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function process_batch( string $job_id, string $site_id, string $processed_at_utc ): Purge_Batch_Result {
		new Public_Id( $job_id );
		$this->site_id( $site_id );
		$this->utc( $processed_at_utc );
		$lock_name   = $this->acquire_lock( $site_id );
		$transaction = false;
		try {
			$this->query_or_fail( 'START TRANSACTION' );
			$transaction = true;
			$row         = $this->batch_job_row( $job_id );
			$state       = (string) ( $row['state'] ?? '' );
			if ( ! in_array( $state, array( 'running', 'completed', 'cancelled', 'failed' ), true ) ) {
				throw Purge_Failure::because( Purge_Failure::JOB_STATE_CONFLICT );
			}
			$preview      = $this->decode_stored( (string) ( $row['preview_json'] ?? '' ), (string) ( $row['preview_hash'] ?? '' ) );
			$preview_hash = (string) ( $preview['preview_hash'] ?? '' );
			$this->hash_value( $preview_hash );
			$checkpoint = $this->decode_checkpoint_row( $row );
			$this->assert_checkpoint( $checkpoint, $preview_hash );
			if ( 'running' !== $state ) {
				$this->query_or_fail( 'COMMIT' );
				$transaction = false;
				return new Purge_Batch_Result( $job_id, $state, $checkpoint, (string) $row['checkpoint_hash'] );
			}
			$this->assert_maintenance_state();

			$actor_user_id = $this->positive_integer( $row['approved_by_user_id'] ?? null );
			if ( $actor_user_id < 1 ) {
				throw Purge_Failure::because( Purge_Failure::CHECKPOINT_INVALID );
			}
			$remaining = Execute_Purge::BATCH_SIZE;
			$deadline  = $this->monotonic_now() + Execute_Purge::TIME_LIMIT_SECONDS;
			if ( 'private_files' === $checkpoint['cursor']['stage'] ) {
				$this->purge_private_files( $checkpoint, $remaining, $deadline, $actor_user_id, $processed_at_utc );
			}
			if ( $remaining > 0 && $this->monotonic_now() < $deadline && 'tables' === $checkpoint['cursor']['stage'] ) {
				$this->purge_tables( $checkpoint, $remaining, $deadline );
			}

			$terminal_state = 'running';
			$error_code     = null;
			if ( 'verify' === $checkpoint['cursor']['stage'] ) {
				$postcondition               = $this->postcondition( $processed_at_utc );
				$checkpoint['postcondition'] = $postcondition;
				$expected                    = $checkpoint['expected'];
				$totals                      = $checkpoint['totals'];
				$verified                    = 0 === $postcondition['remaining_rows']
					&& 0 === $postcondition['remaining_files']
					&& 0 === $postcondition['remaining_blob_bytes']
					&& $totals['deleted_rows'] === $expected['target_row_count']
					&& $totals['deleted_files'] === $expected['private_file_count']
					&& $totals['deleted_bytes'] === $expected['private_blob_bytes'];
				$terminal_state              = $verified ? 'completed' : 'failed';
				$error_code                  = $verified ? null : 'purge_postcondition_mismatch';
				$checkpoint['phase']         = $terminal_state;
			}

			$checkpoint_json = $this->canonical->encode( $checkpoint );
			$checkpoint_hash = hash( 'sha256', $checkpoint_json );
			$this->save_checkpoint( $row, $terminal_state, $checkpoint_json, $checkpoint_hash, $processed_at_utc, $error_code );
			if ( 'running' !== $terminal_state ) {
				$event = 'completed' === $terminal_state ? self::EVENT_COMPLETED : self::EVENT_FAILED;
				$this->append_lifecycle_event(
					$event,
					$actor_user_id,
					$job_id,
					$preview_hash,
					$checkpoint_hash,
					$processed_at_utc,
					$checkpoint,
					$error_code
				);
			}
			$this->query_or_fail( 'COMMIT' );
			$transaction = false;
			return new Purge_Batch_Result( $job_id, $terminal_state, $checkpoint, $checkpoint_hash );
		} catch ( Purge_Failure $failure ) {
			if ( $transaction ) {
				$this->rollback();
			}
			throw $failure;
		} catch ( Throwable $error ) {
			if ( $transaction ) {
				$this->rollback();
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Purge_Failure::because( Purge_Failure::DELETE_FAILED, $error );
		} finally {
			$this->release_lock( $lock_name );
		}
	}

	/**
	 * Purge private files.
	 *
	 * @param array<string,mixed> &$checkpoint Checkpoint.
	 * @param int                 &$remaining Remaining.
	 * @param float               $deadline Deadline.
	 * @param int                 $actor_user_id Authenticated actor from the access context.
	 * @param string              $processed_at_utc Processed at utc.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function purge_private_files( array &$checkpoint, int &$remaining, float $deadline, int $actor_user_id, string $processed_at_utc ): void {
		$limit = max( 1, $remaining );
		$sql   = 'SELECT id, storage_key, byte_size, legal_hold_until_utc FROM ' . self::TABLE_PRIVATE_FILES
			. ' WHERE purged_at_utc IS NULL ORDER BY id ASC LIMIT ' . $limit . ' FOR UPDATE';
		$rows  = $this->rows( $sql );
		foreach ( $rows as $row ) {
			if ( $remaining < 1 || $this->monotonic_now() >= $deadline ) {
				break;
			}
			if ( null !== ( $row['legal_hold_until_utc'] ?? null ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Purge_Failure::because( Purge_Failure::LEGAL_HOLD_BLOCKED );
			}
			$id          = $this->positive_integer( $row['id'] ?? null );
			$bytes       = $this->non_negative_integer( $row['byte_size'] ?? null );
			$storage_key = $row['storage_key'] ?? null;
			if ( $id < 1 || ! is_string( $storage_key ) || '' === $storage_key ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Purge_Failure::because( Purge_Failure::CHECKPOINT_INVALID );
			}
			try {
				$this->files->delete( $storage_key );
			} catch ( Throwable $error ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Purge_Failure::because( Purge_Failure::DELETE_FAILED, $error );
			}
			$updated = $this->query_affected(
				$this->prepare(
					'UPDATE ' . self::TABLE_PRIVATE_FILES . ' SET lock_version = lock_version + 1, updated_by_user_id = %d, updated_at_utc = %s, state = %s, purged_at_utc = %s WHERE id = %d AND purged_at_utc IS NULL',
					array( $actor_user_id, $processed_at_utc, 'purged', $processed_at_utc, $id )
				)
			);
			if ( 1 !== $updated ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Purge_Failure::because( Purge_Failure::DELETE_FAILED );
			}
			++$checkpoint['totals']['deleted_files'];
			$checkpoint['totals']['deleted_bytes'] = $this->checked_add( $checkpoint['totals']['deleted_bytes'], $bytes );
			--$remaining;
		}
		if ( count( $rows ) < $limit || 0 === $this->non_negative_integer( $this->scalar( 'SELECT COUNT(*) FROM ' . self::TABLE_PRIVATE_FILES . ' WHERE purged_at_utc IS NULL' ) ) ) {
			$checkpoint['cursor']['stage'] = 'tables';
		}
	}

	/**
	 * Purge tables.
	 *
	 * @param array<string,mixed> &$checkpoint Checkpoint.
	 * @param int                 &$remaining Remaining.
	 * @param float               $deadline Deadline.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function purge_tables( array &$checkpoint, int &$remaining, float $deadline ): void {
		$tables = $this->targets->table_names();
		while ( $remaining > 0 && $this->monotonic_now() < $deadline ) {
			$index = $checkpoint['cursor']['table_index'];
			if ( $index >= count( $tables ) ) {
				$checkpoint['cursor']['stage'] = 'verify';
				return;
			}
			$table = $tables[ $index ];
			$limit = $remaining;
			$ids   = $this->deletable_ids( $table, $limit );
			if ( array() === $ids ) {
				$count = $this->non_negative_integer( $this->scalar( 'SELECT COUNT(*) FROM `' . $table . '`' ) );
				if ( 0 !== $count ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					throw Purge_Failure::because( Purge_Failure::DELETE_FAILED );
				}
				++$checkpoint['cursor']['table_index'];
				continue;
			}
			$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
			$deleted      = $this->query_affected(
				$this->prepare( 'DELETE FROM `' . $table . '` WHERE id IN (' . $placeholders . ')', $ids )
			);
			if ( $deleted !== count( $ids ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Purge_Failure::because( Purge_Failure::DELETE_FAILED );
			}
			$checkpoint['totals']['deleted_rows'] = $this->checked_add( $checkpoint['totals']['deleted_rows'], $deleted );
			$remaining                           -= $deleted;
			if ( $deleted < $limit && 0 === $this->non_negative_integer( $this->scalar( 'SELECT COUNT(*) FROM `' . $table . '`' ) ) ) {
				++$checkpoint['cursor']['table_index'];
			}
		}
		if ( $checkpoint['cursor']['table_index'] >= count( $tables ) ) {
			$checkpoint['cursor']['stage'] = 'verify';
		}
	}

	/**
	 * Deletable ids.
	 *
	 * @param string $table Table.
	 * @param int    $limit Maximum number of entries requested.
	 * @return array<int,int>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function deletable_ids( string $table, int $limit ): array {
		if ( ! in_array( $table, $this->targets->table_names(), true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Purge_Failure::because( Purge_Failure::DELETE_FAILED );
		}
		$definition = ( new Schema_Catalog() )->table( $table );
		$joins      = array();
		$where      = array();
		$alias      = 0;
		foreach ( $definition->foreign_keys() as $foreign_key ) {
			if ( $foreign_key['table'] !== $table ) {
				continue;
			}
			++$alias;
			$child   = 'child' . $alias;
			$joins[] = ' LEFT JOIN `' . $table . '` AS ' . $child . ' ON ' . $child . '.`' . $foreign_key['column'] . '` = source.id';
			$where[] = $child . '.id IS NULL';
		}
		$sql = 'SELECT source.id FROM `' . $table . '` AS source' . implode( '', $joins );
		if ( array() !== $where ) {
			$sql .= ' WHERE ' . implode( ' AND ', $where );
		}
		$sql .= ' ORDER BY source.id DESC LIMIT ' . max( 1, min( Execute_Purge::BATCH_SIZE, $limit ) ) . ' FOR UPDATE';
		$ids  = array();
		foreach ( $this->rows( $sql ) as $row ) {
			$id = $this->positive_integer( $row['id'] ?? null );
			if ( $id < 1 ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Purge_Failure::because( Purge_Failure::DELETE_FAILED );
			}
			$ids[] = $id;
		}
		return $ids;
	}

	/**
	 * Postcondition.
	 *
	 * @param string $verified_at_utc Verified at utc.
	 * @return array<string,mixed>
	 */
	private function postcondition( string $verified_at_utc ): array {
		$remaining_rows = 0;
		foreach ( $this->targets->table_names() as $table ) {
			$remaining_rows = $this->checked_add(
				$remaining_rows,
				$this->non_negative_integer( $this->scalar( 'SELECT COUNT(*) FROM `' . $table . '`' ) )
			);
		}
		$remaining_files = $this->non_negative_integer( $this->scalar( 'SELECT COUNT(*) FROM ' . self::TABLE_PRIVATE_FILES . ' WHERE purged_at_utc IS NULL' ) );
		$remaining_bytes = $this->non_negative_integer( $this->scalar( 'SELECT COALESCE(SUM(byte_size), 0) FROM ' . self::TABLE_PRIVATE_FILES . ' WHERE purged_at_utc IS NULL' ) );
		return array(
			'remaining_rows'       => $remaining_rows,
			'remaining_files'      => $remaining_files,
			'remaining_blob_bytes' => $remaining_bytes,
			'verified_at_utc'      => $verified_at_utc,
		);
	}

	/**
	 * Save checkpoint.
	 *
	 * @param array<string,mixed> $row Row.
	 * @param string              $state State.
	 * @param string              $json Json.
	 * @param string              $hash Hash.
	 * @param string              $at At.
	 * @param string|null         $error_code Error code.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function save_checkpoint( array $row, string $state, string $json, string $hash, string $at, ?string $error_code ): void {
		$common = array(
			$this->positive_integer( $row['approved_by_user_id'] ?? null ),
			$at,
			$state,
			$json,
			$hash,
		);
		$tail   = array(
			$this->positive_integer( $row['id'] ?? null ),
			(string) $row['public_id'],
			$this->non_negative_integer( $row['lock_version'] ?? null ),
			'running',
		);
		if ( 'running' === $state ) {
			$sql = $this->prepare(
				'UPDATE ' . self::TABLE_JOBS . ' SET lock_version = lock_version + 1, updated_by_user_id = %d, updated_at_utc = %s, state = %s, checkpoint_json = %s, checkpoint_hash = %s WHERE id = %d AND public_id = %s AND lock_version = %d AND state = %s',
				array_merge( $common, $tail )
			);
		} elseif ( 'completed' === $state ) {
			$sql = $this->prepare(
				'UPDATE ' . self::TABLE_JOBS . ' SET lock_version = lock_version + 1, updated_by_user_id = %d, updated_at_utc = %s, state = %s, checkpoint_json = %s, checkpoint_hash = %s, completed_at_utc = %s, error_code = NULL WHERE id = %d AND public_id = %s AND lock_version = %d AND state = %s',
				array_merge( $common, array( $at ), $tail )
			);
		} elseif ( 'failed' === $state && is_string( $error_code ) && '' !== $error_code ) {
			$sql = $this->prepare(
				'UPDATE ' . self::TABLE_JOBS . ' SET lock_version = lock_version + 1, updated_by_user_id = %d, updated_at_utc = %s, state = %s, checkpoint_json = %s, checkpoint_hash = %s, completed_at_utc = %s, error_code = %s WHERE id = %d AND public_id = %s AND lock_version = %d AND state = %s',
				array_merge( $common, array( $at, $error_code ), $tail )
			);
		} else {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Purge_Failure::because( Purge_Failure::CHECKPOINT_INVALID );
		}
		if ( 1 !== $this->query_affected( $sql ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Purge_Failure::because( Purge_Failure::JOB_STATE_CONFLICT );
		}
	}

	/**
	 * Append lifecycle event.
	 *
	 * @param string              $event Event.
	 * @param int                 $actor Actor.
	 * @param string              $job_id Job id.
	 * @param string              $before Before.
	 * @param string              $after After.
	 * @param string              $at At.
	 * @param array<string,mixed> $checkpoint Checkpoint.
	 * @param string|null         $error Error.
	 * @return void
	 */
	private function append_lifecycle_event( string $event, int $actor, string $job_id, string $before, string $after, string $at, array $checkpoint, ?string $error ): void {
		$correlation = 'purge-worker:' . strtolower( $job_id );
		$audit_id    = $this->ids->create()->value();
		$outbox_id   = $this->ids->create()->value();
		$this->append_audit(
			$event,
			self::LIFECYCLE_AUDIT_SCHEMA,
			$audit_id,
			$actor,
			$job_id,
			$correlation,
			$before,
			$after,
			$at,
			array(
				'error_code'    => $error,
				'phase'         => $checkpoint['phase'],
				'postcondition' => $checkpoint['postcondition'],
				'totals'        => $checkpoint['totals'],
			)
		);
		$payload = array(
			'event'           => $event,
			'checkpoint_hash' => $after,
			'error_code'      => $error,
			'state'           => $checkpoint['phase'],
		);
		$this->insert_outbox(
			$outbox_id,
			$actor,
			$job_id,
			$event,
			'event:' . hash( 'sha256', $event . '|' . $job_id . '|' . $after ),
			self::LIFECYCLE_OUTBOX_SCHEMA,
			$this->canonical->encode( $payload ),
			$at
		);
	}

	/**
	 * Batch job row.
	 *
	 * @param string $job_id Job id.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function batch_job_row( string $job_id ): array {
		$row = $this->row(
			$this->prepare(
				'SELECT id, public_id, lock_version, state, preview_json, preview_hash, approved_by_user_id, checkpoint_schema, checkpoint_version, checkpoint_json, checkpoint_hash FROM ' . self::TABLE_JOBS . ' WHERE public_id = %s LIMIT 1 FOR UPDATE',
				array( $job_id )
			)
		);
		if ( null === $row ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Purge_Failure::because( Purge_Failure::JOB_NOT_FOUND );
		}
		return $row;
	}

	/**
	 * Reject invalid maintenance state.
	 *
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_maintenance_state(): void {
		$row = $this->row(
			$this->prepare(
				'SELECT id, state FROM ' . self::TABLE_INSTALLATIONS . ' WHERE addon_id = %s LIMIT 1 FOR UPDATE',
				array( Schema_Catalog::ADDON_ID )
			)
		);
		if ( null === $row || Installation_State::MAINTENANCE_REQUIRED !== ( $row['state'] ?? null ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Purge_Failure::because( Purge_Failure::MAINTENANCE_UNAVAILABLE );
		}
		$this->positive_integer( $row['id'] ?? null );
	}

	/**
	 * Job snapshot.
	 *
	 * @param string $job_id Job id.
	 * @param bool   $for_update For update.
	 * @return Purge_Job_Snapshot
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function job_snapshot( string $job_id, bool $for_update ): Purge_Job_Snapshot {
		new Public_Id( $job_id );
		$sql = 'SELECT id, public_id, lock_version, state, preview_json, preview_hash, approved_at_utc, checkpoint_schema, checkpoint_version, checkpoint_json, checkpoint_hash FROM '
			. self::TABLE_JOBS . ' WHERE public_id = %s LIMIT 1' . ( $for_update ? ' FOR UPDATE' : '' );
		$row = $this->row( $this->prepare( $sql, array( $job_id ) ) );
		if ( null === $row ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Purge_Failure::because( Purge_Failure::JOB_NOT_FOUND );
		}
		$preview     = $this->decode_stored( (string) ( $row['preview_json'] ?? '' ), (string) ( $row['preview_hash'] ?? '' ) );
		$approved_at = (string) ( $row['approved_at_utc'] ?? '' );
		$this->utc( $approved_at );
		$approved = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $approved_at, new DateTimeZone( 'UTC' ) );
		if ( ! $approved instanceof DateTimeImmutable ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Purge_Failure::because( Purge_Failure::STORAGE_UNAVAILABLE );
		}
		$approval = $this->approval_metadata( $job_id );
		if ( ! hash_equals( (string) ( $preview['preview_hash'] ?? '' ), (string) ( $approval['preview_hash'] ?? '' ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Purge_Failure::because( Purge_Failure::STORAGE_UNAVAILABLE );
		}
		$checkpoint      = null;
		$checkpoint_hash = null;
		if ( null !== ( $row['checkpoint_json'] ?? null ) || null !== ( $row['checkpoint_hash'] ?? null ) ) {
			$checkpoint      = $this->decode_checkpoint_row( $row );
			$checkpoint_hash = (string) $row['checkpoint_hash'];
		}
		$installation_sql = 'SELECT id, lock_version, state FROM ' . self::TABLE_INSTALLATIONS . ' WHERE addon_id = %s LIMIT 1' . ( $for_update ? ' FOR UPDATE' : '' );
		$installation     = $this->row( $this->prepare( $installation_sql, array( Schema_Catalog::ADDON_ID ) ) );
		if ( null === $installation ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Purge_Failure::because( Purge_Failure::MAINTENANCE_UNAVAILABLE );
		}
		return new Purge_Job_Snapshot(
			$this->positive_integer( $row['id'] ?? null ),
			(string) ( $row['public_id'] ?? '' ),
			$this->non_negative_integer( $row['lock_version'] ?? null ),
			(string) ( $row['state'] ?? '' ),
			$preview,
			(string) ( $row['preview_hash'] ?? '' ),
			$approved_at,
			$approved->modify( '+' . Execute_Purge::APPROVAL_TTL_SECONDS . ' seconds' )->format( 'Y-m-d H:i:s' ),
			(string) ( $approval['backup_evidence_digest'] ?? '' ),
			(string) ( $approval['backup_verified_at_utc'] ?? '' ),
			$checkpoint,
			$checkpoint_hash,
			$this->positive_integer( $installation['id'] ?? null ),
			$this->non_negative_integer( $installation['lock_version'] ?? null ),
			(string) ( $installation['state'] ?? '' )
		);
	}

	/**
	 * Approval metadata.
	 *
	 * @param string $job_id Job id.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function approval_metadata( string $job_id ): array {
		$row = $this->row(
			$this->prepare(
				'SELECT metadata_schema, metadata_version, metadata_json, metadata_hash FROM ' . self::TABLE_AUDIT . ' WHERE action_key = %s AND object_type = %s AND object_public_id = %s ORDER BY id DESC LIMIT 1',
				array( 'purge.approved', self::AGGREGATE_TYPE, $job_id )
			)
		);
		if (
			null === $row
			|| self::APPROVAL_AUDIT_SCHEMA !== ( $row['metadata_schema'] ?? null )
			|| 1 !== $this->positive_integer( $row['metadata_version'] ?? null )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Purge_Failure::because( Purge_Failure::STORAGE_UNAVAILABLE );
		}
		return $this->decode_stored( (string) ( $row['metadata_json'] ?? '' ), (string) ( $row['metadata_hash'] ?? '' ) );
	}

	/**
	 * Decode checkpoint row.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function decode_checkpoint_row( array $row ): array {
		if (
			Execute_Purge::CHECKPOINT_SCHEMA !== ( $row['checkpoint_schema'] ?? null )
			|| Execute_Purge::CHECKPOINT_VERSION !== $this->positive_integer( $row['checkpoint_version'] ?? null )
			|| ! is_string( $row['checkpoint_json'] ?? null )
			|| ! is_string( $row['checkpoint_hash'] ?? null )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Purge_Failure::because( Purge_Failure::CHECKPOINT_INVALID );
		}
		try {
			return $this->decode_stored( $row['checkpoint_json'], $row['checkpoint_hash'] );
		} catch ( Purge_Failure $failure ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Purge_Failure::because( Purge_Failure::CHECKPOINT_INVALID, $failure );
		}
	}

	/**
	 * Reject invalid checkpoint.
	 *
	 * @param array<string,mixed> $checkpoint Checkpoint.
	 * @param string              $preview_hash Preview hash.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_checkpoint( array $checkpoint, string $preview_hash ): void {
		$cursor        = $checkpoint['cursor'] ?? null;
		$totals        = $checkpoint['totals'] ?? null;
		$expected      = $checkpoint['expected'] ?? null;
		$maintenance   = $checkpoint['maintenance'] ?? null;
		$postcondition = $checkpoint['postcondition'] ?? null;
		if (
			Execute_Purge::CHECKPOINT_PROFILE !== ( $checkpoint['profile'] ?? null )
			|| ! in_array( $checkpoint['phase'] ?? null, array( 'running', 'completed', 'cancelled', 'failed' ), true )
			|| Execute_Purge::BATCH_SIZE !== ( $checkpoint['batch_size'] ?? null )
			|| Execute_Purge::TIME_LIMIT_SECONDS !== ( $checkpoint['time_limit_seconds'] ?? null )
			|| ! hash_equals( $preview_hash, (string) ( $checkpoint['preview_hash'] ?? '' ) )
			|| ! is_array( $cursor ) || array_is_list( $cursor )
			|| ! in_array( $cursor['stage'] ?? null, array( 'private_files', 'tables', 'verify' ), true )
			|| ! is_int( $cursor['table_index'] ?? null ) || $cursor['table_index'] < 0 || $cursor['table_index'] > Purge_Target_Catalog::TARGET_TABLE_COUNT
			|| ! is_array( $totals ) || array_is_list( $totals )
			|| ! is_array( $expected ) || array_is_list( $expected )
			|| ! is_array( $maintenance ) || array_is_list( $maintenance )
			|| ! in_array( $maintenance['previous_installation_state'] ?? null, array( Installation_State::REGISTRATION_PENDING, Installation_State::RUNTIME_READY, Installation_State::MAINTENANCE_REQUIRED ), true )
			|| ! is_array( $postcondition ) || array_is_list( $postcondition )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Purge_Failure::because( Purge_Failure::CHECKPOINT_INVALID );
		}
		$this->hash_value( (string) ( $checkpoint['scope_hash'] ?? '' ) );
		foreach ( array( 'deleted_rows', 'deleted_files', 'deleted_bytes' ) as $key ) {
			if ( ! is_int( $totals[ $key ] ?? null ) || $totals[ $key ] < 0 ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Purge_Failure::because( Purge_Failure::CHECKPOINT_INVALID );
			}
		}
		foreach ( array( 'target_table_count', 'target_row_count', 'private_file_count', 'private_blob_bytes' ) as $key ) {
			if ( ! is_int( $expected[ $key ] ?? null ) || $expected[ $key ] < 0 ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Purge_Failure::because( Purge_Failure::CHECKPOINT_INVALID );
			}
		}
		if ( Purge_Target_Catalog::TARGET_TABLE_COUNT !== $expected['target_table_count'] ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Purge_Failure::because( Purge_Failure::CHECKPOINT_INVALID );
		}
		foreach ( array( 'remaining_rows', 'remaining_files', 'remaining_blob_bytes' ) as $key ) {
			if ( ! is_int( $postcondition[ $key ] ?? null ) || $postcondition[ $key ] < 0 ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Purge_Failure::because( Purge_Failure::CHECKPOINT_INVALID );
			}
		}
		if ( null !== ( $postcondition['verified_at_utc'] ?? null ) ) {
			$this->utc( (string) $postcondition['verified_at_utc'] );
		}
	}

	/**
	 * Command receipt.
	 *
	 * @param string $key Key.
	 * @param string $digest Digest.
	 * @param string $event Event.
	 * @param string $schema Schema.
	 * @param bool   $for_update For update.
	 * @return array<string,mixed>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function command_receipt( string $key, string $digest, string $event, string $schema, bool $for_update ): ?array {
		$this->storage_key( $key );
		$this->hash_value( $digest );
		$sql = 'SELECT aggregate_public_id, event_type, payload_schema, payload_version, payload_json, payload_hash FROM '
			. self::TABLE_OUTBOX . ' WHERE idempotency_key = %s LIMIT 1' . ( $for_update ? ' FOR UPDATE' : '' );
		$row = $this->row( $this->prepare( $sql, array( $key ) ) );
		if ( null === $row ) {
			return null;
		}
		if ( $event !== ( $row['event_type'] ?? null ) || $schema !== ( $row['payload_schema'] ?? null ) || 1 !== $this->positive_integer( $row['payload_version'] ?? null ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Purge_Failure::because( Purge_Failure::STORAGE_UNAVAILABLE );
		}
		$payload = $this->decode_stored( (string) ( $row['payload_json'] ?? '' ), (string) ( $row['payload_hash'] ?? '' ) );
		if ( ! hash_equals( $digest, (string) ( $payload['request_digest'] ?? '' ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Purge_Failure::because( Purge_Failure::IDEMPOTENCY_CONFLICT );
		}
		$receipt = $payload['receipt'] ?? null;
		if ( ! is_array( $receipt ) || array_is_list( $receipt ) || ! hash_equals( (string) ( $row['aggregate_public_id'] ?? '' ), (string) ( $receipt['job_id'] ?? '' ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Purge_Failure::because( Purge_Failure::STORAGE_UNAVAILABLE );
		}
		return $receipt;
	}

	/**
	 * Execution result.
	 *
	 * @param array<string,mixed> $receipt Receipt.
	 * @param bool                $replayed Replayed.
	 * @return Execute_Purge_Result
	 */
	private function execution_result( array $receipt, bool $replayed ): Execute_Purge_Result {
		return new Execute_Purge_Result(
			(string) ( $receipt['job_id'] ?? '' ),
			(string) ( $receipt['state'] ?? '' ),
			(string) ( $receipt['preview_hash'] ?? '' ),
			is_array( $receipt['checkpoint'] ?? null ) ? $receipt['checkpoint'] : array(),
			(string) ( $receipt['checkpoint_hash'] ?? '' ),
			(string) ( $receipt['started_at_utc'] ?? '' ),
			$replayed
		);
	}

	/**
	 * Cancellation result.
	 *
	 * @param array<string,mixed> $receipt Receipt.
	 * @param bool                $replayed Replayed.
	 * @return Cancel_Purge_Result
	 */
	private function cancellation_result( array $receipt, bool $replayed ): Cancel_Purge_Result {
		$checkpoint = $receipt['checkpoint'] ?? null;
		return new Cancel_Purge_Result(
			(string) ( $receipt['job_id'] ?? '' ),
			(string) ( $receipt['state'] ?? '' ),
			(string) ( $receipt['preview_hash'] ?? '' ),
			is_array( $checkpoint ) ? $checkpoint : null,
			is_string( $receipt['checkpoint_hash'] ?? null ) ? $receipt['checkpoint_hash'] : null,
			(string) ( $receipt['cancelled_at_utc'] ?? '' ),
			true === ( $receipt['maintenance_retained'] ?? null ),
			$this->positive_integer( $receipt['ledger_mutation_count'] ?? null ),
			$replayed
		);
	}

	/**
	 * Execution receipt payload.
	 *
	 * @param Execute_Purge_Result $result Result.
	 * @return array<string,mixed>
	 */
	private function execution_receipt_payload( Execute_Purge_Result $result ): array {
		return array(
			'job_id'          => $result->job_id(),
			'state'           => $result->state(),
			'preview_hash'    => $result->preview_hash(),
			'checkpoint'      => $result->checkpoint(),
			'checkpoint_hash' => $result->checkpoint_hash(),
			'started_at_utc'  => $result->started_at_utc(),
		);
	}

	/**
	 * Cancellation receipt payload.
	 *
	 * @param Cancel_Purge_Result $result Result.
	 * @param int                 $ledger_mutations Ledger mutations.
	 * @return array<string,mixed>
	 */
	private function cancellation_receipt_payload( Cancel_Purge_Result $result, int $ledger_mutations ): array {
		return array(
			'job_id'                => $result->job_id(),
			'state'                 => $result->state(),
			'preview_hash'          => $result->preview_hash(),
			'checkpoint'            => $result->checkpoint(),
			'checkpoint_hash'       => $result->checkpoint_hash(),
			'cancelled_at_utc'      => $result->cancelled_at_utc(),
			'maintenance_retained'  => $result->maintenance_retained(),
			'ledger_mutation_count' => $ledger_mutations,
		);
	}

	/**
	 * Append audit.
	 *
	 * @param string              $event Event.
	 * @param string              $schema Schema.
	 * @param string              $public_id Public id.
	 * @param int                 $actor Actor.
	 * @param string              $job_id Job id.
	 * @param string              $correlation Correlation.
	 * @param string              $before Before.
	 * @param string              $after After.
	 * @param string              $at At.
	 * @param array<string,mixed> $metadata Metadata.
	 * @return void
	 */
	private function append_audit( string $event, string $schema, string $public_id, int $actor, string $job_id, string $correlation, string $before, string $after, string $at, array $metadata ): void {
		new Public_Id( $public_id );
		new Public_Id( $job_id );
		$this->safe_token( $correlation, 8, 128 );
		$this->hash_value( $before );
		$this->hash_value( $after );
		$previous = $this->scalar( 'SELECT event_hash FROM ' . self::TABLE_AUDIT . ' ORDER BY id DESC LIMIT 1 FOR UPDATE' );
		if ( null !== $previous ) {
			$this->hash_value( (string) $previous );
		}
		$metadata_json = $this->canonical->encode( $metadata );
		$metadata_hash = hash( 'sha256', $metadata_json );
		$event_hash    = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'action_key'          => $event,
					'actor_user_id'       => $actor,
					'after_digest'        => $after,
					'before_digest'       => $before,
					'correlation_id'      => $correlation,
					'metadata_hash'       => $metadata_hash,
					'object_public_id'    => $job_id,
					'object_type'         => self::AGGREGATE_TYPE,
					'occurred_at_utc'     => $at,
					'previous_event_hash' => $previous,
				)
			)
		);
		$this->insert_or_fail(
			self::TABLE_AUDIT,
			array(
				'public_id'           => $public_id,
				'occurred_at_utc'     => $at,
				'actor_user_id'       => $actor,
				'action_key'          => $event,
				'object_type'         => self::AGGREGATE_TYPE,
				'object_public_id'    => $job_id,
				'context_type'        => null,
				'context_id'          => null,
				'context_revision'    => null,
				'correlation_id'      => $correlation,
				'before_digest'       => $before,
				'after_digest'        => $after,
				'metadata_schema'     => $schema,
				'metadata_version'    => 1,
				'metadata_json'       => $metadata_json,
				'metadata_hash'       => $metadata_hash,
				'previous_event_hash' => $previous,
				'event_hash'          => $event_hash,
			)
		);
	}

	/**
	 * Insert command outbox.
	 *
	 * @param string              $event Event.
	 * @param string              $schema Schema.
	 * @param string              $public_id Public id.
	 * @param int                 $actor Actor.
	 * @param string              $job_id Job id.
	 * @param string              $storage_key Storage key.
	 * @param string              $command_id Command identifier used for idempotency.
	 * @param string              $digest Digest.
	 * @param array<string,mixed> $receipt Receipt.
	 * @param string              $at At.
	 * @return void
	 */
	private function insert_command_outbox( string $event, string $schema, string $public_id, int $actor, string $job_id, string $storage_key, string $command_id, string $digest, array $receipt, string $at ): void {
		$payload = $this->canonical->encode(
			array(
				'command_id'     => $command_id,
				'event'          => $event,
				'request_digest' => $digest,
				'receipt'        => $receipt,
			)
		);
		$this->insert_outbox( $public_id, $actor, $job_id, $event, $storage_key, $schema, $payload, $at );
	}

	/**
	 * Insert outbox.
	 *
	 * @param string $public_id Public id.
	 * @param int    $actor Actor.
	 * @param string $job_id Job id.
	 * @param string $event Event.
	 * @param string $key Key.
	 * @param string $schema Schema.
	 * @param string $payload Input payload at this boundary; see the declared shape.
	 * @param string $at At.
	 * @return void
	 */
	private function insert_outbox( string $public_id, int $actor, string $job_id, string $event, string $key, string $schema, string $payload, string $at ): void {
		$this->insert_or_fail(
			self::TABLE_OUTBOX,
			array(
				'public_id'            => $public_id,
				'lock_version'         => 0,
				'created_by_user_id'   => $actor,
				'created_at_utc'       => $at,
				'updated_by_user_id'   => $actor,
				'updated_at_utc'       => $at,
				'aggregate_type'       => self::AGGREGATE_TYPE,
				'aggregate_public_id'  => $job_id,
				'event_type'           => $event,
				'idempotency_key'      => $key,
				'payload_schema'       => $schema,
				'payload_version'      => 1,
				'payload_json'         => $payload,
				'payload_hash'         => hash( 'sha256', $payload ),
				'available_at_utc'     => $at,
				'published_at_utc'     => null,
				'lease_token'          => null,
				'lease_expires_at_utc' => null,
				'attempt_count'        => 0,
				'last_error_code'      => null,
			)
		);
	}

	/**
	 * Reject invalid start record.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_start_record( array $record ): void {
		$expected = array(
			'actor_user_id',
			'audit_public_id',
			'checkpoint',
			'checkpoint_hash',
			'checkpoint_json',
			'command_id',
			'command_storage_key',
			'correlation_id',
			'expected_installation_database_id',
			'expected_installation_lock_version',
			'expected_installation_state',
			'expected_job_database_id',
			'expected_job_lock_version',
			'expected_preview_json_hash',
			'job_public_id',
			'outbox_public_id',
			'preview_hash',
			'request_digest',
			'site_id',
			'started_at_utc',
		);
		$this->exact_keys( $record, $expected );
		foreach ( array( 'audit_public_id', 'command_id', 'job_public_id', 'outbox_public_id' ) as $key ) {
			new Public_Id( (string) $record[ $key ] );
		}
		foreach ( array( 'checkpoint_hash', 'expected_preview_json_hash', 'preview_hash', 'request_digest' ) as $key ) {
			$this->hash_value( (string) $record[ $key ] );
		}
		$this->common_record( $record );
		if (
			! is_array( $record['checkpoint'] )
			|| array_is_list( $record['checkpoint'] )
			|| ! hash_equals( (string) $record['checkpoint_json'], $this->canonical->encode( $record['checkpoint'] ) )
			|| ! hash_equals( (string) $record['checkpoint_hash'], hash( 'sha256', (string) $record['checkpoint_json'] ) )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Purge_Failure::because( Purge_Failure::STORAGE_UNAVAILABLE );
		}
		$this->assert_checkpoint( $record['checkpoint'], (string) $record['preview_hash'] );
	}

	/**
	 * Reject invalid cancel record.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_cancel_record( array $record ): void {
		$expected = array(
			'actor_user_id',
			'audit_public_id',
			'cancelled_at_utc',
			'command_id',
			'command_storage_key',
			'correlation_id',
			'expected_installation_database_id',
			'expected_installation_lock_version',
			'expected_job_database_id',
			'expected_job_lock_version',
			'expected_preview_json_hash',
			'expected_state',
			'job_public_id',
			'outbox_public_id',
			'preview_hash',
			'request_digest',
			'site_id',
		);
		$this->exact_keys( $record, $expected );
		foreach ( array( 'audit_public_id', 'command_id', 'job_public_id', 'outbox_public_id' ) as $key ) {
			new Public_Id( (string) $record[ $key ] );
		}
		foreach ( array( 'expected_preview_json_hash', 'preview_hash', 'request_digest' ) as $key ) {
			$this->hash_value( (string) $record[ $key ] );
		}
		if ( ! in_array( $record['expected_state'], array( 'approved', 'running' ), true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Purge_Failure::because( Purge_Failure::STORAGE_UNAVAILABLE );
		}
		$this->common_record( $record );
		$this->utc( (string) $record['cancelled_at_utc'] );
	}

	/**
	 * Common record.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function common_record( array $record ): void {
		if (
			! is_int( $record['actor_user_id'] ) || $record['actor_user_id'] < 1
			|| ! is_int( $record['expected_installation_database_id'] ) || $record['expected_installation_database_id'] < 1
			|| ! is_int( $record['expected_installation_lock_version'] ) || $record['expected_installation_lock_version'] < 0
			|| ! is_int( $record['expected_job_database_id'] ) || $record['expected_job_database_id'] < 1
			|| ! is_int( $record['expected_job_lock_version'] ) || $record['expected_job_lock_version'] < 0
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Purge_Failure::because( Purge_Failure::STORAGE_UNAVAILABLE );
		}
		$this->storage_key( (string) $record['command_storage_key'] );
		$this->safe_token( (string) $record['correlation_id'], 8, 128 );
		$this->site_id( (string) $record['site_id'] );
		if ( isset( $record['started_at_utc'] ) ) {
			$this->utc( (string) $record['started_at_utc'] );
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
			throw Purge_Failure::because( Purge_Failure::STORAGE_UNAVAILABLE );
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
			throw Purge_Failure::because( Purge_Failure::STORAGE_UNAVAILABLE );
		}
		try {
			$value = json_decode( $json, true, 64, JSON_THROW_ON_ERROR );
		} catch ( JsonException $error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Purge_Failure::because( Purge_Failure::STORAGE_UNAVAILABLE, $error );
		}
		if ( ! is_array( $value ) || array_is_list( $value ) || ! hash_equals( $json, $this->canonical->encode( $value ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Purge_Failure::because( Purge_Failure::STORAGE_UNAVAILABLE );
		}
		return $value;
	}

	/**
	 * Acquire lock.
	 *
	 * @param string $site_id Site id.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function acquire_lock( string $site_id ): string {
		$this->site_id( $site_id );
		$name = 'oj_ai_dashboard_purge_' . substr( hash( 'sha256', $site_id ), 0, 16 );
		try {
			if ( ! $this->lock()->acquire( $name, self::LOCK_TIMEOUT_SECONDS ) ) {
				throw Purge_Failure::because( Purge_Failure::MAINTENANCE_UNAVAILABLE );
			}
		} catch ( Purge_Failure $failure ) {
			throw $failure;
		} catch ( Throwable $error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Purge_Failure::because( Purge_Failure::MAINTENANCE_UNAVAILABLE, $error );
		}
		return $name;
	}

	/**
	 * Release lock.
	 *
	 * @param string $name Name.
	 * @return void
	 */
	private function release_lock( string $name ): void {
		try {
			$this->lock()->release( $name );
		} catch ( Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Preserve the documented recovery or optional-operation outcome; the catch body explains the fallback.
			// This optional step must not replace the surrounding operation outcome.
 // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- This optional step must not replace the surrounding operation outcome.
		}
	}

	/**
	 * Lock.
	 *
	 * @return Schema_Lock_Port_Interface
	 */
	private function lock(): Schema_Lock_Port_Interface {
		if ( ! $this->lock instanceof Schema_Lock_Port_Interface ) {
			$this->lock = new WordPress_Schema_Lock( $this->database() );
		}
		return $this->lock;
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
			throw Purge_Failure::because( Purge_Failure::STORAGE_UNAVAILABLE );
		}
		foreach ( array( 'prepare', 'get_row', 'get_results', 'get_var', 'insert', 'query' ) as $method ) {
			if ( ! method_exists( $database, $method ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Purge_Failure::because( Purge_Failure::STORAGE_UNAVAILABLE );
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
		$database = $this->database();
		$this->reset_error( $database );
		$row = $database->get_row( $sql, defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A' );
		$this->assert_read( $database );
		if ( null === $row ) {
			return null;
		}
		if ( ! is_array( $row ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Purge_Failure::because( Purge_Failure::STORAGE_UNAVAILABLE );
		}
		return $row;
	}

	/**
	 * Rows.
	 *
	 * @param string $sql Sql.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function rows( string $sql ): array {
		$database = $this->database();
		$this->reset_error( $database );
		$rows = $database->get_results( $sql, defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A' );
		$this->assert_read( $database );
		if ( ! is_array( $rows ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Purge_Failure::because( Purge_Failure::STORAGE_UNAVAILABLE );
		}
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Purge_Failure::because( Purge_Failure::STORAGE_UNAVAILABLE );
			}
		}
		return $rows;
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
			throw Purge_Failure::because( Purge_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Query affected.
	 *
	 * @param string $sql Sql.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function query_affected( string $sql ): int {
		$database = $this->database();
		$this->reset_error( $database );
		$result = $database->query( $sql );
		if ( false === $result || '' !== trim( (string) ( $database->last_error ?? '' ) ) || ! is_int( $result ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Purge_Failure::because( Purge_Failure::STORAGE_UNAVAILABLE );
		}
		return $result;
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
			throw Purge_Failure::because( Purge_Failure::STORAGE_UNAVAILABLE );
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
			throw Purge_Failure::because( Purge_Failure::STORAGE_UNAVAILABLE );
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
			throw Purge_Failure::because( Purge_Failure::STORAGE_UNAVAILABLE );
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
			throw Purge_Failure::because( Purge_Failure::STORAGE_UNAVAILABLE );
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
			throw Purge_Failure::because( Purge_Failure::STORAGE_UNAVAILABLE );
		}
		$time = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new DateTimeZone( 'UTC' ) );
		if ( ! $time instanceof DateTimeImmutable || $time->format( 'Y-m-d H:i:s' ) !== $value ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Purge_Failure::because( Purge_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Site id.
	 *
	 * @param string $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function site_id( string $value ): void {
		if ( 1 !== preg_match( '/^[1-9][0-9]{0,19}$/D', $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Purge_Failure::because( Purge_Failure::STORAGE_UNAVAILABLE );
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
			throw Purge_Failure::because( Purge_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Positive integer.
	 *
	 * @param mixed $value Value.
	 * @return int
	 */
	private function positive_integer( $value ): int {
		$value = $this->non_negative_integer( $value );
		return $value > 0 ? $value : 0;
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
		if ( ! is_string( $value ) || 1 !== preg_match( '/^(0|[1-9][0-9]*)$/D', $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Purge_Failure::because( Purge_Failure::STORAGE_UNAVAILABLE );
		}
		$parsed = filter_var( $value, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 0 ) ) );
		if ( false === $parsed ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Purge_Failure::because( Purge_Failure::STORAGE_UNAVAILABLE );
		}
		return $parsed;
	}

	/**
	 * Checked add.
	 *
	 * @param int $left Left.
	 * @param int $right Right.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function checked_add( int $left, int $right ): int {
		if ( $left < 0 || $right < 0 || $right > PHP_INT_MAX - $left ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Purge_Failure::because( Purge_Failure::CHECKPOINT_INVALID );
		}
		return $left + $right;
	}

	/**
	 * Monotonic now.
	 *
	 * @return float
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function monotonic_now(): float {
		$value = ( $this->monotonic )();
		if ( ! is_float( $value ) && ! is_int( $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Purge_Failure::because( Purge_Failure::STORAGE_UNAVAILABLE );
		}
		return (float) $value;
	}
}
