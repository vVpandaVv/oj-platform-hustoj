<?php
/**
 * Assignment evaluation batch source boundary.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Port;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads one stable target page without exposing learner identity.
 */
interface Assignment_Evaluation_Batch_Source_Port_Interface {
	/**
	 * Page.
	 *
	 * @param string $assignment_id Distribution public identifier.
	 * @param string $context_type Context type.
	 * @param string $context_id Context id.
	 * @param string $context_revision Context revision.
	 * @param string $source_cutoff_utc Source cutoff utc.
	 * @param string $item_cursor Item cursor.
	 * @param int    $limit Maximum number of entries requested.
	 * @return array{
	 *   assignment_id:string,
	 *   current_revision:string,
	 *   state:string,
	 *   context_type:string,
	 *   context_id:string,
	 *   context_revision:string,
	 *   source_cutoff_utc:string,
	 *   items:array<int,array<string,mixed>>,
	 *   next_cursor:string
	 * }|null
	 */
	public function page(
		string $assignment_id,
		string $context_type,
		string $context_id,
		string $context_revision,
		string $source_cutoff_utc,
		string $item_cursor,
		int $limit
	): ?array;
}
