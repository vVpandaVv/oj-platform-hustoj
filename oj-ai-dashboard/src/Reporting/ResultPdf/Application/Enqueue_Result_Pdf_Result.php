<?php
/**
 * Enqueue result pdf result.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\ResultPdf\Application;

use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueue result pdf result.
 */
final class Enqueue_Result_Pdf_Result {
	/**
	 * Initialize enqueue result pdf result dependencies and state.
	 *
	 * @param string $job_id Job id.
	 * @param string $state State.
	 * @param string $release_id Release id.
	 * @param string $release_hash Release hash.
	 * @param string $queued_at_utc Queued at utc.
	 * @param bool   $replayed Replayed.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private string $job_id,
		private string $state,
		private string $release_id,
		private string $release_hash,
		private string $queued_at_utc,
		private bool $replayed
	) {
		new Public_Id( $job_id );
		new Public_Id( $release_id );
		if (
			! in_array( $state, array( 'queued', 'running', 'ready', 'failed', 'cancelled', 'expired' ), true )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $release_hash )
			|| 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $queued_at_utc )
		) {
			throw new InvalidArgumentException( 'Result PDF enqueue result is invalid.' );
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
	 * Release id.
	 *
	 * @return string
	 */
	public function release_id(): string {
		return $this->release_id;
	}
	/**
	 * Release hash.
	 *
	 * @return string
	 */
	public function release_hash(): string {
		return $this->release_hash;
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
