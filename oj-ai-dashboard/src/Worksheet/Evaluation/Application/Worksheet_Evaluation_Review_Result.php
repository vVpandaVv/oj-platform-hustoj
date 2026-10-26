<?php
/**
 * Durable C33 worksheet evaluation review receipt.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Evaluation\Application;

use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Durable C33 worksheet evaluation review receipt.
 */
final class Worksheet_Evaluation_Review_Result {
	/**
	 * Initialize worksheet evaluation review result dependencies and state.
	 *
	 * @param string                         $evaluation_id Evaluation id.
	 * @param string                         $evaluation_state Evaluation state.
	 * @param string                         $worksheet_revision_id Worksheet revision id.
	 * @param string                         $source_hash Source hash.
	 * @param string                         $result_id Result id.
	 * @param string                         $review_id Review id.
	 * @param int                            $review_no Review no.
	 * @param string                         $decision Decision.
	 * @param array<int,array<string,mixed>> $item_decisions Item decisions.
	 * @param string                         $occurred_at_utc Occurred at utc.
	 * @param bool                           $replayed Replayed.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private string $evaluation_id,
		private string $evaluation_state,
		private string $worksheet_revision_id,
		private string $source_hash,
		private string $result_id,
		private string $review_id,
		private int $review_no,
		private string $decision,
		private array $item_decisions,
		private string $occurred_at_utc,
		private bool $replayed
	) {
		new Public_Id( $evaluation_id );
		new Public_Id( $worksheet_revision_id );
		new Public_Id( $result_id );
		new Public_Id( $review_id );
		$valid_transition =
			( 'in_progress' === $decision && 'review_required' === $evaluation_state )
			|| ( 'completed' === $decision && 'reviewed' === $evaluation_state );
		if (
			! $valid_transition
			|| $review_no < 1
			|| count( $item_decisions ) > 100
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $source_hash )
			|| 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $occurred_at_utc )
		) {
			throw new InvalidArgumentException( 'Worksheet evaluation review result is invalid.' );
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
	 * Evaluation state.
	 *
	 * @return string
	 */
	public function evaluation_state(): string {
		return $this->evaluation_state;
	}
	/**
	 * Worksheet revision id.
	 *
	 * @return string
	 */
	public function worksheet_revision_id(): string {
		return $this->worksheet_revision_id;
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
	 * Result id.
	 *
	 * @return string
	 */
	public function result_id(): string {
		return $this->result_id;
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
	 * Item decisions.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function item_decisions(): array {
		return $this->item_decisions;
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
