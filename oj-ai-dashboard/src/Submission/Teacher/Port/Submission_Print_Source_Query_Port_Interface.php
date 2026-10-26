<?php
/**
 * Exact official submission print-source query boundary.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Teacher\Port;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps Submission-owned integrity checks outside the Reporting module.
 */
interface Submission_Print_Source_Query_Port_Interface {
	/**
	 * Returns the same safe projection as Q14 only when the requested revision
	 * is the submission root's current official revision.
	 *
	 * @param string $revision_id Revision id.
	 * @return array<string,mixed>|null
	 */
	public function find_official_revision( string $revision_id ): ?array;
}
