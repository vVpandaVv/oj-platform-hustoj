<?php
/**
 * Legacy migration approval repository port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\LegacyMigration\Port;

use OJAI\Dashboard\Operations\LegacyMigration\Application\Approve_Legacy_Migration_Result;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Legacy migration approval repository port interface.
 */
interface Legacy_Migration_Approval_Repository_Port_Interface {
	/**
	 * Approve.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Approve_Legacy_Migration_Result
	 */
	public function approve( array $record ): Approve_Legacy_Migration_Result;
}
