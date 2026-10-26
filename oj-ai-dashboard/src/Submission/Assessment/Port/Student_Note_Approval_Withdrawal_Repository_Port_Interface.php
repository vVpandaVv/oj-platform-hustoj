<?php
/**
 * Student note approval withdrawal repository port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Port;

use OJAI\Dashboard\Submission\Assessment\Application\Student_Note_Revision_Result;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Student note approval withdrawal repository port interface.
 */
interface Student_Note_Approval_Withdrawal_Repository_Port_Interface {
	/**
	 * Find withdrawal receipt.
	 *
	 * @param string $command_storage_key Command storage key.
	 * @param string $request_digest Request digest.
	 * @return Student_Note_Revision_Result|null
	 */
	public function find_withdrawal_receipt( string $command_storage_key, string $request_digest ): ?Student_Note_Revision_Result;
	/**
	 * Find owned withdrawal snapshot.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $note_id Note id.
	 * @return array<string,mixed>|null
	 */
	public function find_owned_withdrawal_snapshot( int $actor_user_id, string $note_id ): ?array;
	/**
	 * Append withdrawn revision.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Student_Note_Revision_Result
	 */
	public function append_withdrawn_revision( array $record ): Student_Note_Revision_Result;
}
