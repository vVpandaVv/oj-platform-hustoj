<?php
/**
 * Submission result release result.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Application;

use InvalidArgumentException;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Result_Projection;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Submission result release result.
 */
final class Submission_Result_Release_Result {
	/**
	 * Initialize submission result release result dependencies and state.
	 *
	 * @param string      $evaluation_id Evaluation id.
	 * @param string      $official_review_id Official review id.
	 * @param string      $official_review_hash Official review hash.
	 * @param string|null $feedback_revision_id Feedback revision id.
	 * @param string      $release_id Release id.
	 * @param string      $release_hash Release hash.
	 * @param int         $release_no Release no.
	 * @param string      $state State.
	 * @param string      $projection_hash Projection hash.
	 * @param string|null $visible_score Visible score.
	 * @param string      $released_at_utc Released at utc.
	 * @param bool        $replayed Replayed.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private string $evaluation_id,
		private string $official_review_id,
		private string $official_review_hash,
		private ?string $feedback_revision_id,
		private string $release_id,
		private string $release_hash,
		private int $release_no,
		private string $state,
		private string $projection_hash,
		private ?string $visible_score,
		private string $released_at_utc,
		private bool $replayed
	) {
		foreach ( array( $evaluation_id, $official_review_id, $release_id ) as $id ) {
			new Public_Id( $id );
		}
		if ( null !== $feedback_revision_id ) {
			new Public_Id( $feedback_revision_id );
		}
		if (
			1 !== preg_match( '/^[a-f0-9]{64}$/D', $official_review_hash )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $release_hash )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $projection_hash )
			|| $release_no < 1
			|| ! in_array( $state, array( 'released', 'corrected' ), true )
			|| ( 'released' === $state && 1 !== $release_no )
			|| ( null !== $visible_score && 1 !== preg_match( '/^(?:0|[1-9][0-9]{0,7})\.[0-9]{4}$/D', $visible_score ) )
			|| 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $released_at_utc )
		) {
			throw new InvalidArgumentException( 'Submission result release receipt is invalid.' );
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
	 * @return string|null
	 */
	public function feedback_revision_id(): ?string {
		return $this->feedback_revision_id;
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
	 * Release no.
	 *
	 * @return int
	 */
	public function release_no(): int {
		return $this->release_no;
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
	 * Projection schema.
	 *
	 * @return string
	 */
	public function projection_schema(): string {
		return Submission_Result_Projection::SCHEMA;
	}
	/**
	 * Projection version.
	 *
	 * @return int
	 */
	public function projection_version(): int {
		return Submission_Result_Projection::VERSION;
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
	 * Visible score.
	 *
	 * @return string|null
	 */
	public function visible_score(): ?string {
		return $this->visible_score;
	}
	/**
	 * Released at utc.
	 *
	 * @return string
	 */
	public function released_at_utc(): string {
		return $this->released_at_utc;
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
	 * Release path.
	 *
	 * @return string
	 */
	public function release_path(): string {
		return '/oj-addons/oj-ai-dashboard/results/' . $this->release_id;
	}
}
