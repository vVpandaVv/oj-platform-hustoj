<?php
/**
 * Durable C30 receipt boundary.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Port;

use OJAI\Dashboard\Submission\Assessment\Application\Enqueue_Assignment_Evaluations_Result;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores only the page receipt; each evaluation remains owned by the C29 transaction.
 */
interface Assignment_Evaluation_Batch_Receipt_Port_Interface {
	/**
	 * Find receipt.
	 *
	 * @param string $idempotency_storage_key Idempotency storage key.
	 * @param string $request_digest Request digest.
	 * @return Enqueue_Assignment_Evaluations_Result|null
	 */
	public function find_receipt( string $idempotency_storage_key, string $request_digest ): ?Enqueue_Assignment_Evaluations_Result;

	/**
	 * Store receipt.
	 *
	 * @param array<string,mixed>                   $record Record.
	 * @param Enqueue_Assignment_Evaluations_Result $result Result.
	 * @return Enqueue_Assignment_Evaluations_Result
	 */
	public function store_receipt( array $record, Enqueue_Assignment_Evaluations_Result $result ): Enqueue_Assignment_Evaluations_Result;
}
