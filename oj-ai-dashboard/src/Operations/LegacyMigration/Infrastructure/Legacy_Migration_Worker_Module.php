<?php
/**
 * Legacy migration worker module.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\LegacyMigration\Infrastructure;

use OJAI\Dashboard\Contracts\Module_Interface;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Process_Legacy_Migration_Batch;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs the exact migration named by an immediate C62 queue event.
 */
final class Legacy_Migration_Worker_Module implements Module_Interface {
	/**
	 * Registered.
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * Initialize legacy migration worker module dependencies and state.
	 *
	 * @param Process_Legacy_Migration_Batch $worker Worker.
	 */
	public function __construct( private Process_Legacy_Migration_Batch $worker ) {
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
		add_action( WordPress_Legacy_Migration_Queue_Wakeup::HOOK, array( $this, 'run' ) );
	}

	/**
	 * Run legacy migration worker module.
	 *
	 * @param mixed $run_id Run id.
	 * @return void
	 */
	public function run( $run_id = null ): void {
		try {
			if ( ! is_string( $run_id ) || ! Public_Id::is_valid( $run_id ) ) {
				return;
			}
			$this->worker->execute( $run_id );
		} catch ( Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- This optional step must not replace the surrounding operation outcome.
			// The durable checkpoint remains authoritative for the recovery path.
		}
	}

	/**
	 * Clear.
	 *
	 * @return void
	 */
	public static function clear(): void {
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( WordPress_Legacy_Migration_Queue_Wakeup::HOOK );
		}
	}
}
