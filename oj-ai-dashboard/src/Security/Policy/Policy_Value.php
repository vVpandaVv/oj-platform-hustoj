<?php
/**
 * Access policy value validation.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Security\Policy;

use InvalidArgumentException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps untrusted policy identifiers inside the public Addon API token profile.
 */
final class Policy_Value {
	/**
	 * Initialize policy value dependencies and state.
	 */
	private function __construct() {
	}

	/**
	 * Positive id.
	 *
	 * @param int    $value Value.
	 * @param string $field Field.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public static function positive_id( int $value, string $field ): void {
		if ( $value < 1 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw self::invalid( $field );
		}
	}

	/**
	 * Actor id.
	 *
	 * @param int $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public static function actor_id( int $value ): void {
		if ( $value < 0 ) {
			throw self::invalid( 'actor_user_id' );
		}
	}

	/**
	 * One of.
	 *
	 * @param string            $value Value.
	 * @param array<int,string> $allowed Allowed.
	 * @param string            $field Field.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public static function one_of( string $value, array $allowed, string $field ): void {
		if ( ! in_array( $value, $allowed, true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw self::invalid( $field );
		}
	}

	/**
	 * Safe token.
	 *
	 * @param string $value Value.
	 * @param string $field Field.
	 * @param int    $maximum Maximum.
	 * @return void
	 */
	public static function safe_token( string $value, string $field, int $maximum = 160 ): void {
		self::matches( $value, '/^[A-Za-z0-9][A-Za-z0-9_.:@-]*$/D', $field, $maximum );
	}

	/**
	 * Optional safe token.
	 *
	 * @param string|null $value Value.
	 * @param string      $field Field.
	 * @param int         $maximum Maximum.
	 * @return void
	 */
	public static function optional_safe_token( ?string $value, string $field, int $maximum = 160 ): void {
		if ( null !== $value ) {
			self::safe_token( $value, $field, $maximum );
		}
	}

	/**
	 * Simple key.
	 *
	 * @param string $value Value.
	 * @param string $field Field.
	 * @return void
	 */
	public static function simple_key( string $value, string $field ): void {
		self::matches( $value, '/^[a-z][a-z0-9_.-]{0,159}$/D', $field, 160 );
	}

	/**
	 * Stable code.
	 *
	 * @param string $value Value.
	 * @param string $field Field.
	 * @return void
	 */
	public static function stable_code( string $value, string $field = 'code' ): void {
		self::matches( $value, '/^[a-z][a-z0-9_]{2,99}$/D', $field, 100 );
	}

	/**
	 * Correlation id.
	 *
	 * @param string $value Value.
	 * @return void
	 */
	public static function correlation_id( string $value ): void {
		self::safe_token( $value, 'correlation_id', 128 );
	}

	/**
	 * Matches.
	 *
	 * @param string $value Value.
	 * @param string $pattern Pattern.
	 * @param string $field Field.
	 * @param int    $maximum Maximum.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private static function matches( string $value, string $pattern, string $field, int $maximum ): void {
		if ( '' === $value || strlen( $value ) > $maximum || 1 !== preg_match( $pattern, $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw self::invalid( $field );
		}
	}

	/**
	 * Invalid.
	 *
	 * @param string $field Field.
	 * @return InvalidArgumentException
	 */
	private static function invalid( string $field ): InvalidArgumentException {
		return new InvalidArgumentException( 'Invalid access policy value: ' . $field );
	}
}
