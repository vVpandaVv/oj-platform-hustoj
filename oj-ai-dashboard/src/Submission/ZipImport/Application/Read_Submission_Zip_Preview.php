<?php
/**
 * Read submission zip preview.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\ZipImport\Application;

use OJAI\Dashboard\Submission\ZipImport\Port\Submission_Zip_Preview_Query_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Frozen A-DS08-Q35 query boundary.
 */
final class Read_Submission_Zip_Preview {
	/**
	 * Initialize submission zip preview dependencies and state.
	 *
	 * @param Submission_Zip_Preview_Query_Port_Interface $query Query.
	 */
	public function __construct( private Submission_Zip_Preview_Query_Port_Interface $query ) {
	}

	/**
	 * Authorization snapshot.
	 *
	 * @param string $upload_id Upload id.
	 * @return array<string,mixed>|null
	 */
	public function authorization_snapshot( string $upload_id ): ?array {
		new Public_Id( $upload_id );
		return $this->query->authorization_snapshot( $upload_id );
	}

	/**
	 * Execute submission zip preview.
	 *
	 * @param string $upload_id Upload id.
	 * @param string $context_type Context type.
	 * @param string $context_id Context id.
	 * @param string $context_revision Context revision.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function execute( string $upload_id, string $context_type, string $context_id, string $context_revision ): array {
		new Public_Id( $upload_id );
		$result = $this->query->find_preview( $upload_id, $context_type, $context_id, $context_revision );
		if ( null === $result ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Zip_Import_Failure::because( Zip_Import_Failure::NOT_FOUND );
		}
		return $result;
	}
}
