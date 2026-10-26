<?php
/**
 * Approved legacy migration run.
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
 * Validated approved-run snapshot used before C62 source reinspection.
 */
final class Approved_Legacy_Migration_Run {
	/**
	 * Initialize approved legacy migration run dependencies and state.
	 *
	 * @param int                    $database_id Database id.
	 * @param string                 $public_id Public id.
	 * @param int                    $lock_version Lock version.
	 * @param string                 $source_profile Source profile.
	 * @param string                 $source_version Source version.
	 * @param string                 $source_fingerprint Source fingerprint.
	 * @param string                 $preview_hash Preview hash.
	 * @param string                 $plan_hash Plan hash.
	 * @param array<array-key,mixed> $plan Plan to validate.
	 * @param int                    $item_total Item total.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private int $database_id,
		private string $public_id,
		private int $lock_version,
		private string $source_profile,
		private string $source_version,
		private string $source_fingerprint,
		private string $preview_hash,
		private string $plan_hash,
		private array $plan,
		private int $item_total
	) {
		if (
			$database_id < 1
			|| ! Public_Id::is_valid( $public_id )
			|| $lock_version < 0
			|| 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@\/-]{7,127}$/D', $source_profile )
			|| 1 !== preg_match( '/^[0-9]+\.[0-9]+\.[0-9]+$/D', $source_version )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $source_fingerprint )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $preview_hash )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $plan_hash )
			|| array_is_list( $plan )
			|| $item_total < 0
		) {
			throw new InvalidArgumentException( 'Approved legacy migration run is invalid.' );
		}
	}

	/**
	 * Database id.
	 *
	 * @return int
	 */
	public function database_id(): int {
		return $this->database_id;
	}
	/**
	 * Public id.
	 *
	 * @return string
	 */
	public function public_id(): string {
		return $this->public_id;
	}
	/**
	 * Lock version.
	 *
	 * @return int
	 */
	public function lock_version(): int {
		return $this->lock_version;
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
	 * Preview hash.
	 *
	 * @return string
	 */
	public function preview_hash(): string {
		return $this->preview_hash;
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
	 * Plan.
	 *
	 * @return array<string,mixed>
	 */
	public function plan(): array {
		return $this->plan;
	}
	/**
	 * Item total.
	 *
	 * @return int
	 */
	public function item_total(): int {
		return $this->item_total;
	}
}
