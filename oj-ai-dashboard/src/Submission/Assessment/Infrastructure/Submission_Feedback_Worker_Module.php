<?php
/**
 * Submission feedback worker module.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Submission\Assessment\Infrastructure;

use OJAI\Dashboard\Contracts\Module_Interface;
use OJAI\Dashboard\Worksheet\Evaluation\Infrastructure\WordPress_Evaluation_Queue_Dispatcher;
use OJAI\Dashboard\Submission\Assessment\Application\Run_Next_Submission_Feedback;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Submission feedback worker module.
 */
final class Submission_Feedback_Worker_Module implements Module_Interface {
	public const SWEEP_HOOK = 'oj_ai_dashboard_submission_feedback_worker_sweep';
	/**
	 * Initialize submission feedback worker module dependencies and state.
	 *
	 * @param Run_Next_Submission_Feedback               $worker Worker.
	 * @param WordPress_Evaluation_Queue_Dispatcher|null $dispatcher Shared serial dispatcher.
	 */
	public function __construct( private Run_Next_Submission_Feedback $worker, private ?WordPress_Evaluation_Queue_Dispatcher $dispatcher = null ) {
		$this->dispatcher?->register_worker( 'feedback_draft', array( $worker, 'run_once' ) );
	}
	/**
	 * Register.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'init', array( $this, 'ensure_scheduled' ) );
		$callback = array( $this->dispatcher ?? $this, 'run' );
		add_action( WordPress_Submission_Evaluation_Queue_Wakeup::HOOK, $callback );
		add_action( self::SWEEP_HOOK, $callback );
	}
	/**
	 * Ensure scheduled.
	 *
	 * @return void
	 */
	public function ensure_scheduled(): void {
		if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_event' ) ) {
			return;
		} if ( false === wp_next_scheduled( self::SWEEP_HOOK ) ) {
			wp_schedule_event( time() + 60, Submission_Evaluation_Worker_Module::SCHEDULE, self::SWEEP_HOOK );
		}
	}
	/**
	 * Run submission feedback worker module.
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
			wp_clear_scheduled_hook( self::SWEEP_HOOK );
		}
	}
}
