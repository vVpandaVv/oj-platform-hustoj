<?php
/**
 * Safe OJ Platform Runtime bridge diagnostic result.
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
 * Safe OJ Platform Runtime bridge diagnostic result.
 */
final class Bridge_Diagnostics_Result {
	public const READY       = 'ready';
	public const UNAVAILABLE = 'unavailable';

	/**
	 * Initialize bridge diagnostics result dependencies and state.
	 *
	 * @param string      $status Status.
	 * @param string      $code Code.
	 * @param string|null $platform_version Platform version.
	 * @param string|null $addon_api_version Addon api version.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private string $status,
		private string $code,
		private ?string $platform_version = null,
		private ?string $addon_api_version = null
	) {
		if ( ! in_array( $status, array( self::READY, self::UNAVAILABLE ), true ) ) {
			throw new InvalidArgumentException( 'Unknown bridge diagnostic status.' );
		}
		if ( 1 !== preg_match( '/^[a-z][a-z0-9_]{2,99}$/D', $code ) ) {
			throw new InvalidArgumentException( 'Bridge diagnostics require a stable code.' );
		}
		if ( self::READY === $status ) {
			if ( 'addon_runtime_ready' !== $code || ! $this->version( $platform_version ) || ! $this->version( $addon_api_version ) ) {
				throw new InvalidArgumentException( 'Ready bridge diagnostics require safe Runtime versions.' );
			}
		} elseif ( null !== $platform_version || null !== $addon_api_version ) {
			throw new InvalidArgumentException( 'Unavailable bridge diagnostics cannot expose Runtime details.' );
		}
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
	 * Details.
	 *
	 * @return array<string,string>
	 */
	public function details(): array {
		if ( self::READY !== $this->status ) {
			return array();
		}

		return array(
			'platform_version'  => (string) $this->platform_version,
			'addon_api_version' => (string) $this->addon_api_version,
		);
	}

	/**
	 * Version.
	 *
	 * @param string|null $version Version.
	 * @return bool
	 */
	private function version( ?string $version ): bool {
		return is_string( $version )
			&& 1 === preg_match( '/^[0-9A-Za-z][0-9A-Za-z.+_-]{0,63}$/D', $version );
	}
}
