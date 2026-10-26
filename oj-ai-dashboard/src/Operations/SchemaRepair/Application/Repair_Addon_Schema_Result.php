<?php
/**
 * Repair addon schema result.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\SchemaRepair\Application;

use InvalidArgumentException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Repair addon schema result.
 */
final class Repair_Addon_Schema_Result {
	/**
	 * Initialize repair addon schema result dependencies and state.
	 *
	 * @param string $code Code.
	 * @param string $catalog_fingerprint Catalog fingerprint.
	 * @param string $completed_at_utc Completed at utc.
	 * @param bool   $replayed Replayed.
	 * @param int    $mutation_count Mutation count.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private string $code,
		private string $catalog_fingerprint,
		private string $completed_at_utc,
		private bool $replayed,
		private int $mutation_count
	) {
		if (
			! in_array( $code, array( 'schema_repaired', 'schema_repair_converged' ), true )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $catalog_fingerprint )
			|| 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $completed_at_utc )
			|| $mutation_count < 0
		) {
			throw new InvalidArgumentException( 'Schema repair result is invalid.' );
		}
	}

	/**
	 * Code.
	 *
	 * @return string
	 */
	public function code(): string {
		return $this->code;
	}

	/**
	 * Catalog fingerprint.
	 *
	 * @return string
	 */
	public function catalog_fingerprint(): string {
		return $this->catalog_fingerprint;
	}

	/**
	 * Completed at utc.
	 *
	 * @return string
	 */
	public function completed_at_utc(): string {
		return $this->completed_at_utc;
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
		return $this->mutation_count;
	}

	/**
	 * As replay.
	 *
	 * @return self
	 */
	public function as_replay(): self {
		return new self( $this->code, $this->catalog_fingerprint, $this->completed_at_utc, true, 0 );
	}
}
