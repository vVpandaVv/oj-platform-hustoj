<?php
/**
 * Approve legacy migration result.
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
 * Approve legacy migration result.
 */
final class Approve_Legacy_Migration_Result {
	/**
	 * Initialize legacy migration result dependencies and state.
	 *
	 * @param string $run_id Run id.
	 * @param string $state State.
	 * @param string $source_profile Source profile.
	 * @param string $source_version Source version.
	 * @param string $source_fingerprint Source fingerprint.
	 * @param string $plan_hash Plan hash.
	 * @param string $preview_hash Preview hash.
	 * @param int    $item_total Item total.
	 * @param string $approved_at_utc Approved at utc.
	 * @param bool   $replayed Replayed.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private string $run_id,
		private string $state,
		private string $source_profile,
		private string $source_version,
		private string $source_fingerprint,
		private string $plan_hash,
		private string $preview_hash,
		private int $item_total,
		private string $approved_at_utc,
		private bool $replayed
	) {
		new Public_Id( $run_id );
		if (
			'approved' !== $state
			|| 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@\/-]{7,127}$/D', $source_profile )
			|| 1 !== preg_match( '/^[0-9]+\.[0-9]+\.[0-9]+$/D', $source_version )
			|| $item_total < 0
			|| 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $approved_at_utc )
		) {
			throw new InvalidArgumentException( 'Legacy migration approval result is invalid.' );
		}
		foreach ( array( $source_fingerprint, $plan_hash, $preview_hash ) as $digest ) {
			if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $digest ) ) {
				throw new InvalidArgumentException( 'Legacy migration approval digest is invalid.' );
			}
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
	 * Source profile.
	 *
	 * @return string
	 */
	public function source_profile(): string {
		return $this->source_profile;
	}
	/**
	 * Source version.
	 *
	 * @return string
	 */
	public function source_version(): string {
		return $this->source_version;
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
	 * Preview hash.
	 *
	 * @return string
	 */
	public function preview_hash(): string {
		return $this->preview_hash;
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
	 * Approved at utc.
	 *
	 * @return string
	 */
	public function approved_at_utc(): string {
		return $this->approved_at_utc;
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
		return $this->replayed ? 0 : 1;
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
