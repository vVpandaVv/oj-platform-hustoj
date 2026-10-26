<?php
/**
 * Assignment lifecycle repository port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Submission\Assignment\Port;

use OJAI\Dashboard\Submission\Assignment\Application\Change_Assignment_Result;
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Assignment lifecycle repository port interface.
 */
interface Assignment_Lifecycle_Repository_Port_Interface {
	/**
	 * Find receipt.
	 *
	 * @param string $idempotency_storage_key Idempotency storage key.
	 * @param string $request_digest Request digest.
	 * @param string $action Action.
	 * @return Change_Assignment_Result|null
	 */
	public function find_receipt( string $idempotency_storage_key, string $request_digest, string $action ): ?Change_Assignment_Result;
	/**
	 * Change.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Change_Assignment_Result
	 */
	public function change( array $record ): Change_Assignment_Result;
}
