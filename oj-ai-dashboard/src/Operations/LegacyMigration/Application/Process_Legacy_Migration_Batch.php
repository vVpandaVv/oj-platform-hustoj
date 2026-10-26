<?php
/**
 * Process legacy migration batch.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\LegacyMigration\Application;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Migration_Batch_Result;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Migration_Plan;
use OJAI\Dashboard\Operations\LegacyMigration\Port\Legacy_Migration_Batch_Repository_Port_Interface;
use OJAI\Dashboard\Operations\LegacyMigration\Port\Legacy_Migration_Queue_Wakeup_Port_Interface;
use OJAI\Dashboard\Operations\LegacyMigration\Port\Legacy_Migration_Site_Port_Interface;
use OJAI\Dashboard\Operations\LegacyMigration\Port\Legacy_Source_Inspector_Port_Interface;
use OJAI\Dashboard\Operations\LegacyMigration\Port\Legacy_Source_Item_Reader_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Processes one bounded C62 worker pass and requests the next pass after commit.
 */
final class Process_Legacy_Migration_Batch {
	/**
	 * Clock.
	 *
	 * @var Closure
	 */
	private Closure $clock;

	/**
	 * Initialize process legacy migration batch dependencies and state.
	 *
	 * @param Legacy_Source_Inspector_Port_Interface           $sources Sources.
	 * @param Legacy_Migration_Site_Port_Interface             $sites Sites.
	 * @param Legacy_Source_Item_Reader_Port_Interface         $reader Reader.
	 * @param Legacy_Migration_Batch_Repository_Port_Interface $repository Persistence boundary used by this operation.
	 * @param Legacy_Migration_Queue_Wakeup_Port_Interface     $queue Queue.
	 * @param Canonical_Json                                   $canonical Canonical JSON encoder used for stable digests.
	 * @param callable|null                                    $clock Clock.
	 */
	public function __construct(
		private Legacy_Source_Inspector_Port_Interface $sources,
		private Legacy_Migration_Site_Port_Interface $sites,
		private Legacy_Source_Item_Reader_Port_Interface $reader,
		private Legacy_Migration_Batch_Repository_Port_Interface $repository,
		private Legacy_Migration_Queue_Wakeup_Port_Interface $queue,
		private Canonical_Json $canonical,
		?callable $clock = null
	) {
		$this->clock = null === $clock
			? static fn (): string => gmdate( 'Y-m-d H:i:s' )
			: Closure::fromCallable( $clock );
	}

	/**
	 * Execute process legacy migration batch.
	 *
	 * @param string $run_id Run id.
	 * @return Legacy_Migration_Batch_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function execute( string $run_id ): Legacy_Migration_Batch_Result {
		try {
			$run          = $this->repository->running_run( $run_id );
			$source       = $this->sources->inspect( $run->source_profile() );
			$planner      = new Legacy_Migration_Plan( $this->canonical );
			$current_plan = $planner->rebuild( $this->sites->site_id(), $source, $run->plan() );
			$planner->assert_valid( $current_plan );
			if (
				! hash_equals( $run->source_fingerprint(), $source->source_fingerprint() )
				|| ! hash_equals( $run->plan_hash(), (string) ( $current_plan['plan_hash'] ?? '' ) )
			) {
				throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::SOURCE_STALE );
			}

			$checkpoint = $run->checkpoint();
			$cursor     = $checkpoint['cursor'] ?? null;
			if ( ! is_array( $cursor ) ) {
				throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
			}
			$batch  = $this->reader->read(
				$run->source_profile(),
				(int) ( $cursor['table_index'] ?? -1 ),
				(int) ( $cursor['after_source_id'] ?? -1 ),
				(int) ( $cursor['next_item_no'] ?? 0 ),
				Execute_Legacy_Migration::BATCH_SIZE
			);
			$result = $this->repository->commit_batch( $run, $batch, $this->now() );
			if ( ! $result->terminal() ) {
				try {
					$this->queue->wake( $result->run_id() );
				} catch ( Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- This optional step must not replace the surrounding operation outcome.
					// The committed checkpoint remains authoritative for recovery.
				}
			}
			return $result;
		} catch ( Legacy_Migration_Failure $failure ) {
			throw $failure;
		} catch ( Throwable $error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE, $error );
		}
	}

	/**
	 * Now.
	 *
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function now(): string {
		$value = ( $this->clock )();
		if ( ! is_string( $value ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		$time = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new DateTimeZone( 'UTC' ) );
		if ( ! $time instanceof DateTimeImmutable || $time->format( 'Y-m-d H:i:s' ) !== $value ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		return $value;
	}
}
