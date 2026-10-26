<?php
/**
 * Worksheet public identifier.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Authoring\Domain;

use InvalidArgumentException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enforces the frozen PublicIdV1 path profile.
 */
final class Public_Id {
	public const PATTERN = '/^[0-9A-HJKMNP-TV-Z]{26}$/D';

	/**
	 * Value.
	 *
	 * @var string
	 */
	private string $value;

	/**
	 * Initialize public id dependencies and state.
	 *
	 * @param string $value Value.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct( string $value ) {
		if ( ! self::is_valid( $value ) ) {
			throw new InvalidArgumentException( 'Invalid worksheet public ID.' );
		}

		$this->value = $value;
	}

	/**
	 * Is valid.
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	public static function is_valid( string $value ): bool {
		return 1 === preg_match( self::PATTERN, $value );
	}

	/**
	 * Value.
	 *
	 * @return string
	 */
	public function value(): string {
		return $this->value;
	}
}
