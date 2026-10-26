<?php
/**
 * Student report detail source query port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\Report\Port;

use OJAI\Dashboard\Reporting\Report\Domain\Report_Filter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Student report detail source query port interface.
 */
interface Student_Report_Detail_Source_Query_Port_Interface {
	/**
	 * For student.
	 *
	 * @param int                $actor_user_id Authenticated actor from the access context.
	 * @param string             $context_type Context type.
	 * @param string             $context_id Context id.
	 * @param string             $context_revision Context revision.
	 * @param string             $student_target_id Student target id.
	 * @param string             $cursor Continuation cursor from the previous page.
	 * @param int                $limit Maximum number of entries requested.
	 * @param Report_Filter|null $filter Filter.
	 * @return null|array{source:array<string,mixed>,next_cursor:string}
	 */
	public function for_student(
		int $actor_user_id,
		string $context_type,
		string $context_id,
		string $context_revision,
		string $student_target_id,
		string $cursor,
		int $limit,
		?Report_Filter $filter = null
	): ?array;
}
