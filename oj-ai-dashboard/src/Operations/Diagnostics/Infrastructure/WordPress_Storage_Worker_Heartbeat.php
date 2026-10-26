<?php
/**
 * Autoload-disabled option-backed storage worker heartbeat.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\Diagnostics\Infrastructure;

use OJAI\Dashboard\Contracts\Storage_Worker_Heartbeat_Port_Interface;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One small `wp_options` row per worker (autoload disabled, matching the
 * existing bootstrap/lock option convention used elsewhere in this add-on --
 * see WordPress_Schema_Repair_Receipt and WordPress_External_Ai_Consent_Repository).
 * Never throws: a heartbeat write is incidental instrumentation, not the
 * worker's real job, so any failure here is swallowed and simply leaves the
 * previous (or no) heartbeat in place. A read failure resolves to "never
 * recorded" rather than raising.
 */
final class WordPress_Storage_Worker_Heartbeat implements Storage_Worker_Heartbeat_Port_Interface {
	private const OPTION_PREFIX = 'oj_ai_dashboard_storage_heartbeat_';

	/**
	 * Record.
	 *
	 * @param string $worker_key Worker key.
	 * @param string $now_utc Now utc.
	 * @return void
	 */
	public function record( string $worker_key, string $now_utc ): void {
		try {
			if ( 1 !== preg_match( '/^[a-z][a-z0-9_]{2,63}$/D', $worker_key ) ) {
				return;
			}
			if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $now_utc ) ) {
				return;
			}
			if ( ! function_exists( 'update_option' ) ) {
				return;
			}
			update_option( self::option_name( $worker_key ), $now_utc, false );
		} catch ( Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- This optional step must not replace the surrounding operation outcome.
			// A heartbeat write must never block or fail the real worker tick.
		}
	}

	/**
	 * Last heartbeats.
	 *
	 * @param array<int,string> $worker_keys Worker keys.
	 * @return array<string,string|null>
	 */
	public function last_heartbeats( array $worker_keys ): array {
		$result = array();
		foreach ( $worker_keys as $worker_key ) {
			$result[ $worker_key ] = $this->last_heartbeat( $worker_key );
		}
		return $result;
	}

	/**
	 * Last heartbeat.
	 *
	 * @param string $worker_key Worker key.
	 * @return string|null
	 */
	private function last_heartbeat( string $worker_key ): ?string {
		try {
			if ( 1 !== preg_match( '/^[a-z][a-z0-9_]{2,63}$/D', $worker_key ) ) {
				return null;
			}
			if ( ! function_exists( 'get_option' ) ) {
				return null;
			}
			$value = get_option( self::option_name( $worker_key ), null );
			if ( ! is_string( $value ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value ) ) {
				return null;
			}
			return $value;
		} catch ( Throwable ) {
			return null;
		}
	}

	/**
	 * Option name.
	 *
	 * @param string $worker_key Worker key.
	 * @return string
	 */
	private static function option_name( string $worker_key ): string {
		return self::OPTION_PREFIX . $worker_key;
	}
}
