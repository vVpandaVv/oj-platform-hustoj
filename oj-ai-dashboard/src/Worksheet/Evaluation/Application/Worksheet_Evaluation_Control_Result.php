<?php
/**
 * C11/C12 durable command receipt.
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
 * C11/C12 durable command receipt.
 */
final class Worksheet_Evaluation_Control_Result {
	public const OPERATION_RETRY  = 'retry';
	public const OPERATION_CANCEL = 'cancel';

	/**
	 * Initialize worksheet evaluation control result dependencies and state.
	 *
	 * @param string $operation Operation.
	 * @param string $evaluation_id Evaluation id.
	 * @param string $evaluation_state Evaluation state.
	 * @param string $worksheet_revision_id Worksheet revision id.
	 * @param string $source_hash Source hash.
	 * @param string $run_id Run id.
	 * @param int    $run_no Run no.
	 * @param string $run_state Run state.
	 * @param string $occurred_at_utc Occurred at utc.
	 * @param bool   $replayed Replayed.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private string $operation,
		private string $evaluation_id,
		private string $evaluation_state,
		private string $worksheet_revision_id,
		private string $source_hash,
		private string $run_id,
		private int $run_no,
		private string $run_state,
		private string $occurred_at_utc,
		private bool $replayed
	) {
		new Public_Id( $evaluation_id );
		new Public_Id( $worksheet_revision_id );
		new Public_Id( $run_id );
		$valid_transition =
			( self::OPERATION_RETRY === $operation && 'queued' === $evaluation_state && 'queued' === $run_state )
			|| ( self::OPERATION_CANCEL === $operation && 'cancelled' === $evaluation_state && 'cancelled' === $run_state );
		if (
			! $valid_transition
			|| $run_no < 1
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $source_hash )
			|| 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $occurred_at_utc )
		) {
			throw new InvalidArgumentException( 'Worksheet evaluation control result is invalid.' );
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
