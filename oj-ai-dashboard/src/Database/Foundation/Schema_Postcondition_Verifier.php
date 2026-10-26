<?php
/**
 * Exact schema postcondition verifier.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Database\Foundation;

use InvalidArgumentException;
use OJAI\Dashboard\Database\Foundation\Port\Schema_Inspector_Port_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compares physical metadata with the frozen catalog after every DDL step.
 */
final class Schema_Postcondition_Verifier {
	/**
	 * Catalog.
	 *
	 * @var Schema_Catalog
	 */
	private Schema_Catalog $catalog;
	/**
	 * Inspector.
	 *
	 * @var Schema_Inspector_Port_Interface
	 */
	private Schema_Inspector_Port_Interface $inspector;

	/**
	 * Initialize schema postcondition verifier dependencies and state.
	 *
	 * @param Schema_Catalog                  $catalog Catalog.
	 * @param Schema_Inspector_Port_Interface $inspector Inspector.
	 */
	public function __construct( Schema_Catalog $catalog, Schema_Inspector_Port_Interface $inspector ) {
		$this->catalog   = $catalog;
		$this->inspector = $inspector;
	}

	/**
	 * Verify migration.
	 *
	 * @param Migration_Definition $migration Migration.
	 * @return Schema_Postcondition_Result
	 */
	public function verify_migration( Migration_Definition $migration ): Schema_Postcondition_Result {
		return $this->verify_tables( $migration->table_names() );
	}

	/**
	 * Verify catalog.
	 *
	 * @return Schema_Postcondition_Result
	 */
	public function verify_catalog(): Schema_Postcondition_Result {
		$expected   = $this->catalog->table_names();
		$observed   = $this->inspector->literal_table_names();
		$unexpected = array_values( array_diff( $observed, $expected ) );
		$issues     = array();

		foreach ( $unexpected as $table_name ) {
			$issues[] = array(
				'code'  => 'unexpected_addon_table',
				'table' => $table_name,
			);
		}

		$result = $this->verify_tables( $expected );
		$issues = array_merge( $issues, $result->issues() );

		return new Schema_Postcondition_Result(
			$issues,
			array() === $issues ? $this->catalog->fingerprint() : null
		);
	}

	/**
	 * Verify tables.
	 *
	 * @param array<int,string> $table_names Table names.
	 * @return Schema_Postcondition_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function verify_tables( array $table_names ): Schema_Postcondition_Result {
		$catalog     = $this->catalog->tables();
		$table_names = array_values( array_unique( $table_names ) );
		$definitions = array();
		foreach ( $table_names as $table_name ) {
			if ( ! isset( $catalog[ $table_name ] ) ) {
				throw new InvalidArgumentException( 'Postconditions may only inspect frozen literal tables.' );
			}
			$definitions[ $table_name ] = $catalog[ $table_name ];
		}

		return $this->verify_definitions( $definitions );
	}

	/**
	 * Verify definitions.
	 *
	 * @param array<array-key,mixed> $definitions Definitions to validate against the catalog.
	 * @return Schema_Postcondition_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function verify_definitions( array $definitions ): Schema_Postcondition_Result {
		$catalog = $this->catalog->tables();
		foreach ( $definitions as $table_name => $definition ) {
			if ( ! is_string( $table_name )
				|| ! $definition instanceof Table_Definition
				|| $definition->name() !== $table_name
				|| ! isset( $catalog[ $table_name ] ) ) {
				throw new InvalidArgumentException( 'Postconditions require owned literal table definitions.' );
			}
		}

		$table_names    = array_keys( $definitions );
		$observed_names = $this->inspector->literal_table_names();
		$present        = array_values( array_intersect( $table_names, $observed_names ) );
		$issues         = array();
		foreach ( array_diff( $table_names, $present ) as $table_name ) {
			$issues[] = array(
				'code'  => 'table_missing',
				'table' => $table_name,
			);
		}

		$metadata = array() === $present ? array() : $this->inspector->metadata( $present );
		foreach ( $present as $table_name ) {
			if ( ! isset( $metadata[ $table_name ] ) ) {
				$issues[] = array(
					'code'  => 'table_metadata_missing',
					'table' => $table_name,
				);
				continue;
			}

			$expected = Schema_Metadata_Contract::expected( $definitions[ $table_name ] );
			$actual   = $metadata[ $table_name ];
			if ( isset( $actual['indexes'] ) && is_array( $actual['indexes'] ) ) {
				$actual['indexes'] = Schema_Metadata_Contract::normalize_observed_indexes( $definitions[ $table_name ], $actual['indexes'] );
			}
			foreach ( array( 'engine', 'collation', 'columns', 'indexes', 'foreign_keys' ) as $part ) {
				if ( ! array_key_exists( $part, $actual ) || $actual[ $part ] !== $expected[ $part ] ) {
					$issues[] = array(
						'code'   => 'table_' . $part . '_mismatch',
						'table'  => $table_name,
						'object' => $part,
					);
				}
			}
		}

		return new Schema_Postcondition_Result( $issues );
	}
}
