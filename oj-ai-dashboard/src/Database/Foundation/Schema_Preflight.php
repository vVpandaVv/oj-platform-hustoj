<?php
/**
 * Side-effect-free database schema preflight.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Database\Foundation;

use InvalidArgumentException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Classifies fresh, owned, partial, unknown, and drifted schemas.
 */
final class Schema_Preflight {
	/**
	 * Catalog.
	 *
	 * @var Schema_Catalog
	 */
	private Schema_Catalog $catalog;

	/**
	 * Initialize schema preflight dependencies and state.
	 *
	 * @param Schema_Catalog $catalog Catalog.
	 */
	public function __construct( Schema_Catalog $catalog ) {
		$this->catalog = $catalog;
	}

	/**
	 * Inspect a physical table snapshot without performing discovery or DDL.
	 *
	 * The caller supplies exact table names obtained by the infrastructure
	 * adapter. Names outside the literal add-on namespace are deliberately
	 * ignored; they are never adopted as candidates.
	 *
	 * @param array<int,string>        $existing_tables Exact physical names.
	 * @param array<string,mixed>|null $installation Installation owner row.
	 * @return Schema_Preflight_Result
	 */
	public function inspect( array $existing_tables, ?array $installation ): Schema_Preflight_Result {
		$observed   = $this->normalize_table_names( $existing_tables );
		$expected   = $this->catalog->table_names();
		$literal    = array_values(
			array_filter(
				$observed,
				static fn ( string $name ): bool => str_starts_with( $name, 'oj_ai_' )
			)
		);
		$present    = array_values( array_intersect( $expected, $literal ) );
		$missing    = array_values( array_diff( $expected, $present ) );
		$unexpected = array_values( array_diff( $literal, $expected ) );
		$issues     = array();

		foreach ( $unexpected as $table ) {
			$issues[] = array(
				'code'    => 'unexpected_addon_table',
				'message' => 'An unknown literal add-on table already exists.',
				'table'   => $table,
			);
		}

		if ( array() === $present ) {
			if ( null !== $installation ) {
				$issues[] = array(
					'code'    => 'orphan_installation_marker',
					'message' => 'An installation owner row exists without its target tables.',
				);
			}
			if ( array() === $issues ) {
				return new Schema_Preflight_Result(
					Schema_Preflight_Result::FRESH,
					array(),
					array(),
					$missing,
					array()
				);
			}

			return $this->blocked( $issues, $present, $missing, $unexpected );
		}

		if ( count( $present ) !== count( $expected ) ) {
			$issues[] = array(
				'code'    => 'partial_schema',
				'message' => 'Only part of the frozen 45-table schema exists; repair approval is required.',
			);

			return $this->blocked( $issues, $present, $missing, $unexpected );
		}

		if ( null === $installation ) {
			$issues[] = array(
				'code'    => 'unowned_schema',
				'message' => 'All target names exist without a verified add-on owner marker.',
			);

			return $this->blocked( $issues, $present, $missing, $unexpected );
		}

		$checks = array(
			'addon_id'           => array( Schema_Catalog::ADDON_ID, 'installation_addon_mismatch' ),
			'owner_marker'       => array( $this->catalog->owner_marker(), 'installation_owner_mismatch' ),
			'schema_version'     => array( Schema_Catalog::SCHEMA_VERSION, 'schema_version_mismatch' ),
			'schema_fingerprint' => array( $this->catalog->fingerprint(), 'schema_fingerprint_mismatch' ),
		);
		foreach ( $checks as $field => $expectation ) {
			$actual = isset( $installation[ $field ] ) ? (string) $installation[ $field ] : '';
			if ( $actual !== $expectation[0] ) {
				$issues[] = array(
					'code'    => $expectation[1],
					'message' => 'The installation owner contract does not match the frozen schema.',
				);
			}
		}

		if ( array() !== $issues ) {
			return $this->blocked( $issues, $present, $missing, $unexpected );
		}

		return new Schema_Preflight_Result(
			Schema_Preflight_Result::READY,
			array(),
			$present,
			array(),
			array()
		);
	}

	/**
	 * Normalize table names.
	 *
	 * @param array<array-key,mixed> $names Observed names to validate.
	 * @return array<int,string>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function normalize_table_names( array $names ): array {
		$normalized = array();
		foreach ( $names as $name ) {
			if ( ! is_string( $name ) || '' === $name ) {
				throw new InvalidArgumentException( 'Observed table names must be non-empty strings.' );
			}
			$normalized[ $name ] = true;
		}
		$names = array_keys( $normalized );
		sort( $names, SORT_STRING );

		return $names;
	}

	/**
	 * Blocked.
	 *
	 * @param array<int,array{code:string,message:string,table?:string}> $issues Issues.
	 * @param array<int,string>                                          $present Present tables.
	 * @param array<int,string>                                          $missing Missing tables.
	 * @param array<int,string>                                          $unexpected Unexpected tables.
	 * @return Schema_Preflight_Result
	 */
	private function blocked(
		array $issues,
		array $present,
		array $missing,
		array $unexpected
	): Schema_Preflight_Result {
		return new Schema_Preflight_Result(
			Schema_Preflight_Result::BLOCKED,
			$issues,
			$present,
			$missing,
			$unexpected
		);
	}
}
