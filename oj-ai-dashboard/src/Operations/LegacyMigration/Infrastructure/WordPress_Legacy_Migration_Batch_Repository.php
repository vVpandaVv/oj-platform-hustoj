<?php
/**
 * WordPress legacy migration batch repository.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\LegacyMigration\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Approve_Legacy_Migration;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Execute_Legacy_Migration;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Legacy_Migration_Failure;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Legacy_Target_Transformation_Failure;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Migration_Batch_Result;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Migration_Plan;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Source_Item;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Source_Item_Batch;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Target_Write_Result;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Running_Legacy_Migration_Run;
use OJAI\Dashboard\Operations\LegacyMigration\Port\Legacy_Migration_Batch_Repository_Port_Interface;
use OJAI\Dashboard\Operations\LegacyMigration\Port\Legacy_Target_Writer_Port_Interface;
use OJAI\Dashboard\Operations\LegacyMigration\Port\Legacy_Target_Batch_Lifecycle_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Commits target writes, item receipts, totals, and cursor as one batch transaction.
 */
final class WordPress_Legacy_Migration_Batch_Repository implements Legacy_Migration_Batch_Repository_Port_Interface {
	private const TABLE_RUNS                  = 'oj_ai_import_runs';
	private const TABLE_ITEMS                 = 'oj_ai_import_items';
	private const TABLE_AUDIT                 = 'oj_ai_audit_events';
	private const TABLE_OUTBOX                = 'oj_ai_outbox_events';
	private const ITEM_SCHEMA                 = 'oj_ai.legacy_item_payload';
	private const ITEM_PROFILE                = 'oj_ai.legacy_item_payload@1.0.0';
	private const EVENT_COMPLETED             = 'legacy_migration.execution_completed';
	private const EVENT_COMPLETED_WITH_ERRORS = 'legacy_migration.execution_completed_with_errors';
	private const AGGREGATE_TYPE              = 'legacy_migration_run';
	private const TERMINAL_EVENT_SCHEMA       = 'oj_ai.legacy_migration_terminal_event';
	private const TERMINAL_AUDIT_SCHEMA       = 'oj_ai.legacy_migration_terminal_audit';

	/**
	 * Initialize WordPress legacy migration batch repository dependencies and state.
	 *
	 * @param Legacy_Target_Writer_Port_Interface $targets Targets.
	 * @param Canonical_Json                      $canonical Canonical JSON encoder used for stable digests.
	 * @param object|null                         $database Database.
	 * @param Public_Id_Generator|null            $ids Ids.
	 */
	public function __construct(
		private Legacy_Target_Writer_Port_Interface $targets,
		private Canonical_Json $canonical,
		private ?object $database = null,
		private ?Public_Id_Generator $ids = null
	) {
	}

	/**
	 * Running run.
	 *
	 * @param string $run_id Run id.
	 * @return Running_Legacy_Migration_Run
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function running_run( string $run_id ): Running_Legacy_Migration_Run {
		new Public_Id( $run_id );
		$row = $this->run_row( $run_id, false );
		if ( null === $row ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::RUN_NOT_FOUND );
		}
		return $this->running_from_row( $row );
	}

	/**
	 * Commit batch.
	 *
	 * @param Running_Legacy_Migration_Run $run Run.
	 * @param Legacy_Source_Item_Batch     $batch Batch.
	 * @param string                       $processed_at_utc Processed at utc.
	 * @return Legacy_Migration_Batch_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function commit_batch( Running_Legacy_Migration_Run $run, Legacy_Source_Item_Batch $batch, string $processed_at_utc ): Legacy_Migration_Batch_Result {
		$this->assert_batch( $run, $batch, $processed_at_utc );
		$transaction      = false;
		$target_lifecycle = $this->targets instanceof Legacy_Target_Batch_Lifecycle_Port_Interface ? $this->targets : null;
		$target_batch     = false;
		try {
			$this->query_or_fail( 'START TRANSACTION' );
			$transaction = true;
			$row         = $this->run_row( $run->public_id(), true );
			if ( null === $row ) {
				throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::RUN_NOT_FOUND );
			}
			$locked = $this->running_from_row( $row );
			if (
				$locked->database_id() !== $run->database_id()
				|| $locked->lock_version() !== $run->lock_version()
				|| ! hash_equals( $locked->checkpoint_hash(), $run->checkpoint_hash() )
				|| ! hash_equals( $locked->source_fingerprint(), $run->source_fingerprint() )
				|| ! hash_equals( $locked->plan_hash(), $run->plan_hash() )
			) {
				throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::RUN_STATE_CONFLICT );
			}

			if ( $target_lifecycle instanceof Legacy_Target_Batch_Lifecycle_Port_Interface ) {
				$target_lifecycle->begin_batch();
				$target_batch = true;
			}
			$success_increment = 0;
			$failure_increment = 0;
			foreach ( $batch->items() as $item ) {
				if ( null !== $this->item_row( $run->database_id(), $item ) ) {
					throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::RUN_STATE_CONFLICT );
				}
				try {
					$target = $this->targets->write( $run->database_id(), $item, $run->actor_user_id(), $processed_at_utc );
					$this->insert_item( $run, $item, $target, null, $processed_at_utc );
					++$success_increment;
				} catch ( Legacy_Target_Transformation_Failure $failure ) {
					$this->insert_item( $run, $item, null, $failure->reason(), $processed_at_utc );
					++$failure_increment;
				}
			}

			$success_total   = $run->success_total() + $success_increment;
			$failure_total   = $run->failure_total() + $failure_increment;
			$skipped_total   = $run->skipped_total();
			$processed_total = $success_total + $failure_total + $skipped_total;
			if ( $processed_total > $run->item_total() || ( $batch->exhausted() && $processed_total !== $run->item_total() ) ) {
				throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::SOURCE_STALE );
			}
			$state           = $batch->exhausted()
				? ( $failure_total > 0 ? 'completed_with_errors' : 'completed' )
				: 'running';
			$checkpoint      = $this->next_checkpoint( $run, $batch, $state, $success_total, $failure_total, $skipped_total );
			$checkpoint_json = $this->canonical->encode( $checkpoint );
			$checkpoint_hash = hash( 'sha256', $checkpoint_json );
			$this->update_run(
				$run,
				$state,
				$checkpoint_json,
				$checkpoint_hash,
				$success_total,
				$failure_total,
				$skipped_total,
				$processed_at_utc
			);
			if ( $batch->exhausted() ) {
				$this->insert_terminal_events(
					$run,
					$state,
					$checkpoint_hash,
					$batch->count(),
					$success_total,
					$failure_total,
					$skipped_total,
					$processed_at_utc
				);
			}
			$this->query_or_fail( 'COMMIT' );
			$transaction = false;
			if ( $target_batch ) {
				// SQL is durable now; compensation must never remove committed files.
				$target_batch = false;
				$target_lifecycle->commit_batch();
			}

			return new Legacy_Migration_Batch_Result(
				$run->public_id(),
				$state,
				$checkpoint_hash,
				$batch->count(),
				$success_total,
				$failure_total,
				$skipped_total
			);
		} catch ( Legacy_Migration_Failure $failure ) {
			if ( $transaction ) {
				$this->rollback();
			}
			$this->rollback_target_batch( $target_lifecycle, $target_batch );
			throw $failure;
		} catch ( Throwable $error ) {
			if ( $transaction ) {
				$this->rollback();
			}
			$this->rollback_target_batch( $target_lifecycle, $target_batch );
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE, $error );
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
		$sql = 'SELECT id, public_id, lock_version, updated_by_user_id, source_kind, source_version, source_fingerprint, state, preview_schema, preview_version, preview_json, preview_hash, checkpoint_schema, checkpoint_version, checkpoint_json, checkpoint_hash, item_total, success_total, failure_total, skipped_total FROM ' . self::TABLE_RUNS . ' WHERE public_id = %s LIMIT 1';
		if ( $for_update ) {
			$sql .= ' FOR UPDATE';
		}
		return $this->row( $this->prepare( $sql, array( $run_id ) ) );
	}

	/**
	 * Running from row.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return Running_Legacy_Migration_Run
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function running_from_row( array $row ): Running_Legacy_Migration_Run {
		if ( 'running' !== ( $row['state'] ?? null ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::RUN_STATE_CONFLICT );
		}
		if (
			Approve_Legacy_Migration::PREVIEW_SCHEMA !== ( $row['preview_schema'] ?? null )
			|| Approve_Legacy_Migration::PREVIEW_DATABASE_VERSION !== $this->positive_integer( $row['preview_version'] ?? null )
			|| Execute_Legacy_Migration::CHECKPOINT_SCHEMA !== ( $row['checkpoint_schema'] ?? null )
			|| Execute_Legacy_Migration::CHECKPOINT_VERSION !== $this->positive_integer( $row['checkpoint_version'] ?? null )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		$preview = $this->decode_stored( (string) ( $row['preview_json'] ?? '' ), (string) ( $row['preview_hash'] ?? '' ) );
		$this->exact_keys( $preview, array( 'plan', 'profile', 'state' ) );
		$checkpoint = $this->decode_stored( (string) ( $row['checkpoint_json'] ?? '' ), (string) ( $row['checkpoint_hash'] ?? '' ) );
		$plan       = $preview['plan'] ?? null;
		if (
			Approve_Legacy_Migration::PREVIEW_PROFILE !== ( $preview['profile'] ?? null )
			|| 'approved' !== ( $preview['state'] ?? null )
			|| ! is_array( $plan )
			|| array_is_list( $plan )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		( new Legacy_Migration_Plan( $this->canonical ) )->assert_valid( $plan );
		$source_profile = (string) ( $plan['source']['profile'] ?? '' );
		$source_kind    = strstr( $source_profile, '@', true );
		$success_total  = $this->non_negative_integer( $row['success_total'] ?? null );
		$failure_total  = $this->non_negative_integer( $row['failure_total'] ?? null );
		$skipped_total  = $this->non_negative_integer( $row['skipped_total'] ?? null );
		$item_total     = $this->non_negative_integer( $row['item_total'] ?? null );
		$this->assert_checkpoint(
			$checkpoint,
			$source_profile,
			(string) ( $row['source_fingerprint'] ?? '' ),
			(string) ( $plan['plan_hash'] ?? '' ),
			$success_total,
			$failure_total,
			$skipped_total,
			$item_total
		);
		if (
			! is_string( $source_kind )
			|| ! hash_equals( (string) ( $row['source_kind'] ?? '' ), $source_kind )
			|| ! hash_equals( (string) ( $row['source_version'] ?? '' ), (string) ( $plan['source']['version'] ?? '' ) )
			|| ! hash_equals( (string) ( $row['source_fingerprint'] ?? '' ), (string) ( $plan['source']['fingerprint'] ?? '' ) )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}

		return new Running_Legacy_Migration_Run(
			$this->positive_integer( $row['id'] ?? null ),
			(string) ( $row['public_id'] ?? '' ),
			$this->positive_integer( $row['lock_version'] ?? null ),
			$this->positive_integer( $row['updated_by_user_id'] ?? null ),
			$source_profile,
			(string) ( $row['source_fingerprint'] ?? '' ),
			(string) ( $plan['plan_hash'] ?? '' ),
			$plan,
			(string) ( $row['checkpoint_hash'] ?? '' ),
			$checkpoint,
			$item_total,
			$success_total,
			$failure_total,
			$skipped_total
		);
	}

	/**
	 * Reject invalid checkpoint.
	 *
	 * @param array<string,mixed> $checkpoint Checkpoint.
	 * @param string              $source_profile Source profile.
	 * @param string              $source_fingerprint Source fingerprint.
	 * @param string              $plan_hash Plan hash.
	 * @param int                 $success_total Success total.
	 * @param int                 $failure_total Failure total.
	 * @param int                 $skipped_total Skipped total.
	 * @param int                 $item_total Item total.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_checkpoint(
		array $checkpoint,
		string $source_profile,
		string $source_fingerprint,
		string $plan_hash,
		int $success_total,
		int $failure_total,
		int $skipped_total,
		int $item_total
	): void {
		$this->exact_keys( $checkpoint, array( 'batch_size', 'cursor', 'phase', 'plan_hash', 'profile', 'source', 'totals' ) );
		$source = $checkpoint['source'] ?? null;
		$cursor = $checkpoint['cursor'] ?? null;
		$totals = $checkpoint['totals'] ?? null;
		if ( is_array( $source ) ) {
			$this->exact_keys( $source, array( 'fingerprint', 'profile' ) );
		}
		if ( is_array( $cursor ) ) {
			$this->exact_keys( $cursor, array( 'after_source_id', 'next_item_no', 'table_index' ) );
		}
		if ( is_array( $totals ) ) {
			$this->exact_keys( $totals, array( 'failure', 'skipped', 'success' ) );
		}
		if (
			Execute_Legacy_Migration::CHECKPOINT_PROFILE !== ( $checkpoint['profile'] ?? null )
			|| 'running' !== ( $checkpoint['phase'] ?? null )
			|| Execute_Legacy_Migration::BATCH_SIZE !== ( $checkpoint['batch_size'] ?? null )
			|| ! is_array( $source ) || ! is_array( $cursor ) || ! is_array( $totals )
			|| ! hash_equals( $source_profile, (string) ( $source['profile'] ?? '' ) )
			|| ! hash_equals( $source_fingerprint, (string) ( $source['fingerprint'] ?? '' ) )
			|| ! hash_equals( $plan_hash, (string) ( $checkpoint['plan_hash'] ?? '' ) )
			|| ! is_int( $cursor['table_index'] ?? null ) || $cursor['table_index'] < 0
			|| ! is_int( $cursor['after_source_id'] ?? null ) || $cursor['after_source_id'] < 0
			|| ! is_int( $cursor['next_item_no'] ?? null ) || $cursor['next_item_no'] < 1
			|| $success_total !== ( $totals['success'] ?? null )
			|| $failure_total !== ( $totals['failure'] ?? null )
			|| $skipped_total !== ( $totals['skipped'] ?? null )
			|| 1 + $success_total + $failure_total + $skipped_total !== ( $cursor['next_item_no'] )
			|| ( $cursor['next_item_no'] ) > $item_total + 1
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Reject invalid batch.
	 *
	 * @param Running_Legacy_Migration_Run $run Run.
	 * @param Legacy_Source_Item_Batch     $batch Batch.
	 * @param string                       $processed_at_utc Processed at utc.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_batch( Running_Legacy_Migration_Run $run, Legacy_Source_Item_Batch $batch, string $processed_at_utc ): void {
		$this->utc( $processed_at_utc );
		$cursor = $run->checkpoint()['cursor'] ?? null;
		if ( ! is_array( $cursor ) || ( 0 === $batch->count() && ! $batch->exhausted() ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		$expected_item_no = (int) ( $cursor['next_item_no'] ?? 0 );
		foreach ( $batch->items() as $item ) {
			if ( $item->item_no() !== $expected_item_no ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::SOURCE_STALE );
			}
			++$expected_item_no;
		}
		if ( $batch->next_item_no() !== $expected_item_no ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::SOURCE_STALE );
		}
	}

	/**
	 * Item row.
	 *
	 * @param int                $run_database_id Run database id.
	 * @param Legacy_Source_Item $item Item.
	 * @return array|null
	 */
	private function item_row( int $run_database_id, Legacy_Source_Item $item ): ?array {
		$sql = 'SELECT item_no, source_checksum, state, target_type, target_public_id FROM ' . self::TABLE_ITEMS
			. ' WHERE import_run_id = %d AND source_type = %s AND source_key = %s LIMIT 1 FOR UPDATE';
		return $this->row( $this->prepare( $sql, array( $run_database_id, $item->source_type(), $item->source_key() ) ) );
	}

	/**
	 * Insert item.
	 *
	 * @param Running_Legacy_Migration_Run    $run Run.
	 * @param Legacy_Source_Item              $item Item.
	 * @param Legacy_Target_Write_Result|null $target Target.
	 * @param string|null                     $error_code Error code.
	 * @param string                          $created_at_utc Created at utc.
	 * @return void
	 */
	private function insert_item(
		Running_Legacy_Migration_Run $run,
		Legacy_Source_Item $item,
		?Legacy_Target_Write_Result $target,
		?string $error_code,
		string $created_at_utc
	): void {
		$payload      = array(
			'profile'    => self::ITEM_PROFILE,
			'source'     => array(
				'type'     => $item->source_type(),
				'key'      => $item->source_key(),
				'checksum' => $item->source_checksum(),
			),
			'state'      => null === $target ? 'failure' : 'success',
			'target'     => null === $target ? null : array(
				'type'         => $target->target_type(),
				'public_id'    => $target->target_public_id(),
				'content_hash' => $target->content_hash(),
				'metadata'     => $target->metadata(),
			),
			'error_code' => $error_code,
		);
		$payload_json = $this->canonical->encode( $payload );
		$this->insert_or_fail(
			self::TABLE_ITEMS,
			array(
				'lock_version'       => 0,
				'created_by_user_id' => $run->actor_user_id(),
				'created_at_utc'     => $created_at_utc,
				'updated_by_user_id' => $run->actor_user_id(),
				'updated_at_utc'     => $created_at_utc,
				'import_run_id'      => $run->database_id(),
				'item_no'            => $item->item_no(),
				'source_type'        => $item->source_type(),
				'source_key'         => $item->source_key(),
				'source_checksum'    => $item->source_checksum(),
				'state'              => null === $target ? 'failure' : 'success',
				'payload_schema'     => self::ITEM_SCHEMA,
				'payload_version'    => 1,
				'payload_json'       => $payload_json,
				'payload_hash'       => hash( 'sha256', $payload_json ),
				'target_type'        => null === $target ? null : $target->target_type(),
				'target_public_id'   => null === $target ? null : $target->target_public_id(),
				'error_code'         => $error_code,
			)
		);
	}

	/**
	 * Next checkpoint.
	 *
	 * @param Running_Legacy_Migration_Run $run Run.
	 * @param Legacy_Source_Item_Batch     $batch Batch.
	 * @param string                       $state State.
	 * @param int                          $success_total Success total.
	 * @param int                          $failure_total Failure total.
	 * @param int                          $skipped_total Skipped total.
	 * @return array<string,mixed>
	 */
	private function next_checkpoint(
		Running_Legacy_Migration_Run $run,
		Legacy_Source_Item_Batch $batch,
		string $state,
		int $success_total,
		int $failure_total,
		int $skipped_total
	): array {
		return array(
			'profile'    => Execute_Legacy_Migration::CHECKPOINT_PROFILE,
			'phase'      => $state,
			'batch_size' => Execute_Legacy_Migration::BATCH_SIZE,
			'source'     => array(
				'profile'     => $run->source_profile(),
				'fingerprint' => $run->source_fingerprint(),
			),
			'plan_hash'  => $run->plan_hash(),
			'cursor'     => array(
				'table_index'     => $batch->next_table_index(),
				'after_source_id' => $batch->next_after_source_id(),
				'next_item_no'    => $batch->next_item_no(),
			),
			'totals'     => array(
				'success' => $success_total,
				'failure' => $failure_total,
				'skipped' => $skipped_total,
			),
		);
	}

	/**
	 * Update run.
	 *
	 * @param Running_Legacy_Migration_Run $run Run.
	 * @param string                       $state State.
	 * @param string                       $checkpoint_json Checkpoint json.
	 * @param string                       $checkpoint_hash Checkpoint hash.
	 * @param int                          $success_total Success total.
	 * @param int                          $failure_total Failure total.
	 * @param int                          $skipped_total Skipped total.
	 * @param string                       $processed_at_utc Processed at utc.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function update_run(
		Running_Legacy_Migration_Run $run,
		string $state,
		string $checkpoint_json,
		string $checkpoint_hash,
		int $success_total,
		int $failure_total,
		int $skipped_total,
		string $processed_at_utc
	): void {
		$database = $this->database();
		$this->reset_error( $database );
		$result = $database->update(
			self::TABLE_RUNS,
			array(
				'lock_version'       => $run->lock_version() + 1,
				'updated_by_user_id' => $run->actor_user_id(),
				'updated_at_utc'     => $processed_at_utc,
				'state'              => $state,
				'checkpoint_json'    => $checkpoint_json,
				'checkpoint_hash'    => $checkpoint_hash,
				'success_total'      => $success_total,
				'failure_total'      => $failure_total,
				'skipped_total'      => $skipped_total,
				'error_code'         => 'completed_with_errors' === $state ? 'legacy_migration_item_failures' : null,
			),
			array(
				'id'              => $run->database_id(),
				'lock_version'    => $run->lock_version(),
				'state'           => 'running',
				'checkpoint_hash' => $run->checkpoint_hash(),
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
	 * Insert terminal events.
	 *
	 * @param Running_Legacy_Migration_Run $run Run.
	 * @param string                       $state State.
	 * @param string                       $checkpoint_hash Checkpoint hash.
	 * @param int                          $batch_item_count Batch item count.
	 * @param int                          $success_total Success total.
	 * @param int                          $failure_total Failure total.
	 * @param int                          $skipped_total Skipped total.
	 * @param string                       $processed_at_utc Processed at utc.
	 * @return void
	 */
	private function insert_terminal_events(
		Running_Legacy_Migration_Run $run,
		string $state,
		string $checkpoint_hash,
		int $batch_item_count,
		int $success_total,
		int $failure_total,
		int $skipped_total,
		string $processed_at_utc
	): void {
		$event          = 'completed_with_errors' === $state
			? self::EVENT_COMPLETED_WITH_ERRORS
			: self::EVENT_COMPLETED;
		$correlation_id = 'legacy-migration-worker-' . $run->public_id() . '-' . ( $run->lock_version() + 1 );
		$metadata_json  = $this->canonical->encode(
			array(
				'batch_item_count' => $batch_item_count,
				'checkpoint_hash'  => $checkpoint_hash,
				'failure_total'    => $failure_total,
				'skipped_total'    => $skipped_total,
				'success_total'    => $success_total,
			)
		);
		$metadata_hash  = hash( 'sha256', $metadata_json );
		$previous       = $this->scalar( 'SELECT event_hash FROM ' . self::TABLE_AUDIT . ' ORDER BY id DESC LIMIT 1 FOR UPDATE' );
		if ( null !== $previous ) {
			$this->hash_value( (string) $previous );
		}
		$event_hash = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'action_key'          => $event,
					'actor_user_id'       => $run->actor_user_id(),
					'after_digest'        => $checkpoint_hash,
					'before_digest'       => $run->checkpoint_hash(),
					'correlation_id'      => $correlation_id,
					'metadata_hash'       => $metadata_hash,
					'object_public_id'    => $run->public_id(),
					'object_type'         => self::AGGREGATE_TYPE,
					'occurred_at_utc'     => $processed_at_utc,
					'previous_event_hash' => $previous,
				)
			)
		);
		$ids        = $this->ids ??= new Public_Id_Generator();
		$this->insert_or_fail(
			self::TABLE_AUDIT,
			array(
				'public_id'           => $ids->create()->value(),
				'occurred_at_utc'     => $processed_at_utc,
				'actor_user_id'       => $run->actor_user_id(),
				'action_key'          => $event,
				'object_type'         => self::AGGREGATE_TYPE,
				'object_public_id'    => $run->public_id(),
				'context_type'        => null,
				'context_id'          => null,
				'context_revision'    => null,
				'correlation_id'      => $correlation_id,
				'before_digest'       => $run->checkpoint_hash(),
				'after_digest'        => $checkpoint_hash,
				'metadata_schema'     => self::TERMINAL_AUDIT_SCHEMA,
				'metadata_version'    => 1,
				'metadata_json'       => $metadata_json,
				'metadata_hash'       => $metadata_hash,
				'previous_event_hash' => $previous,
				'event_hash'          => $event_hash,
			)
		);

		$payload_json = $this->canonical->encode(
			array(
				'batch_item_count' => $batch_item_count,
				'checkpoint_hash'  => $checkpoint_hash,
				'event'            => $event,
				'profile'          => self::TERMINAL_EVENT_SCHEMA . '@1.0.0',
				'run_id'           => $run->public_id(),
				'state'            => $state,
				'totals'           => array(
					'success' => $success_total,
					'failure' => $failure_total,
					'skipped' => $skipped_total,
				),
			)
		);
		$this->insert_or_fail(
			self::TABLE_OUTBOX,
			array(
				'public_id'            => $ids->create()->value(),
				'lock_version'         => 0,
				'created_by_user_id'   => $run->actor_user_id(),
				'created_at_utc'       => $processed_at_utc,
				'updated_by_user_id'   => $run->actor_user_id(),
				'updated_at_utc'       => $processed_at_utc,
				'aggregate_type'       => self::AGGREGATE_TYPE,
				'aggregate_public_id'  => $run->public_id(),
				'event_type'           => $event,
				'idempotency_key'      => 'legacy-migration-terminal:' . $run->public_id(),
				'payload_schema'       => self::TERMINAL_EVENT_SCHEMA,
				'payload_version'      => 1,
				'payload_json'         => $payload_json,
				'payload_hash'         => hash( 'sha256', $payload_json ),
				'available_at_utc'     => $processed_at_utc,
				'published_at_utc'     => null,
				'lease_token'          => null,
				'lease_expires_at_utc' => null,
				'attempt_count'        => 0,
				'last_error_code'      => null,
			)
		);
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
		if ( '' !== trim( (string) ( $database->last_error ?? '' ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
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
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function scalar( string $sql ) {
		$database = $this->database();
		$this->reset_error( $database );
		$value = $database->get_var( $sql );
		if ( '' !== trim( (string) ( $database->last_error ?? '' ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
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
	 * Rollback target batch.
	 *
	 * @param Legacy_Target_Batch_Lifecycle_Port_Interface|null $lifecycle Lifecycle.
	 * @param bool                                              &$active Active.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function rollback_target_batch( ?Legacy_Target_Batch_Lifecycle_Port_Interface $lifecycle, bool &$active ): void {
		if ( ! $active || ! $lifecycle instanceof Legacy_Target_Batch_Lifecycle_Port_Interface ) {
			return;
		}
		$active = false;
		try {
			$lifecycle->rollback_batch(); } catch ( Throwable $error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE, $error );
			}
	}

	/**
	 * Rollback.
	 *
	 * @return void
	 */
	private function rollback(): void {
		try {
			$this->database()->query( 'ROLLBACK' ); } catch ( Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Preserve the documented recovery or optional-operation outcome; the catch body explains the fallback.
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
