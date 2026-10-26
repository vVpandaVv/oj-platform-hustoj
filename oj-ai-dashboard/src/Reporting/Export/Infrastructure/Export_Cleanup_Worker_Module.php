<?php
/**
 * WordPress Cron registration for export retention.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Reporting\Export\Infrastructure;

use OJAI\Dashboard\Contracts\Module_Interface;
use OJAI\Dashboard\Contracts\Storage_Worker_Heartbeat_Port_Interface;
use OJAI\Dashboard\Reporting\Export\Application\Run_Next_Export_Cleanup;
use Throwable;

/**
 * Schedules one bounded export cleanup per existing storage interval.
 */
final class Export_Cleanup_Worker_Module implements Module_Interface {
	public const SWEEP_HOOK    = 'oj_ai_dashboard_export_cleanup_sweep';
	public const SCHEDULE      = 'oj_ai_dashboard_every_minute';
	public const HEARTBEAT_KEY = 'export_cleanup';
	/**
	 * Shares the cleanup operation and diagnostic heartbeat.
	 *
	 * @param Run_Next_Export_Cleanup                 $worker Retention operation.
	 * @param Storage_Worker_Heartbeat_Port_Interface $heartbeat Invocation recorder.
	 */
	public function __construct( private Run_Next_Export_Cleanup $worker, private Storage_Worker_Heartbeat_Port_Interface $heartbeat ) {
	}
	/**
	 * Registers scheduling and invocation hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'cron_schedules', array( $this, 'cron_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- This queue uses bounded claims and leases; retain its reviewed 60/300-second wakeup cadence.
		add_action( 'init', array( $this, 'ensure_scheduled' ) );
		add_action( self::SWEEP_HOOK, array( $this, 'run' ) );
	}
	/**
	 * Reuses the storage worker interval.
	 *
	 * @param array<string,array<string,mixed>> $schedules Existing WordPress schedules.
	 * @return array<string,array<string,mixed>>
	 */
	public function cron_schedules( array $schedules ): array {
		$schedules[ self::SCHEDULE ] ??= array(
			'interval' => 60,
			'display'  => 'OJ AI storage cleanup every minute',
		);
		return $schedules;
	}
	/**
	 * Creates the sweep schedule only when missing.
	 *
	 * @return void
	 */
	public function ensure_scheduled(): void {
		if ( function_exists( 'wp_next_scheduled' ) && function_exists( 'wp_schedule_event' ) && false === wp_next_scheduled( self::SWEEP_HOOK ) ) {
			wp_schedule_event( time() + 60, self::SCHEDULE, self::SWEEP_HOOK );
		}
	}
	/**
	 * Records invocation separately from cleanup success.
	 *
	 * @return void
	 */
	public function run(): void {
		try {
			$this->heartbeat->record( self::HEARTBEAT_KEY, gmdate( 'Y-m-d H:i:s' ) );
		} catch ( Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- This optional step must not replace the surrounding operation outcome.
			// Instrumentation must not block retention.
		}
		$this->worker->run_once();
	}
	/**
	 * Removes this hook during plugin deactivation.
	 *
	 * @return void
	 */
	public static function clear(): void {
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( self::SWEEP_HOOK );
		}
	}
}
