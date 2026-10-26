<?php
/**
 * C45 immutable snapshot receipt.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\Report\Application;

use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * C45 immutable snapshot receipt.
 */
final class Create_Report_Snapshot_Result {
	/**
	 * Initialize report snapshot result dependencies and state.
	 *
	 * @param string $snapshot_id Snapshot id.
	 * @param string $state State.
	 * @param string $snapshot_hash Snapshot hash.
	 * @param string $filter_hash Filter hash.
	 * @param string $source_manifest_hash Source manifest hash.
	 * @param string $projection_hash Projection hash.
	 * @param int    $row_count Row count.
	 * @param string $generated_at_utc Generated at utc.
	 * @param bool   $replayed Replayed.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private string $snapshot_id,
		private string $state,
		private string $snapshot_hash,
		private string $filter_hash,
		private string $source_manifest_hash,
		private string $projection_hash,
		private int $row_count,
		private string $generated_at_utc,
		private bool $replayed
	) {
		new Public_Id( $snapshot_id );
		if ( 'ready' !== $state || $row_count < 0 || $row_count > Create_Report_Snapshot::MAXIMUM_ROWS ) {
			throw new InvalidArgumentException( 'Report snapshot receipt is invalid.' );
		}
		foreach ( array( $snapshot_hash, $filter_hash, $source_manifest_hash, $projection_hash ) as $hash ) {
			if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $hash ) ) {
				throw new InvalidArgumentException( 'Report snapshot receipt hash is invalid.' );
			}
		}
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $generated_at_utc ) ) {
			throw new InvalidArgumentException( 'Report snapshot receipt time is invalid.' );
		}
	}

	/**
	 * Snapshot id.
	 *
	 * @return string
	 */
	public function snapshot_id(): string {
		return $this->snapshot_id;
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
	 * Snapshot hash.
	 *
	 * @return string
	 */
	public function snapshot_hash(): string {
		return $this->snapshot_hash;
	}
	/**
	 * Filter hash.
	 *
	 * @return string
	 */
	public function filter_hash(): string {
		return $this->filter_hash;
	}
	/**
	 * Source manifest hash.
	 *
	 * @return string
	 */
	public function source_manifest_hash(): string {
		return $this->source_manifest_hash;
	}
	/**
	 * Projection hash.
	 *
	 * @return string
	 */
	public function projection_hash(): string {
		return $this->projection_hash;
	}
	/**
	 * Row count.
	 *
	 * @return int
	 */
	public function row_count(): int {
		return $this->row_count;
	}
	/**
	 * Generated at utc.
	 *
	 * @return string
	 */
	public function generated_at_utc(): string {
		return $this->generated_at_utc;
	}
	/**
	 * Replayed.
	 *
	 * @return bool
	 */
	public function replayed(): bool {
		return $this->replayed;
	}
}
