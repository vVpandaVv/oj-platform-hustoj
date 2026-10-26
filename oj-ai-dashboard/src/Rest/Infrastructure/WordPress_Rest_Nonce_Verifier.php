<?php
/**
 * WordPress REST nonce verifier.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Rest\Infrastructure;

use OJAI\Dashboard\Rest\Port\Rest_Nonce_Verifier_Port_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps all browser mutation requests on the standard wp_rest action.
 */
final class WordPress_Rest_Nonce_Verifier implements Rest_Nonce_Verifier_Port_Interface {
	public const ACTION = 'wp_rest';

	/**
	 * Verify WordPress rest nonce verifier.
	 *
	 * @param string $nonce Nonce.
	 * @return bool
	 */
	public function verify( string $nonce ): bool {
		if (
			'' === $nonce
			|| strlen( $nonce ) > 128
			|| 1 === preg_match( '/[\x00-\x1F\x7F]/', $nonce )
			|| ! function_exists( 'wp_verify_nonce' )
		) {
			return false;
		}

		$result = wp_verify_nonce( $nonce, self::ACTION );
		return 1 === $result || 2 === $result;
	}
}
