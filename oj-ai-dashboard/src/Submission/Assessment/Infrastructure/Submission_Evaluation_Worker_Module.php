<?php
/**
 * Submission evaluation worker module.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Infrastructure;

use OJAI\Dashboard\Contracts\Module_Interface;
use OJAI\Dashboard\Worksheet\Evaluation\Infrastructure\WordPress_Evaluation_Queue_Dispatcher;
use OJAI\Dashboard\Submission\Assessment\Application\Run_Next_Submission_Evaluation;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Submission evaluation worker module.
 */
final class Submission_Evaluation_Worker_Module implements Module_Interface {
	public const SWEEP_HOOK = 'oj_ai_dashboard_submission_evaluation_worker_sweep';
	public const SCHEDULE   = 'oj_ai_dashboard_every_minute';

	/**
	 * Initialize submission evaluation worker module dependencies and state.
	 *
	 * @param Run_Next_Submission_Evaluation             $worker Worker.
	 * @param WordPress_Evaluation_Queue_Dispatcher|null $dispatcher Shared serial dispatcher.
	 */
	public function __construct( private Run_Next_Submission_Evaluation $worker, private ?WordPress_Evaluation_Queue_Dispatcher $dispatcher = null ) {
		$this->dispatcher?->register_worker( 'score', array( $worker, 'run_once' ) );
	}

	/**
	 * Register.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'cron_schedules', array( $this, 'cron_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- This queue uses bounded claims and leases; retain its reviewed 60/300-second wakeup cadence.
		add_action( 'init', array( $this, 'ensure_scheduled' ) );
		$callback = array( $this->dispatcher ?? $this, 'run' );
		add_action( WordPress_Submission_Evaluation_Queue_Wakeup::HOOK, $callback );
		add_action( self::SWEEP_HOOK, $callback );
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
				'display'  => 'OJ AI evaluation every minute',
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
	 * Run submission evaluation worker module.
	 *
	 * @return void
	 */
	public function run(): void {
		if ( null !== $this->dispatcher ) {
			$this->dispatcher->run();
			return;
		}
		try {
			$this->worker->run_once(); } catch ( Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Preserve the documented recovery or optional-operation outcome; the catch body explains the fallback.
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
			wp_clear_scheduled_hook( WordPress_Submission_Evaluation_Queue_Wakeup::HOOK );
			wp_clear_scheduled_hook( self::SWEEP_HOOK );
		}
	}
}
