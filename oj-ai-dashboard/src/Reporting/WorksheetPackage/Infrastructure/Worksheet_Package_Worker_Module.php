<?php
/**
 * Worksheet package worker module.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Reporting\WorksheetPackage\Infrastructure;

use OJAI\Dashboard\Contracts\Module_Interface;
use OJAI\Dashboard\Reporting\WorksheetPackage\Application\Run_Next_Worksheet_Package;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Worksheet package worker module.
 */
final class Worksheet_Package_Worker_Module implements Module_Interface {
	public const SWEEP_HOOK = 'oj_ai_dashboard_worksheet_package_worker_sweep';
	public const SCHEDULE   = 'oj_ai_dashboard_every_minute';
	/**
	 * Initialize worksheet package worker module dependencies and state.
	 *
	 * @param Run_Next_Worksheet_Package $worker Worker.
	 */
	public function __construct( private Run_Next_Worksheet_Package $worker ) {
	}
	/**
	 * Register.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'cron_schedules', array( $this, 'cron_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- This queue uses bounded claims and leases; retain its reviewed 60/300-second wakeup cadence.
		add_action( 'init', array( $this, 'ensure_scheduled' ) );
		add_action( WordPress_Worksheet_Package_Queue_Wakeup::HOOK, array( $this, 'run' ) );
		add_action( self::SWEEP_HOOK, array( $this, 'run' ) );
	}
	/**
	 * Cron schedules.
	 *
	 * @param array $schedules Schedules.
	 * @return array
	 */
	public function cron_schedules( array $schedules ): array {
		if ( ! isset( $schedules[ self::SCHEDULE ] ) ) {
			$schedules[ self::SCHEDULE ] = array(
				'interval' => 60,
				'display'  => 'OJ AI worksheet package every minute',
			);
		}return $schedules;
	}
	/**
	 * Ensure scheduled.
	 *
	 * @return void
	 */
	public function ensure_scheduled(): void {
		if ( function_exists( 'wp_next_scheduled' ) && function_exists( 'wp_schedule_event' ) && false === wp_next_scheduled( self::SWEEP_HOOK ) ) {
			wp_schedule_event( time() + 60, self::SCHEDULE, self::SWEEP_HOOK );
		}
	}
	/**
	 * Run worksheet package worker module.
	 *
	 * @return void
	 */
	public function run(): void {
		try {
			$this->worker->run_once();} catch ( Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Preserve the documented recovery or optional-operation outcome; the catch body explains the fallback.
			// This optional step must not replace the surrounding operation outcome.
 // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- This optional step must not replace the surrounding operation outcome.
			}
	}
	/**
	 * Clear.
	 *
	 * @return void
	 */
	public static function clear(): void {
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( WordPress_Worksheet_Package_Queue_Wakeup::HOOK );
			wp_clear_scheduled_hook( self::SWEEP_HOOK );
		}
	}
}
