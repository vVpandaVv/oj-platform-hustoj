<?php
/**
 * Deadline auto-submit assignment queue boundary.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Student\Port;

use OJAI\Dashboard\Submission\Student\Domain\Auto_Submit_Assignment_Work_Item;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deadline auto-submit assignment queue boundary.
 */
interface Auto_Submit_Assignment_Repository_Port_Interface {
	/**
	 * Compensate due.
	 *
	 * @param string $now_utc Now utc.
	 * @param string $outbox_public_id Outbox public id.
	 * @return bool
	 */
	public function compensate_due( string $now_utc, string $outbox_public_id ): bool;
	/**
	 * Claim due.
	 *
	 * @param string $now_utc Now utc.
	 * @param string $lease_token Lease token.
	 * @param string $lease_expires_at_utc Lease expires at utc.
	 * @return Auto_Submit_Assignment_Work_Item|null
	 */
	public function claim_due( string $now_utc, string $lease_token, string $lease_expires_at_utc ): ?Auto_Submit_Assignment_Work_Item;

	/**
	 * Candidates.
	 *
	 * @param Auto_Submit_Assignment_Work_Item $item Item.
	 * @param int                              $limit Maximum number of entries requested.
	 * @return array<int,array{submission_id:string,learner_user_id:int,state:string,lock_version:int,current_revision_id:?string}>
	 */
	public function candidates( Auto_Submit_Assignment_Work_Item $item, int $limit ): array;

	/**
	 * Finish.
	 *
	 * @param Auto_Submit_Assignment_Work_Item $item Item.
	 * @param string                           $now_utc Now utc.
	 * @param string                           $retry_at_utc Retry at utc.
	 * @param string|null                      $error_code Error code.
	 * @return string completed|retry_wait|stale
	 */
	public function finish( Auto_Submit_Assignment_Work_Item $item, string $now_utc, string $retry_at_utc, ?string $error_code ): string;
}
