<?php
/**
 * Platform-only readiness used by the narrow schema recovery surface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Platform;

use OJAI\Dashboard\Contracts\Platform_Runtime_Readiness_Port_Interface;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Platform-only readiness used by the narrow schema recovery surface.
 */
final class Addon_Recovery_Runtime_Readiness implements Platform_Runtime_Readiness_Port_Interface {
	/**
	 * Is ready.
	 *
	 * @return mixed
	 */
	public function is_ready() {
		try {
			if ( function_exists( 'is_multisite' ) && is_multisite() ) {
				return false;
			}
			$platform = defined( 'OJ_PLATFORM_VERSION' ) ? (string) OJ_PLATFORM_VERSION : '';
			$api      = defined( 'OJ_PLATFORM_ADDON_API_VERSION' ) ? (string) OJ_PLATFORM_ADDON_API_VERSION : '';

			return Addon_Integration::version_in_range( $platform, Addon_Integration::MIN_PLATFORM, Addon_Integration::MAX_PLATFORM )
				&& Addon_Integration::version_in_range( $api, Addon_Integration::MIN_ADDON_API, Addon_Integration::MAX_ADDON_API )
				&& Addon_Integration::api_contract_available();
		} catch ( Throwable ) {
			return false;
		}
	}
}
