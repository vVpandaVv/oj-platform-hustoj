<?php
/**
 * Deterministic schema installation and forward-repair runner.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Database\Foundation;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use OJAI\Dashboard\Database\Foundation\Port\Schema_Inspector_Port_Interface;
use OJAI\Dashboard\Database\Foundation\Port\Schema_Installer_Port_Interface;
use OJAI\Dashboard\Database\Foundation\Port\Schema_Repairer_Port_Interface;
use OJAI\Dashboard\Database\Foundation\Port\Schema_Ledger_Port_Interface;
use OJAI\Dashboard\Database\Foundation\Port\Schema_Lock_Port_Interface;
use OJAI\Dashboard\Database\Foundation\Port\Schema_Statement_Executor_Port_Interface;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Applies immutable statements one at a time and verifies every postcondition.
 */
final class Schema_Installation_Runner implements Schema_Installer_Port_Interface, Schema_Repairer_Port_Interface {
	private const LOCK_TIMEOUT_SECONDS = 5;

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
	 * Lock.
	 *
	 * @var Schema_Lock_Port_Interface
	 */
	private Schema_Lock_Port_Interface $lock;
	/**
	 * Ledger.
	 *
	 * @var Schema_Ledger_Port_Interface
	 */
	private Schema_Ledger_Port_Interface $ledger;
	/**
	 * Executor.
	 *
	 * @var Schema_Statement_Executor_Port_Interface
	 */
	private Schema_Statement_Executor_Port_Interface $executor;
	/**
	 * Verifier.
	 *
	 * @var Schema_Postcondition_Verifier
	 */
	private Schema_Postcondition_Verifier $verifier;
	/**
	 * Clock.
	 *
	 * @var Closure
	 */
	private Closure $clock;

	/**
	 * Initialize schema installation runner dependencies and state.
	 *
	 * @param Schema_Catalog                           $catalog Catalog.
	 * @param Migration_Plan                           $plan Plan.
	 * @param Schema_Preflight                         $preflight Preflight.
	 * @param Schema_Inspector_Port_Interface          $inspector Inspector.
	 * @param Schema_Lock_Port_Interface               $lock Lock.
	 * @param Schema_Ledger_Port_Interface             $ledger Ledger.
	 * @param Schema_Statement_Executor_Port_Interface $executor Executor.
	 * @param Schema_Postcondition_Verifier            $verifier Verifier.
	 * @param Closure|null                             $clock Clock.
	 */
	public function __construct(
		Schema_Catalog $catalog,
		Migration_Plan $plan,
		Schema_Preflight $preflight,
		Schema_Inspector_Port_Interface $inspector,
		Schema_Lock_Port_Interface $lock,
		Schema_Ledger_Port_Interface $ledger,
		Schema_Statement_Executor_Port_Interface $executor,
		Schema_Postcondition_Verifier $verifier,
		?Closure $clock = null
	) {
		$this->catalog   = $catalog;
		$this->plan      = $plan;
		$this->preflight = $preflight;
		$this->inspector = $inspector;
		$this->lock      = $lock;
		$this->ledger    = $ledger;
		$this->executor  = $executor;
		$this->verifier  = $verifier;
		$this->clock     = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
	}

	/**
	 * Install.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $correlation_id Request correlation identifier for diagnostics.
	 * @return Schema_Installation_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function install( int $actor_user_id, string $correlation_id ): Schema_Installation_Result {
		if ( $actor_user_id < 0 ) {
			throw new InvalidArgumentException( 'Installation actor IDs cannot be negative.' );
		}
		$this->assert_correlation_id( $correlation_id );

		return $this->run( $actor_user_id, $correlation_id, null );
	}

	/**
	 * Repair.
	 *
	 * @param Schema_Repair_Authorization $authorization Authorization.
	 * @return Schema_Installation_Result
	 */
	public function repair( Schema_Repair_Authorization $authorization ): Schema_Installation_Result {
		$now = $this->now();
		if ( ! $authorization->valid_for( $this->catalog, $now ) ) {
			return new Schema_Installation_Result(
				Schema_Installation_Result::BLOCKED,
				'schema_repair_authorization_invalid',
				$authorization->correlation_id()
			);
		}

		return $this->run(
			$authorization->actor_user_id(),
			$authorization->correlation_id(),
			$authorization
		);
	}

	/**
	 * Run schema installation runner.
	 *
	 * @param int                              $actor_user_id Authenticated actor from the access context.
	 * @param string                           $correlation_id Request correlation identifier for diagnostics.
	 * @param Schema_Repair_Authorization|null $authorization Authorization.
	 * @return Schema_Installation_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function run(
		int $actor_user_id,
		string $correlation_id,
		?Schema_Repair_Authorization $authorization
	): Schema_Installation_Result {
		$is_repair = null !== $authorization;

		try {
			$initial                     = $this->inspect_state();
			$resume_issues               = array();
			$resume_missing_installation = false;
			$resumable_initial_create    = ! $is_repair && $this->can_resume_initial_create(
				$initial,
				$resume_issues,
				$resume_missing_installation
			);
			$gate                        = $resumable_initial_create ? null : $this->gate_before_lock( $initial, $is_repair, $correlation_id, $resume_issues );
			if ( null !== $gate ) {
				return $gate;
			}
		} catch ( Throwable $error ) {
			return $this->failed_result( $error, $correlation_id );
		}

		try {
			$acquired = $this->lock->acquire( Migration_Plan::LOCK_NAME, self::LOCK_TIMEOUT_SECONDS );
		} catch ( Throwable $error ) {
			return $this->failed_result( $error, $correlation_id );
		}
		if ( ! $acquired ) {
			return new Schema_Installation_Result(
				Schema_Installation_Result::LOCK_UNAVAILABLE,
				'schema_lock_unavailable',
				$correlation_id
			);
		}

		$completed_this_attempt      = array();
		$active_migration            = null;
		$active_checkpoint           = array();
		$migration_started           = false;
		$installation_available      = false;
		$ledger_available            = false;
		$is_upgrade                  = false;
		$resumable_initial_create    = false;
		$resume_missing_installation = false;

		try {
			$current                  = $this->inspect_state();
			$resume_issues            = array();
			$resumable_initial_create = ! $is_repair && $this->can_resume_initial_create(
				$current,
				$resume_issues,
				$resume_missing_installation
			);
			$gate                     = $resumable_initial_create ? null : $this->gate_before_lock( $current, $is_repair, $correlation_id, $resume_issues );
			if ( null !== $gate ) {
				return $gate;
			}
			$is_upgrade = $this->is_supported_upgrade_state( $current );

			$names                  = $current['names'];
			$installation_available = in_array( 'oj_ai_installations', $names, true ) && null !== $current['installation'];
			$ledger_available       = in_array( 'oj_ai_schema_migrations', $names, true );
			$records                = $ledger_available ? $this->ledger->all_migrations() : array();
			$ledger_issues          = $this->ledger_issues( $records, false );
			if ( array() !== $ledger_issues ) {
				return new Schema_Installation_Result(
					Schema_Installation_Result::BLOCKED,
					'schema_migration_ledger_invalid',
					$correlation_id,
					array(),
					$ledger_issues
				);
			}

			foreach ( $this->plan->migrations() as $migration ) {
				$active_migration  = $migration;
				$migration_started = false;
				$existing_record   = $records[ $migration->id() ] ?? null;
				$existing_tables   = array_values( array_intersect( $migration->table_names(), $names ) );

				if ( is_array( $existing_record ) && Migration_Ledger_State::APPLIED === (string) ( $existing_record['state'] ?? '' ) ) {
					$postcondition = $this->verifier->verify_definitions(
						$this->plan->postcondition_definitions( $migration, $records )
					);
					if ( ! $postcondition->passed() ) {
						return new Schema_Installation_Result(
							Schema_Installation_Result::BLOCKED,
							'schema_applied_migration_drift',
							$correlation_id,
							array(),
							$postcondition->issues()
						);
					}
					continue;
				}

				if ( $migration->creates_tables()
					&& ! $is_repair && ! $resumable_initial_create && ( null !== $existing_record || array() !== $existing_tables ) ) {
					return new Schema_Installation_Result(
						Schema_Installation_Result::BLOCKED,
						'schema_partial_requires_explicit_repair',
						$correlation_id
					);
				}

				if ( $migration->creates_tables() && $is_repair && null === $existing_record
					&& array() !== $existing_tables && $this->plan->migrations()[0]->id() !== $migration->id() ) {
					return new Schema_Installation_Result(
						Schema_Installation_Result::BLOCKED,
						'schema_migration_ledger_missing',
						$correlation_id
					);
				}
				if ( $migration->alters_tables()
					&& count( $existing_tables ) !== count( $migration->table_names() ) ) {
					return new Schema_Installation_Result(
						Schema_Installation_Result::BLOCKED,
						'schema_migration_target_missing',
						$correlation_id
					);
				}

				$checkpoint_tables = $migration->creates_tables() ? $existing_tables : array();
				$active_checkpoint = $this->checkpoint(
					$migration,
					$checkpoint_tables,
					$correlation_id,
					$authorization,
					'applying',
					'pending'
				);

				$is_bootstrap = $this->plan->migrations()[0]->id() === $migration->id();
				if ( ! $is_bootstrap ) {
					$this->begin_migration( $migration, $existing_record, $active_checkpoint );
					$migration_started = true;
				}

				$statements = $migration->statements();
				if ( $migration->creates_tables() ) {
					$baseline = $this->plan->baseline_tables() + $this->catalog->tables();
					foreach ( $migration->table_names() as $position => $table_name ) {
						if ( in_array( $table_name, $names, true ) ) {
							continue;
						}

						$this->executor->execute( $statements[ $position ] );
						$completed_this_attempt[] = $table_name;
						$names[]                  = $table_name;
						$postcondition            = $this->verifier->verify_definitions(
							array( $table_name => $baseline[ $table_name ] )
						);
						if ( ! $postcondition->passed() ) {
							throw new \RuntimeException( 'schema_postcondition_failed' );
						}

						if ( 'oj_ai_installations' === $table_name && ! $installation_available ) {
							$this->ledger->create_installation(
								$this->catalog->owner_marker(),
								Schema_Catalog::SCHEMA_VERSION,
								$this->catalog->fingerprint(),
								Installation_State::INSTALLING,
								$actor_user_id,
								$this->now_string()
							);
							$installation_available = true;
						}
						if ( 'oj_ai_schema_migrations' === $table_name ) {
							$ledger_available = true;
						}

						$existing_tables[] = $table_name;
						$active_checkpoint = $this->checkpoint(
							$migration,
							$existing_tables,
							$correlation_id,
							$authorization,
							'applying',
							'pending'
						);
						if ( $migration_started ) {
							$this->ledger->save_checkpoint( $migration->id(), $active_checkpoint );
						}
					}
				} else {
					$target_definitions = $this->plan->postcondition_definitions( $migration, $records );
					$target_result      = $this->verifier->verify_definitions( $target_definitions );
					if ( ! $target_result->passed() ) {
						$baseline        = array_intersect_key(
							$this->plan->baseline_tables(),
							array_flip( $migration->table_names() )
						);
						$baseline_result = $this->verifier->verify_definitions( $baseline );
						if ( ! $baseline_result->passed() ) {
							throw new \RuntimeException( 'schema_existing_table_drift' );
						}
						foreach ( $migration->table_names() as $position => $table_name ) {
							$this->executor->execute( $statements[ $position ] );
							$completed_this_attempt[] = $table_name;
						}
						$target_result = $this->verifier->verify_definitions( $target_definitions );
						if ( ! $target_result->passed() ) {
							throw new \RuntimeException( 'schema_postcondition_failed' );
						}
					}
					$existing_tables   = $migration->table_names();
					$active_checkpoint = $this->checkpoint(
						$migration,
						$existing_tables,
						$correlation_id,
						$authorization,
						'applying',
						'passed'
					);
					if ( $migration_started ) {
						$this->ledger->save_checkpoint( $migration->id(), $active_checkpoint );
					}
				}

				if ( $is_bootstrap && $resume_missing_installation
					&& $ledger_available && ! $installation_available ) {
					if ( 0 !== $this->ledger->installation_row_count() ) {
						throw new \RuntimeException( 'schema_installation_ledger_occupied' );
					}
					$this->ledger->create_installation(
						$this->catalog->owner_marker(),
						Schema_Catalog::SCHEMA_VERSION,
						$this->catalog->fingerprint(),
						Installation_State::INSTALLING,
						$actor_user_id,
						$this->now_string()
					);
					$installation_available = true;
				}
				if ( $is_bootstrap ) {
					if ( ! $ledger_available || ! $installation_available ) {
						throw new \RuntimeException( 'schema_bootstrap_incomplete' );
					}
					$this->begin_migration( $migration, $existing_record, $active_checkpoint );
					$migration_started = true;
				}

				$postcondition = $this->verifier->verify_definitions(
					$this->plan->postcondition_definitions( $migration, $records )
				);
				if ( ! $postcondition->passed() ) {
					throw new \RuntimeException( 'schema_postcondition_failed' );
				}
				$active_checkpoint = $this->checkpoint(
					$migration,
					$migration->table_names(),
					$correlation_id,
					$authorization,
					'completed',
					'passed'
				);
				$this->ledger->finish_migration(
					$migration->id(),
					Migration_Ledger_State::APPLIED,
					$active_checkpoint,
					null,
					$this->now_string()
				);
				$migration_started           = false;
				$records[ $migration->id() ] = array(
					'migration_id'  => $migration->id(),
					'checksum'      => $migration->checksum(),
					'state'         => Migration_Ledger_State::APPLIED,
					'attempt_count' => is_array( $existing_record ) ? (int) ( $existing_record['attempt_count'] ?? 0 ) + 1 : 1,
				);
			}

			$full = $this->verifier->verify_catalog();
			if ( ! $full->passed() || $full->verified_fingerprint() !== $this->catalog->fingerprint() ) {
				throw new \RuntimeException( 'schema_catalog_postcondition_failed' );
			}
			$ledger_issues = $this->ledger_issues( $this->ledger->all_migrations(), true );
			if ( array() !== $ledger_issues ) {
				throw new \RuntimeException( 'schema_migration_ledger_invalid' );
			}

			$this->ledger->update_installation(
				Schema_Catalog::SCHEMA_VERSION,
				$this->catalog->fingerprint(),
				Installation_State::REGISTRATION_PENDING,
				$actor_user_id,
				$this->now_string(),
				true
			);

			$status = $is_repair
				? Schema_Installation_Result::REPAIRED
				: ( $is_upgrade ? Schema_Installation_Result::UPGRADED : Schema_Installation_Result::INSTALLED );
			return new Schema_Installation_Result(
				$status,
				$is_repair ? 'schema_repaired' : ( $is_upgrade ? 'schema_upgraded' : 'schema_installed' ),
				$correlation_id,
				$completed_this_attempt
			);
		} catch ( Throwable $error ) {
			$error_code = $this->stable_error_code( $error );
			if ( $migration_started && $active_migration instanceof Migration_Definition && $ledger_available ) {
				$active_checkpoint['phase']         = 'failed';
				$active_checkpoint['postcondition'] = 'failed';
				try {
					$this->ledger->finish_migration(
						$active_migration->id(),
						Migration_Ledger_State::REPAIR_REQUIRED,
						$active_checkpoint,
						$error_code,
						$this->now_string()
					);
				} catch ( Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- This optional step must not replace the surrounding operation outcome.
					// The physical snapshot remains the source for the next fail-closed preflight.
				}
			}
			if ( $installation_available ) {
				try {
					$this->ledger->update_installation(
						Schema_Catalog::SCHEMA_VERSION,
						$this->catalog->fingerprint(),
						Installation_State::MAINTENANCE_REQUIRED,
						$actor_user_id,
						$this->now_string(),
						false
					);
				} catch ( Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- This optional step must not replace the surrounding operation outcome.
					// Never hide the original stable failure code with a secondary ledger error.
				}
			}

			$status = Schema_Installation_Result::FAILED;
			try {
				if ( array() !== array_intersect( $this->catalog->table_names(), $this->inspector->literal_table_names() ) ) {
					$status = Schema_Installation_Result::REPAIR_REQUIRED;
				}
			} catch ( Throwable ) {
				$status = Schema_Installation_Result::REPAIR_REQUIRED;
			}

			return new Schema_Installation_Result(
				$status,
				$error_code,
				$correlation_id,
				$completed_this_attempt,
				array( array( 'code' => $error_code ) )
			);
		} finally {
			$this->lock->release( Migration_Plan::LOCK_NAME );
		}
	}

	/**
	 * Inspect state.
	 *
	 * @return array{names:array<int,string>,installation:?array<string,mixed>,preflight:Schema_Preflight_Result}
	 */
	private function inspect_state(): array {
		$names        = $this->inspector->literal_table_names();
		$installation = in_array( 'oj_ai_installations', $names, true )
			? $this->ledger->find_installation()
			: null;

		return array(
			'names'        => $names,
			'installation' => $installation,
			'preflight'    => $this->preflight->inspect( $names, $installation ),
		);
	}

	/**
	 * Gate before lock.
	 *
	 * @param array{names:array<int,string>,installation:?array<string,mixed>,preflight:Schema_Preflight_Result} $state State.
	 * @param bool                                                                                               $is_repair Is repair.
	 * @param string                                                                                             $correlation_id Request correlation identifier for diagnostics.
	 * @param array                                                                                              $resume_issues Resume issues.
	 * @return Schema_Installation_Result|null
	 */
	private function gate_before_lock(
		array $state,
		bool $is_repair,
		string $correlation_id,
		array $resume_issues = array()
	): ?Schema_Installation_Result {
		$preflight = $state['preflight'];
		if ( $this->is_supported_upgrade_state( $state ) ) {
			if ( $is_repair ) {
				return new Schema_Installation_Result(
					Schema_Installation_Result::BLOCKED,
					'schema_repair_not_required',
					$correlation_id
				);
			}
			$records = $this->ledger->all_migrations();
			$issues  = $this->upgrade_ledger_issues(
				$records,
				(string) ( $state['installation']['schema_version'] ?? '' )
			);
			if ( array() !== $issues ) {
				return new Schema_Installation_Result(
					Schema_Installation_Result::BLOCKED,
					'schema_migration_ledger_invalid',
					$correlation_id,
					array(),
					$issues
				);
			}
			$physical = $this->verifier->verify_definitions(
				$this->plan->upgrade_source_tables( $state['installation'] )
			);
			if ( ! $physical->passed() ) {
				return new Schema_Installation_Result(
					Schema_Installation_Result::BLOCKED,
					'schema_catalog_drift',
					$correlation_id,
					array(),
					$physical->issues()
				);
			}
			return null;
		}
		if ( $preflight->is_ready() ) {
			$records     = $this->ledger->all_migrations();
			$state_value = (string) ( $state['installation']['state'] ?? '' );
			$ready_state = in_array(
				$state_value,
				array( Installation_State::REGISTRATION_PENDING, Installation_State::RUNTIME_READY ),
				true
			);
			if ( $is_repair && ! $ready_state ) {
				$issues = $this->ledger_issues( $records, false );
				if ( array() !== $issues ) {
					return new Schema_Installation_Result(
						Schema_Installation_Result::BLOCKED,
						'schema_migration_ledger_invalid',
						$correlation_id,
						array(),
						$issues
					);
				}
				$verified_existing = $this->verify_existing_tables( $state['names'], $records );
				if ( ! $verified_existing->passed() ) {
					return new Schema_Installation_Result(
						Schema_Installation_Result::BLOCKED,
						'schema_existing_table_drift',
						$correlation_id,
						array(),
						$verified_existing->issues()
					);
				}
				return null;
			}
			$physical = $this->verifier->verify_catalog();
			if ( ! $physical->passed() ) {
				return new Schema_Installation_Result(
					Schema_Installation_Result::BLOCKED,
					'schema_catalog_drift',
					$correlation_id,
					array(),
					$physical->issues()
				);
			}

			$issues = $this->ledger_issues( $records, true );
			if ( array() === $issues && $ready_state ) {
				return new Schema_Installation_Result(
					Schema_Installation_Result::ALREADY_READY,
					'schema_already_ready',
					$correlation_id
				);
			}
			if ( ! $is_repair ) {
				return new Schema_Installation_Result(
					Schema_Installation_Result::BLOCKED,
					'schema_explicit_repair_required',
					$correlation_id,
					array(),
					$issues
				);
			}
		}

		if ( $preflight->can_install() ) {
			return $is_repair
				? new Schema_Installation_Result(
					Schema_Installation_Result::BLOCKED,
					'schema_repair_not_required',
					$correlation_id
				)
				: null;
		}

		if ( ! $is_repair ) {
			return new Schema_Installation_Result(
				Schema_Installation_Result::BLOCKED,
				'schema_preflight_blocked',
				$correlation_id,
				array(),
				array_merge( $preflight->issues(), $resume_issues )
			);
		}

		$ownership_issues = $this->ownership_issues( $state['installation'], $preflight );
		if ( array() !== $ownership_issues ) {
			return new Schema_Installation_Result(
				Schema_Installation_Result::BLOCKED,
				'schema_owner_conflict',
				$correlation_id,
				array(),
				$ownership_issues
			);
		}

		$present       = array_values( array_intersect( $this->catalog->table_names(), $state['names'] ) );
		$records       = in_array( 'oj_ai_schema_migrations', $state['names'], true )
			? $this->ledger->all_migrations() : array();
		$ledger_issues = $this->ledger_issues( $records, false );
		if ( array() !== $ledger_issues ) {
			return new Schema_Installation_Result(
				Schema_Installation_Result::BLOCKED,
				'schema_migration_ledger_invalid',
				$correlation_id,
				array(),
				$ledger_issues
			);
		}
		$verified_existing = $this->verify_existing_tables( $present, $records );
		if ( ! $verified_existing->passed() ) {
			return new Schema_Installation_Result(
				Schema_Installation_Result::BLOCKED,
				'schema_existing_table_drift',
				$correlation_id,
				array(),
				$verified_existing->issues()
			);
		}

		return null;
	}

	/**
	 * Select one strictly verified automatic restart path for initial CREATE.
	 *
	 * @param array{names:array<int,string>,installation:?array<string,mixed>,preflight:Schema_Preflight_Result} $state State.
	 * @param array                                                                                              &$resume_issues Resume issues.
	 * @param bool                                                                                               &$missing_installation Missing installation.
	 * @return bool
	 */
	private function can_resume_initial_create(
		array $state,
		array &$resume_issues,
		bool &$missing_installation
	): bool {
		$missing_installation = false;
		if ( null !== $state['installation'] ) {
			return $this->can_resume_known_create_postcondition_failure( $state, $resume_issues );
		}

		$missing_installation = $this->can_recreate_missing_initial_installation( $state, $resume_issues );

		return $missing_installation;
	}

	/**
	 * Recreate only the owner row lost after the first verified CREATE boundary.
	 *
	 * @param array{names:array<int,string>,installation:?array<string,mixed>,preflight:Schema_Preflight_Result} $state State.
	 * @param array                                                                                              &$resume_issues Resume issues.
	 * @return bool
	 */
	private function can_recreate_missing_initial_installation( array $state, array &$resume_issues ): bool {
		$resume_issues = array();
		if ( 1 !== count( $state['names'] ) || ! in_array( 'oj_ai_installations', $state['names'], true ) ) {
			$resume_issues[] = array( 'code' => 'initial_resume_installation_missing' );
			return false;
		}
		if ( 0 !== $this->ledger->installation_row_count() ) {
			$resume_issues[] = array( 'code' => 'initial_resume_installation_rows_present' );
			return false;
		}

		$physical = $this->verify_existing_tables( array( 'oj_ai_installations' ), array() );
		if ( ! $physical->passed() ) {
			$resume_issues = $physical->issues();
		}

		return $physical->passed();
	}

	/**
	 * Permit one narrow compatibility restart for the 0.1.2 FK-index verifier defect.
	 *
	 * The restart remains CREATE-only and is accepted only when ownership, ledger,
	 * checkpoint, and every existing table match the immutable baseline. A failed
	 * maintenance-state write may leave the same attempt in INSTALLING, so that
	 * state is accepted only after all remaining checks pass. General drift and
	 * every interrupted ALTER continue to require explicit C75 repair.
	 *
	 * @param array{names:array<int,string>,installation:?array<string,mixed>,preflight:Schema_Preflight_Result} $state State.
	 * @param array                                                                                              &$resume_issues Resume issues.
	 * @return bool
	 */
	private function can_resume_known_create_postcondition_failure( array $state, array &$resume_issues ): bool {
		$resume_issues = array();
		$installation  = $state['installation'];
		if ( ! is_array( $installation ) ) {
			$resume_issues[] = array( 'code' => 'initial_resume_installation_missing' );
			return false;
		}
		$installation_checks = array(
			'initial_resume_installation_state_mismatch'   => in_array(
				(string) ( $installation['state'] ?? '' ),
				array( Installation_State::INSTALLING, Installation_State::MAINTENANCE_REQUIRED ),
				true
			),
			'initial_resume_installation_addon_mismatch'   => Schema_Catalog::ADDON_ID === (string) ( $installation['addon_id'] ?? '' ),
			'initial_resume_installation_owner_mismatch'   => hash_equals(
				$this->catalog->owner_marker(),
				(string) ( $installation['owner_marker'] ?? '' )
			),
			'initial_resume_installation_version_mismatch' => Schema_Catalog::SCHEMA_VERSION === (string) ( $installation['schema_version'] ?? '' ),
			'initial_resume_installation_fingerprint_mismatch' => hash_equals(
				$this->catalog->fingerprint(),
				(string) ( $installation['schema_fingerprint'] ?? '' )
			),
		);
		foreach ( $installation_checks as $code => $passed ) {
			if ( ! $passed ) {
				$resume_issues[] = array( 'code' => $code );
			}
		}
		if ( array() !== $resume_issues ) {
			return false;
		}

		$expected_names = $this->catalog->table_names();
		if ( array() !== array_diff( $state['names'], $expected_names ) ) {
			$resume_issues[] = array( 'code' => 'initial_resume_table_inventory_mismatch' );
			return false;
		}

		$migrations = $this->plan->migrations();
		if ( count( $migrations ) < 2
			|| ! $migrations[0]->creates_tables()
			|| ! $migrations[1]->creates_tables()
			|| array() !== array_diff( $migrations[0]->table_names(), $state['names'] )
			|| array() === array_intersect( $migrations[1]->table_names(), $state['names'] ) ) {
			$resume_issues[] = array( 'code' => 'initial_resume_plan_unsupported' );
			return false;
		}

		$records = $this->ledger->all_migrations();
		if ( 2 !== count( $records )
			|| array() !== array_diff( array_keys( $records ), array( $migrations[0]->id(), $migrations[1]->id() ) ) ) {
			$resume_issues[] = array( 'code' => 'initial_resume_chain_invalid' );
			return false;
		}
		$ledger_issues = $this->ledger_issues( $records, false );
		if ( array() !== $ledger_issues ) {
			$resume_issues = $ledger_issues;
			return false;
		}

		$bootstrap = $records[ $migrations[0]->id() ] ?? null;
		$create    = $records[ $migrations[1]->id() ] ?? null;
		if ( ! is_array( $bootstrap )
			|| ! is_array( $create )
			|| Migration_Ledger_State::APPLIED !== (string) ( $bootstrap['state'] ?? '' )
			|| Migration_Ledger_State::REPAIR_REQUIRED !== (string) ( $create['state'] ?? '' )
			|| 'schema_postcondition_failed' !== (string) ( $create['error_code'] ?? '' ) ) {
			$resume_issues[] = array( 'code' => 'initial_resume_chain_invalid' );
			return false;
		}

		$checkpoint = Schema_Migration_Checkpoint::validated_payload( $migrations[1], $create );
		if ( ! is_array( $checkpoint ) || false !== ( $checkpoint['repair'] ?? null ) ) {
			$resume_issues[] = array( 'code' => 'initial_resume_checkpoint_invalid' );
			return false;
		}

		$present  = array_values( array_intersect( $expected_names, $state['names'] ) );
		$physical = $this->verify_existing_tables( $present, $records );

		if ( ! $physical->passed() ) {
			$resume_issues = $physical->issues();
		}
		return $physical->passed();
	}

	/**
	 * Is supported upgrade state.
	 *
	 * @param array{names:array<int,string>,installation:?array<string,mixed>,preflight:Schema_Preflight_Result} $state State.
	 * @return bool
	 */
	private function is_supported_upgrade_state( array $state ): bool {
		if ( ! $this->plan->is_supported_upgrade_installation( $state['installation'] ) ) {
			return false;
		}
		$source_names = array_keys( $this->plan->upgrade_source_tables( $state['installation'] ) );
		return array() === array_diff( $source_names, $state['names'] )
			&& array() === array_diff( $state['names'], $source_names );
	}

	/**
	 * Upgrade ledger issues.
	 *
	 * @param array<string,array<string,mixed>> $records Records.
	 * @param string                            $source_version Source version.
	 * @return array<int,array<string,mixed>>
	 */
	private function upgrade_ledger_issues( array $records, string $source_version ): array {
		$issues = $this->ledger_issues( $records, false );
		foreach ( $this->plan->migrations() as $migration ) {
			$record          = $records[ $migration->id() ] ?? null;
			$must_be_applied = version_compare( $migration->target_version(), $source_version, '<=' );
			if ( $must_be_applied ) {
				if ( ! is_array( $record ) ) {
					$issues[] = array(
						'code'         => 'schema_migration_missing',
						'migration_id' => $migration->id(),
					);
				} elseif ( Migration_Ledger_State::APPLIED !== (string) ( $record['state'] ?? '' ) ) {
					$issues[] = array(
						'code'         => 'schema_migration_not_applied',
						'migration_id' => $migration->id(),
					);
				}
				continue;
			}
			if ( null !== $record ) {
				$issues[] = array(
					'code'         => 'schema_forward_migration_unexpected',
					'migration_id' => $migration->id(),
				);
			}
		}

		return $issues;
	}

	/**
	 * Verify existing tables.
	 *
	 * @param array<int,string>                 $table_names Existing owned names.
	 * @param array<string,array<string,mixed>> $records Ledger records.
	 * @return Schema_Postcondition_Result
	 */
	private function verify_existing_tables( array $table_names, array $records ): Schema_Postcondition_Result {
		$baseline = $this->plan->baseline_tables() + $this->catalog->tables();
		$target   = $this->catalog->tables();
		$issues   = array();

		foreach ( $table_names as $table_name ) {
			$expected = $baseline[ $table_name ];
			$flexible = false;
			foreach ( $this->plan->migrations() as $migration ) {
				if ( ! $migration->alters_tables() || ! in_array( $table_name, $migration->table_names(), true ) ) {
					continue;
				}
				$state = (string) ( $records[ $migration->id() ]['state'] ?? '' );
				if ( Migration_Ledger_State::APPLIED === $state ) {
					$expected = $target[ $table_name ];
					$flexible = false;
				} elseif ( in_array( $state, array( Migration_Ledger_State::RUNNING, Migration_Ledger_State::REPAIR_REQUIRED ), true ) ) {
					$flexible = true;
				}
			}

			$result = $this->verifier->verify_definitions( array( $table_name => $expected ) );
			if ( $result->passed() ) {
				continue;
			}
			if ( $flexible ) {
				$alternate        = $expected === $baseline[ $table_name ]
					? $target[ $table_name ] : $baseline[ $table_name ];
				$alternate_result = $this->verifier->verify_definitions( array( $table_name => $alternate ) );
				if ( $alternate_result->passed() ) {
					continue;
				}
			}
			$issues = array_merge( $issues, $result->issues() );
		}

		return new Schema_Postcondition_Result( $issues );
	}

	/**
	 * Ownership issues.
	 *
	 * @param array|null              $installation Installation.
	 * @param Schema_Preflight_Result $preflight Preflight.
	 * @return array<int,array<string,mixed>>
	 */
	private function ownership_issues( ?array $installation, Schema_Preflight_Result $preflight ): array {
		$issues = array();
		foreach ( $preflight->unexpected_tables() as $table_name ) {
			$issues[] = array(
				'code'  => 'unexpected_addon_table',
				'table' => $table_name,
			);
		}
		if ( null === $installation ) {
			$issues[] = array( 'code' => 'unowned_schema' );
			return $issues;
		}

		$expected = array(
			'addon_id'           => Schema_Catalog::ADDON_ID,
			'owner_marker'       => $this->catalog->owner_marker(),
			'schema_version'     => Schema_Catalog::SCHEMA_VERSION,
			'schema_fingerprint' => $this->catalog->fingerprint(),
		);
		foreach ( $expected as $field => $value ) {
			if ( ! isset( $installation[ $field ] ) || ! hash_equals( $value, (string) $installation[ $field ] ) ) {
				$issues[] = array( 'code' => 'installation_' . $field . '_mismatch' );
			}
		}

		return $issues;
	}

	/**
	 * Ledger issues.
	 *
	 * @param array<string,array<string,mixed>> $records Records.
	 * @param bool                              $require_complete Require complete.
	 * @return array<int,array<string,mixed>>
	 */
	private function ledger_issues( array $records, bool $require_complete ): array {
		$definitions = array();
		foreach ( $this->plan->migrations() as $migration ) {
			$definitions[ $migration->id() ] = $migration;
		}
		$issues = array();
		foreach ( $records as $migration_id => $record ) {
			if ( ! isset( $definitions[ $migration_id ] ) ) {
				$issues[] = array(
					'code'         => 'unknown_schema_migration',
					'migration_id' => $migration_id,
				);
				continue;
			}
			if ( $migration_id !== (string) ( $record['migration_id'] ?? '' ) ) {
				$issues[] = array(
					'code'         => 'schema_migration_id_mismatch',
					'migration_id' => $migration_id,
				);
			}
			if ( ! isset( $record['checksum'] ) || ! hash_equals( $definitions[ $migration_id ]->checksum(), (string) $record['checksum'] ) ) {
				$issues[] = array(
					'code'         => 'schema_checksum_mismatch',
					'migration_id' => $migration_id,
				);
			}
			$record_state = (string) ( $record['state'] ?? '' );
			if ( ! in_array( $record_state, Migration_Ledger_State::all(), true ) ) {
				$issues[] = array(
					'code'         => 'schema_migration_state_invalid',
					'migration_id' => $migration_id,
				);
			} elseif ( Migration_Ledger_State::BLOCKED === $record_state ) {
				$issues[] = array(
					'code'         => 'schema_migration_blocked',
					'migration_id' => $migration_id,
				);
			}
			$attempt_count = $record['attempt_count'] ?? null;
			if ( ( ! is_int( $attempt_count ) && ! ( is_string( $attempt_count ) && ctype_digit( $attempt_count ) ) )
				|| (int) $attempt_count < 1 ) {
				$issues[] = array(
					'code'         => 'schema_migration_attempt_invalid',
					'migration_id' => $migration_id,
				);
			}
			if ( Migration_Ledger_State::PLANNED !== $record_state && in_array( $record_state, Migration_Ledger_State::all(), true ) ) {
				$issues = array_merge( $issues, Schema_Migration_Checkpoint::issues( $definitions[ $migration_id ], $record ) );
			}
		}

		if ( $require_complete ) {
			foreach ( $definitions as $migration_id => $migration ) {
				if ( ! isset( $records[ $migration_id ] ) ) {
					$issues[] = array(
						'code'         => 'schema_migration_missing',
						'migration_id' => $migration_id,
					);
					continue;
				}
				if ( Migration_Ledger_State::APPLIED !== (string) ( $records[ $migration_id ]['state'] ?? '' ) ) {
					$issues[] = array(
						'code'         => 'schema_migration_not_applied',
						'migration_id' => $migration_id,
					);
				}
			}
		}

		return $issues;
	}

	/**
	 * Begin migration.
	 *
	 * @param Migration_Definition     $migration Migration.
	 * @param array<string,mixed>|null $record Record.
	 * @param array<string,mixed>      $checkpoint Checkpoint.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function begin_migration(
		Migration_Definition $migration,
		?array $record,
		array $checkpoint
	): void {
		if ( null === $record ) {
			$this->ledger->start_migration( $migration, $checkpoint, $this->now_string() );
			return;
		}

		$state = (string) ( $record['state'] ?? '' );
		if ( ! in_array( $state, array( Migration_Ledger_State::PLANNED, Migration_Ledger_State::RUNNING, Migration_Ledger_State::REPAIR_REQUIRED ), true ) ) {
			throw new \RuntimeException( 'schema_migration_cannot_resume' );
		}
		$this->ledger->resume_migration(
			$migration,
			max( 2, (int) ( $record['attempt_count'] ?? 0 ) + 1 ),
			$checkpoint,
			$this->now_string()
		);
	}

	/**
	 * Checkpoint.
	 *
	 * @param Migration_Definition             $migration Migration.
	 * @param array<int,string>                $completed_tables Completed physical tables.
	 * @param string                           $correlation_id Request correlation identifier for diagnostics.
	 * @param Schema_Repair_Authorization|null $authorization Authorization.
	 * @param string                           $phase Phase.
	 * @param string                           $postcondition Postcondition.
	 * @return array<string,mixed>
	 */
	private function checkpoint(
		Migration_Definition $migration,
		array $completed_tables,
		string $correlation_id,
		?Schema_Repair_Authorization $authorization,
		string $phase,
		string $postcondition
	): array {
		$completed_tables = array_values( array_intersect( $migration->table_names(), $completed_tables ) );
		$remaining        = array_values( array_diff( $migration->table_names(), $completed_tables ) );
		$checkpoint       = array(
			'phase'            => $phase,
			'cursor'           => count( $completed_tables ),
			'completed_tables' => $completed_tables,
			'next_table'       => $remaining[0] ?? null,
			'postcondition'    => $postcondition,
			'correlation_id'   => $correlation_id,
			'repair'           => null !== $authorization,
		);
		if ( null !== $authorization ) {
			$checkpoint['approval_token_hash']    = $authorization->approval_token_hash();
			$checkpoint['backup_evidence_hash']   = $authorization->backup_evidence_hash();
			$checkpoint['backup_verified_at_utc'] = $authorization->backup_verified_at_utc()
				->setTimezone( new DateTimeZone( 'UTC' ) )
				->format( 'Y-m-d H:i:s' );
		}

		return $checkpoint;
	}

	/**
	 * Failed result.
	 *
	 * @param Throwable $error Error.
	 * @param string    $correlation_id Request correlation identifier for diagnostics.
	 * @return Schema_Installation_Result
	 */
	private function failed_result( Throwable $error, string $correlation_id ): Schema_Installation_Result {
		$code = $this->stable_error_code( $error );

		return new Schema_Installation_Result(
			Schema_Installation_Result::FAILED,
			$code,
			$correlation_id,
			array(),
			array( array( 'code' => $code ) )
		);
	}

	/**
	 * Stable error code.
	 *
	 * @param Throwable $error Error.
	 * @return string
	 */
	private function stable_error_code( Throwable $error ): string {
		$message = $error->getMessage();

		return 1 === preg_match( '/^[a-z][a-z0-9_]{2,99}$/', $message )
			? $message
			: 'schema_installation_failed';
	}

	/**
	 * Reject invalid correlation id.
	 *
	 * @param string $correlation_id Request correlation identifier for diagnostics.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_correlation_id( string $correlation_id ): void {
		if ( 1 !== preg_match( '/^[A-Za-z0-9._:-]{8,100}$/', $correlation_id ) ) {
			throw new InvalidArgumentException( 'Installation requires a stable correlation ID.' );
		}
	}

	/**
	 * Now.
	 *
	 * @return DateTimeImmutable
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function now(): DateTimeImmutable {
		$now = ( $this->clock )();
		if ( ! $now instanceof DateTimeImmutable ) {
			throw new \LogicException( 'The schema clock must return DateTimeImmutable.' );
		}

		return $now->setTimezone( new DateTimeZone( 'UTC' ) );
	}

	/**
	 * Now string.
	 *
	 * @return string
	 */
	private function now_string(): string {
		return $this->now()->format( 'Y-m-d H:i:s' );
	}
}
