<?php
/**
 * Legacy preview token verifier port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\LegacyMigration\Port;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Legacy preview token verifier port interface.
 */
interface Legacy_Preview_Token_Verifier_Port_Interface {
	/**
	 * Verify legacy preview token verifier port interface.
	 *
	 * @param string $token Token.
	 * @return array{purpose:string,site:string,source_fingerprint:string,plan_hash:string,idempotency_key_hash:string,issued_at_utc:string,expires_at_utc:string,nonce:string}
	 */
	public function verify( string $token ): array;
}
