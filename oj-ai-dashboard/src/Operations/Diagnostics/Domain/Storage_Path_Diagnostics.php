<?php
/**
 * Safe private storage path diagnostic result.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\Diagnostics\Domain;

use InvalidArgumentException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Safe private storage path diagnostic result.
 */
final class Storage_Path_Diagnostics {
	public const READY       = 'ready';
	public const UNAVAILABLE = 'unavailable';

	/**
	 * Initialize storage path diagnostics dependencies and state.
	 *
	 * @param string $status Status.
	 * @param string $code Code.
	 * @param bool   $private Private.
	 * @param bool   $readable Readable.
	 * @param bool   $writable Writable.
	 * @param bool   $space_available Space available.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private string $status,
		private string $code,
		private bool $private, // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.privateFound -- Preserve the established named-argument signature; these parameter names are legal in supported PHP versions.
		private bool $readable,
		private bool $writable,
		private bool $space_available
	) {
		if ( ! in_array( $status, array( self::READY, self::UNAVAILABLE ), true ) ) {
			throw new InvalidArgumentException( 'Unknown storage path diagnostic status.' );
		}
		if ( 1 !== preg_match( '/^[a-z][a-z0-9_]{2,99}$/D', $code ) ) {
			throw new InvalidArgumentException( 'Storage path diagnostics require a stable code.' );
		}
		if ( self::READY === $status ) {
			if ( 'storage_path_ready' !== $code || ! $private || ! $readable || ! $writable || ! $space_available ) {
				throw new InvalidArgumentException( 'Ready storage path diagnostics are inconsistent.' );
			}
		} elseif ( $private && $readable && $writable && $space_available ) {
			throw new InvalidArgumentException( 'Unavailable storage path diagnostics require a failed check.' );
		}
	}

	/**
	 * Unavailable.
	 *
	 * @param string $code Code.
	 * @return self
	 */
	public static function unavailable( string $code = 'storage_path_observation_failed' ): self {
		return new self( self::UNAVAILABLE, $code, false, false, false, false );
	}

	/**
	 * Status.
	 *
	 * @return string
	 */
	public function status(): string {
		return $this->status;
	}

	/**
	 * Code.
	 *
	 * @return string
	 */
	public function code(): string {
		return $this->code;
	}

	/**
	 * Projection.
	 *
	 * @return array{private:bool,readable:bool,writable:bool,space_available:bool}
	 */
	public function projection(): array {
		return array(
			'private'         => $this->private,
			'readable'        => $this->readable,
			'writable'        => $this->writable,
			'space_available' => $this->space_available,
		);
	}
}
