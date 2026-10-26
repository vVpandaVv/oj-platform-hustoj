<?php
/**
 * Legacy source inspector port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\LegacyMigration\Port;

use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Source_Snapshot;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only boundary for an explicitly selected, known legacy release.
 */
interface Legacy_Source_Inspector_Port_Interface {
	/**
	 * Inspect.
	 *
	 * @param string $source_profile Source profile.
	 * @return Legacy_Source_Snapshot
	 */
	public function inspect( string $source_profile ): Legacy_Source_Snapshot;
}
