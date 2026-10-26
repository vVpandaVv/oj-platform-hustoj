<?php
/**
 * C31/C32 durable command receipt.
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
 * C31/C32 durable command receipt.
 */
final class Submission_Evaluation_Control_Result {
	public const OPERATION_RETRY  = 'retry';
	public const OPERATION_CANCEL = 'cancel';

	/**
	 * Initialize submission evaluation control result dependencies and state.
	 *
	 * @param string      $operation Operation.
	 * @param string      $evaluation_id Evaluation id.
	 * @param string      $evaluation_state Evaluation state.
	 * @param string      $submission_id Submission public identifier.
	 * @param string      $submission_revision_id Submission revision id.
	 * @param string      $source_hash Source hash.
	 * @param string      $publication_id Publication id.
	 * @param string      $publication_hash Publication hash.
	 * @param string      $run_id Run id.
	 * @param int         $run_no Run no.
	 * @param string      $run_state Run state.
	 * @param string|null $latest_result_id Latest result id.
	 * @param string      $occurred_at_utc Occurred at utc.
	 * @param bool        $replayed Replayed.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private string $operation,
		private string $evaluation_id,
		private string $evaluation_state,
		private string $submission_id,
		private string $submission_revision_id,
		private string $source_hash,
		private string $publication_id,
		private string $publication_hash,
		private string $run_id,
		private int $run_no,
		private string $run_state,
		private ?string $latest_result_id,
		private string $occurred_at_utc,
		private bool $replayed
	) {
		foreach ( array( $evaluation_id, $submission_id, $submission_revision_id, $publication_id, $run_id ) as $id ) {
			new Public_Id( $id );
		}
		if ( null !== $latest_result_id ) {
			new Public_Id( $latest_result_id );
		}
		$valid_transition =
			( self::OPERATION_RETRY === $operation && 'queued' === $evaluation_state && 'queued' === $run_state )
			|| ( self::OPERATION_CANCEL === $operation && 'cancelled' === $evaluation_state && 'cancelled' === $run_state );
		if (
			! $valid_transition || $run_no < 1
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $source_hash )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $publication_hash )
			|| 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $occurred_at_utc )
		) {
			throw new InvalidArgumentException( 'Submission evaluation control result is invalid.' );
		}
	}

	/**
	 * Operation.
	 *
	 * @return string
	 */
	public function operation(): string {
		return $this->operation;
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
	 * Source hash.
	 *
	 * @return string
	 */
	public function source_hash(): string {
		return $this->source_hash;
	}
	/**
	 * Publication id.
	 *
	 * @return string
	 */
	public function publication_id(): string {
		return $this->publication_id;
	}
	/**
	 * Publication hash.
	 *
	 * @return string
	 */
	public function publication_hash(): string {
		return $this->publication_hash;
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
	 * Latest result id.
	 *
	 * @return string|null
	 */
	public function latest_result_id(): ?string {
		return $this->latest_result_id;
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
