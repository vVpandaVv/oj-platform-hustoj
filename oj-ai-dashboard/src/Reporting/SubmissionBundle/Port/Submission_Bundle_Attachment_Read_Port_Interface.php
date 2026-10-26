<?php
/**
 * Submission bundle attachment read port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\SubmissionBundle\Port;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Submission bundle attachment read port interface.
 */
interface Submission_Bundle_Attachment_Read_Port_Interface {
	/**
	 * Readable path.
	 *
	 * @param string              $submission_id Submission public identifier.
	 * @param array<string,mixed> $attachment Attachment.
	 * @return string
	 */
	public function readable_path( string $submission_id, array $attachment ): string;
}
