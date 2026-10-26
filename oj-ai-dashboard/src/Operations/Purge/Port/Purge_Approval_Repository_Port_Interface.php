<?php
/**
 * Purge approval repository port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\Purge\Port;

use OJAI\Dashboard\Operations\Purge\Application\Approve_Purge_Result;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Purge approval repository port interface.
 */
interface Purge_Approval_Repository_Port_Interface {
	/**
	 * Approve.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Approve_Purge_Result
	 */
	public function approve( array $record ): Approve_Purge_Result;
}
