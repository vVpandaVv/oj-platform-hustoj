<?php
/**
 * Safe Q22 immutable report snapshot projection.
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
 * Safe Q22 immutable report snapshot projection.
 */
final class Read_Report_Snapshot_Result {
	/**
	 * Initialize report snapshot result dependencies and state.
	 *
	 * @param string              $snapshot_id Snapshot id.
	 * @param string              $state State.
	 * @param string              $snapshot_hash Snapshot hash.
	 * @param array<string,mixed> $filter Filter.
	 * @param string              $filter_hash Filter hash.
	 * @param string              $source_manifest_hash Source manifest hash.
	 * @param array<string,mixed> $projection Projection.
	 * @param string              $projection_hash Projection hash.
	 * @param int                 $row_count Row count.
	 * @param string              $generated_at_utc Generated at utc.
	 * @param int                 $authorized_source_count Authorized source count.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private string $snapshot_id,
		private string $state,
		private string $snapshot_hash,
		private array $filter,
		private string $filter_hash,
		private string $source_manifest_hash,
		private array $projection,
		private string $projection_hash,
		private int $row_count,
		private string $generated_at_utc,
		private int $authorized_source_count
	) {
		new Public_Id( $snapshot_id );
		foreach ( array( $snapshot_hash, $filter_hash, $source_manifest_hash, $projection_hash ) as $hash ) {
			if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $hash ) ) {
				throw new InvalidArgumentException( 'Report snapshot result hash is invalid.' );
			}
		}
		if ( 'ready' !== $state || $row_count < 0 || $authorized_source_count < 0 || $authorized_source_count > $row_count ) {
			throw new InvalidArgumentException( 'Report snapshot result state is invalid.' );
		}
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $generated_at_utc ) ) {
			throw new InvalidArgumentException( 'Report snapshot result time is invalid.' );
		}
	}

	/**
	 * Payload.
	 *
	 * @return array<string,mixed>
	 */
	public function payload(): array {
		return array(
			'id'                   => $this->snapshot_id,
			'state'                => $this->state,
			'snapshot_hash'        => $this->snapshot_hash,
			'filter'               => $this->filter,
			'filter_hash'          => $this->filter_hash,
			'source_manifest_hash' => $this->source_manifest_hash,
			'projection'           => $this->projection,
			'projection_hash'      => $this->projection_hash,
			'row_count'            => $this->row_count,
			'generated_at_utc'     => $this->generated_at_utc,
		);
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
	 * Authorized source count.
	 *
	 * @return int
	 */
	public function authorized_source_count(): int {
		return $this->authorized_source_count;
	}
}
