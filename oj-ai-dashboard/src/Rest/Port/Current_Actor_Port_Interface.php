<?php
/**
 * Current REST actor contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Rest\Port;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves the authenticated WordPress actor without reading request input.
 */
interface Current_Actor_Port_Interface {
	/**
	 * Current actor user id.
	 *
	 * @return int
	 */
	public function current_actor_user_id(): int;
}
