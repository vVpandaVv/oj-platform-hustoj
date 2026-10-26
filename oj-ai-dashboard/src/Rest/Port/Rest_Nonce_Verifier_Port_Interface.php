<?php
/**
 * REST nonce verification contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Rest\Port;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Verifies only the standard WordPress REST nonce profile.
 */
interface Rest_Nonce_Verifier_Port_Interface {
	/**
	 * Verify rest nonce verifier port interface.
	 *
	 * @param string $nonce Nonce.
	 * @return bool
	 */
	public function verify( string $nonce ): bool;
}
