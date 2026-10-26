<?php
/**
 * Durable C36/C37 feedback revision receipt.
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
 * Durable C36/C37 feedback revision receipt.
 */
final class Submission_Feedback_Review_Result {
	/**
	 * Initialize submission feedback review result dependencies and state.
	 *
	 * @param string      $evaluation_id Evaluation id.
	 * @param string      $official_review_id Official review id.
	 * @param string      $official_review_hash Official review hash.
	 * @param string      $feedback_revision_id Feedback revision id.
	 * @param string      $feedback_revision_hash Feedback revision hash.
	 * @param int         $feedback_revision_no Feedback revision no.
	 * @param string      $state State.
	 * @param string|null $previous_revision_id Previous revision id.
	 * @param string|null $source_evaluation_run_id Source evaluation run id.
	 * @param string      $occurred_at_utc Occurred at utc.
	 * @param bool        $replayed Replayed.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private string $evaluation_id,
		private string $official_review_id,
		private string $official_review_hash,
		private string $feedback_revision_id,
		private string $feedback_revision_hash,
		private int $feedback_revision_no,
		private string $state,
		private ?string $previous_revision_id,
		private ?string $source_evaluation_run_id,
		private string $occurred_at_utc,
		private bool $replayed
	) {
		foreach ( array( $evaluation_id, $official_review_id, $feedback_revision_id ) as $id ) {
			new Public_Id( $id );
		}
		foreach ( array( $previous_revision_id, $source_evaluation_run_id ) as $id ) {
			if ( null !== $id ) {
				new Public_Id( $id );
			}
		}
		if (
			1 !== preg_match( '/^[a-f0-9]{64}$/D', $official_review_hash )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $feedback_revision_hash )
			|| $feedback_revision_no < 1
			|| ! in_array( $state, array( 'teacher_draft', 'rejected', 'approved' ), true )
			|| ( 1 === $feedback_revision_no ) !== ( null === $previous_revision_id )
			|| 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $occurred_at_utc )
		) {
			throw new InvalidArgumentException( 'Submission feedback review result is invalid.' );
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
	 * Feedback revision id.
	 *
	 * @return string
	 */
	public function feedback_revision_id(): string {
		return $this->feedback_revision_id;
	}
	/**
	 * Feedback revision hash.
	 *
	 * @return string
	 */
	public function feedback_revision_hash(): string {
		return $this->feedback_revision_hash;
	}
	/**
	 * Feedback revision no.
	 *
	 * @return int
	 */
	public function feedback_revision_no(): int {
		return $this->feedback_revision_no;
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
	 * Previous revision id.
	 *
	 * @return string|null
	 */
	public function previous_revision_id(): ?string {
		return $this->previous_revision_id;
	}
	/**
	 * Source evaluation run id.
	 *
	 * @return string|null
	 */
	public function source_evaluation_run_id(): ?string {
		return $this->source_evaluation_run_id;
	}
	/**
	 * Occurred at utc.
	 *
	 * @return string
	 */
	public function occurred_at_utc(): string {
		return $this->occurred_at_utc;
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
