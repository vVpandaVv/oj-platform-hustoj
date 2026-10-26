<?php
/**
 * Rollback legacy migration result.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\LegacyMigration\Application;

use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Durable C63 compensation receipt.
 */
final class Rollback_Legacy_Migration_Result {
	/**
	 * Initialize rollback legacy migration result dependencies and state.
	 *
	 * @param string $run_id Run id.
	 * @param string $state State.
	 * @param string $checkpoint_hash Checkpoint hash.
	 * @param int    $item_total Item total.
	 * @param int    $success_total Success total.
	 * @param int    $failure_total Failure total.
	 * @param int    $skipped_total Skipped total.
	 * @param int    $compensated_target_count Compensated target count.
	 * @param string $compensated_at_utc Compensated at utc.
	 * @param bool   $replayed Replayed.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private string $run_id,
		private string $state,
		private string $checkpoint_hash,
		private int $item_total,
		private int $success_total,
		private int $failure_total,
		private int $skipped_total,
		private int $compensated_target_count,
		private string $compensated_at_utc,
		private bool $replayed
	) {
		if (
			! Public_Id::is_valid( $run_id )
			|| 'compensated' !== $state
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $checkpoint_hash )
			|| min( $item_total, $success_total, $failure_total, $skipped_total, $compensated_target_count ) < 0
			|| $success_total + $failure_total + $skipped_total !== $item_total
			|| 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $compensated_at_utc )
		) {
			throw new InvalidArgumentException( 'Legacy migration rollback result is invalid.' );
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
	 * Item total.
	 *
	 * @return int
	 */
	public function item_total(): int {
		return $this->item_total;
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
	 * Compensated target count.
	 *
	 * @return int
	 */
	public function compensated_target_count(): int {
		return $this->compensated_target_count;
	}
	/**
	 * Compensated at utc.
	 *
	 * @return string
	 */
	public function compensated_at_utc(): string {
		return $this->compensated_at_utc;
	}
	/**
	 * Replayed.
	 *
	 * @return bool
	 */
	public function replayed(): bool {
		return $this->replayed;
	}
	/**
	 * Ledger mutation count.
	 *
	 * @return int
	 */
	public function ledger_mutation_count(): int {
		return $this->replayed ? 0 : 3;
	}
	/**
	 * Business mutation count.
	 *
	 * @return int
	 */
	public function business_mutation_count(): int {
		return $this->replayed ? 0 : $this->compensated_target_count;
	}
	/**
	 * Mutation count.
	 *
	 * @return int
	 */
	public function mutation_count(): int {
		return $this->ledger_mutation_count() + $this->business_mutation_count();
	}
	/**
	 * Platform write count.
	 *
	 * @return int
	 */
	public function platform_write_count(): int {
		return 0;
	}

	/**
	 * Item totals.
	 *
	 * @return array{total:int,success:int,failure:int,skipped:int}
	 */
	public function item_totals(): array {
		return array(
			'total'   => $this->item_total,
			'success' => $this->success_total,
			'failure' => $this->failure_total,
			'skipped' => $this->skipped_total,
		);
	}
}
