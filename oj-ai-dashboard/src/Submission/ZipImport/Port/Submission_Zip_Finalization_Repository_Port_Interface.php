<?php
/**
 * Submission zip finalization repository port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\ZipImport\Port;

use OJAI\Dashboard\Submission\ZipImport\Application\Cancel_Submission_Zip_Result;
use OJAI\Dashboard\Submission\ZipImport\Application\Confirm_Submission_Zip_Result;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Submission zip finalization repository port interface.
 */
interface Submission_Zip_Finalization_Repository_Port_Interface {
	/**
	 * Find confirmation receipt.
	 *
	 * @param string $idempotency_storage_key Idempotency storage key.
	 * @param string $request_digest Request digest.
	 * @return Confirm_Submission_Zip_Result|null
	 */
	public function find_confirmation_receipt( string $idempotency_storage_key, string $request_digest ): ?Confirm_Submission_Zip_Result;

	/**
	 * Begin confirmation.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return array<int,int>|Confirm_Submission_Zip_Result
	 */
	public function begin_confirmation( array $record ): array|Confirm_Submission_Zip_Result;

	/**
	 * Confirm item.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return array{error_code:?string,item_no:int,result_submission_id:?string,state:string}
	 */
	public function confirm_item( array $record ): array;

	/**
	 * Complete confirmation.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Confirm_Submission_Zip_Result
	 */
	public function complete_confirmation( array $record ): Confirm_Submission_Zip_Result;

	/**
	 * Find cancellation receipt.
	 *
	 * @param string $idempotency_storage_key Idempotency storage key.
	 * @param string $request_digest Request digest.
	 * @return Cancel_Submission_Zip_Result|null
	 */
	public function find_cancellation_receipt( string $idempotency_storage_key, string $request_digest ): ?Cancel_Submission_Zip_Result;

	/**
	 * Cancel.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Cancel_Submission_Zip_Result
	 */
	public function cancel( array $record ): Cancel_Submission_Zip_Result;
}
