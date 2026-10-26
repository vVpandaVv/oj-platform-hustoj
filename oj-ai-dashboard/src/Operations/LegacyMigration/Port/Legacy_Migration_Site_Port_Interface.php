<?php
/**
 * Legacy migration site port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\LegacyMigration\Port;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Legacy migration site port interface.
 */
interface Legacy_Migration_Site_Port_Interface {
	/**
	 * Site id.
	 *
	 * @return string
	 */
	public function site_id(): string;
}
