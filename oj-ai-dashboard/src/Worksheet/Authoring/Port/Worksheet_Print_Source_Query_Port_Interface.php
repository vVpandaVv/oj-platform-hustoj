<?php
/**
 * Exact worksheet print-source query port.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Authoring\Port;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads an owner-authorized immutable source without creating an artifact.
 */
interface Worksheet_Print_Source_Query_Port_Interface {
	/**
	 * Find owned.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $source_type Source type.
	 * @param string $source_public_id Source public id.
	 * @return array{
	 *   source_type:string,
	 *   source_public_id:string,
	 *   source_hash:string,
	 *   worksheet_id:string,
	 *   revision_id:string,
	 *   revision_document_hash:string,
	 *   revision_validation_hash:string,
	 *   publication_id:?string,
	 *   publication_hash:?string,
	 *   publication_state:?string,
	 *   projection_manifest_version:?string,
	 *   teacher_document:?array<string,mixed>,
	 *   print_envelope:?array<string,mixed>,
	 *   print_profile_hash:?string
	 * }|null
	 */
	public function find_owned(
		int $actor_user_id,
		string $source_type,
		string $source_public_id
	): ?array;
}
