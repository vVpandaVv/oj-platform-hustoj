<?php
/**
 * Run submission feedback result.
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
 * Run submission feedback result.
 */
final class Run_Submission_Feedback_Result {
	public const IDLE       = 'idle';
	public const SUCCEEDED  = 'succeeded';
	public const RETRY_WAIT = 'retry_wait';
	public const FAILED     = 'failed';
	public const STALE      = 'stale';
	/**
	 * Initialize submission feedback result dependencies and state.
	 *
	 * @param string      $state State.
	 * @param string|null $run_id Run id.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct( private string $state, private ?string $run_id = null ) {
		if ( ! in_array( $state, array( self::IDLE, self::SUCCEEDED, self::RETRY_WAIT, self::FAILED, self::STALE ), true ) || ( self::IDLE === $state ) !== ( null === $run_id ) ) {
			throw new InvalidArgumentException( 'Run submission feedback result is invalid.' );
		}
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
	 * Run id.
	 *
	 * @return string|null
	 */
	public function run_id(): ?string {
		return $this->run_id;
	}
}
