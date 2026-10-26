<?php
/**
 * Teacher submission control repository port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Teacher\Port;

use OJAI\Dashboard\Submission\Teacher\Application\Control_Teacher_Submission_Result;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persists C27-C28 root, revision, receipt, audit, and outbox atomically.
 */
interface Teacher_Submission_Control_Repository_Port_Interface {
	/**
	 * Find receipt.
	 *
	 * @param string $storage_key Storage key.
	 * @param string $request_digest Request digest.
	 * @param string $action Action.
	 * @return Control_Teacher_Submission_Result|null
	 */
	public function find_receipt( string $storage_key, string $request_digest, string $action ): ?Control_Teacher_Submission_Result;

	/**
	 * Control.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Control_Teacher_Submission_Result
	 */
	public function control( array $record ): Control_Teacher_Submission_Result;
}
