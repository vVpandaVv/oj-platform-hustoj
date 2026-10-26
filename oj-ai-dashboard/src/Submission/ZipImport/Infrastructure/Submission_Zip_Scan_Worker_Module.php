<?php
/**
 * WordPress Cron entrypoint for staged submission ZIP scans.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\ZipImport\Infrastructure;

use OJAI\Dashboard\Contracts\Module_Interface;
use OJAI\Dashboard\Contracts\Storage_Worker_Heartbeat_Port_Interface;
use OJAI\Dashboard\Submission\ZipImport\Application\Run_Next_Submission_Zip_Scan;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WordPress Cron entrypoint for staged submission ZIP scans.
 */
final class Submission_Zip_Scan_Worker_Module implements Module_Interface {
	public const SWEEP_HOOK = 'oj_ai_dashboard_submission_zip_scan_sweep';
	public const SCHEDULE   = 'oj_ai_dashboard_every_minute';
	/** Storage detail page worker key -- kept identical to the Resume_Storage_Jobs projection key. */
	public const HEARTBEAT_KEY = 'zip_scan';

	/**
	 * Initialize submission zip scan worker module dependencies and state.
	 *
	 * @param Run_Next_Submission_Zip_Scan            $worker Worker.
	 * @param Storage_Worker_Heartbeat_Port_Interface $heartbeat Heartbeat.
	 */
	public function __construct(
		private Run_Next_Submission_Zip_Scan $worker,
		private Storage_Worker_Heartbeat_Port_Interface $heartbeat
	) {
	}

	/**
	 * Register.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'cron_schedules', array( $this, 'cron_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- This queue uses bounded claims and leases; retain its reviewed 60/300-second wakeup cadence.
		add_action( 'init', array( $this, 'ensure_scheduled' ) );
		add_action( self::SWEEP_HOOK, array( $this, 'run' ) );
	}

	/**
	 * Cron schedules.
	 *
	 * @param array<string,array<string,mixed>> $schedules Schedules.
	 * @return array<string,array<string,mixed>>
	 */
	public function cron_schedules( array $schedules ): array {
		if ( ! isset( $schedules[ self::SCHEDULE ] ) ) {
			$schedules[ self::SCHEDULE ] = array(
				'interval' => 60,
				'display'  => 'OJ AI background work every minute',
			);
		}
		return $schedules;
	}

	/**
	 * Ensure scheduled.
	 *
	 * @return void
	 */
	public function ensure_scheduled(): void {
		if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_event' ) ) {
			return;
		}
		if ( false === wp_next_scheduled( self::SWEEP_HOOK ) ) {
			wp_schedule_event( time() + 60, self::SCHEDULE, self::SWEEP_HOOK );
		}
	}

	/**
	 * Run submission zip scan worker module.
	 *
	 * @return void
	 */
	public function run(): void {
		$this->record_heartbeat();
		try {
			$this->worker->run_once(); } catch ( Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- This optional step must not replace the surrounding operation outcome.
			// A stale five-minute root lease is reclaimed by the next sweep.
			}
	}

	/**
	 * Recorded before the real tick, and isolated from it: a heartbeat failure must never block or fail the tick.
	 *
	 * @return void
	 */
	private function record_heartbeat(): void {
		try {
			$this->heartbeat->record( self::HEARTBEAT_KEY, gmdate( 'Y-m-d H:i:s' ) ); } catch ( Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- This optional step must not replace the surrounding operation outcome.
			/* Best effort only. */
			}
	}

	/**
	 * Clear.
	 *
	 * @return void
	 */
	public static function clear(): void {
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( self::SWEEP_HOOK );
		}
	}
}
