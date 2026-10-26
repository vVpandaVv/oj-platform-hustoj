<?php
/**
 * Storage boundary for expired export artifacts.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Reporting\Export\Port;

/**
 * Storage boundary for expired export artifacts.
 */
interface Export_Cleanup_Repository_Port_Interface {
	/**
	 * Deletes at most one file under owning-job and private-file locks.
	 *
	 * @param string   $now_utc Current UTC cutoff.
	 * @param string   $retry_before_utc Earliest permitted previous attempt.
	 * @param callable $delete Private binary deletion callback.
	 * @param string   $audit_id Audit event public ID.
	 * @param string   $outbox_id Outbox event public ID.
	 * @return string
	 */
	public function purge_next( string $now_utc, string $retry_before_utc, callable $delete, string $audit_id, string $outbox_id ): string;
}
