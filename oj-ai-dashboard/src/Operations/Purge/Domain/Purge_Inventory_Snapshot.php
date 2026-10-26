<?php
/**
 * Purge inventory snapshot.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\Purge\Domain;

use InvalidArgumentException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Purge inventory snapshot.
 */
final class Purge_Inventory_Snapshot {
	/**
	 * Initialize purge inventory snapshot dependencies and state.
	 *
	 * @param array<array-key,mixed> $owner_rows Inventory counts to validate.
	 * @param int                    $private_file_count Private file count.
	 * @param int                    $private_blob_bytes Private blob bytes.
	 * @param int                    $legal_hold_count Legal hold count.
	 * @param array<array-key,mixed> $incomplete_jobs Job counts to validate.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private array $owner_rows,
		private int $private_file_count,
		private int $private_blob_bytes,
		private int $legal_hold_count,
		private array $incomplete_jobs
	) {
		if ( array() === $owner_rows || array_is_list( $owner_rows ) || array() === $incomplete_jobs || array_is_list( $incomplete_jobs ) ) {
			throw new InvalidArgumentException( 'Purge inventory maps are invalid.' );
		}
		foreach ( array( $owner_rows, $incomplete_jobs ) as $counts ) {
			foreach ( $counts as $key => $count ) {
				if ( ! is_string( $key ) || 1 !== preg_match( '/^[A-Za-z][A-Za-z0-9_]{1,63}$/D', $key ) || ! is_int( $count ) || $count < 0 ) {
					throw new InvalidArgumentException( 'Purge inventory count is invalid.' );
				}
			}
		}
		foreach ( array( $private_file_count, $private_blob_bytes, $legal_hold_count ) as $count ) {
			if ( $count < 0 ) {
				throw new InvalidArgumentException( 'Purge private file inventory is invalid.' );
			}
		}
		if ( $legal_hold_count > $private_file_count ) {
			throw new InvalidArgumentException( 'Purge legal hold count exceeds the file count.' );
		}
	}

	/**
	 * Owner rows.
	 *
	 * @return array<string,int>
	 */
	public function owner_rows(): array {
		return $this->owner_rows;
	}

	/**
	 * Total row count.
	 *
	 * @return int
	 */
	public function total_row_count(): int {
		return $this->sum( $this->owner_rows );
	}

	/**
	 * Private file count.
	 *
	 * @return int
	 */
	public function private_file_count(): int {
		return $this->private_file_count;
	}

	/**
	 * Private blob bytes.
	 *
	 * @return int
	 */
	public function private_blob_bytes(): int {
		return $this->private_blob_bytes;
	}

	/**
	 * Legal hold count.
	 *
	 * @return int
	 */
	public function legal_hold_count(): int {
		return $this->legal_hold_count;
	}

	/**
	 * Incomplete jobs.
	 *
	 * @return array<string,int>
	 */
	public function incomplete_jobs(): array {
		return $this->incomplete_jobs;
	}

	/**
	 * Incomplete job total.
	 *
	 * @return int
	 */
	public function incomplete_job_total(): int {
		return $this->sum( $this->incomplete_jobs );
	}

	/**
	 * Sum.
	 *
	 * @param array<string,int> $counts Counts.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function sum( array $counts ): int {
		$total = 0;
		foreach ( $counts as $count ) {
			if ( $count > PHP_INT_MAX - $total ) {
				throw new InvalidArgumentException( 'Purge inventory total exceeds the supported integer range.' );
			}
			$total += $count;
		}
		return $total;
	}
}
