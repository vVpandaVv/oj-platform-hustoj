<?php
/**
 * Context value validation helpers.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Context;

use InvalidArgumentException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps the neutral Context contract independent from platform validators.
 */
final class Context_Value {
	/**
	 * Initialize context value dependencies and state.
	 */
	private function __construct() {
	}

	/**
	 * Text.
	 *
	 * @param string $value Value.
	 * @param string $field Field.
	 * @param int    $maximum Maximum.
	 * @param bool   $allow_empty Allow empty.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public static function text(
		string $value,
		string $field,
		int $maximum,
		bool $allow_empty = false
	): void {
		self::text_syntax( $value, $field, $allow_empty );
		if ( false === preg_match_all( '/./us', $value, $matches ) || count( $matches[0] ) > $maximum ) {
			throw new InvalidArgumentException( 'Invalid Context value: ' . $field ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception details stay in the application failure channel; presentation maps and escapes displayed errors.
		}
	}

	/**
	 * Text syntax.
	 *
	 * @param string $value Value.
	 * @param string $field Field.
	 * @param bool   $allow_empty Allow empty.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public static function text_syntax(
		string $value,
		string $field,
		bool $allow_empty = false
	): void {
		if (
			( ! $allow_empty && '' === $value )
			|| 1 !== preg_match( '//u', $value )
			|| 1 === preg_match( '/[\r\n\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value )
		) {
			throw new InvalidArgumentException( 'Invalid Context value: ' . $field ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception details stay in the application failure channel; presentation maps and escapes displayed errors.
		}
	}

	/**
	 * Label.
	 *
	 * @param string $value Value.
	 * @param string $field Field.
	 * @param int    $maximum Maximum.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public static function label( string $value, string $field = 'label', int $maximum = 80 ): void {
		self::text( $value, $field, $maximum );
		if ( $value !== strip_tags( $value ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Text projection preserves its established canonical text and whitespace contract.
			throw new InvalidArgumentException( 'Invalid Context value: ' . $field ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception details stay in the application failure channel; presentation maps and escapes displayed errors.
		}
	}

	/**
	 * Safe token.
	 *
	 * @param string $value Value.
	 * @param string $field Field.
	 * @param int    $maximum Maximum.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public static function safe_token( string $value, string $field, int $maximum = 160 ): void {
		if (
			'' === $value
			|| strlen( $value ) > $maximum
			|| 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@-]*$/D', $value )
		) {
			throw new InvalidArgumentException( 'Invalid Context value: ' . $field ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception details stay in the application failure channel; presentation maps and escapes displayed errors.
		}
	}
}
