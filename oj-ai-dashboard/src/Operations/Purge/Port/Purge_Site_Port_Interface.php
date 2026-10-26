<?php
/**
 * Purge site port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\Purge\Port;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Purge site port interface.
 */
interface Purge_Site_Port_Interface {
	/**
	 * Site id.
	 *
	 * @return string
	 */
	public function site_id(): string;
}
