<?php
/**
 * Submission pdf work repository port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\SubmissionPdf\Port;

use OJAI\Dashboard\Reporting\SubmissionPdf\Domain\Submission_Pdf_Work_Item;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Submission pdf work repository port interface.
 */
interface Submission_Pdf_Work_Repository_Port_Interface {
	/**
	 * Claim due.
	 *
	 * @param string $now_utc Now utc.
	 * @param string $lease_token Lease token.
	 * @param string $lease_expires_at_utc Lease expires at utc.
	 * @return Submission_Pdf_Work_Item|null
	 */
	public function claim_due( string $now_utc, string $lease_token, string $lease_expires_at_utc ): ?Submission_Pdf_Work_Item;

	/**
	 * Complete ready.
	 *
	 * @param Submission_Pdf_Work_Item $item Item.
	 * @param array<string,mixed>      $file File.
	 * @param array<string,mixed>      $artifact Artifact.
	 * @param string                   $finished_at_utc Finished at utc.
	 * @return bool
	 */
	public function complete_ready( Submission_Pdf_Work_Item $item, array $file, array $artifact, string $finished_at_utc ): bool;

	/**
	 * Complete failure.
	 *
	 * @param Submission_Pdf_Work_Item $item Item.
	 * @param string                   $error_code Error code.
	 * @param string                   $audit_public_id Audit public id.
	 * @param string                   $outbox_public_id Outbox public id.
	 * @param string                   $correlation_id Request correlation identifier for diagnostics.
	 * @param string                   $finished_at_utc Finished at utc.
	 * @return bool
	 */
	public function complete_failure(
		Submission_Pdf_Work_Item $item,
		string $error_code,
		string $audit_public_id,
		string $outbox_public_id,
		string $correlation_id,
		string $finished_at_utc
	): bool;
}
