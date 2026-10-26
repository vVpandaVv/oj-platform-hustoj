<?php
/**
 * Cron-liveness heartbeat contract for the four storage workers.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records that a storage worker's cron hook actually executed, and reports
 * back the last recorded time per worker key. This is the closest honest
 * signal to "is cron really running" available without deeper server
 * access -- it observes invocation, not success.
 *
 * An implementation must never let a write or read failure raise: this is
 * incidental instrumentation, never the worker's real job, and a failure
 * here must never turn into a stuck job or a broken diagnostics read.
 */
interface Storage_Worker_Heartbeat_Port_Interface {
	/**
	 * Record that the named worker was invoked at the given UTC time.
	 * Called on every tick, whether or not the tick found work to do.
	 *
	 * @param string $worker_key Worker key.
	 * @param string $now_utc Now utc.
	 * @return void
	 */
	public function record( string $worker_key, string $now_utc ): void;

	/**
	 * Last heartbeats.
	 *
	 * @param array<int,string> $worker_keys Worker keys.
	 * @return array<string,string|null> Last recorded UTC timestamp per worker key, or null if never recorded.
	 */
	public function last_heartbeats( array $worker_keys ): array;
}
