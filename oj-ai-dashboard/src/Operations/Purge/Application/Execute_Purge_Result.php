<?php
/**
 * Execute purge result.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\Purge\Application;

use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Durable C73 acceptance receipt.
 */
final class Execute_Purge_Result {
	/**
	 * Initialize execute purge result dependencies and state.
	 *
	 * @param string                 $job_id Job id.
	 * @param string                 $state State.
	 * @param string                 $preview_hash Preview hash.
	 * @param array<array-key,mixed> $checkpoint Checkpoint to validate.
	 * @param string                 $checkpoint_hash Checkpoint hash.
	 * @param string                 $started_at_utc Started at utc.
	 * @param bool                   $replayed Replayed.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private string $job_id,
		private string $state,
		private string $preview_hash,
		private array $checkpoint,
		private string $checkpoint_hash,
		private string $started_at_utc,
		private bool $replayed
	) {
		if (
			! Public_Id::is_valid( $job_id )
			|| 'running' !== $state
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $preview_hash )
			|| array_is_list( $checkpoint )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $checkpoint_hash )
			|| 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $started_at_utc )
		) {
			throw new InvalidArgumentException( 'Purge execution result is invalid.' );
		}
	}

	/**
	 * Job id.
	 *
	 * @return string
	 */
	public function job_id(): string {
		return $this->job_id;
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
	 * Preview hash.
	 *
	 * @return string
	 */
	public function preview_hash(): string {
		return $this->preview_hash;
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
	 * Checkpoint hash.
	 *
	 * @return string
	 */
	public function checkpoint_hash(): string {
		return $this->checkpoint_hash;
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
		return $this->replayed ? 0 : 4;
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
	 * File mutation count.
	 *
	 * @return int
	 */
	public function file_mutation_count(): int {
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
