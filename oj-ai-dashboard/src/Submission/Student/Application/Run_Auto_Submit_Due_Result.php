<?php
/**
 * One automatic submission sweep result.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Student\Application;

use InvalidArgumentException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One automatic submission sweep result.
 */
final class Run_Auto_Submit_Due_Result {
	public const IDLE       = 'idle';
	public const COMPLETED  = 'completed';
	public const RETRY_WAIT = 'retry_wait';
	public const STALE      = 'stale';

	/**
	 * Initialize auto submit due result dependencies and state.
	 *
	 * @param string      $state State.
	 * @param string|null $assignment_id Distribution public identifier.
	 * @param int         $submitted_count Submitted count.
	 * @param string|null $error_code Error code.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private string $state,
		private ?string $assignment_id = null,
		private int $submitted_count = 0,
		private ?string $error_code = null
	) {
		if (
			! in_array( $state, array( self::IDLE, self::COMPLETED, self::RETRY_WAIT, self::STALE ), true )
			|| ( self::IDLE === $state ) !== ( null === $assignment_id ) || $submitted_count < 0
			|| ( null !== $error_code && ( '' === $error_code || strlen( $error_code ) > 100 ) )
		) {
			throw new InvalidArgumentException( 'Auto-submit run result is invalid.' );
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
	 * Assignment id.
	 *
	 * @return string|null
	 */
	public function assignment_id(): ?string {
		return $this->assignment_id;
	}
	/**
	 * Submitted count.
	 *
	 * @return int
	 */
	public function submitted_count(): int {
		return $this->submitted_count;
	}
	/**
	 * Error code.
	 *
	 * @return string|null
	 */
	public function error_code(): ?string {
		return $this->error_code;
	}
}
