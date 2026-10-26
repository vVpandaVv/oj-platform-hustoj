<?php
/**
 * Student note report inclusion repository port interface.
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
 * Student note report inclusion repository port interface.
 */
interface Student_Note_Report_Inclusion_Repository_Port_Interface {
	/**
	 * Find report inclusion receipt.
	 *
	 * @param string $command_storage_key Command storage key.
	 * @param string $request_digest Request digest.
	 * @param bool   $target_include_in_reports Target include in reports.
	 * @return Student_Note_Revision_Result|null
	 */
	public function find_report_inclusion_receipt( string $command_storage_key, string $request_digest, bool $target_include_in_reports ): ?Student_Note_Revision_Result;
	/**
	 * Find owned report inclusion snapshot.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $note_id Note id.
	 * @return array<string,mixed>|null
	 */
	public function find_owned_report_inclusion_snapshot( int $actor_user_id, string $note_id ): ?array;
	/**
	 * Append report inclusion revision.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Student_Note_Revision_Result
	 */
	public function append_report_inclusion_revision( array $record ): Student_Note_Revision_Result;
}
