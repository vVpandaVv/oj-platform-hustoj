<?php
/**
 * Read frozen source contracts without treating formatting or comments as code.
 *
 * @package OJAI\Dashboard
 */

namespace OJAI\Dashboard\Tests\Support;

/** Inspects simple literal declarations used by release and route contracts. */
final class Source_Contract_Tokens {

	/**
	 * Read a named literal class/global constant.
	 *
	 * @param string $source PHP source.
	 * @param string $name Constant name.
	 * @return string|null
	 */
	public static function string_constant( string $source, string $name ): ?string {
		$tokens = self::tokens( $source );
		$total  = count( $tokens );
		for ( $i = 0; $i < $total; ++$i ) {
			if ( T_CONST !== $tokens[ $i ][0] ) {
				continue;
			}
			for ( $j = $i + 1; $j + 2 < $total && ';' !== $tokens[ $j ][1]; ++$j ) {
				if ( T_STRING === $tokens[ $j ][0] && $name === $tokens[ $j ][1] && '=' === $tokens[ $j + 1 ][1] ) {
					return self::literal( $tokens[ $j + 2 ] );
				}
			}
		}
		return null;
	}

	/**
	 * Count literal array entries without matching example strings or comments.
	 *
	 * @param string $source PHP source.
	 * @param string $key Literal key.
	 * @param string $value Literal value.
	 * @return int
	 */
	public static function array_string_value_count( string $source, string $key, string $value ): int {
		$tokens = self::tokens( $source );
		$total  = count( $tokens );
		$count  = 0;
		for ( $i = 0; $i + 2 < $total; ++$i ) {
			if ( self::literal( $tokens[ $i ] ) === $key && T_DOUBLE_ARROW === $tokens[ $i + 1 ][0] && self::literal( $tokens[ $i + 2 ] ) === $value ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Keep executable tokens and exact literal spelling.
	 *
	 * @param string $source PHP source.
	 * @return array<array{int,string}>
	 */
	private static function tokens( string $source ): array {
		$result = array();
		foreach ( token_get_all( $source, TOKEN_PARSE ) as $token ) {
			if ( is_array( $token ) ) {
				if ( in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
					continue;
				}
				$result[] = array( $token[0], $token[1] );
			} else {
				$result[] = array( 0, $token );
			}
		}
		return $result;
	}

	/**
	 * Accept only the unencoded fixed literals used by these contracts.
	 *
	 * @param array{int,string} $token Significant token.
	 * @return string|null
	 */
	private static function literal( array $token ): ?string {
		if ( T_CONSTANT_ENCAPSED_STRING !== $token[0] ) {
			return null;
		}
		$value = substr( $token[1], 1, -1 );
		return str_contains( $value, '\\' ) || str_contains( $value, '$' ) ? null : $value;
	}
}
