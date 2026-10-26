<?php
/**
 * WordPress Cron entrypoint for assignment deadline finalization.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Student\Infrastructure;

use OJAI\Dashboard\Contracts\Module_Interface;
use OJAI\Dashboard\Submission\Student\Application\Run_Auto_Submit_Due;
use OJAI\Dashboard\Submission\Student\Application\Run_Auto_Submit_Due_Result;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WordPress Cron entrypoint for assignment deadline finalization.
 */
final class Auto_Submit_Due_Worker_Module implements Module_Interface {
	public const SWEEP_HOOK                 = 'oj_ai_dashboard_assignment_auto_submit_sweep';
	public const SCHEDULE                   = 'oj_ai_dashboard_every_minute';
	private const MAX_ASSIGNMENTS_PER_SWEEP = 10;

	/**
	 * Initialize auto submit due worker module dependencies and state.
	 *
	 * @param Run_Auto_Submit_Due $worker Worker.
	 */
	public function __construct( private Run_Auto_Submit_Due $worker ) {
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
				'display'  => 'OJ AI auto-submit every minute',
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
		if ( function_exists( 'wp_next_scheduled' ) && function_exists( 'wp_schedule_event' ) && false === wp_next_scheduled( self::SWEEP_HOOK ) ) {
			wp_schedule_event( time() + 10, self::SCHEDULE, self::SWEEP_HOOK );
		}
	}

	/**
	 * Run auto submit due worker module.
	 *
	 * @return void
	 */
	public function run(): void {
		try {
			for ( $count = 0; $count < self::MAX_ASSIGNMENTS_PER_SWEEP; ++$count ) {
				if ( Run_Auto_Submit_Due_Result::IDLE === $this->worker->run_once()->state() ) {
					break;
				}
			}
		} catch ( Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- This optional step must not replace the surrounding operation outcome.
			/* The outbox lease and retry time remain the recovery source. */
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
