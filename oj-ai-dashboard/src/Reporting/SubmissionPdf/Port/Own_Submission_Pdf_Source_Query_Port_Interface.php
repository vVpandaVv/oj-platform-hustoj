<?php
/**
 * Own submission pdf source query port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\SubmissionPdf\Port;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads the learner-owned current official submission binding for C67.
 */
interface Own_Submission_Pdf_Source_Query_Port_Interface {
	/**
	 * Find current official.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $submission_id Submission public identifier.
	 * @return array{
	 *   assignment_id:string,
	 *   assignment_state:string,
	 *   attachment_set_hash:string,
	 *   context_type:string,
	 *   context_id:string,
	 *   context_revision:string,
	 *   official_revision_id:string,
	 *   official_revision_hash:string,
	 *   publication_id:string,
	 *   publication_hash:string,
	 *   submission_id:string,
	 *   submission_lock_version:int,
	 *   submission_state:string,
	 *   target_id:string,
	 *   target_state:string
	 * }|null
	 */
	public function find_current_official( int $actor_user_id, string $submission_id ): ?array;
}
