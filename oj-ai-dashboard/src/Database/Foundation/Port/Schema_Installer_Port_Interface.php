<?php
/**
 * Schema installation use-case port.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Database\Foundation\Port;

use OJAI\Dashboard\Database\Foundation\Schema_Installation_Result;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Installs or verifies the add-on schema for an activation attempt.
 */
interface Schema_Installer_Port_Interface {
	/**
	 * Install.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $correlation_id Request correlation identifier for diagnostics.
	 * @return Schema_Installation_Result
	 */
	public function install( int $actor_user_id, string $correlation_id ): Schema_Installation_Result;
}
