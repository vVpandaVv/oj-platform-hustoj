<?php
/**
 * Student attachment scan repository port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Student\Port;

use OJAI\Dashboard\Submission\Student\Domain\Submission_Attachment_Scan_Item;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns reclaimable scan leases and atomic terminal metadata transitions.
 */
interface Student_Attachment_Scan_Repository_Port_Interface {
	/**
	 * Claim due.
	 *
	 * @param string $now_utc Now utc.
	 * @param string $stale_before_utc Stale before utc.
	 * @return Submission_Attachment_Scan_Item|null
	 */
	public function claim_due( string $now_utc, string $stale_before_utc ): ?Submission_Attachment_Scan_Item;

	/**
	 * Complete.
	 *
	 * @param Submission_Attachment_Scan_Item $item Item.
	 * @param string                          $outcome Outcome.
	 * @param string|null                     $reason_code Reason code.
	 * @param string                          $audit_public_id Audit public id.
	 * @param string                          $outbox_public_id Outbox public id.
	 * @param string                          $occurred_at_utc Occurred at utc.
	 * @return bool
	 */
	public function complete(
		Submission_Attachment_Scan_Item $item,
		string $outcome,
		?string $reason_code,
		string $audit_public_id,
		string $outbox_public_id,
		string $occurred_at_utc
	): bool;
}
