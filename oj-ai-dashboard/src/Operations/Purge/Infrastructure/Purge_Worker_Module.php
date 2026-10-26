<?php
/**
 * Purge worker module.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\Purge\Infrastructure;

use OJAI\Dashboard\Contracts\Module_Interface;
use OJAI\Dashboard\Operations\Purge\Application\Process_Purge_Batch;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs the exact purge job named by an immediate C73 queue event.
 */
final class Purge_Worker_Module implements Module_Interface {
	/**
	 * Registered.
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * Initialize purge worker module dependencies and state.
	 *
	 * @param Process_Purge_Batch $worker Worker.
	 */
	public function __construct( private Process_Purge_Batch $worker ) {
	}

	/**
	 * Register.
	 *
	 * @return void
	 */
	public function register() {
		if ( $this->registered ) {
			return;
		}
		$this->registered = true;
		add_action( WordPress_Purge_Queue_Wakeup::HOOK, array( $this, 'run' ) );
	}

	/**
	 * Run purge worker module.
	 *
	 * @param mixed $job_id Job id.
	 * @return void
	 */
	public function run( $job_id = null ): void {
		try {
			if ( ! is_string( $job_id ) || ! Public_Id::is_valid( $job_id ) ) {
				return;
			}
			$this->worker->execute( $job_id );
		} catch ( Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- This optional step must not replace the surrounding operation outcome.
			// The durable checkpoint remains authoritative for recovery.
		}
	}

	/**
	 * Clear.
	 *
	 * @return void
	 */
	public static function clear(): void {
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( WordPress_Purge_Queue_Wakeup::HOOK );
		}
	}
}
