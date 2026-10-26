<?php
/**
 * PHP source assertions that preserve tokens while ignoring formatting.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

/**
 * PHP source assertions that preserve tokens while ignoring formatting.
 */
final class Ojai_Php_Source_Contract {

	/**
	 * Return a token-stream offset, not a byte offset in the original source.
	 *
	 * @param string $source Source.
	 * @param string $fragment Fragment.
	 * @return int|false
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public static function position( string $source, string $fragment ): int|false {
		$needle = self::tokens( $fragment );
		if ( '' === $needle ) {
			throw new InvalidArgumentException( 'A PHP source contract requires executable tokens.' );
		}
		return strpos( self::tokens( $source ), $needle );
	}

	/**
	 * Contains.
	 *
	 * @param string $source Source.
	 * @param string $fragment Fragment.
	 * @return bool
	 */
	public static function contains( string $source, string $fragment ): bool {
		return false !== self::position( $source, $fragment );
	}

	/**
	 * Count.
	 *
	 * @param string $source Source.
	 * @param string $fragment Fragment.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public static function count( string $source, string $fragment ): int {
		$needle = self::tokens( $fragment );
		if ( '' === $needle ) {
			throw new InvalidArgumentException( 'A PHP source contract requires executable tokens.' );
		}
		return substr_count( self::tokens( $source ), $needle );
	}

	/**
	 * Raw text markers remain exact; PHP fragments also tolerate token whitespace.
	 *
	 * @param string $source Source.
	 * @param string $fragment Fragment.
	 * @return bool
	 */
	public static function marker( string $source, string $fragment ): bool {
		return str_contains( $source, $fragment ) || self::contains( $source, $fragment );
	}

	/**
	 * Tokens.
	 *
	 * @param string $source Source.
	 * @return string
	 */
	private static function tokens( string $source ): string {
		if ( ! str_starts_with( ltrim( $source ), '<?php' ) ) {
			$source = '<?php ' . $source;
		}
		$result = '';
		foreach ( token_get_all( $source ) as $token ) {
			if ( is_array( $token ) ) {
				if ( in_array( $token[0], array( T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
					continue;
				}
				$result .= json_encode( array( $token[0], $token[1] ), JSON_THROW_ON_ERROR ) . "\n";
			} else {
				$result .= json_encode( $token, JSON_THROW_ON_ERROR ) . "\n";
			}
		}
		return $result;
	}
}
