<?php
/**
 * Worksheet asset source authorization port.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Storage\Port;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Worksheet asset source authorization port.
 */
interface Worksheet_Asset_Source_Port_Interface {
	/**
	 * Owned source references asset.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $source_type Source type.
	 * @param string $source_id Source id.
	 * @param string $asset_id Asset id.
	 * @return bool
	 */
	public function owned_source_references_asset(
		int $actor_user_id,
		string $source_type,
		string $source_id,
		string $asset_id
	): bool;
}
