<?php
/**
 * Purge preview token verifier port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\Purge\Port;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Purge preview token verifier port interface.
 */
interface Purge_Preview_Token_Verifier_Port_Interface {
	/**
	 * Verify purge preview token verifier port interface.
	 *
	 * @param string $token Token.
	 * @return array{purpose:string,site:string,scope_fingerprint:string,preview_hash:string,idempotency_key_hash:string,issued_at_utc:string,expires_at_utc:string,nonce:string}
	 */
	public function verify( string $token ): array;
}
