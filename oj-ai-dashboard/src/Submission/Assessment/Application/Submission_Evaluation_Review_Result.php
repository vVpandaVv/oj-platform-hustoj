<?php
/**
 * Durable C34 submission evaluation review receipt.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Application;

use InvalidArgumentException;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Score_Input_Builder;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Durable C34 submission evaluation review receipt.
 */
final class Submission_Evaluation_Review_Result {
	/**
	 * Initialize submission evaluation review result dependencies and state.
	 *
	 * @param string                              $evaluation_id Evaluation id.
	 * @param string                              $submission_id Submission public identifier.
	 * @param string                              $submission_revision_id Submission revision id.
	 * @param string                              $submission_revision_hash Submission revision hash.
	 * @param string                              $result_id Result id.
	 * @param string                              $result_hash Result hash.
	 * @param string                              $review_id Review id.
	 * @param string                              $review_hash Review hash.
	 * @param int                                 $review_no Review no.
	 * @param string                              $decision Decision.
	 * @param string|null                         $official_score Official score.
	 * @param array<int,array<string,mixed>>|null $score_decisions Score decisions.
	 * @param string|null                         $reason Reason.
	 * @param string|null                         $official_review_id Official review id.
	 * @param string|null                         $official_review_hash Official review hash.
	 * @param bool                                $official_pointer_advanced Official pointer advanced.
	 * @param string                              $occurred_at_utc Occurred at utc.
	 * @param bool                                $replayed Replayed.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private string $evaluation_id,
		private string $submission_id,
		private string $submission_revision_id,
		private string $submission_revision_hash,
		private string $result_id,
		private string $result_hash,
		private string $review_id,
		private string $review_hash,
		private int $review_no,
		private string $decision,
		private ?string $official_score,
		private ?array $score_decisions,
		private ?string $reason,
		private ?string $official_review_id,
		private ?string $official_review_hash,
		private bool $official_pointer_advanced,
		private string $occurred_at_utc,
		private bool $replayed
	) {
		foreach ( array( $evaluation_id, $submission_id, $submission_revision_id, $result_id, $review_id ) as $id ) {
			new Public_Id( $id );
		}
		foreach ( array( $submission_revision_hash, $result_hash, $review_hash ) as $hash ) {
			$this->hash( $hash );
		}
		$this->pointer( $official_review_id, $official_review_hash );
		if (
			$review_no < 1 || ! in_array( $decision, array( 'accepted', 'adjusted', 'rejected', 'withdrawn' ), true )
			|| in_array( $decision, array( 'rejected', 'withdrawn' ), true ) !== ( null === $official_score )
			|| in_array( $decision, array( 'rejected', 'withdrawn' ), true ) !== ( null === $score_decisions )
			|| ( 'withdrawn' === $decision && ( null !== $official_review_id || ! $official_pointer_advanced ) )
			|| ( null !== $score_decisions && ( ! array_is_list( $score_decisions ) || count( $score_decisions ) > Submission_Score_Input_Builder::MAXIMUM_BINDINGS ) )
			|| ( null !== $official_score && 1 !== preg_match( '/^(?:0|[1-9][0-9]{0,7})\.[0-9]{4}$/D', $official_score ) )
			|| ( null !== $reason && ( '' === trim( $reason ) || strlen( $reason ) > 2000 ) )
			|| 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $occurred_at_utc )
		) {
			throw new InvalidArgumentException( 'Submission evaluation review result is invalid.' );
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
	 * Submission id.
	 *
	 * @return string
	 */
	public function submission_id(): string {
		return $this->submission_id;
	}
	/**
	 * Submission revision id.
	 *
	 * @return string
	 */
	public function submission_revision_id(): string {
		return $this->submission_revision_id;
	}
	/**
	 * Submission revision hash.
	 *
	 * @return string
	 */
	public function submission_revision_hash(): string {
		return $this->submission_revision_hash;
	}
	/**
	 * Result id.
	 *
	 * @return string
	 */
	public function result_id(): string {
		return $this->result_id;
	}
	/**
	 * Result hash.
	 *
	 * @return string
	 */
	public function result_hash(): string {
		return $this->result_hash;
	}
	/**
	 * Review id.
	 *
	 * @return string
	 */
	public function review_id(): string {
		return $this->review_id;
	}
	/**
	 * Review hash.
	 *
	 * @return string
	 */
	public function review_hash(): string {
		return $this->review_hash;
	}
	/**
	 * Review no.
	 *
	 * @return int
	 */
	public function review_no(): int {
		return $this->review_no;
	}
	/**
	 * Decision.
	 *
	 * @return string
	 */
	public function decision(): string {
		return $this->decision;
	}
	/**
	 * Official score.
	 *
	 * @return string|null
	 */
	public function official_score(): ?string {
		return $this->official_score;
	}
	/**
	 * Score decisions.
	 *
	 * @return array<int,array<string,mixed>>|null
	 */
	public function score_decisions(): ?array {
		return $this->score_decisions;
	}
	/**
	 * Return the stable failure reason used by boundary mappers.
	 *
	 * @return string|null
	 */
	public function reason(): ?string {
		return $this->reason;
	}
	/**
	 * Official review id.
	 *
	 * @return string|null
	 */
	public function official_review_id(): ?string {
		return $this->official_review_id;
	}
	/**
	 * Official review hash.
	 *
	 * @return string|null
	 */
	public function official_review_hash(): ?string {
		return $this->official_review_hash;
	}
	/**
	 * Official pointer advanced.
	 *
	 * @return bool
	 */
	public function official_pointer_advanced(): bool {
		return $this->official_pointer_advanced;
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

	/**
	 * Pointer.
	 *
	 * @param string|null $id Id.
	 * @param string|null $hash Hash.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function pointer( ?string $id, ?string $hash ): void {
		if ( ( null === $id ) !== ( null === $hash ) ) {
			throw new InvalidArgumentException( 'Submission evaluation review pointer is partial.' );
		}
		if ( null !== $id ) {
			new Public_Id( $id );
			$this->hash( (string) $hash );
		}
	}

	/**
	 * Hash.
	 *
	 * @param string $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function hash( string $value ): void {
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $value ) ) {
			throw new InvalidArgumentException( 'Submission evaluation review hash is invalid.' );
		}
	}
}
