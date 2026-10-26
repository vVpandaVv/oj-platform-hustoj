<?php
/**
 * Submission bundle source port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\SubmissionBundle\Port;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Submission-owner projection consumed by the C50 export boundary.
 */
interface Submission_Bundle_Source_Port_Interface {
	/**
	 * Read submission bundle source port interface.
	 *
	 * @param string $assignment_id Distribution public identifier.
	 * @param string $context_type Context type.
	 * @param string $context_id Context id.
	 * @param string $context_revision Context revision.
	 * @param bool   $include_allowed_originals Include allowed originals.
	 * @return array<string,mixed>|null
	 */
	public function read(
		string $assignment_id,
		string $context_type,
		string $context_id,
		string $context_revision,
		bool $include_allowed_originals
	): ?array;
}
