<?php
/**
 * Student submission commit repository port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Student\Port;

use OJAI\Dashboard\Submission\Student\Application\Submit_Own_Submission_Result;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Commits one exact official revision and its immutable receipt.
 */
interface Student_Submission_Commit_Repository_Port_Interface {
	/**
	 * Find submit receipt.
	 *
	 * @param string $idempotency_storage_key Idempotency storage key.
	 * @param string $request_digest Request digest.
	 * @return Submit_Own_Submission_Result|null
	 */
	public function find_submit_receipt( string $idempotency_storage_key, string $request_digest ): ?Submit_Own_Submission_Result;

	/**
	 * Submit.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Submit_Own_Submission_Result
	 */
	public function submit( array $record ): Submit_Own_Submission_Result;
}
