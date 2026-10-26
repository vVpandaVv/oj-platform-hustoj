<?php
/**
 * Generate submission feedback result.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Submission\Assessment\Application;

use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generate submission feedback result.
 */
final class Generate_Submission_Feedback_Result {
	/**
	 * Initialize generate submission feedback result dependencies and state.
	 *
	 * @param string $evaluation_id Evaluation id.
	 * @param string $official_review_id Official review id.
	 * @param string $official_review_hash Official review hash.
	 * @param string $run_id Run id.
	 * @param int    $run_no Run no.
	 * @param string $run_state Run state.
	 * @param string $input_digest Input digest.
	 * @param string $queued_at_utc Queued at utc.
	 * @param bool   $replayed Replayed.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private string $evaluation_id,
		private string $official_review_id,
		private string $official_review_hash,
		private string $run_id,
		private int $run_no,
		private string $run_state,
		private string $input_digest,
		private string $queued_at_utc,
		private bool $replayed
	) {
		foreach ( array( $evaluation_id, $official_review_id, $run_id ) as $id ) {
			new Public_Id( $id );
		}
		if ( $run_no < 1 || ! in_array( $run_state, array( 'queued', 'leased', 'running', 'succeeded', 'retry_wait', 'failed', 'cancelled' ), true ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $official_review_hash ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $input_digest ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $queued_at_utc ) ) {
			throw new InvalidArgumentException( 'Generate submission feedback result is invalid.' );
		}
	}
	/**
	 * Evaluation id.
	 *
	 * @return string
	 */
	public function evaluation_id(): string {
		return $this->evaluation_id;
	}
	/**
	 * Official review id.
	 *
	 * @return string
	 */
	public function official_review_id(): string {
		return $this->official_review_id;
	}
	/**
	 * Official review hash.
	 *
	 * @return string
	 */
	public function official_review_hash(): string {
		return $this->official_review_hash;
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
	 * Run no.
	 *
	 * @return int
	 */
	public function run_no(): int {
		return $this->run_no;
	}
	/**
	 * Run state.
	 *
	 * @return string
	 */
	public function run_state(): string {
		return $this->run_state;
	}
	/**
	 * Input digest.
	 *
	 * @return string
	 */
	public function input_digest(): string {
		return $this->input_digest;
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
