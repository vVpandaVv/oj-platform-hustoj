<?php
/**
 * Submission zip scan repository port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\ZipImport\Port;

use OJAI\Dashboard\Submission\ZipImport\Domain\Submission_Zip_Scan_Job;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns reclaimable ZIP root leases and atomic scan completion.
 */
interface Submission_Zip_Scan_Repository_Port_Interface {
	/**
	 * Claim due.
	 *
	 * @param string $now_utc Now utc.
	 * @param string $stale_before_utc Stale before utc.
	 * @return Submission_Zip_Scan_Job|null
	 */
	public function claim_due( string $now_utc, string $stale_before_utc ): ?Submission_Zip_Scan_Job;

	/**
	 * Complete.
	 *
	 * @param Submission_Zip_Scan_Job        $job Job.
	 * @param array<int,array<string,mixed>> $items Items.
	 * @param array<string,mixed>            $mapping Mapping.
	 * @param string                         $audit_public_id Audit public id.
	 * @param string                         $outbox_public_id Outbox public id.
	 * @param string                         $occurred_at_utc Occurred at utc.
	 * @return bool
	 */
	public function complete(
		Submission_Zip_Scan_Job $job,
		array $items,
		array $mapping,
		string $audit_public_id,
		string $outbox_public_id,
		string $occurred_at_utc
	): bool;

	/**
	 * Reject.
	 *
	 * @param Submission_Zip_Scan_Job $job Job.
	 * @param string                  $reason_code Reason code.
	 * @param string                  $audit_public_id Audit public id.
	 * @param string                  $outbox_public_id Outbox public id.
	 * @param string                  $occurred_at_utc Occurred at utc.
	 * @return bool
	 */
	public function reject(
		Submission_Zip_Scan_Job $job,
		string $reason_code,
		string $audit_public_id,
		string $outbox_public_id,
		string $occurred_at_utc
	): bool;
}
