<?php
/**
 * One storage worker's manual-resume outcome.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\Diagnostics\Application;

use InvalidArgumentException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Carries only a safe iteration count and pass/fail flag -- never an item identity or reason code.
 */
final class Resume_Storage_Jobs_Worker_Outcome {
	/**
	 * Initialize resume storage jobs worker outcome dependencies and state.
	 *
	 * @param int  $iterations Iterations.
	 * @param bool $succeeded Succeeded.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function __construct( private int $iterations, private bool $succeeded ) {
		if ( $iterations < 0 ) {
			throw new InvalidArgumentException( 'Resume storage jobs iteration count cannot be negative.' );
		}
	}

	/**
	 * Completed.
	 *
	 * @param int $iterations Iterations.
	 * @return self
	 */
	public static function completed( int $iterations ): self {
		return new self( $iterations, true );
	}

	/**
	 * Failed.
	 *
	 * @param int $iterations Iterations.
	 * @return self
	 */
	public static function failed( int $iterations ): self {
		return new self( $iterations, false );
	}

	/**
	 * Iterations.
	 *
	 * @return int
	 */
	public function iterations(): int {
		return $this->iterations;
	}

	/**
	 * Succeeded.
	 *
	 * @return bool
	 */
	public function succeeded(): bool {
		return $this->succeeded;
	}

	/**
	 * Projection.
	 *
	 * @return array{iterations:int,succeeded:bool}
	 */
	public function projection(): array {
		return array(
			'iterations' => $this->iterations,
			'succeeded'  => $this->succeeded,
		);
	}
}
