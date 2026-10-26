<?php
/**
 * WordPress purge queue wakeup.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\Purge\Infrastructure;

use OJAI\Dashboard\Operations\Purge\Port\Purge_Queue_Wakeup_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use RuntimeException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Requests one immediate C73 worker pass after a durable checkpoint commit.
 */
final class WordPress_Purge_Queue_Wakeup implements Purge_Queue_Wakeup_Port_Interface {
	public const HOOK = 'oj_ai_purge_worker_wakeup';

	/**
	 * Wake.
	 *
	 * @param string $job_id Job id.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function wake( string $job_id ): void {
		new Public_Id( $job_id );
		if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_single_event' ) ) {
			throw new RuntimeException( 'Purge queue scheduling is unavailable.' );
		}
		$args = array( $job_id );
		if ( false !== wp_next_scheduled( self::HOOK, $args ) ) {
			return;
		}
		$result = wp_schedule_single_event( time() + 1, self::HOOK, $args, true );
		if ( true !== $result ) {
			throw new RuntimeException( 'Purge queue scheduling failed.' );
		}
	}
}
