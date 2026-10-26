<?php
/**
 * Read-only Runtime schema readiness verifier.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Database\Foundation;

use OJAI\Dashboard\Database\Foundation\Port\Schema_Inspector_Port_Interface;
use OJAI\Dashboard\Database\Foundation\Port\Schema_Ledger_Reader_Port_Interface;
use OJAI\Dashboard\Database\Foundation\Port\Schema_Runtime_Readiness_Port_Interface;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Verifies owner, exact physical metadata, installation state, and migration ledger.
 */
final class Schema_Runtime_Readiness_Verifier implements Schema_Runtime_Readiness_Port_Interface {
	/**
	 * Catalog.
	 *
	 * @var Schema_Catalog
	 */
	private Schema_Catalog $catalog;
	/**
	 * Plan.
	 *
	 * @var Migration_Plan
	 */
	private Migration_Plan $plan;
	/**
	 * Preflight.
	 *
	 * @var Schema_Preflight
	 */
	private Schema_Preflight $preflight;
	/**
	 * Inspector.
	 *
	 * @var Schema_Inspector_Port_Interface
	 */
	private Schema_Inspector_Port_Interface $inspector;
	/**
	 * Ledger.
	 *
	 * @var Schema_Ledger_Reader_Port_Interface
	 */
	private Schema_Ledger_Reader_Port_Interface $ledger;
	/**
	 * Postconditions.
	 *
	 * @var Schema_Postcondition_Verifier
	 */
	private Schema_Postcondition_Verifier $postconditions;

	/**
	 * Initialize schema runtime readiness verifier dependencies and state.
	 *
	 * @param Schema_Catalog                      $catalog Catalog.
	 * @param Migration_Plan                      $plan Plan.
	 * @param Schema_Preflight                    $preflight Preflight.
	 * @param Schema_Inspector_Port_Interface     $inspector Inspector.
	 * @param Schema_Ledger_Reader_Port_Interface $ledger Ledger.
	 * @param Schema_Postcondition_Verifier       $postconditions Postconditions.
	 */
	public function __construct(
		Schema_Catalog $catalog,
		Migration_Plan $plan,
		Schema_Preflight $preflight,
		Schema_Inspector_Port_Interface $inspector,
		Schema_Ledger_Reader_Port_Interface $ledger,
		Schema_Postcondition_Verifier $postconditions
	) {
		$this->catalog        = $catalog;
		$this->plan           = $plan;
		$this->preflight      = $preflight;
		$this->inspector      = $inspector;
		$this->ledger         = $ledger;
		$this->postconditions = $postconditions;
	}

	/**
	 * Verify schema runtime readiness verifier.
	 *
	 * @return Schema_Runtime_Readiness_Result
	 */
	public function verify(): Schema_Runtime_Readiness_Result {
		try {
			$names        = $this->inspector->literal_table_names();
			$installation = in_array( 'oj_ai_installations', $names, true )
				? $this->ledger->find_installation()
				: null;
			$preflight    = $this->preflight->inspect( $names, $installation );

			if ( $preflight->can_install() ) {
				return $this->unavailable( 'schema_not_installed' );
			}
			if ( ! $preflight->is_ready() ) {
				return $this->unavailable(
					'schema_preflight_blocked',
					$this->issue_codes( $preflight->issues() )
				);
			}

			$installation_state = (string) ( $installation['state'] ?? '' );
			if ( ! in_array(
				$installation_state,
				array( Installation_State::REGISTRATION_PENDING, Installation_State::RUNTIME_READY ),
				true
			) ) {
				return $this->unavailable( 'schema_installation_not_ready', array( 'installation_state_' . $this->safe_state( $installation_state ) ) );
			}

			$postcondition = $this->postconditions->verify_catalog();
			if (
				! $postcondition->passed()
				|| $postcondition->verified_fingerprint() !== $this->catalog->fingerprint()
			) {
				return $this->unavailable(
					'schema_catalog_drift',
					$this->issue_codes( $postcondition->issues() )
				);
			}

			$ledger_issues = $this->ledger_issues( $this->ledger->all_migrations() );
			if ( array() !== $ledger_issues ) {
				return $this->unavailable( 'schema_migration_ledger_invalid', $ledger_issues );
			}

			return new Schema_Runtime_Readiness_Result(
				Schema_Runtime_Readiness_Result::READY,
				'schema_runtime_ready'
			);
		} catch ( Throwable ) {
			return $this->unavailable( 'schema_runtime_verification_failed' );
		}
	}

	/**
	 * Ledger issues.
	 *
	 * @param array<string,array<string,mixed>> $records Migration records.
	 * @return array<int,string>
	 */
	private function ledger_issues( array $records ): array {
		$expected = array();
		foreach ( $this->plan->migrations() as $migration ) {
			$expected[ $migration->id() ] = $migration;
		}

		$issues = array();
		foreach ( $records as $migration_id => $record ) {
			if ( ! isset( $expected[ $migration_id ] ) ) {
				$issues[] = 'unknown_schema_migration';
				continue;
			}
			if ( $migration_id !== (string) ( $record['migration_id'] ?? '' ) ) {
				$issues[] = 'schema_migration_id_mismatch';
			}
			if (
				! isset( $record['checksum'] )
				|| ! hash_equals( $expected[ $migration_id ]->checksum(), (string) $record['checksum'] )
			) {
				$issues[] = 'schema_checksum_mismatch';
			}
			if ( Migration_Ledger_State::APPLIED !== (string) ( $record['state'] ?? '' ) ) {
				$issues[] = 'schema_migration_not_applied';
			}
			foreach ( Schema_Migration_Checkpoint::issues( $expected[ $migration_id ], $record ) as $checkpoint_issue ) {
				$issues[] = $checkpoint_issue['code'];
			}
		}

		foreach ( array_keys( $expected ) as $migration_id ) {
			if ( ! isset( $records[ $migration_id ] ) ) {
				$issues[] = 'schema_migration_missing';
			}
		}

		return array_values( array_unique( $issues ) );
	}

	/**
	 * Issue codes.
	 *
	 * @param array<int,array<string,mixed>> $issues Issues.
	 * @return array<int,string>
	 */
	private function issue_codes( array $issues ): array {
		$codes = array();
		foreach ( $issues as $issue ) {
			if ( isset( $issue['code'] ) && is_string( $issue['code'] ) ) {
				$codes[] = $issue['code'];
			}
		}

		return array_values( array_unique( $codes ) );
	}

	/**
	 * Safe state.
	 *
	 * @param string $state State.
	 * @return string
	 */
	private function safe_state( string $state ): string {
		$state = preg_replace( '/[^a-z0-9_]+/', '_', strtolower( $state ) );

		return is_string( $state ) && '' !== trim( $state, '_' ) ? trim( $state, '_' ) : 'unknown';
	}

	/**
	 * Unavailable.
	 *
	 * @param string            $code Code.
	 * @param array<int,string> $issues Issues.
	 * @return Schema_Runtime_Readiness_Result
	 */
	private function unavailable( string $code, array $issues = array() ): Schema_Runtime_Readiness_Result {
		return new Schema_Runtime_Readiness_Result(
			Schema_Runtime_Readiness_Result::UNAVAILABLE,
			$code,
			$issues
		);
	}
}
