<?php
/**
 * WordPress schema repair state.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\SchemaRepair\Infrastructure;

use OJAI\Dashboard\Database\Foundation\Infrastructure\WordPress_Schema_Inspector;
use OJAI\Dashboard\Database\Foundation\Infrastructure\WordPress_Schema_Ledger_Reader;
use OJAI\Dashboard\Database\Foundation\Installation_State;
use OJAI\Dashboard\Database\Foundation\Schema_Catalog;
use OJAI\Dashboard\Operations\SchemaRepair\Domain\Schema_Repair_State;
use OJAI\Dashboard\Operations\SchemaRepair\Port\Schema_Repair_State_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WordPress schema repair state.
 */
final class WordPress_Schema_Repair_State implements Schema_Repair_State_Port_Interface {
	/**
	 * Initialize WordPress schema repair state dependencies and state.
	 *
	 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
	 * @param object|null    $database Database.
	 */
	public function __construct(
		private Canonical_Json $canonical,
		private ?object $database = null
	) {
	}

	/**
	 * Inspect.
	 *
	 * @return Schema_Repair_State
	 */
	public function inspect(): Schema_Repair_State {
		try {
			$database = $this->database ?? ( $GLOBALS['wpdb'] ?? null );
			if ( ! is_object( $database ) ) {
				return Schema_Repair_State::blocked( 'schema_repair_database_unavailable' );
			}
			$catalog   = new Schema_Catalog();
			$inspector = new WordPress_Schema_Inspector( $database );
			$names     = $inspector->literal_table_names();
			if ( ! in_array( 'oj_ai_installations', $names, true ) ) {
				return array() === array_intersect( $catalog->table_names(), $names )
					? Schema_Repair_State::not_required()
					: Schema_Repair_State::blocked( 'schema_repair_owner_unavailable' );
			}

			$ledger       = new WordPress_Schema_Ledger_Reader( $database );
			$installation = $ledger->find_installation();
			if ( ! is_array( $installation ) || ! $this->owned_by_catalog( $installation, $catalog ) ) {
				return Schema_Repair_State::blocked( 'schema_repair_owner_unavailable' );
			}

			$state = (string) ( $installation['state'] ?? '' );
			if ( in_array( $state, array( Installation_State::REGISTRATION_PENDING, Installation_State::RUNTIME_READY ), true ) ) {
				return Schema_Repair_State::not_required();
			}
			if ( ! in_array( $state, array( Installation_State::INSTALLING, Installation_State::MAINTENANCE_REQUIRED ), true ) ) {
				return Schema_Repair_State::blocked( 'schema_repair_state_blocked' );
			}

			$records = in_array( 'oj_ai_schema_migrations', $names, true ) ? $ledger->all_migrations() : array();
			$summary = array();
			foreach ( $records as $migration_id => $record ) {
				$summary[ $migration_id ] = array(
					'attempt_count'   => (int) ( $record['attempt_count'] ?? 0 ),
					'checkpoint_hash' => isset( $record['checkpoint_hash'] ) ? (string) $record['checkpoint_hash'] : null,
					'checksum'        => isset( $record['checksum'] ) ? (string) $record['checksum'] : null,
					'error_code'      => isset( $record['error_code'] ) ? (string) $record['error_code'] : null,
					'migration_id'    => (string) ( $record['migration_id'] ?? '' ),
					'state'           => (string) ( $record['state'] ?? '' ),
				);
			}
			ksort( $summary, SORT_STRING );
			$present = array_values( array_intersect( $catalog->table_names(), $names ) );
			sort( $present, SORT_STRING );
			$repair_state_hash = hash(
				'sha256',
				$this->canonical->encode(
					array(
						'catalog_fingerprint' => $catalog->fingerprint(),
						'installation_state'  => $state,
						'migrations'          => $summary,
						'present_tables'      => $present,
					)
				)
			);

			return Schema_Repair_State::repairable( $state, $catalog->fingerprint(), $repair_state_hash );
		} catch ( Throwable ) {
			return Schema_Repair_State::blocked( 'schema_repair_state_unavailable' );
		}
	}

	/**
	 * Owned by catalog.
	 *
	 * @param array<string,mixed> $installation Installation.
	 * @param Schema_Catalog      $catalog Catalog.
	 * @return bool
	 */
	private function owned_by_catalog( array $installation, Schema_Catalog $catalog ): bool {
		$expected = array(
			'addon_id'           => Schema_Catalog::ADDON_ID,
			'owner_marker'       => $catalog->owner_marker(),
			'schema_version'     => Schema_Catalog::SCHEMA_VERSION,
			'schema_fingerprint' => $catalog->fingerprint(),
		);
		foreach ( $expected as $field => $value ) {
			if ( ! isset( $installation[ $field ] ) || ! hash_equals( $value, (string) $installation[ $field ] ) ) {
				return false;
			}
		}

		return true;
	}
}
