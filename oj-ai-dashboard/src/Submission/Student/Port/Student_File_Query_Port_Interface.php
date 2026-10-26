<?php
/**
 * Student file query port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Submission\Student\Port;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Internal metadata only; never serialize these rows into a student response.
 */
interface Student_File_Query_Port_Interface {
	/**
	 * Asset.
	 *
	 * @param int    $actor Actor.
	 * @param string $assignment_id Distribution public identifier.
	 * @param string $asset_id Asset id.
	 * @return array<string,mixed>|null
	 */
	public function asset( int $actor, string $assignment_id, string $asset_id ): ?array;
	/**
	 * Attachment.
	 *
	 * @param int    $actor Actor.
	 * @param string $submission_id Submission public identifier.
	 * @param string $attachment_id Attachment id.
	 * @return array<string,mixed>|null
	 */
	public function attachment( int $actor, string $submission_id, string $attachment_id ): ?array;
}
