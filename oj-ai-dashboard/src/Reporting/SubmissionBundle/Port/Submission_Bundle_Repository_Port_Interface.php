<?php
/**
 * Submission bundle repository port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\SubmissionBundle\Port;

use OJAI\Dashboard\Reporting\SubmissionBundle\Application\Enqueue_Submission_Bundle_Result;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Submission bundle repository port interface.
 */
interface Submission_Bundle_Repository_Port_Interface {
	/**
	 * Find command receipt.
	 *
	 * @param string $command_storage_key Command storage key.
	 * @param string $request_digest Request digest.
	 * @return Enqueue_Submission_Bundle_Result|null
	 */
	public function find_command_receipt( string $command_storage_key, string $request_digest ): ?Enqueue_Submission_Bundle_Result;
	/**
	 * Enqueue.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Enqueue_Submission_Bundle_Result
	 */
	public function enqueue( array $record ): Enqueue_Submission_Bundle_Result;
}
