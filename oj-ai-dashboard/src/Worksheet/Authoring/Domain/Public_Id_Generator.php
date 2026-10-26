<?php
/**
 * Worksheet public identifier generator.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Authoring\Domain;

use Closure;
use RuntimeException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Encodes 128 bits of secure entropy as a 26-character Crockford token.
 */
final class Public_Id_Generator {
	private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

	/**
	 * Random bytes.
	 *
	 * @var Closure|null
	 */
	private ?Closure $random_bytes;

	/**
	 * Initialize public id generator dependencies and state.
	 *
	 * @param callable|null $random_bytes Random bytes.
	 */
	public function __construct( ?callable $random_bytes = null ) {
		$this->random_bytes = null === $random_bytes ? null : Closure::fromCallable( $random_bytes );
	}

	/**
	 * Create public id generator.
	 *
	 * @return Public_Id
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function create(): Public_Id {
		$bytes = $this->random_bytes instanceof Closure
			? ( $this->random_bytes )( 16 )
			: random_bytes( 16 );

		if ( ! is_string( $bytes ) || 16 !== strlen( $bytes ) ) {
			throw new RuntimeException( 'Public ID entropy source returned an invalid value.' );
		}

		$encoded = $this->encode( $bytes );
		if ( 26 !== strlen( $encoded ) ) {
			throw new RuntimeException( 'Public ID encoding failed.' );
		}

		return new Public_Id( $encoded );
	}

	/**
	 * Encode public id generator.
	 *
	 * @param string $bytes Bytes.
	 * @return string
	 */
	private function encode( string $bytes ): string {
		$output = '';
		$buffer = 0;
		$bits   = 0;

		$byte_count = strlen( $bytes );
		for ( $index = 0; $index < $byte_count; ++$index ) {
			$buffer = ( $buffer << 8 ) | ord( $bytes[ $index ] );
			$bits  += 8;

			while ( $bits >= 5 ) {
				$bits   -= 5;
				$output .= self::ALPHABET[ ( $buffer >> $bits ) & 31 ];
			}

			$buffer = 0 === $bits ? 0 : $buffer & ( ( 1 << $bits ) - 1 );
		}

		if ( $bits > 0 ) {
			$output .= self::ALPHABET[ ( $buffer << ( 5 - $bits ) ) & 31 ];
		}

		return $output;
	}
}
