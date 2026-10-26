<?php
/**
 * Portable worksheet package reader port.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Authoring\Port;

use OJAI\Dashboard\Worksheet\Authoring\Application\Worksheet_Template_Package;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Portable worksheet package reader port.
 */
interface Worksheet_Template_Package_Reader_Port_Interface {
	/**
	 * Read worksheet template package reader port interface.
	 *
	 * @param array<string,mixed> $upload Upload.
	 * @return Worksheet_Template_Package
	 */
	public function read( array $upload ): Worksheet_Template_Package;
}
