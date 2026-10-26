<?php
/**
 * Student attachment cleanup repository port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Student\Port;

use OJAI\Dashboard\Submission\Student\Domain\Submission_Attachment_Cleanup_Item;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns expiration, retry backoff, legal-hold recheck, and terminal purge metadata.
 */
interface Student_Attachment_Cleanup_Repository_Port_Interface {
	/**
	 * Claim purge due.
	 *
	 * @param string $now_utc Now utc.
	 * @param string $stale_before_utc Stale before utc.
	 * @return Submission_Attachment_Cleanup_Item|null
	 */
	public function claim_purge_due( string $now_utc, string $stale_before_utc ): ?Submission_Attachment_Cleanup_Item;

	/**
	 * Expire due.
	 *
	 * @param string $now_utc Now utc.
	 * @param string $audit_public_id Audit public id.
	 * @param string $outbox_public_id Outbox public id.
	 * @return Submission_Attachment_Cleanup_Item|null
	 */
	public function expire_due(
		string $now_utc,
		string $audit_public_id,
		string $outbox_public_id
	): ?Submission_Attachment_Cleanup_Item;

	/**
	 * Purge claimed.
	 *
	 * @param Submission_Attachment_Cleanup_Item $item Item.
	 * @param callable(string):void              $delete Delete.
	 * @param string                             $audit_public_id Audit public id.
	 * @param string                             $outbox_public_id Outbox public id.
	 * @param string                             $occurred_at_utc Occurred at utc.
	 * @return bool
	 */
	public function purge_claimed(
		Submission_Attachment_Cleanup_Item $item,
		callable $delete,
		string $audit_public_id,
		string $outbox_public_id,
		string $occurred_at_utc
	): bool;
}
