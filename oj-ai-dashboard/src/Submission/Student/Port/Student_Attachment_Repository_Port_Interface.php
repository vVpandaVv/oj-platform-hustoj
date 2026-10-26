<?php
/**
 * Student attachment repository port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Student\Port;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persists attachment rows only for the authenticated learner's submission.
 */
interface Student_Attachment_Repository_Port_Interface {
	/**
	 * Staging profile.
	 *
	 * @param string $submission_id Submission public identifier.
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @return array<string,mixed>|null
	 */
	public function staging_profile( string $submission_id, int $actor_user_id ): ?array;

	/**
	 * Find by file public id.
	 *
	 * @param string $submission_id Submission public identifier.
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $file_id File id.
	 * @return array<string,mixed>|null
	 */
	public function find_by_file_public_id( string $submission_id, int $actor_user_id, string $file_id ): ?array;

	/**
	 * Stage.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return array<string,mixed>
	 */
	public function stage( array $record ): array;
}
