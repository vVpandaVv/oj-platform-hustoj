<?php
/**
 * Worksheet target snapshot port for Storage commands.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Storage\Port;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Worksheet target snapshot port for Storage commands.
 */
interface Worksheet_Asset_Target_Port_Interface {
	/**
	 * Current owned target.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $worksheet_id Worksheet public identifier.
	 * @return array{state:string,current_revision_id:string}|null
	 */
	public function current_owned_target( int $actor_user_id, string $worksheet_id ): ?array;
}
