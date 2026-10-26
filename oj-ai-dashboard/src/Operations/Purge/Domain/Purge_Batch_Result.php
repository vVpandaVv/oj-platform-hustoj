<?php
/**
 * Purge batch result.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\Purge\Domain;

use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Purge batch result.
 */
final class Purge_Batch_Result {
	/**
	 * Initialize purge batch result dependencies and state.
	 *
	 * @param string              $job_id Job id.
	 * @param string              $state State.
	 * @param array<string,mixed> $checkpoint Checkpoint.
	 * @param string              $checkpoint_hash Checkpoint hash.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private string $job_id,
		private string $state,
		private array $checkpoint,
		private string $checkpoint_hash
	) {
		new Public_Id( $job_id );
		if ( ! in_array( $state, array( 'running', 'completed', 'cancelled', 'failed' ), true ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $checkpoint_hash ) ) {
			throw new InvalidArgumentException( 'Purge batch result is invalid.' );
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
	 * Terminal.
	 *
	 * @return bool
	 */
	public function terminal(): bool {
		return 'running' !== $this->state;
	}
}
