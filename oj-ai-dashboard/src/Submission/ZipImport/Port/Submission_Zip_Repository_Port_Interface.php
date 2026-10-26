<?php
/**
 * Submission zip repository port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\ZipImport\Port;

use OJAI\Dashboard\Submission\ZipImport\Application\Stage_Submission_Zip_Result;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persists one ZIP root, audit event, and durable outbox receipt atomically.
 */
interface Submission_Zip_Repository_Port_Interface {
	/**
	 * Find receipt.
	 *
	 * @param string $storage_key Storage key.
	 * @param string $request_digest Request digest.
	 * @return Stage_Submission_Zip_Result|null
	 */
	public function find_receipt( string $storage_key, string $request_digest ): ?Stage_Submission_Zip_Result;

	/**
	 * Stage.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Stage_Submission_Zip_Result
	 */
	public function stage( array $record ): Stage_Submission_Zip_Result;
}
