<?php
/**
 * Exact immutable worksheet publication print-source boundary.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Authoring\Port;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Allows another owner to consume only a publication ID and matching hash.
 */
interface Worksheet_Publication_Print_Source_Query_Port_Interface {
	/**
	 * Find exact publication.
	 *
	 * @param string $publication_id Publication id.
	 * @param string $expected_publication_hash Expected publication hash.
	 * @return array<string,mixed>|null
	 */
	public function find_exact_publication( string $publication_id, string $expected_publication_hash ): ?array;
}
