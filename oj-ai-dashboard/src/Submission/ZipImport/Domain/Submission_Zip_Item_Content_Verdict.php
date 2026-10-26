<?php
/**
 * Submission zip item content verdict.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\ZipImport\Domain;

use InvalidArgumentException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stable result of extension, MIME, and magic-byte inspection.
 */
final class Submission_Zip_Item_Content_Verdict {
	public const CLEAN       = 'clean';
	public const REJECTED    = 'rejected';
	public const UNAVAILABLE = 'unavailable';

	/**
	 * Initialize submission zip item content verdict dependencies and state.
	 *
	 * @param string      $state State.
	 * @param string|null $mime_type Mime type.
	 * @param string|null $reason_code Reason code.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function __construct(
		private string $state,
		private ?string $mime_type,
		private ?string $reason_code
	) {
		if ( ! in_array( $state, array( self::CLEAN, self::REJECTED, self::UNAVAILABLE ), true ) ) {
			throw new InvalidArgumentException( 'Submission ZIP item verdict state is invalid.' );
		}
	}

	/**
	 * Clean.
	 *
	 * @param string $mime_type Mime type.
	 * @return self
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public static function clean( string $mime_type ): self {
		if ( 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9!#$&^_.+-]*\/[A-Za-z0-9][A-Za-z0-9!#$&^_.+-]*$/D', $mime_type ) ) {
			throw new InvalidArgumentException( 'Submission ZIP item MIME is invalid.' );
		}
		return new self( self::CLEAN, $mime_type, null );
	}

	/**
	 * Rejected.
	 *
	 * @param string $reason_code Reason code.
	 * @return self
	 */
	public static function rejected( string $reason_code ): self {
		return new self( self::REJECTED, null, self::reason( $reason_code ) );
	}

	/**
	 * Unavailable.
	 *
	 * @param string $reason_code Reason code.
	 * @return self
	 */
	public static function unavailable( string $reason_code ): self {
		return new self( self::UNAVAILABLE, null, self::reason( $reason_code ) );
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
	 * Mime type.
	 *
	 * @return string|null
	 */
	public function mime_type(): ?string {
		return $this->mime_type;
	}
	/**
	 * Reason code.
	 *
	 * @return string|null
	 */
	public function reason_code(): ?string {
		return $this->reason_code;
	}

	/**
	 * Return the stable failure reason used by boundary mappers.
	 *
	 * @param string $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private static function reason( string $value ): string {
		if ( 1 !== preg_match( '/^[a-z][a-z0-9_]{2,99}$/D', $value ) ) {
			throw new InvalidArgumentException( 'Submission ZIP item reason is invalid.' );
		}
		return $value;
	}
}
