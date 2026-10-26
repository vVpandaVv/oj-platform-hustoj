<?php
/**
 * Worksheet package source query port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Authoring\Port;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Worksheet package source query port interface.
 */
interface Worksheet_Package_Source_Query_Port_Interface {
	/**
	 * Find owned package source.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $revision_id Revision id.
	 * @return array<string,mixed>|null
	 */
	public function find_owned_package_source( int $actor_user_id, string $revision_id ): ?array;
}
