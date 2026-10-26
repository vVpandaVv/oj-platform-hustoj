<?php
/**
 * WordPress legacy migration queue wakeup.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\LegacyMigration\Infrastructure;

use OJAI\Dashboard\Operations\LegacyMigration\Port\Legacy_Migration_Queue_Wakeup_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use RuntimeException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Requests one immediate worker pass after the durable running transition.
 */
final class WordPress_Legacy_Migration_Queue_Wakeup implements Legacy_Migration_Queue_Wakeup_Port_Interface {
	public const HOOK = 'oj_ai_legacy_migration_worker_wakeup';

	/**
	 * Wake.
	 *
	 * @param string $run_id Run id.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function wake( string $run_id ): void {
		new Public_Id( $run_id );
		if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_single_event' ) ) {
			throw new RuntimeException( 'Legacy migration queue scheduling is unavailable.' );
		}
		$args = array( $run_id );
		if ( false !== wp_next_scheduled( self::HOOK, $args ) ) {
			return;
		}
		$result = wp_schedule_single_event( time() + 1, self::HOOK, $args, true );
		if ( true !== $result ) {
			throw new RuntimeException( 'Legacy migration queue scheduling failed.' );
		}
	}
}
