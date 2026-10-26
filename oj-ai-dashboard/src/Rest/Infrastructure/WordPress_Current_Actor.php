<?php
/**
 * WordPress current actor adapter.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Rest\Infrastructure;

use OJAI\Dashboard\Rest\Port\Current_Actor_Port_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Uses WordPress authentication state and never accepts an actor from input.
 */
final class WordPress_Current_Actor implements Current_Actor_Port_Interface {
	/**
	 * Current actor user id.
	 *
	 * @return int
	 */
	public function current_actor_user_id(): int {
		if ( ! function_exists( 'get_current_user_id' ) ) {
			return 0;
		}

		$actor_user_id = (int) get_current_user_id();
		return $actor_user_id > 0 ? $actor_user_id : 0;
	}
}
