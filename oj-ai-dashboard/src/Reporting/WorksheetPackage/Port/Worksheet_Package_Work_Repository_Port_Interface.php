<?php
/**
 * Worksheet package work repository port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Reporting\WorksheetPackage\Port;

use OJAI\Dashboard\Reporting\WorksheetPackage\Domain\Worksheet_Package_Work_Item;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Worksheet package work repository port interface.
 */
interface Worksheet_Package_Work_Repository_Port_Interface {
	/**
	 * Claim due.
	 *
	 * @param string $now_utc Now utc.
	 * @param string $lease_token Lease token.
	 * @param string $lease_expires_at_utc Lease expires at utc.
	 * @return Worksheet_Package_Work_Item|null
	 */
	public function claim_due( string $now_utc, string $lease_token, string $lease_expires_at_utc ): ?Worksheet_Package_Work_Item;
	/**
	 * Complete ready.
	 *
	 * @param Worksheet_Package_Work_Item $item Item.
	 * @param array<string,mixed>         $file File.
	 * @param array<string,mixed>         $event Event.
	 * @param string                      $finished_at_utc Finished at utc.
	 * @return bool
	 */
	public function complete_ready( Worksheet_Package_Work_Item $item, array $file, array $event, string $finished_at_utc ): bool;
	/**
	 * Complete failure.
	 *
	 * @param Worksheet_Package_Work_Item $item Item.
	 * @param string                      $error_code Error code.
	 * @param string                      $audit_public_id Audit public id.
	 * @param string                      $outbox_public_id Outbox public id.
	 * @param string                      $correlation_id Request correlation identifier for diagnostics.
	 * @param string                      $finished_at_utc Finished at utc.
	 * @return bool
	 */
	public function complete_failure( Worksheet_Package_Work_Item $item, string $error_code, string $audit_public_id, string $outbox_public_id, string $correlation_id, string $finished_at_utc ): bool;
}
