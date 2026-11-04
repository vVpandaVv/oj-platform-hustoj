<?php
/**
 * Optional teacher-only whole-assessment exception scores.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Assessment\Configuration\Domain;

use InvalidArgumentException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Keep absent configuration absent so existing immutable hashes remain valid. */
final class Rubric_Exception_Scores {
	/**
	 * Validate configured scores without inventing defaults.
	 *
	 * @param mixed  $value Optional score map.
	 * @param string $maximum Published or configured total.
	 * @return array
	 * @throws InvalidArgumentException Rejects invalid or out-of-range scores.
	 */
	public static function normalize( mixed $value, string $maximum ): array {
		$keys = array( 'not_submitted', 'long_absence' );
		if ( ! is_array( $value ) || array_diff( array_keys( $value ), $keys ) || array_diff( $keys, array_keys( $value ) ) ) {
			throw new InvalidArgumentException( 'Invalid exception score configuration.' );
		}
		$result = array();
		foreach ( $keys as $key ) {
			if ( null === $value[ $key ] ) {
				$result[ $key ] = null;
				continue;
			}
			if ( ! is_string( $value[ $key ] ) || self::units( $value[ $key ] ) > self::units( $maximum ) ) {
				throw new InvalidArgumentException( 'Exception score exceeds the rubric total.' );
			}
			$result[ $key ] = self::decimal( self::units( $value[ $key ] ) );
		}
		return $result;
	}

	/**
	 * Parse points without floating-point rounding.
	 *
	 * @param string $value Decimal points.
	 * @return int
	 * @throws InvalidArgumentException Rejects invalid decimals.
	 */
	public static function units( string $value ): int {
		if ( 1 !== preg_match( '/^(0|[1-9][0-9]{0,7})(?:\.([0-9]{1,4}))?$/D', $value, $parts ) ) {
			throw new InvalidArgumentException( 'Invalid grade points.' );
		}
		return (int) $parts[1] * 10000 + (int) str_pad( $parts[2] ?? '', 4, '0' );
	}

	/**
	 * Format validated point units.
	 *
	 * @param int $units Non-negative units.
	 * @return string
	 */
	public static function decimal( int $units ): string {
		return rtrim( rtrim( sprintf( '%d.%04d', intdiv( $units, 10000 ), $units % 10000 ), '0' ), '.' );
	}
}
