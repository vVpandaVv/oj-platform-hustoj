<?php
/**
 * Actor authorization port.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Security\Policy\Port;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads current actor status and assigned capabilities without role inference.
 */
interface Actor_Authorization_Port_Interface {
	/**
	 * Is active.
	 *
	 * @param int $actor_user_id Authenticated actor from the access context.
	 * @return bool
	 */
	public function is_active( int $actor_user_id ): bool;

	/**
	 * Has capability.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $wordpress_capability WordPress capability.
	 * @return bool
	 */
	public function has_capability( int $actor_user_id, string $wordpress_capability ): bool;
}
