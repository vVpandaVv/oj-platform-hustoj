<?php
/**
 * Run submission evaluation result.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Submission\Assessment\Application;

use InvalidArgumentException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Run submission evaluation result.
 */
final class Run_Submission_Evaluation_Result {
	public const IDLE       = 'idle';
	public const SUCCEEDED  = 'succeeded';
	public const RETRY_WAIT = 'retry_wait';
	public const FAILED     = 'failed';
	public const STALE      = 'stale';

	/**
	 * Initialize submission evaluation result dependencies and state.
	 *
	 * @param string      $outcome Outcome.
	 * @param string|null $run_id Run id.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct( private string $outcome, private ?string $run_id = null ) {
		if ( ! in_array( $outcome, array( self::IDLE, self::SUCCEEDED, self::RETRY_WAIT, self::FAILED, self::STALE ), true ) ) {
			throw new InvalidArgumentException( 'Submission evaluation worker outcome is invalid.' );
		}
	}

	/**
	 * Outcome.
	 *
	 * @return string
	 */
	public function outcome(): string {
		return $this->outcome;
	}
	/**
	 * Run id.
	 *
	 * @return string|null
	 */
	public function run_id(): ?string {
		return $this->run_id;
	}
}
