<?php
/**
 * Process purge batch.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\Purge\Application;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use OJAI\Dashboard\Operations\Purge\Domain\Purge_Batch_Result;
use OJAI\Dashboard\Operations\Purge\Port\Purge_Operation_Repository_Port_Interface;
use OJAI\Dashboard\Operations\Purge\Port\Purge_Queue_Wakeup_Port_Interface;
use OJAI\Dashboard\Operations\Purge\Port\Purge_Site_Port_Interface;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Processes one bounded C73 worker pass and wakes the next pass after commit.
 */
final class Process_Purge_Batch {
	/**
	 * Clock.
	 *
	 * @var Closure
	 */
	private Closure $clock;

	/**
	 * Initialize process purge batch dependencies and state.
	 *
	 * @param Purge_Operation_Repository_Port_Interface $repository Persistence boundary used by this operation.
	 * @param Purge_Site_Port_Interface                 $sites Sites.
	 * @param Purge_Queue_Wakeup_Port_Interface         $queue Queue.
	 * @param callable|null                             $clock Clock.
	 */
	public function __construct(
		private Purge_Operation_Repository_Port_Interface $repository,
		private Purge_Site_Port_Interface $sites,
		private Purge_Queue_Wakeup_Port_Interface $queue,
		?callable $clock = null
	) {
		$this->clock = null === $clock
			? static fn (): string => gmdate( 'Y-m-d H:i:s' )
			: Closure::fromCallable( $clock );
	}

	/**
	 * Execute process purge batch.
	 *
	 * @param string $job_id Job id.
	 * @return Purge_Batch_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function execute( string $job_id ): Purge_Batch_Result {
		try {
			$result = $this->repository->process_batch( $job_id, $this->sites->site_id(), $this->now() );
			if ( ! $result->terminal() ) {
				try {
					$this->queue->wake( $result->job_id() );
				} catch ( Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- This optional step must not replace the surrounding operation outcome.
					// The committed checkpoint remains authoritative for recovery.
				}
			}
			return $result;
		} catch ( Purge_Failure $failure ) {
			if (
				in_array(
					$failure->reason(),
					array(
						Purge_Failure::DELETE_FAILED,
						Purge_Failure::MAINTENANCE_UNAVAILABLE,
						Purge_Failure::STORAGE_UNAVAILABLE,
					),
					true
				)
			) {
				$this->wake( $job_id );
			}
			throw $failure;
		} catch ( Throwable $error ) {
			$this->wake( $job_id );
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Purge_Failure::because( Purge_Failure::STORAGE_UNAVAILABLE, $error );
		}
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
			// The committed checkpoint remains authoritative for recovery.
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
			throw Purge_Failure::because( Purge_Failure::STORAGE_UNAVAILABLE );
		}
		$time = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new DateTimeZone( 'UTC' ) );
		if ( ! $time instanceof DateTimeImmutable || $time->format( 'Y-m-d H:i:s' ) !== $value ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Purge_Failure::because( Purge_Failure::STORAGE_UNAVAILABLE );
		}
		return $value;
	}
}
