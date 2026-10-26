<?php
/**
 * Submission evaluation work repository port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Submission\Assessment\Port;

use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Evaluation_Gateway_Result;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Evaluation_Normalized_Result;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Evaluation_Routing_Snapshot;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Evaluation_Work_Item;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Submission evaluation work repository port interface.
 */
interface Submission_Evaluation_Work_Repository_Port_Interface {
	/**
	 * Claim due.
	 *
	 * @param string $now_utc Now utc.
	 * @param string $lease_token Lease token.
	 * @param string $lease_expires_at_utc Lease expires at utc.
	 * @return Submission_Evaluation_Work_Item|null
	 */
	public function claim_due(
		string $now_utc,
		string $lease_token,
		string $lease_expires_at_utc
	): ?Submission_Evaluation_Work_Item;

	/**
	 * Start attempt.
	 *
	 * @param Submission_Evaluation_Work_Item $item Item.
	 * @param string                          $attempt_public_id Attempt public id.
	 * @param string                          $started_at_utc Started at utc.
	 * @param string                          $lease_expires_at_utc Lease expires at utc.
	 * @return Submission_Evaluation_Work_Item|null
	 */
	public function start_attempt(
		Submission_Evaluation_Work_Item $item,
		string $attempt_public_id,
		string $started_at_utc,
		string $lease_expires_at_utc
	): ?Submission_Evaluation_Work_Item;

	/**
	 * Complete success.
	 *
	 * @param Submission_Evaluation_Work_Item         $item Item.
	 * @param Submission_Evaluation_Gateway_Result    $gateway Gateway.
	 * @param Submission_Evaluation_Normalized_Result $result Result.
	 * @param string                                  $result_public_id Result public id.
	 * @param string                                  $audit_public_id Audit public id.
	 * @param string                                  $outbox_public_id Outbox public id.
	 * @param string                                  $finished_at_utc Finished at utc.
	 * @return bool
	 */
	public function complete_success(
		Submission_Evaluation_Work_Item $item,
		Submission_Evaluation_Gateway_Result $gateway,
		Submission_Evaluation_Normalized_Result $result,
		string $result_public_id,
		string $audit_public_id,
		string $outbox_public_id,
		string $finished_at_utc
	): bool;

	/**
	 * Complete failure.
	 *
	 * @param Submission_Evaluation_Work_Item             $item Item.
	 * @param string                                      $attempt_state Attempt state.
	 * @param string                                      $error_code Error code.
	 * @param string|null                                 $gateway_correlation_id Gateway correlation id.
	 * @param string|null                                 $provider_output_digest Provider output digest.
	 * @param int|null                                    $duration_ms Duration ms.
	 * @param Submission_Evaluation_Routing_Snapshot|null $actual_routing Actual routing.
	 * @param bool                                        $retry Retry.
	 * @param string                                      $available_at_utc Available at utc.
	 * @param string                                      $audit_public_id Audit public id.
	 * @param string                                      $outbox_public_id Outbox public id.
	 * @param string                                      $finished_at_utc Finished at utc.
	 * @param string|null                                 $error_message Error message.
	 * @return bool
	 */
	public function complete_failure(
		Submission_Evaluation_Work_Item $item,
		string $attempt_state,
		string $error_code,
		?string $gateway_correlation_id,
		?string $provider_output_digest,
		?int $duration_ms,
		?Submission_Evaluation_Routing_Snapshot $actual_routing,
		bool $retry,
		string $available_at_utc,
		string $audit_public_id,
		string $outbox_public_id,
		string $finished_at_utc,
		?string $error_message = null
	): bool;
}
