<?php
/**
 * Worksheet asset lifecycle metadata port.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Storage\Port;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Worksheet asset lifecycle metadata port.
 */
interface Worksheet_Asset_Lifecycle_Port_Interface {
	/**
	 * Find by public id.
	 *
	 * @param string $asset_id Asset id.
	 * @return array<string,mixed>|null
	 */
	public function find_by_public_id( string $asset_id ): ?array;

	/**
	 * Atomically transitions the actor-owned unbound quarantine row to purged.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $worksheet_id Worksheet public identifier.
	 * @param string $asset_id Asset id.
	 * @param string $purged_at_utc Purged at utc.
	 * @return bool
	 */
	public function mark_quarantined_purged(
		int $actor_user_id,
		string $worksheet_id,
		string $asset_id,
		string $purged_at_utc
	): bool;
}
