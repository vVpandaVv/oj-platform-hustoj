<?php
/**
 * Execute legacy migration result.
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
 * Durable C62 acceptance receipt.
 */
final class Execute_Legacy_Migration_Result {
	/**
	 * Initialize execute legacy migration result dependencies and state.
	 *
	 * @param string                 $run_id Run id.
	 * @param string                 $state State.
	 * @param string                 $source_fingerprint Source fingerprint.
	 * @param string                 $plan_hash Plan hash.
	 * @param string                 $checkpoint_hash Checkpoint hash.
	 * @param array<array-key,mixed> $checkpoint Checkpoint to validate.
	 * @param int                    $item_total Item total.
	 * @param string                 $started_at_utc Started at utc.
	 * @param bool                   $replayed Replayed.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private string $run_id,
		private string $state,
		private string $source_fingerprint,
		private string $plan_hash,
		private string $checkpoint_hash,
		private array $checkpoint,
		private int $item_total,
		private string $started_at_utc,
		private bool $replayed
	) {
		if (
			! Public_Id::is_valid( $run_id )
			|| 'running' !== $state
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $source_fingerprint )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $plan_hash )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $checkpoint_hash )
			|| array_is_list( $checkpoint )
			|| $item_total < 0
			|| 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $started_at_utc )
		) {
			throw new InvalidArgumentException( 'Legacy migration execution result is invalid.' );
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
	 * Source fingerprint.
	 *
	 * @return string
	 */
	public function source_fingerprint(): string {
		return $this->source_fingerprint;
	}
	/**
	 * Plan hash.
	 *
	 * @return string
	 */
	public function plan_hash(): string {
		return $this->plan_hash;
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
	 * Checkpoint.
	 *
	 * @return array<string,mixed>
	 */
	public function checkpoint(): array {
		return $this->checkpoint;
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
	 * Started at utc.
	 *
	 * @return string
	 */
	public function started_at_utc(): string {
		return $this->started_at_utc;
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
	 * Mutation count.
	 *
	 * @return int
	 */
	public function mutation_count(): int {
		return $this->replayed ? 0 : 1;
	}
	/**
	 * Ledger mutation count.
	 *
	 * @return int
	 */
	public function ledger_mutation_count(): int {
		return $this->mutation_count();
	}
	/**
	 * Business mutation count.
	 *
	 * @return int
	 */
	public function business_mutation_count(): int {
		return 0;
	}
	/**
	 * Platform write count.
	 *
	 * @return int
	 */
	public function platform_write_count(): int {
		return 0;
	}
}
