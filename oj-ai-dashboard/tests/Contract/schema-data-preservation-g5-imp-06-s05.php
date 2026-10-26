<?php
/**
 * G5-IMP-06-S05 schema data-preservation integration contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Database\Foundation\Installation_State;
use OJAI\Dashboard\Database\Foundation\Migration_Plan;
use OJAI\Dashboard\Database\Foundation\Port\Schema_Runtime_Readiness_Port_Interface;
use OJAI\Dashboard\Database\Foundation\Schema_Catalog;
use OJAI\Dashboard\Database\Foundation\Schema_Installation_Result;
use OJAI\Dashboard\Database\Foundation\Schema_Metadata_Contract;
use OJAI\Dashboard\Database\Foundation\Schema_Postcondition_Verifier;
use OJAI\Dashboard\Database\Foundation\Schema_Preflight;
use OJAI\Dashboard\Database\Foundation\Schema_Runtime_Readiness_Verifier;
use OJAI\Dashboard\Operations\SchemaRepair\Application\Repair_Addon_Schema;
use OJAI\Dashboard\Operations\SchemaRepair\Application\Repair_Addon_Schema_Command;
use OJAI\Dashboard\Operations\SchemaRepair\Application\Repair_Addon_Schema_Result;
use OJAI\Dashboard\Operations\SchemaRepair\Application\Schema_Repair_Failure;
use OJAI\Dashboard\Operations\SchemaRepair\Domain\Schema_Repair_State;
use OJAI\Dashboard\Operations\SchemaRepair\Port\Schema_Repair_Receipt_Port_Interface;
use OJAI\Dashboard\Operations\SchemaRepair\Port\Schema_Repair_State_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;

$addon_root = dirname( __DIR__, 2 );

ob_start();
require $addon_root . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'Contract'
	. DIRECTORY_SEPARATOR . 'database-infrastructure.php';
$foundation_output = (string) ob_get_clean();

/**
 * G5 s05 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_s05_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * G5 s05 same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_s05_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException(
			$message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true )
		);
	}
}

/**
 * Opaque application rows, options, and actual private bytes protected from schema operations.
 */
final class G5_S05_Data_Plane {
	/**
	 * Rows.
	 *
	 * @var array<string,array<int,array<string,mixed>>>
	 */
	public array $rows = array();

	/**
	 * Options.
	 *
	 * @var array<string,mixed>
	 */
	public array $options = array(
		'oj_ai_dashboard_version'              => '0.1.7',
		'oj_ai_dashboard_operator_preferences' => array(
			'report_period' => '30d',
			'locale'        => 'ko_KR',
		),
	);

	/**
	 * Files.
	 *
	 * @var array<string,array{path:string,storage_key:string,mime_type:string}>
	 */
	private array $files = array();

	/**
	 * External sources.
	 *
	 * @var array<string,mixed>
	 */
	private array $external_sources = array(
		'platform_context' => array(
			'type'     => 'site',
			'id'       => 'site-preservation-01',
			'revision' => '17',
		),
		'legacy_source'    => array(
			'profile'     => 'read_only',
			'fingerprint' => 'legacy-source-preserve-01',
		),
	);

	/**
	 * Initialize g5 s05 data plane dependencies and state.
	 *
	 * @param Schema_Catalog $catalog Catalog.
	 * @param bool           $seed_owned_rows Seed owned rows.
	 */
	public function __construct( Schema_Catalog $catalog, bool $seed_owned_rows ) {
		if ( $seed_owned_rows ) {
			$excluded = array( 'oj_ai_installations', 'oj_ai_schema_migrations' );
			foreach ( $catalog->tables() as $table_name => $definition ) {
				if ( in_array( $table_name, $excluded, true ) ) {
					continue;
				}
				$this->rows[ $table_name ] = array(
					array(
						'fixture_key'  => 'preserve:' . $table_name,
						'owner'        => $definition->owner(),
						'payload_hash' => hash( 'sha256', 'business-row:' . $table_name ),
					),
				);
			}

			$this->rows['oj_ai_evaluation_policy_revisions'] = array(
				array(
					'public_id'    => 'policy-preserve-0000000001',
					'subject_type' => 'worksheet_quality',
					'state'        => 'active',
					'content_hash' => hash( 'sha256', 'teacher-policy' ),
				),
			);
			$this->rows['oj_ai_rubric_revisions']            = array(
				array(
					'public_id'    => 'rubric-preserve-0000000001',
					'subject_type' => 'worksheet_quality',
					'state'        => 'active',
					'content_hash' => hash( 'sha256', 'teacher-rubric' ),
				),
			);
			$this->rows['oj_ai_review_rule_revisions']       = array(
				array(
					'public_id'    => 'review-preserve-0000000001',
					'subject_type' => 'student_submission',
					'state'        => 'active',
					'content_hash' => hash( 'sha256', 'teacher-review-rule' ),
				),
			);
			$this->rows['oj_ai_audit_events']                = array(
				array(
					'public_id'  => 'audit-preserve-00000000001',
					'action_key' => 'worksheet.revision.saved',
					'event_hash' => hash( 'sha256', 'existing-audit' ),
				),
			);
			$this->rows['oj_ai_outbox_events']               = array(
				array(
					'public_id'       => 'outbox-preserve-000000001',
					'event_type'      => 'worksheet.revision.saved',
					'idempotency_key' => 'existing-receipt-01',
					'payload_hash'    => hash( 'sha256', 'existing-outbox' ),
				),
			);
		}

		$this->create_private_file( 'worksheet_asset', 'private worksheet asset bytes\n' );
		$this->create_private_file( 'submission_attachment', 'private submission attachment bytes\n' );
		$this->create_private_file( 'worksheet_pdf', "%PDF-1.7\nprivate worksheet pdf fixture\n%%EOF\n" );
		$this->create_private_file( 'report_export', 'student_id,score\nstudent-preserve,9\n' );

		if ( $seed_owned_rows ) {
			$this->rows['oj_ai_private_files'] = array();
			foreach ( $this->files as $kind => $file ) {
				$bytes                               = (string) file_get_contents( $file['path'] );
				$this->rows['oj_ai_private_files'][] = array(
					'public_id'   => 'private-' . str_replace( '_', '-', $kind ),
					'owner_type'  => $kind,
					'storage_key' => $file['storage_key'],
					'mime_type'   => $file['mime_type'],
					'byte_size'   => strlen( $bytes ),
					'sha256'      => hash( 'sha256', $bytes ),
					'state'       => 'ready',
				);
			}
		}
	}

	/**
	 * Snapshot.
	 *
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function snapshot(): array {
		$rows    = $this->rows;
		$options = $this->options;
		ksort( $rows );
		ksort( $options );
		$files = array();
		foreach ( $this->files as $kind => $file ) {
			$bytes = file_get_contents( $file['path'] );
			if ( ! is_string( $bytes ) ) {
				throw new RuntimeException( 'A protected private file became unreadable: ' . $kind );
			}
			$files[ $kind ] = array(
				'storage_key' => $file['storage_key'],
				'mime_type'   => $file['mime_type'],
				'byte_size'   => strlen( $bytes ),
				'sha256'      => hash( 'sha256', $bytes ),
			);
		}
		ksort( $files );

		return array(
			'rows'             => $rows,
			'options'          => $options,
			'private_files'    => $files,
			'external_sources' => $this->external_sources,
		);
	}

	/**
	 * Protected snapshot.
	 *
	 * @return array<string,mixed>
	 */
	public function protected_snapshot(): array {
		$snapshot = $this->snapshot();
		unset( $snapshot['rows']['oj_ai_audit_events'], $snapshot['rows']['oj_ai_outbox_events'] );

		return $snapshot;
	}

	/**
	 * Cleanup.
	 *
	 * @return void
	 */
	public function cleanup(): void {
		foreach ( $this->files as $file ) {
			if ( is_file( $file['path'] ) ) {
				unlink( $file['path'] );
			}
		}
	}

	/**
	 * Create private file.
	 *
	 * @param string $kind Kind.
	 * @param string $bytes Bytes.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function create_private_file( string $kind, string $bytes ): void {
		$path = tempnam( sys_get_temp_dir(), 'ojai-g5-s05-' );
		if ( false === $path || strlen( $bytes ) !== file_put_contents( $path, $bytes ) ) {
			throw new RuntimeException( 'Unable to create the private-file preservation fixture.' );
		}
		$this->files[ $kind ] = array(
			'path'        => $path,
			'storage_key' => 'g5-s05/' . $kind . '/' . hash( 'sha256', $bytes ),
			'mime_type'   => match ( $kind ) {
				'worksheet_pdf' => 'application/pdf',
				'report_export' => 'text/csv',
				default => 'application/octet-stream',
			},
		);
	}
}

/**
 * G5 s05 baseline store.
 *
 * @param Schema_Catalog $catalog Catalog.
 * @return Database_Infrastructure_Test_Store
 */
function g5_s05_baseline_store( Schema_Catalog $catalog ): Database_Infrastructure_Test_Store {
	$plan  = new Migration_Plan( $catalog );
	$store = new Database_Infrastructure_Test_Store( $catalog );
	foreach ( $plan->baseline_tables() as $table_name => $definition ) {
		$store->tables[ $table_name ] = Schema_Metadata_Contract::expected( $definition );
	}
	$store->installation = array(
		'addon_id'             => Schema_Catalog::ADDON_ID,
		'owner_marker'         => $catalog->owner_marker(),
		'schema_version'       => Migration_Plan::BASELINE_SCHEMA_VERSION,
		'schema_fingerprint'   => Migration_Plan::BASELINE_SCHEMA_FINGERPRINT,
		'state'                => Installation_State::RUNTIME_READY,
		'lock_version'         => 9,
		'created_by_user_id'   => 91,
		'created_at_utc'       => '2026-08-14 09:00:00',
		'updated_by_user_id'   => 91,
		'updated_at_utc'       => '2026-08-14 09:00:00',
		'last_verified_at_utc' => '2026-08-14 09:00:00',
	);
	foreach ( array_slice( $plan->migrations(), 0, 2 ) as $migration ) {
		$record                                = database_infrastructure_applied_record( $migration, 'fixture:data-preservation' );
		$record['source_evidence']             = 'preserve-existing-ledger-evidence';
		$store->migrations[ $migration->id() ] = $record;
	}

	return $store;
}

/**
 * G5-IMP-06-S05 schema data-preservation integration contract.
 */
final class G5_S05_State implements Schema_Repair_State_Port_Interface {
	/**
	 * Initialize g5 s05 state dependencies and state.
	 *
	 * @param Schema_Repair_State $state State.
	 */
	public function __construct( private Schema_Repair_State $state ) {
	}
	/**
	 * Inspect.
	 *
	 * @return Schema_Repair_State
	 */
	public function inspect(): Schema_Repair_State {
		return $this->state;
	}
}

/**
 * G5-IMP-06-S05 schema data-preservation integration contract.
 */
final class G5_S05_Receipt implements Schema_Repair_Receipt_Port_Interface {
	/**
	 * Records.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private array $records = array();
	/**
	 * Results.
	 *
	 * @var array<string,Repair_Addon_Schema_Result>
	 */
	private array $results = array();

	/**
	 * Initialize g5 s05 receipt dependencies and state.
	 *
	 * @param G5_S05_Data_Plane $data Data.
	 */
	public function __construct( private G5_S05_Data_Plane $data ) {
	}

	/**
	 * Claim.
	 *
	 * @param array $record Record.
	 * @return Repair_Addon_Schema_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function claim( array $record ): ?Repair_Addon_Schema_Result {
		$key = (string) $record['storage_key'];
		if ( isset( $this->records[ $key ] )
			&& ! hash_equals( (string) $this->records[ $key ]['request_digest'], (string) $record['request_digest'] ) ) {
			throw Schema_Repair_Failure::because( Schema_Repair_Failure::IDEMPOTENCY_CONFLICT );
		}
		if ( isset( $this->results[ $key ] ) ) {
			return $this->results[ $key ]->as_replay();
		}
		$this->records[ $key ]                          ??= $record;
		$this->data->options[ $this->option_key( $key ) ] = array(
			'profile'        => (string) $record['profile'],
			'request_digest' => (string) $record['request_digest'],
			'state'          => 'pending',
		);

		return null;
	}

	/**
	 * Complete.
	 *
	 * @param array                      $record Record.
	 * @param Schema_Installation_Result $repair_result Repair result.
	 * @param string                     $completed_at_utc Completed at utc.
	 * @return Repair_Addon_Schema_Result
	 */
	public function complete(
		array $record,
		Schema_Installation_Result $repair_result,
		string $completed_at_utc
	): Repair_Addon_Schema_Result {
		$key                                       = (string) $record['storage_key'];
		$result                                    = new Repair_Addon_Schema_Result(
			'schema_repaired',
			(string) $record['expected_catalog_fingerprint'],
			$completed_at_utc,
			false,
			count( $repair_result->completed_tables() )
		);
		$this->data->rows['oj_ai_audit_events'][]  = array(
			'public_id'    => 'audit-schema-repair-preserve',
			'action_key'   => 'operations.schema.repaired',
			'event_hash'   => hash( 'sha256', 'audit:' . (string) $record['request_digest'] ),
			'completed_at' => $completed_at_utc,
		);
		$this->data->rows['oj_ai_outbox_events'][] = array(
			'public_id'       => 'outbox-schema-repair-preserve',
			'event_type'      => 'operations.schema.repaired',
			'idempotency_key' => 'schema-repair:' . $key,
			'payload_hash'    => hash( 'sha256', 'outbox:' . (string) $record['request_digest'] ),
		);
		unset( $this->data->options[ $this->option_key( $key ) ] );
		$this->results[ $key ] = $result;

		return $result;
	}

	/**
	 * Block.
	 *
	 * @param array  $record Record.
	 * @param string $reason Reason.
	 * @param string $occurred_at_utc Occurred at utc.
	 * @return void
	 */
	public function block( array $record, string $reason, string $occurred_at_utc ): void {
		$key                   = (string) $record['storage_key'];
		$this->records[ $key ] = array_merge(
			$record,
			array(
				'failure_reason'   => $reason,
				'completed_at_utc' => $occurred_at_utc,
			)
		);
	}

	/**
	 * Option key.
	 *
	 * @param string $storage_key Storage key.
	 * @return string
	 */
	private function option_key( string $storage_key ): string {
		return 'oj_ai_dashboard_schema_repair_' . substr( hash( 'sha256', $storage_key ), 0, 32 );
	}
}

/**
 * G5 s05 readiness.
 *
 * @param Schema_Catalog                     $catalog Catalog.
 * @param Database_Infrastructure_Test_Store $store Store.
 * @return Schema_Runtime_Readiness_Port_Interface
 */
function g5_s05_readiness(
	Schema_Catalog $catalog,
	Database_Infrastructure_Test_Store $store
): Schema_Runtime_Readiness_Port_Interface {
	$plan = new Migration_Plan( $catalog );

	return new Schema_Runtime_Readiness_Verifier(
		$catalog,
		$plan,
		new Schema_Preflight( $catalog ),
		$store,
		$store,
		new Schema_Postcondition_Verifier( $catalog, $store )
	);
}

g5_s05_assert(
	str_contains( $foundation_output, 'partial failure, explicit repair' ),
	'The constituent database infrastructure contract did not run.'
);

$catalog = new Schema_Catalog();
$plan    = new Migration_Plan( $catalog );
$planes  = array();

try {
	$fresh_plane      = new G5_S05_Data_Plane( $catalog, false );
	$planes[]         = $fresh_plane;
	$fresh_before     = $fresh_plane->snapshot();
	$fresh_store      = new Database_Infrastructure_Test_Store( $catalog );
	[ $fresh_runner ] = database_infrastructure_runner( $catalog, $fresh_store );
	$fresh_result     = $fresh_runner->install( 91, 'install:data-preservation-fresh' );
	g5_s05_same( Schema_Installation_Result::INSTALLED, $fresh_result->status(), 'Fresh installation did not complete.' );
	g5_s05_same( $fresh_before, $fresh_plane->snapshot(), 'Fresh installation changed external source data or private bytes.' );

	$upgrade_plane      = new G5_S05_Data_Plane( $catalog, true );
	$planes[]           = $upgrade_plane;
	$upgrade_before     = $upgrade_plane->snapshot();
	$upgrade_store      = g5_s05_baseline_store( $catalog );
	$ledger_before      = array_slice( $upgrade_store->migrations, 0, 2, true );
	[ $upgrade_runner ] = database_infrastructure_runner( $catalog, $upgrade_store );
	$upgrade_result     = $upgrade_runner->install( 91, 'upgrade:data-preservation-100' );
	g5_s05_same( Schema_Installation_Result::UPGRADED, $upgrade_result->status(), 'The 1.0.0 preservation fixture did not upgrade.' );
	g5_s05_same( $upgrade_before, $upgrade_plane->snapshot(), 'Sequential upgrade changed protected rows, settings, files, or receipts.' );
	g5_s05_same( $ledger_before, array_slice( $upgrade_store->migrations, 0, 2, true ), 'Sequential upgrade rewrote prior migration receipts.' );
	g5_s05_same( 6, count( $upgrade_store->statements ), 'Sequential upgrade executed an unexpected DDL count.' );
	$ready_snapshot = $upgrade_plane->snapshot();
	$already_ready  = $upgrade_runner->install( 91, 'upgrade:data-preservation-ready' );
	g5_s05_same( Schema_Installation_Result::ALREADY_READY, $already_ready->status(), 'Target reactivation was not a no-op.' );
	g5_s05_same( $ready_snapshot, $upgrade_plane->snapshot(), 'Target reactivation changed protected data.' );

	$blocked_plane  = new G5_S05_Data_Plane( $catalog, true );
	$planes[]       = $blocked_plane;
	$blocked_before = $blocked_plane->snapshot();
	$blocked_store  = g5_s05_baseline_store( $catalog );
	$blocked_store->tables['oj_ai_export_jobs']['engine'] = 'MyISAM';
	[ $blocked_runner ]                                   = database_infrastructure_runner( $catalog, $blocked_store );
	$blocked_result                                       = $blocked_runner->install( 91, 'upgrade:data-preservation-drift' );
	g5_s05_same( Schema_Installation_Result::BLOCKED, $blocked_result->status(), 'Physical drift did not fail closed.' );
	g5_s05_same( array(), $blocked_store->statements, 'Blocked physical drift executed DDL.' );
	g5_s05_same( $blocked_before, $blocked_plane->snapshot(), 'Blocked upgrade changed protected data.' );

	$repair_plane                  = new G5_S05_Data_Plane( $catalog, true );
	$planes[]                      = $repair_plane;
	$repair_before                 = $repair_plane->snapshot();
	$repair_protected_before       = $repair_plane->protected_snapshot();
	$audit_before                  = $repair_plane->rows['oj_ai_audit_events'];
	$outbox_before                 = $repair_plane->rows['oj_ai_outbox_events'];
	$repair_store                  = g5_s05_baseline_store( $catalog );
	$repair_store->fail_at_attempt = 2;
	[ $repair_runner ]             = database_infrastructure_runner( $catalog, $repair_store );
	$partial                       = $repair_runner->install( 91, 'upgrade:data-preservation-partial' );
	g5_s05_same( Schema_Installation_Result::REPAIR_REQUIRED, $partial->status(), 'Partial upgrade did not require repair.' );
	g5_s05_same( Installation_State::MAINTENANCE_REQUIRED, $repair_store->installation['state'], 'Partial upgrade did not freeze maintenance.' );
	g5_s05_same( $repair_before, $repair_plane->snapshot(), 'Partial upgrade changed protected data before C75.' );
	$normal_retry = $repair_runner->install( 91, 'upgrade:data-preservation-normal-retry' );
	g5_s05_same( Schema_Installation_Result::BLOCKED, $normal_retry->status(), 'Normal activation auto-repaired a partial upgrade.' );
	g5_s05_same( $repair_before, $repair_plane->snapshot(), 'Blocked normal retry changed protected data.' );

	$repair_store->fail_at_attempt = null;
	$state_hash                    = hash(
		'sha256',
		json_encode( array( $repair_store->installation, $repair_store->migrations ), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES )
	);
	$receipt                       = new G5_S05_Receipt( $repair_plane );
	$service                       = new Repair_Addon_Schema(
		new G5_S05_State( Schema_Repair_State::repairable( Installation_State::MAINTENANCE_REQUIRED, $catalog->fingerprint(), $state_hash ) ),
		$receipt,
		$repair_runner,
		g5_s05_readiness( $catalog, $repair_store ),
		$catalog,
		static fn (): DateTimeImmutable => new DateTimeImmutable( '2026-08-14 12:00:00', new DateTimeZone( 'UTC' ) )
	);
	$command                       = new Repair_Addon_Schema_Command(
		91,
		'schema-preservation-repair-0001',
		Installation_State::MAINTENANCE_REQUIRED,
		$catalog->fingerprint(),
		$state_hash,
		str_repeat( 'd', 64 ),
		new DateTimeImmutable( '2026-08-14 11:30:00', new DateTimeZone( 'UTC' ) ),
		true,
		str_repeat( 'e', 64 ),
		'g5-s05-correlation-0001',
		new Canonical_Json()
	);
	$repair_result                 = $service->execute( $command );
	g5_s05_same( 'schema_repaired', $repair_result->code(), 'C75 preservation repair did not complete.' );
	g5_s05_same( 6, count( $repair_store->statements ), 'Partial upgrade plus C75 must execute three ALTER and three CREATE statements.' );
	g5_s05_same( 6, count( array_unique( $repair_store->statements ) ), 'C75 repeated a forward DDL statement.' );
	g5_s05_same( $repair_protected_before, $repair_plane->protected_snapshot(), 'C75 changed business rows, settings, private bytes, options, or source data.' );
	g5_s05_same( $audit_before, array_slice( $repair_plane->rows['oj_ai_audit_events'], 0, count( $audit_before ) ), 'C75 rewrote prior audit history.' );
	g5_s05_same( $outbox_before, array_slice( $repair_plane->rows['oj_ai_outbox_events'], 0, count( $outbox_before ) ), 'C75 rewrote prior outbox receipts.' );
	g5_s05_same( count( $audit_before ) + 1, count( $repair_plane->rows['oj_ai_audit_events'] ), 'C75 did not append exactly one audit event.' );
	g5_s05_same( count( $outbox_before ) + 1, count( $repair_plane->rows['oj_ai_outbox_events'] ), 'C75 did not append exactly one outbox receipt.' );
	g5_s05_assert( ! array_filter( array_keys( $repair_plane->options ), static fn ( string $key ): bool => str_starts_with( $key, 'oj_ai_dashboard_schema_repair_' ) ), 'C75 left a bootstrap option after durable completion.' );

	$after_repair = $repair_plane->snapshot();
	$replay       = $service->execute( $command );
	g5_s05_assert( $replay->replayed(), 'Exact C75 retry did not replay its durable receipt.' );
	g5_s05_same( 0, $replay->mutation_count(), 'C75 replay reported a mutation.' );
	g5_s05_same( $after_repair, $repair_plane->snapshot(), 'C75 replay changed rows, files, settings, options, or receipts.' );

	$all_statements = array();
	foreach ( $plan->migrations() as $migration ) {
		$all_statements = array_merge( $all_statements, $migration->statements() );
	}
	$alter_statements = array_values( array_filter( $all_statements, static fn ( string $sql ): bool => str_starts_with( $sql, 'ALTER TABLE ' ) ) );
	g5_s05_same( 3, count( $alter_statements ), 'The preservation gate requires exactly three frozen forward ALTER statements.' );
	foreach ( $all_statements as $statement ) {
		g5_s05_assert( 1 === preg_match( '/^(?:CREATE TABLE|ALTER TABLE) /D', $statement ), 'Migration plan contains a statement outside CREATE or ALTER.' );
		g5_s05_assert( 0 === preg_match( '/\b(?:DROP TABLE|DROP COLUMN|DROP KEY|RENAME TABLE|TRUNCATE TABLE|CHANGE COLUMN)\b/i', $statement ), 'Migration plan contains data-destructive DDL.' );
		g5_s05_assert( 0 === preg_match( '/^(?:DELETE|UPDATE|INSERT|REPLACE)\s+/i', $statement ), 'Migration plan contains data-mutating DML.' );
		g5_s05_assert( ! str_contains( $statement, 'wp_oj_ai_' ), 'Migration plan contains a WordPress-prefixed table.' );
	}
	g5_s05_assert( str_contains( $alter_statements[0], 'ADD COLUMN `active_identity_key` VARCHAR(191) NULL' ), 'The 1.0.1 delta stopped being additive.' );
	g5_s05_assert( str_contains( $alter_statements[1], 'MODIFY COLUMN `prompt_contract_version` VARCHAR(64) NOT NULL' ), 'The 1.0.2 widening delta changed.' );
	g5_s05_assert( str_contains( $alter_statements[2], 'ADD COLUMN `last_error_message` VARCHAR(500) NULL' ), 'The 1.0.3 delta stopped being additive.' );
	foreach ( $catalog->table_names() as $table_name ) {
		g5_s05_assert( 1 === preg_match( '/^oj_ai_[a-z0-9_]+$/D', $table_name ), 'A schema table is not literal oj_ai_*.' );
	}

	$preservation_sources = '';
	foreach ( array(
		'src/Database/Foundation/Migration_Plan.php',
		'src/Database/Foundation/Schema_Installation_Runner.php',
		'src/Database/Foundation/Infrastructure/WordPress_Schema_Statement_Executor.php',
		'src/Operations/SchemaRepair/Application/Repair_Addon_Schema.php',
		'src/Operations/SchemaRepair/Infrastructure/WordPress_Schema_Repair_Receipt.php',
	) as $source_file ) {
		$preservation_sources .= (string) file_get_contents( $addon_root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $source_file ) );
	}
	foreach ( array( '$wpdb->prefix', '$wpdb->base_prefix', 'wp_oj_ai_', 'DROP TABLE', 'RENAME TABLE', 'TRUNCATE TABLE', 'function down(' ) as $forbidden ) {
		g5_s05_assert( ! str_contains( $preservation_sources, $forbidden ), 'Preservation runtime crossed a frozen boundary: ' . $forbidden );
	}
	g5_s05_assert( ! str_contains( $preservation_sources, 'CodingPanda\\OjPlatform\\Kernel\\' ), 'Preservation runtime depends on a platform implementation namespace.' );
} finally {
	foreach ( $planes as $plane ) {
		$plane->cleanup();
	}
}

fwrite( STDOUT, "G5-IMP-06-S05 passed: fresh install isolation, sequential upgrade, blocked drift, partial failure, C75 repair, durable replay, business rows, settings, private bytes, and prior receipts are preserved.\n" );
