<?php
/**
 * Safe projection of the accepted OJ Platform Runtime bridge.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\Diagnostics\Infrastructure;

use OJAI\Dashboard\Operations\Diagnostics\Domain\Bridge_Diagnostics_Result;
use OJAI\Dashboard\Operations\Diagnostics\Port\Bridge_Diagnostics_Port_Interface;
use OJAI\Dashboard\Platform\Addon_Runtime_Bridge;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Safe projection of the accepted OJ Platform Runtime bridge.
 */
final class Addon_Runtime_Bridge_Diagnostics implements Bridge_Diagnostics_Port_Interface {
	/**
	 * Initialize addon runtime bridge diagnostics dependencies and state.
	 *
	 * @param Addon_Runtime_Bridge $bridge Bridge.
	 */
	public function __construct( private Addon_Runtime_Bridge $bridge ) {
	}

	/**
	 * Inspect.
	 *
	 * @return Bridge_Diagnostics_Result
	 */
	public function inspect(): Bridge_Diagnostics_Result {
		try {
			$diagnostics = $this->bridge->diagnostics();
			if ( $this->bridge->is_ready() && Addon_Runtime_Bridge::STATE_READY === $this->bridge->state() ) {
				return new Bridge_Diagnostics_Result(
					Bridge_Diagnostics_Result::READY,
					'addon_runtime_ready',
					$this->safe_version( $diagnostics['platform_version'] ?? null ),
					$this->safe_version( $diagnostics['addon_api_version'] ?? null )
				);
			}

			return new Bridge_Diagnostics_Result(
				Bridge_Diagnostics_Result::UNAVAILABLE,
				$this->safe_code( $diagnostics['code'] ?? $this->bridge->code() )
			);
		} catch ( Throwable ) {
			return new Bridge_Diagnostics_Result(
				Bridge_Diagnostics_Result::UNAVAILABLE,
				'addon_runtime_diagnostics_failed'
			);
		}
	}

	/**
	 * Safe code.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function safe_code( $value ): string {
		return is_string( $value ) && 1 === preg_match( '/^[a-z][a-z0-9_]{2,99}$/D', $value )
			? $value
			: 'addon_runtime_unavailable';
	}

	/**
	 * Safe version.
	 *
	 * @param mixed $value Value.
	 * @return string|null
	 */
	private function safe_version( $value ): ?string {
		return is_string( $value ) && 1 === preg_match( '/^[0-9A-Za-z][0-9A-Za-z.+_-]{0,63}$/D', $value )
			? $value
			: null;
	}
}
