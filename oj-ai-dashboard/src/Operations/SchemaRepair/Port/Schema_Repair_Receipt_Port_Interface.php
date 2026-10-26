<?php
/**
 * Schema repair receipt port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\SchemaRepair\Port;

use OJAI\Dashboard\Database\Foundation\Schema_Installation_Result;
use OJAI\Dashboard\Operations\SchemaRepair\Application\Repair_Addon_Schema_Result;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Schema repair receipt port interface.
 */
interface Schema_Repair_Receipt_Port_Interface {
	/**
	 * Claim.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Repair_Addon_Schema_Result|null
	 */
	public function claim( array $record ): ?Repair_Addon_Schema_Result;

	/**
	 * Complete.
	 *
	 * @param array<string,mixed>        $record Record.
	 * @param Schema_Installation_Result $repair_result Repair result.
	 * @param string                     $completed_at_utc Completed at utc.
	 * @return Repair_Addon_Schema_Result
	 */
	public function complete(
		array $record,
		Schema_Installation_Result $repair_result,
		string $completed_at_utc
	): Repair_Addon_Schema_Result;

	/**
	 * Block.
	 *
	 * @param array<string,mixed> $record Record.
	 * @param string              $reason Reason.
	 * @param string              $occurred_at_utc Occurred at utc.
	 * @return void
	 */
	public function block( array $record, string $reason, string $occurred_at_utc ): void;
}
