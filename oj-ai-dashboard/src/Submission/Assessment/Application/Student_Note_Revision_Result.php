<?php
/**
 * Durable C39-C42 student-note revision receipt.
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
 * Durable C39-C42 student-note revision receipt.
 */
final class Student_Note_Revision_Result {
	/**
	 * Initialize student note revision result dependencies and state.
	 *
	 * @param string      $note_id Note id.
	 * @param string      $evaluation_id Evaluation id.
	 * @param string      $official_review_id Official review id.
	 * @param string      $official_review_hash Official review hash.
	 * @param string      $revision_id Revision id.
	 * @param string      $revision_hash Revision hash.
	 * @param int         $revision_no Revision no.
	 * @param string      $state State.
	 * @param bool        $include_in_reports Include in reports.
	 * @param string|null $previous_revision_id Previous revision id.
	 * @param string|null $source_evaluation_run_id Source evaluation run id.
	 * @param string      $occurred_at_utc Occurred at utc.
	 * @param bool        $replayed Replayed.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private string $note_id,
		private string $evaluation_id,
		private string $official_review_id,
		private string $official_review_hash,
		private string $revision_id,
		private string $revision_hash,
		private int $revision_no,
		private string $state,
		private bool $include_in_reports,
		private ?string $previous_revision_id,
		private ?string $source_evaluation_run_id,
		private string $occurred_at_utc,
		private bool $replayed
	) {
		foreach ( array( $note_id, $evaluation_id, $official_review_id, $revision_id ) as $id ) {
			new Public_Id( $id );
		}
		foreach ( array( $previous_revision_id, $source_evaluation_run_id ) as $id ) {
			if ( null !== $id ) {
				new Public_Id( $id );
			}
		}
		if (
			1 !== preg_match( '/^[a-f0-9]{64}$/D', $official_review_hash )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $revision_hash )
			|| $revision_no < 1
			|| ! in_array( $state, array( 'teacher_draft', 'rejected', 'approved' ), true )
			|| ( $include_in_reports && 'approved' !== $state )
			|| ( 1 === $revision_no ) !== ( null === $previous_revision_id )
			|| 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $occurred_at_utc )
		) {
			throw new InvalidArgumentException( 'Student note revision result is invalid.' );
		}
	}

	/**
	 * Note id.
	 *
	 * @return string
	 */
	public function note_id(): string {
		return $this->note_id;
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
	 * Revision id.
	 *
	 * @return string
	 */
	public function revision_id(): string {
		return $this->revision_id;
	}
	/**
	 * Revision hash.
	 *
	 * @return string
	 */
	public function revision_hash(): string {
		return $this->revision_hash;
	}
	/**
	 * Revision no.
	 *
	 * @return int
	 */
	public function revision_no(): int {
		return $this->revision_no;
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
	 * Include in reports.
	 *
	 * @return bool
	 */
	public function include_in_reports(): bool {
		return $this->include_in_reports;
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
