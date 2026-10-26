<?php
/**
 * Submission attachment content verdict.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Student\Domain;

use InvalidArgumentException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stable result of deep type and container validation.
 */
final class Submission_Attachment_Content_Verdict {
	public const CLEAN       = 'clean';
	public const REJECTED    = 'rejected';
	public const UNAVAILABLE = 'unavailable';

	/**
	 * Initialize submission attachment content verdict dependencies and state.
	 *
	 * @param string      $state State.
	 * @param string|null $reason_code Reason code.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function __construct( private string $state, private ?string $reason_code ) {
		if ( ! in_array( $state, array( self::CLEAN, self::REJECTED, self::UNAVAILABLE ), true ) ) {
			throw new InvalidArgumentException( 'Submission attachment content verdict is invalid.' );
		}
		if ( self::CLEAN === $state && null !== $reason_code ) {
			throw new InvalidArgumentException( 'A clean content verdict cannot have a reason.' );
		}
		if ( self::CLEAN !== $state && ( null === $reason_code || 1 !== preg_match( '/^[a-z][a-z0-9_]{2,99}$/D', $reason_code ) ) ) {
			throw new InvalidArgumentException( 'Submission attachment content reason is invalid.' );
		}
	}

	/**
	 * Clean.
	 *
	 * @return self
	 */
	public static function clean(): self {
		return new self( self::CLEAN, null );
	}
	/**
	 * Rejected.
	 *
	 * @param string $reason_code Reason code.
	 * @return self
	 */
	public static function rejected( string $reason_code ): self {
		return new self( self::REJECTED, $reason_code );
	}
	/**
	 * Unavailable.
	 *
	 * @param string $reason_code Reason code.
	 * @return self
	 */
	public static function unavailable( string $reason_code ): self {
		return new self( self::UNAVAILABLE, $reason_code );
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
	 * Reason code.
	 *
	 * @return string|null
	 */
	public function reason_code(): ?string {
		return $this->reason_code;
	}
}
