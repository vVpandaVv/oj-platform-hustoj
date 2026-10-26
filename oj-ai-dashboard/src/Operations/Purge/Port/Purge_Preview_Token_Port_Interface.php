<?php
/**
 * Purge preview token port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\Purge\Port;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Purge preview token port interface.
 */
interface Purge_Preview_Token_Port_Interface {
	/**
	 * Issue.
	 *
	 * @param string $site_id Site id.
	 * @param string $scope_fingerprint Scope fingerprint.
	 * @param string $preview_hash Preview hash.
	 * @param string $idempotency_key_hash Idempotency key hash.
	 * @return array{preview_token:string,issued_at_utc:string,expires_at_utc:string}
	 */
	public function issue( string $site_id, string $scope_fingerprint, string $preview_hash, string $idempotency_key_hash ): array;
}
