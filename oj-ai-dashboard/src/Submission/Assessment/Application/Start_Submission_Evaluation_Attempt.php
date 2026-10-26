<?php
/**
 * Start one append-only attempt while renewing its run lease.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Application;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Evaluation_Work_Item;
use OJAI\Dashboard\Submission\Assessment\Port\Submission_Evaluation_Work_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Start one append-only attempt while renewing its run lease.
 */
final class Start_Submission_Evaluation_Attempt {
	/**
	 * Clock.
	 *
	 * @var Closure
	 */
	private Closure $clock;

	/**
	 * Initialize start submission evaluation attempt dependencies and state.
	 *
	 * @param Submission_Evaluation_Work_Repository_Port_Interface $repository Persistence boundary used by this operation.
	 * @param Public_Id_Generator                                  $ids Ids.
	 * @param callable|null                                        $clock Clock.
	 */
	public function __construct(
		private Submission_Evaluation_Work_Repository_Port_Interface $repository,
		private Public_Id_Generator $ids,
		?callable $clock = null
	) {
		$this->clock = null === $clock ? static fn (): string => gmdate( 'Y-m-d H:i:s' ) : Closure::fromCallable( $clock );
	}

	/**
	 * Execute start submission evaluation attempt.
	 *
	 * @param Submission_Evaluation_Work_Item $item Item.
	 * @return Submission_Evaluation_Work_Item|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function execute( Submission_Evaluation_Work_Item $item ): ?Submission_Evaluation_Work_Item {
		if ( 'leased' !== $item->state() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Worker_Failure::because( Submission_Evaluation_Worker_Failure::INVALID_WORK_ITEM );
		}
		$now = $this->now();
		return $this->repository->start_attempt(
			$item,
			$this->ids->create()->value(),
			$now,
			$this->plus_seconds( $now, Claim_Next_Submission_Evaluation::LEASE_SECONDS )
		);
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
			throw Submission_Evaluation_Worker_Failure::because( Submission_Evaluation_Worker_Failure::INVALID_WORK_ITEM );
		}
		return $value;
	}

	/**
	 * Plus seconds.
	 *
	 * @param string $utc Utc.
	 * @param int    $seconds Seconds.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function plus_seconds( string $utc, int $seconds ): string {
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $utc, new DateTimeZone( 'UTC' ) );
		if ( false === $date ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Worker_Failure::because( Submission_Evaluation_Worker_Failure::INVALID_WORK_ITEM );
		}
		return $date->modify( '+' . $seconds . ' seconds' )->format( 'Y-m-d H:i:s' );
	}
}
