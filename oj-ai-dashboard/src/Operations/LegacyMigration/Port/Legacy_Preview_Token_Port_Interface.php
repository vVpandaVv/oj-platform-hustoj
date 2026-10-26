<?php
/**
 * Legacy preview token port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\LegacyMigration\Port;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Legacy preview token port interface.
 */
interface Legacy_Preview_Token_Port_Interface {
	/**
	 * Issue.
	 *
	 * @param string $site_id Site id.
	 * @param string $source_fingerprint Source fingerprint.
	 * @param string $plan_hash Plan hash.
	 * @param string $idempotency_key_hash Idempotency key hash.
	 * @return array{preview_token:string,issued_at_utc:string,expires_at_utc:string}
	 */
	public function issue(
		string $site_id,
		string $source_fingerprint,
		string $plan_hash,
		string $idempotency_key_hash
	): array;
}
