<?php
/**
 * Theme-aware page responses with Addon API 1.1 compatibility.
 *
 * @package OJAI\Dashboard
 */

namespace OJAI\Dashboard\Platform;

use CodingPanda\OjPlatform\AddonApi\V1\AddonPageResponseV1;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keep the platform's saved color scheme on token-based add-on pages.
 */
final class Addon_Page_Response {
	/**
	 * Render without changing the route, status or layout contract.
	 *
	 * @param string $title Page title.
	 * @param string $body Escaped page markup.
	 * @param int    $status HTTP status.
	 * @return AddonPageResponseV1
	 */
	public static function html( string $title, string $body, int $status = 200 ): AddonPageResponseV1 {
		if ( in_array( 'page', get_class_methods( AddonPageResponseV1::class ), true ) ) {
			return AddonPageResponseV1::page( $title, $body, 'standard', true, $status );
		}
		return AddonPageResponseV1::html( $title, $body, $status );
	}
}
