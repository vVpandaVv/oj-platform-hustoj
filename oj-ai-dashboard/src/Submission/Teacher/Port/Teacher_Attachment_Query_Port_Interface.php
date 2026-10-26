<?php
/**
 * Teacher attachment query port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Submission\Teacher\Port;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Teacher attachment query port interface.
 */
interface Teacher_Attachment_Query_Port_Interface {
	/**
	 * Find teacher attachment query port interface.
	 *
	 * @param string $submission_id Submission public identifier.
	 * @param string $attachment_id Attachment id.
	 * @return array<string,mixed>|null Internal metadata, never a REST projection.
	 */
	public function find( string $submission_id, string $attachment_id ): ?array;
}
