<?php
/**
 * Purge preview builder.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\Purge\Application;

use OJAI\Dashboard\Database\Foundation\Schema_Catalog;
use OJAI\Dashboard\Operations\Purge\Port\Purge_Inventory_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the exact deterministic C71 preview reused by C72 revalidation.
 */
final class Purge_Preview_Builder {
	public const PROFILE = 'oj_ai.purge_preview@1.0.0';

	/**
	 * Initialize purge preview builder dependencies and state.
	 *
	 * @param Purge_Inventory_Port_Interface $inventory Inventory.
	 * @param Schema_Catalog                 $catalog Catalog.
	 * @param Canonical_Json                 $canonical Canonical JSON encoder used for stable digests.
	 */
	public function __construct(
		private Purge_Inventory_Port_Interface $inventory,
		private Schema_Catalog $catalog,
		private Canonical_Json $canonical
	) {
	}

	/**
	 * Build.
	 *
	 * @param string $scope_type Scope type.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function build( string $scope_type ): array {
		if ( Preview_Purge_Command::SCOPE_ADDON !== $scope_type ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Purge_Failure::because( Purge_Failure::INPUT_INVALID );
		}

		$snapshot        = $this->inventory->inspect();
		$expected_owners = array_keys( $this->catalog->owner_counts() );
		if ( $expected_owners !== array_keys( $snapshot->owner_rows() ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Purge_Failure::because( Purge_Failure::INVENTORY_UNAVAILABLE );
		}

		$scope                   = array(
			'type'      => $scope_type,
			'public_id' => null,
		);
		$scope_descriptor        = array(
			'addon_id'           => Schema_Catalog::ADDON_ID,
			'schema_version'     => Schema_Catalog::SCHEMA_VERSION,
			'schema_fingerprint' => $this->catalog->fingerprint(),
			'table_names'        => $this->catalog->table_names(),
			'scope'              => $scope,
		);
		$scope_hash              = hash( 'sha256', $this->canonical->encode( $scope_descriptor ) );
		$preview                 = array(
			'profile'         => self::PROFILE,
			'scope'           => $scope,
			'scope_hash'      => $scope_hash,
			'table_count'     => Schema_Catalog::TABLE_COUNT,
			'total_row_count' => $snapshot->total_row_count(),
			'owner_rows'      => $snapshot->owner_rows(),
			'private_files'   => array(
				'file_count'       => $snapshot->private_file_count(),
				'blob_bytes'       => $snapshot->private_blob_bytes(),
				'legal_hold_count' => $snapshot->legal_hold_count(),
			),
			'incomplete_jobs' => array(
				'total'   => $snapshot->incomplete_job_total(),
				'by_type' => $snapshot->incomplete_jobs(),
			),
		);
		$preview['preview_hash'] = hash( 'sha256', $this->canonical->encode( $preview ) );

		return $preview;
	}
}
