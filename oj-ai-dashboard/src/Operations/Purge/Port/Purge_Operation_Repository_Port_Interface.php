<?php
/**
 * Purge operation repository port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\Purge\Port;

use OJAI\Dashboard\Operations\Purge\Application\Cancel_Purge_Result;
use OJAI\Dashboard\Operations\Purge\Application\Execute_Purge_Result;
use OJAI\Dashboard\Operations\Purge\Domain\Purge_Batch_Result;
use OJAI\Dashboard\Operations\Purge\Domain\Purge_Job_Snapshot;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Purge operation repository port interface.
 */
interface Purge_Operation_Repository_Port_Interface {
	/**
	 * Execution receipt.
	 *
	 * @param string $storage_key Storage key.
	 * @param string $request_digest Request digest.
	 * @return Execute_Purge_Result|null
	 */
	public function execution_receipt( string $storage_key, string $request_digest ): ?Execute_Purge_Result;
	/**
	 * Cancellation receipt.
	 *
	 * @param string $storage_key Storage key.
	 * @param string $request_digest Request digest.
	 * @return Cancel_Purge_Result|null
	 */
	public function cancellation_receipt( string $storage_key, string $request_digest ): ?Cancel_Purge_Result;
	/**
	 * Job.
	 *
	 * @param string $job_id Job id.
	 * @return Purge_Job_Snapshot
	 */
	public function job( string $job_id ): Purge_Job_Snapshot;
	/**
	 * Start.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Execute_Purge_Result
	 */
	public function start( array $record ): Execute_Purge_Result;
	/**
	 * Cancel.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Cancel_Purge_Result
	 */
	public function cancel( array $record ): Cancel_Purge_Result;
	/**
	 * Process batch.
	 *
	 * @param string $job_id Job id.
	 * @param string $site_id Site id.
	 * @param string $processed_at_utc Processed at utc.
	 * @return Purge_Batch_Result
	 */
	public function process_batch( string $job_id, string $site_id, string $processed_at_utc ): Purge_Batch_Result;
}
