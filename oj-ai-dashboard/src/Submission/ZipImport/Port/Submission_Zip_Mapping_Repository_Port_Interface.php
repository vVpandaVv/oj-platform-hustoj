<?php
/**
 * Submission zip mapping repository port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\ZipImport\Port;

use OJAI\Dashboard\Submission\ZipImport\Application\Map_Submission_Zip_Items_Result;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Submission zip mapping repository port interface.
 */
interface Submission_Zip_Mapping_Repository_Port_Interface {
	/**
	 * Find receipt.
	 *
	 * @param string $idempotency_storage_key Idempotency storage key.
	 * @param string $request_digest Request digest.
	 * @return Map_Submission_Zip_Items_Result|null
	 */
	public function find_receipt( string $idempotency_storage_key, string $request_digest ): ?Map_Submission_Zip_Items_Result;

	/**
	 * Map submission zip mapping repository port interface.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Map_Submission_Zip_Items_Result
	 */
	public function map( array $record ): Map_Submission_Zip_Items_Result;
}
