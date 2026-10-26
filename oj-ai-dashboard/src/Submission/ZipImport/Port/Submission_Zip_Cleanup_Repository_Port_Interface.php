<?php
/**
 * Submission zip cleanup repository port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\ZipImport\Port;

use OJAI\Dashboard\Submission\ZipImport\Domain\Submission_Zip_Cleanup_Item;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns cleanup leases, legal-hold rechecks, immutable evidence, and terminal metadata.
 */
interface Submission_Zip_Cleanup_Repository_Port_Interface {
	/**
	 * Claim due.
	 *
	 * @param string $now_utc Now utc.
	 * @param string $stale_before_utc Stale before utc.
	 * @return Submission_Zip_Cleanup_Item|null
	 */
	public function claim_due( string $now_utc, string $stale_before_utc ): ?Submission_Zip_Cleanup_Item;

	/**
	 * Purge claimed.
	 *
	 * @param Submission_Zip_Cleanup_Item $item Item.
	 * @param callable(string):void       $delete Delete.
	 * @param string                      $audit_public_id Audit public id.
	 * @param string                      $outbox_public_id Outbox public id.
	 * @param string                      $occurred_at_utc Occurred at utc.
	 * @return bool|null True when root cleanup completed, false when more files remain, null for a stale claim.
	 */
	public function purge_claimed(
		Submission_Zip_Cleanup_Item $item,
		callable $delete,
		string $audit_public_id,
		string $outbox_public_id,
		string $occurred_at_utc
	): ?bool;
}
