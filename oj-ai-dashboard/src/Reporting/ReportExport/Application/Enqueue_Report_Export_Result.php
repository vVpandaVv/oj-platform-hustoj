<?php
/**
 * Enqueue report export result.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\ReportExport\Application;

use InvalidArgumentException;
use OJAI\Dashboard\Reporting\ReportExport\Domain\Report_Export_Request_Snapshot;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueue report export result.
 */
final class Enqueue_Report_Export_Result {
	/**
	 * Initialize enqueue report export result dependencies and state.
	 *
	 * @param string $job_id Job id.
	 * @param string $state State.
	 * @param string $artifact_kind Artifact kind.
	 * @param string $snapshot_id Snapshot id.
	 * @param string $snapshot_hash Snapshot hash.
	 * @param string $queued_at_utc Queued at utc.
	 * @param bool   $replayed Replayed.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private string $job_id,
		private string $state,
		private string $artifact_kind,
		private string $snapshot_id,
		private string $snapshot_hash,
		private string $queued_at_utc,
		private bool $replayed
	) {
		new Public_Id( $job_id );
		new Public_Id( $snapshot_id );
		if (
			! in_array( $state, array( 'queued', 'running', 'ready', 'failed', 'cancelled', 'expired' ), true )
			|| ! Report_Export_Request_Snapshot::is_artifact_kind( $artifact_kind )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $snapshot_hash )
			|| 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $queued_at_utc )
		) {
			throw new InvalidArgumentException( 'Report export enqueue result is invalid.' );
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
	 * Artifact kind.
	 *
	 * @return string
	 */
	public function artifact_kind(): string {
		return $this->artifact_kind;
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
	 * Snapshot hash.
	 *
	 * @return string
	 */
	public function snapshot_hash(): string {
		return $this->snapshot_hash;
	}
	/**
	 * Queued at utc.
	 *
	 * @return string
	 */
	public function queued_at_utc(): string {
		return $this->queued_at_utc;
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
