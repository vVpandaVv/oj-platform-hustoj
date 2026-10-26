<?php
/**
 * Execute purge.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\Purge\Application;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use LogicException;
use OJAI\Dashboard\Operations\Purge\Domain\Purge_Job_Snapshot;
use OJAI\Dashboard\Operations\Purge\Domain\Purge_Target_Catalog;
use OJAI\Dashboard\Operations\Purge\Port\Purge_Operation_Repository_Port_Interface;
use OJAI\Dashboard\Operations\Purge\Port\Purge_Queue_Wakeup_Port_Interface;
use OJAI\Dashboard\Operations\Purge\Port\Purge_Site_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Revalidates C72 and durably starts the resumable C73 purge.
 */
final class Execute_Purge {
	public const CHECKPOINT_PROFILE         = 'oj_ai.purge_checkpoint@1.0.0';
	public const CHECKPOINT_SCHEMA          = 'oj_ai.purge_checkpoint';
	public const CHECKPOINT_VERSION         = 1;
	public const BATCH_SIZE                 = 250;
	public const TIME_LIMIT_SECONDS         = 20;
	public const APPROVAL_TTL_SECONDS       = 1800;
	public const BACKUP_MAXIMUM_AGE_SECONDS = 86400;

	/**
	 * Clock.
	 *
	 * @var Closure
	 */
	private Closure $clock;

	/**
	 * Initialize execute purge dependencies and state.
	 *
	 * @param Purge_Preview_Builder                     $previews Previews.
	 * @param Purge_Site_Port_Interface                 $sites Sites.
	 * @param Purge_Operation_Repository_Port_Interface $repository Persistence boundary used by this operation.
	 * @param Purge_Queue_Wakeup_Port_Interface         $queue Queue.
	 * @param Purge_Target_Catalog                      $targets Targets.
	 * @param Public_Id_Generator                       $ids Ids.
	 * @param Canonical_Json                            $canonical Canonical JSON encoder used for stable digests.
	 * @param callable|null                             $clock Clock.
	 */
	public function __construct(
		private Purge_Preview_Builder $previews,
		private Purge_Site_Port_Interface $sites,
		private Purge_Operation_Repository_Port_Interface $repository,
		private Purge_Queue_Wakeup_Port_Interface $queue,
		private Purge_Target_Catalog $targets,
		private Public_Id_Generator $ids,
		private Canonical_Json $canonical,
		?callable $clock = null
	) {
		$this->clock = null === $clock
			? static fn (): string => gmdate( 'Y-m-d H:i:s' )
			: Closure::fromCallable( $clock );
	}

	/**
	 * Execute execute purge.
	 *
	 * @param Execute_Purge_Command $command Command.
	 * @return Execute_Purge_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function execute( Execute_Purge_Command $command ): Execute_Purge_Result {
		try {
			$receipt = $this->repository->execution_receipt(
				$command->idempotency_storage_key(),
				$command->request_digest()
			);
			if ( $receipt instanceof Execute_Purge_Result ) {
				$this->wake( $receipt->job_id() );
				return $receipt;
			}

			$job = $this->repository->job( $command->job_id() );
			if (
				'approved' !== $command->expected_state()
				|| 'approved' !== $job->state()
				|| ! hash_equals( $job->preview_hash(), $command->expected_preview_hash() )
			) {
				throw Purge_Failure::because( Purge_Failure::JOB_STATE_CONFLICT );
			}

			$started_at_utc = $this->now();
			$this->assert_approval( $job, $started_at_utc );
			$stored_preview = $job->preview();
			$this->assert_deletion_ready( $stored_preview );

			$current_preview = $this->normalize_approval_delta(
				$this->previews->build( Preview_Purge_Command::SCOPE_ADDON )
			);
			$this->assert_deletion_ready( $current_preview );
			if ( ! hash_equals( $this->canonical->encode( $stored_preview ), $this->canonical->encode( $current_preview ) ) ) {
				throw Purge_Failure::because( Purge_Failure::PREVIEW_STALE );
			}

			$checkpoint      = $this->checkpoint( $job, $stored_preview );
			$checkpoint_json = $this->canonical->encode( $checkpoint );
			$result          = $this->repository->start(
				array(
					'actor_user_id'                      => $command->actor_user_id(),
					'audit_public_id'                    => $this->id(),
					'checkpoint'                         => $checkpoint,
					'checkpoint_hash'                    => hash( 'sha256', $checkpoint_json ),
					'checkpoint_json'                    => $checkpoint_json,
					'command_id'                         => $command->command_id(),
					'command_storage_key'                => $command->idempotency_storage_key(),
					'correlation_id'                     => $command->correlation_id(),
					'expected_installation_database_id'  => $job->installation_database_id(),
					'expected_installation_lock_version' => $job->installation_lock_version(),
					'expected_installation_state'        => $job->installation_state(),
					'expected_job_database_id'           => $job->database_id(),
					'expected_job_lock_version'          => $job->lock_version(),
					'expected_preview_json_hash'         => $job->preview_json_hash(),
					'job_public_id'                      => $job->public_id(),
					'outbox_public_id'                   => $this->id(),
					'preview_hash'                       => $job->preview_hash(),
					'request_digest'                     => $command->request_digest(),
					'site_id'                            => $this->sites->site_id(),
					'started_at_utc'                     => $started_at_utc,
				)
			);
			$this->wake( $result->job_id() );
			return $result;
		} catch ( Purge_Failure $failure ) {
			throw $failure;
		} catch ( InvalidArgumentException | LogicException $error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Purge_Failure::because( Purge_Failure::EXECUTION_INVALID, $error );
		} catch ( Throwable $error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Purge_Failure::because( Purge_Failure::STORAGE_UNAVAILABLE, $error );
		}
	}

	/**
	 * Reject invalid approval.
	 *
	 * @param Purge_Job_Snapshot $job Job.
	 * @param string             $now Clock value supplied for deterministic scheduling.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_approval( Purge_Job_Snapshot $job, string $now ): void {
		$now_time      = $this->time( $now );
		$approved_time = $this->time( $job->approved_at_utc() );
		$expires_time  = $this->time( $job->approval_expires_at_utc() );
		$backup_time   = $this->time( $job->backup_verified_at_utc() );
		if (
			$expires_time - $approved_time !== self::APPROVAL_TTL_SECONDS
			|| $now_time >= $expires_time
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Purge_Failure::because( Purge_Failure::APPROVAL_EXPIRED );
		}
		if ( $backup_time > $now_time || $now_time - $backup_time > self::BACKUP_MAXIMUM_AGE_SECONDS ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Purge_Failure::because( Purge_Failure::BACKUP_INVALID );
		}
	}

	/**
	 * Reject invalid deletion ready.
	 *
	 * @param array<string,mixed> $preview Preview.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_deletion_ready( array $preview ): void {
		if ( 0 !== ( $preview['private_files']['legal_hold_count'] ?? null ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Purge_Failure::because( Purge_Failure::LEGAL_HOLD_BLOCKED );
		}
		if ( 0 !== ( $preview['incomplete_jobs']['total'] ?? null ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Purge_Failure::because( Purge_Failure::INCOMPLETE_JOBS_BLOCKED );
		}
	}

	/**
	 * Normalize approval delta.
	 *
	 * @param array<string,mixed> $preview Preview.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function normalize_approval_delta( array $preview ): array {
		$operations       = $preview['owner_rows']['Operations'] ?? null;
		$total_rows       = $preview['total_row_count'] ?? null;
		$purge_jobs       = $preview['incomplete_jobs']['by_type']['purge_jobs'] ?? null;
		$incomplete_total = $preview['incomplete_jobs']['total'] ?? null;
		if (
			! is_int( $operations ) || $operations < 3
			|| ! is_int( $total_rows ) || $total_rows < 3
			|| ! is_int( $purge_jobs ) || $purge_jobs < 1
			|| ! is_int( $incomplete_total ) || $incomplete_total < 1
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Purge_Failure::because( Purge_Failure::PREVIEW_STALE );
		}

		$preview['owner_rows']['Operations']                 = $operations - 3;
		$preview['total_row_count']                          = $total_rows - 3;
		$preview['incomplete_jobs']['by_type']['purge_jobs'] = $purge_jobs - 1;
		$preview['incomplete_jobs']['total']                 = $incomplete_total - 1;
		unset( $preview['preview_hash'] );
		$preview['preview_hash'] = hash( 'sha256', $this->canonical->encode( $preview ) );
		return $preview;
	}

	/**
	 * Checkpoint.
	 *
	 * @param Purge_Job_Snapshot  $job Job.
	 * @param array<string,mixed> $preview Preview.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function checkpoint( Purge_Job_Snapshot $job, array $preview ): array {
		$file_count = $preview['private_files']['file_count'] ?? null;
		$blob_bytes = $preview['private_files']['blob_bytes'] ?? null;
		if ( ! is_int( $file_count ) || $file_count < 0 || ! is_int( $blob_bytes ) || $blob_bytes < 0 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Purge_Failure::because( Purge_Failure::EXECUTION_INVALID );
		}
		$target_rows = $this->targets->expected_row_count( $preview );
		return array(
			'profile'            => self::CHECKPOINT_PROFILE,
			'phase'              => 'running',
			'batch_size'         => self::BATCH_SIZE,
			'time_limit_seconds' => self::TIME_LIMIT_SECONDS,
			'scope_hash'         => $job->scope_hash(),
			'preview_hash'       => $job->preview_hash(),
			'cursor'             => array(
				'stage'       => 'private_files',
				'table_index' => 0,
			),
			'totals'             => array(
				'deleted_rows'  => 0,
				'deleted_files' => 0,
				'deleted_bytes' => 0,
			),
			'expected'           => array(
				'target_table_count' => Purge_Target_Catalog::TARGET_TABLE_COUNT,
				'target_row_count'   => $target_rows,
				'private_file_count' => $file_count,
				'private_blob_bytes' => $blob_bytes,
			),
			'maintenance'        => array( 'previous_installation_state' => $job->installation_state() ),
			'postcondition'      => array(
				'remaining_rows'       => $target_rows,
				'remaining_files'      => $file_count,
				'remaining_blob_bytes' => $blob_bytes,
				'verified_at_utc'      => null,
			),
		);
	}

	/**
	 * Wake.
	 *
	 * @param string $job_id Job id.
	 * @return void
	 */
	private function wake( string $job_id ): void {
		try {
			$this->queue->wake( $job_id );
		} catch ( Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- This optional step must not replace the surrounding operation outcome.
			// The durable checkpoint remains authoritative for recovery.
		}
	}

	/**
	 * Id.
	 *
	 * @return string
	 */
	private function id(): string {
		return $this->ids->create()->value();
	}

	/**
	 * Now.
	 *
	 * @return string
	 */
	private function now(): string {
		$value = ( $this->clock )();
		$this->time( is_string( $value ) ? $value : '' );
		return $value;
	}

	/**
	 * Time.
	 *
	 * @param string $value Value.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function time( string $value ): int {
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Purge_Failure::because( Purge_Failure::STORAGE_UNAVAILABLE );
		}
		$time = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new DateTimeZone( 'UTC' ) );
		if ( ! $time instanceof DateTimeImmutable || $time->format( 'Y-m-d H:i:s' ) !== $value ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Purge_Failure::because( Purge_Failure::STORAGE_UNAVAILABLE );
		}
		return $time->getTimestamp();
	}
}
