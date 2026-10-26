<?php
/**
 * Run worksheet evaluation result.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Worksheet\Evaluation\Application;

use InvalidArgumentException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Run worksheet evaluation result.
 */
final class Run_Worksheet_Evaluation_Result {
	public const IDLE       = 'idle';
	public const SUCCEEDED  = 'succeeded';
	public const RETRY_WAIT = 'retry_wait';
	public const FAILED     = 'failed';
	public const STALE      = 'stale';

	/**
	 * Initialize worksheet evaluation result dependencies and state.
	 *
	 * @param string      $outcome Outcome.
	 * @param string|null $run_id Run id.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct( private string $outcome, private ?string $run_id = null ) {
		if (
			! in_array( $outcome, array( self::IDLE, self::SUCCEEDED, self::RETRY_WAIT, self::FAILED, self::STALE ), true )
			|| ( self::IDLE === $outcome && null !== $run_id )
			|| ( self::IDLE !== $outcome && null === $run_id )
		) {
			throw new InvalidArgumentException( 'Worksheet evaluation worker result is invalid.' );
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
