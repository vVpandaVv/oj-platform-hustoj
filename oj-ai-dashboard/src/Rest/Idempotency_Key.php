<?php
/**
 * REST idempotency key value object.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Rest;

use InvalidArgumentException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enforces the frozen 8-128 character safe-token profile.
 */
final class Idempotency_Key {
	/**
	 * Value.
	 *
	 * @var string
	 */
	private string $value;

	/**
	 * Initialize idempotency key dependencies and state.
	 *
	 * @param string $value Value.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct( string $value ) {
		if (
			strlen( $value ) < 8
			|| strlen( $value ) > 128
			|| 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@-]*$/D', $value )
		) {
			throw new InvalidArgumentException( 'Invalid REST idempotency key.' );
		}

		$this->value = $value;
	}

	/**
	 * Value.
	 *
	 * @return string
	 */
	public function value(): string {
		return $this->value;
	}

	/**
	 * Return a log-safe fingerprint rather than the caller-supplied token.
	 *
	 * @return string
	 */
	public function fingerprint(): string {
		return hash( 'sha256', $this->value );
	}
}
