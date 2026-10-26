<?php
/**
 * Legacy migration batch result.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\LegacyMigration\Domain;

use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Receipt for one committed migration worker batch.
 */
final class Legacy_Migration_Batch_Result {
	/**
	 * Initialize legacy migration batch result dependencies and state.
	 *
	 * @param string $run_id Run id.
	 * @param string $state State.
	 * @param string $checkpoint_hash Checkpoint hash.
	 * @param int    $batch_item_count Batch item count.
	 * @param int    $success_total Success total.
	 * @param int    $failure_total Failure total.
	 * @param int    $skipped_total Skipped total.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private string $run_id,
		private string $state,
		private string $checkpoint_hash,
		private int $batch_item_count,
		private int $success_total,
		private int $failure_total,
		private int $skipped_total
	) {
		if (
			! Public_Id::is_valid( $run_id )
			|| ! in_array( $state, array( 'running', 'completed', 'completed_with_errors' ), true )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $checkpoint_hash )
			|| min( $batch_item_count, $success_total, $failure_total, $skipped_total ) < 0
			|| $batch_item_count > 250
		) {
			throw new InvalidArgumentException( 'Legacy migration batch result is invalid.' );
		}
	}

	/**
	 * Run id.
	 *
	 * @return string
	 */
	public function run_id(): string {
		return $this->run_id;
	}
	/**
	 * State.
	 *
	 * @return string
	 */
	public function state(): string {
		return $this->state;
	}
	/**
	 * Checkpoint hash.
	 *
	 * @return string
	 */
	public function checkpoint_hash(): string {
		return $this->checkpoint_hash;
	}
	/**
	 * Batch item count.
	 *
	 * @return int
	 */
	public function batch_item_count(): int {
		return $this->batch_item_count;
	}
	/**
	 * Success total.
	 *
	 * @return int
	 */
	public function success_total(): int {
		return $this->success_total;
	}
	/**
	 * Failure total.
	 *
	 * @return int
	 */
	public function failure_total(): int {
		return $this->failure_total;
	}
	/**
	 * Skipped total.
	 *
	 * @return int
	 */
	public function skipped_total(): int {
		return $this->skipped_total;
	}
	/**
	 * Terminal.
	 *
	 * @return bool
	 */
	public function terminal(): bool {
		return 'running' !== $this->state;
	}
}
