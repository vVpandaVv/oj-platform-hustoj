<?php
/**
 * Manually drains storage worker queues within one HTTP request.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\Diagnostics\Application;

use Closure;
use OJAI\Dashboard\Reporting\Export\Application\Run_Next_Export_Cleanup;
use OJAI\Dashboard\Submission\Student\Application\Run_Next_Submission_Attachment_Cleanup;
use OJAI\Dashboard\Submission\Student\Application\Run_Next_Submission_Attachment_Scan;
use OJAI\Dashboard\Submission\Student\Application\Run_Submission_Attachment_Cleanup_Result;
use OJAI\Dashboard\Submission\Student\Application\Run_Submission_Attachment_Scan_Result;
use OJAI\Dashboard\Submission\ZipImport\Application\Run_Next_Submission_Zip_Cleanup;
use OJAI\Dashboard\Submission\ZipImport\Application\Run_Next_Submission_Zip_Scan;
use OJAI\Dashboard\Submission\ZipImport\Application\Run_Submission_Zip_Cleanup_Result;
use OJAI\Dashboard\Submission\ZipImport\Application\Run_Submission_Zip_Scan_Result;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stands in for WP-Cron on hosts where the pseudo-cron never fires: each of the
 * storage workers already claim and process at most one unit of work
 * per `run_once()` call, with its own per-item optimistic-locking claim, so
 * calling this repeatedly (including concurrently, from two admins) is exactly
 * as safe as letting cron fire the same hooks more often -- no idempotency
 * receipt cache is layered on top because there is nothing here that a second,
 * overlapping call could corrupt.
 */
final class Resume_Storage_Jobs {
	/** Bounded so one HTTP request cannot run away inside a typical PHP execution-time limit. */
	public const MAX_ITERATIONS_PER_WORKER = 20;

	/**
	 * Initialize resume storage jobs dependencies and state.
	 *
	 * @param Run_Next_Submission_Attachment_Scan    $attachment_scan Attachment scan.
	 * @param Run_Next_Submission_Attachment_Cleanup $attachment_cleanup Attachment cleanup.
	 * @param Run_Next_Submission_Zip_Scan           $zip_scan Zip scan.
	 * @param Run_Next_Submission_Zip_Cleanup        $zip_cleanup Zip cleanup.
	 * @param Run_Next_Export_Cleanup                $export_cleanup Export cleanup.
	 */
	public function __construct(
		private Run_Next_Submission_Attachment_Scan $attachment_scan,
		private Run_Next_Submission_Attachment_Cleanup $attachment_cleanup,
		private Run_Next_Submission_Zip_Scan $zip_scan,
		private Run_Next_Submission_Zip_Cleanup $zip_cleanup,
		private Run_Next_Export_Cleanup $export_cleanup
	) {
	}

	/**
	 * Execute resume storage jobs.
	 *
	 * @return Resume_Storage_Jobs_Result
	 */
	public function execute(): Resume_Storage_Jobs_Result {
		return new Resume_Storage_Jobs_Result(
			$this->drain(
				fn (): string => $this->attachment_scan->run_once()->state(),
				Run_Submission_Attachment_Scan_Result::IDLE,
				array( Run_Submission_Attachment_Scan_Result::READY, Run_Submission_Attachment_Scan_Result::REJECTED )
			),
			$this->drain(
				fn (): string => $this->attachment_cleanup->run_once()->state(),
				Run_Submission_Attachment_Cleanup_Result::IDLE,
				array( Run_Submission_Attachment_Cleanup_Result::PURGED )
			),
			$this->drain(
				fn (): string => $this->zip_scan->run_once()->state(),
				Run_Submission_Zip_Scan_Result::IDLE,
				array( Run_Submission_Zip_Scan_Result::READY, Run_Submission_Zip_Scan_Result::REJECTED )
			),
			$this->drain(
				fn (): string => $this->zip_cleanup->run_once()->state(),
				Run_Submission_Zip_Cleanup_Result::IDLE,
				array( Run_Submission_Zip_Cleanup_Result::PURGED )
			),
			$this->drain(
				fn (): string => $this->export_cleanup->run_once(),
				Run_Next_Export_Cleanup::IDLE,
				array( Run_Next_Export_Cleanup::PURGED )
			)
		);
	}

	/**
	 * Runs one worker's `run_once()` until it reports idle or the cap is hit.
	 *
	 * A worker that throws (never expected, since each `run_once()` already
	 * recovers from its own per-item failures) never aborts the sweep -- it is
	 * recorded as a failed worker with whatever iteration count it reached, and
	 * the remaining workers still get their turn.
	 *
	 * @param Closure           $tick Tick.
	 * @phpstan-param Closure():string $tick
	 * @param string            $idle_state Idle state.
	 * @param array<int,string> $completed_states Completed states.
	 * @return Resume_Storage_Jobs_Worker_Outcome
	 */
	private function drain( Closure $tick, string $idle_state, array $completed_states ): Resume_Storage_Jobs_Worker_Outcome {
		$iterations = 0;
		try {
			while ( $iterations < self::MAX_ITERATIONS_PER_WORKER ) {
				$state = $tick();
				if ( $idle_state === $state ) {
					break;
				}
				// Retry waits and stale claims are not completed work; do not spin on them.
				if ( ! in_array( $state, $completed_states, true ) ) {
					return Resume_Storage_Jobs_Worker_Outcome::failed( $iterations );
				}
				++$iterations;
			}
			return Resume_Storage_Jobs_Worker_Outcome::completed( $iterations );
		} catch ( Throwable ) {
			return Resume_Storage_Jobs_Worker_Outcome::failed( $iterations );
		}
	}
}
