<?php
/**
 * Own worksheet pdf source query port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\WorksheetPdf\Port;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads the learner's current assignment-to-publication binding for C51.
 */
interface Own_Worksheet_Pdf_Source_Query_Port_Interface {
	/**
	 * Find available.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $assignment_id Distribution public identifier.
	 * @param string $now_utc Now utc.
	 * @return array{
	 *   assignment_id:string,
	 *   assignment_state:string,
	 *   assignment_lock_version:int,
	 *   context_type:string,
	 *   context_id:string,
	 *   binding_context_revision:string,
	 *   publication_id:string,
	 *   publication_hash:string,
	 *   target_id:string,
	 *   target_state:string
	 * }|null
	 */
	public function find_available( int $actor_user_id, string $assignment_id, string $now_utc ): ?array;
}
