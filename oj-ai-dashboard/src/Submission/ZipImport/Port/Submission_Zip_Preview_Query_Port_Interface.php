<?php
/**
 * Submission zip preview query port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\ZipImport\Port;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads only the authorization snapshot and safe Q35 projection.
 */
interface Submission_Zip_Preview_Query_Port_Interface {
	/**
	 * Authorization snapshot.
	 *
	 * @param string $upload_id Upload id.
	 * @return array<string,mixed>|null
	 */
	public function authorization_snapshot( string $upload_id ): ?array;

	/**
	 * Find preview.
	 *
	 * @param string $upload_id Upload id.
	 * @param string $context_type Context type.
	 * @param string $context_id Context id.
	 * @param string $context_revision Context revision.
	 * @return array<string,mixed>|null
	 */
	public function find_preview( string $upload_id, string $context_type, string $context_id, string $context_revision ): ?array;
}
