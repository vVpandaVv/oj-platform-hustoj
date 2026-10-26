<?php
/**
 * Enqueue worksheet package result.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\WorksheetPackage\Application;

use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueue worksheet package result.
 */
final class Enqueue_Worksheet_Package_Result {
	/**
	 * Initialize enqueue worksheet package result dependencies and state.
	 *
	 * @param string $job_id Job id.
	 * @param string $state State.
	 * @param string $source_id Source id.
	 * @param string $source_hash Source hash.
	 * @param string $queued_at_utc Queued at utc.
	 * @param bool   $replayed Replayed.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private string $job_id,
		private string $state,
		private string $source_id,
		private string $source_hash,
		private string $queued_at_utc,
		private bool $replayed
	) {
		new Public_Id( $job_id );
		new Public_Id( $source_id );
		if ( ! in_array( $state, array( 'queued', 'running', 'ready', 'failed', 'cancelled', 'expired' ), true )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $source_hash )
			|| 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $queued_at_utc ) ) {
			throw new InvalidArgumentException( 'Worksheet package enqueue result is invalid.' );
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
	 * Source id.
	 *
	 * @return string
	 */
	public function source_id(): string {
		return $this->source_id;
	}
	/**
	 * Source hash.
	 *
	 * @return string
	 */
	public function source_hash(): string {
		return $this->source_hash;
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
