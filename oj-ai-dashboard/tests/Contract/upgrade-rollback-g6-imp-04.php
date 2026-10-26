<?php
/**
 * G6-IMP-04 upgrade and rollback acceptance contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

$root = dirname( __DIR__, 2 );

/**
 * G6 imp04 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g6_imp04_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * G6 imp04 same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g6_imp04_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException(
			$message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true )
		);
	}
}

/**
 * G6 imp04 source.
 *
 * @param string $root Root.
 * @param string $relative_path Relative path.
 * @return string
 */
function g6_imp04_source( string $root, string $relative_path ): string {
	$source = file_get_contents( $root . '/' . $relative_path );
	g6_imp04_assert( is_string( $source ), 'Acceptance source could not be read: ' . $relative_path );

	return $source;
}

/**
 * G6 imp04 canonicalize.
 *
 * @param mixed $value Value.
 * @return mixed
 */
function g6_imp04_canonicalize( $value ) {
	if ( ! is_array( $value ) ) {
		return $value;
	}
	if ( array_is_list( $value ) ) {
		return array_map( 'g6_imp04_canonicalize', $value );
	}
	ksort( $value, SORT_STRING );
	foreach ( $value as $key => $item ) {
		$value[ $key ] = g6_imp04_canonicalize( $item );
	}

	return $value;
}

$package_metadata = json_decode( g6_imp04_source( $root, 'package.json' ), true, 512, JSON_THROW_ON_ERROR );
$addon_version    = is_array( $package_metadata ) ? (string) ( $package_metadata['version'] ?? '' ) : '';
g6_imp04_assert( 1 === preg_match( '/^\d+\.\d+\.\d+$/D', $addon_version ), 'Package add-on version is invalid.' );

$dimension_evidence = array(
	'G6-IMP-04-S01' => array(
		'scripts/check-addon-api-consumer.ps1',
		'scripts/check-database-foundation.ps1',
		'scripts/check-activation-database.ps1',
		'scripts/check-runtime-schema-readiness.ps1',
		'scripts/check-assessment-configuration-bootstrap-s01.ps1',
		'scripts/check-fresh-install-g5-imp-06-s01.ps1',
	),
	'G6-IMP-04-S02' => array(
		'scripts/check-sequential-upgrade-g5-imp-06-s02.ps1',
		'scripts/check-failure-restart-g5-imp-06-s03.ps1',
		'scripts/check-forward-repair-g5-imp-06-s04.ps1',
		'scripts/check-data-preservation-g5-imp-06-s05.ps1',
	),
	'G6-IMP-04-S03' => array(
		'scripts/check-sequential-upgrade-g5-imp-06-s02.ps1',
		'scripts/check-data-preservation-g5-imp-06-s05.ps1',
		'scripts/check-release-package-boundary.ps1',
	),
	'G6-IMP-04-S04' => array(
		'scripts/check-activation-database.ps1',
		'scripts/check-assessment-configuration-bootstrap-s01.ps1',
		'scripts/check-legacy-migration-runtime-g5-imp-07-s03-e.ps1',
		'scripts/check-purge-execution-g5-imp-08-s04.ps1',
	),
	'G6-IMP-04-S05' => array(
		'scripts/check-addon-api-consumer.ps1',
		'scripts/check-runtime-schema-readiness.ps1',
		'scripts/check-release-package-boundary.ps1',
	),
);

g6_imp04_same(
	array( 'G6-IMP-04-S01', 'G6-IMP-04-S02', 'G6-IMP-04-S03', 'G6-IMP-04-S04', 'G6-IMP-04-S05' ),
	array_keys( $dimension_evidence ),
	'The G6-IMP-04 ledger is not exactly S01-S05.'
);

$gate_source       = g6_imp04_source( $root, 'scripts/check-upgrade-rollback-g6-imp-04.ps1' );
$runner_source     = g6_imp04_source( $root, 'scripts/run-tests.mjs' );
$package_source    = g6_imp04_source( $root, 'package.json' );
$acceptance_source = g6_imp04_source( $root, 'docs/ai-assessment/g6-imp-04-upgrade-rollback.html' );
$design_source     = g6_imp04_source( $root, 'docs/ai-assessment/a-ds-16-cross-validation-freeze.html' );
$operations_source = g6_imp04_source( $root, 'docs/ai-assessment/a-ds-15-install-upgrade-migration-operations.html' );
$api_audit_source  = g6_imp04_source( $root, 'docs/ai-assessment/platform-api-implementation-audit.html' );

$runner_gate_position = strpos( $runner_source, 'scripts/check-upgrade-rollback-g6-imp-04.ps1' );
g6_imp04_assert( false !== $runner_gate_position, 'The G6-IMP-04 gate is not registered in the aggregate runner.' );
$unique_evidence = array();
foreach ( $dimension_evidence as $dimension => $evidence_files ) {
	g6_imp04_assert( str_contains( $gate_source, "'" . $dimension . "'" ), 'The PowerShell gate does not map ' . $dimension );
	g6_imp04_assert( str_contains( $acceptance_source, $dimension ), 'The HTML acceptance record does not list ' . $dimension );
	foreach ( $evidence_files as $relative_path ) {
		g6_imp04_assert( is_file( $root . '/' . $relative_path ), 'Acceptance evidence file is missing: ' . $relative_path );
		g6_imp04_assert( str_contains( $gate_source, str_replace( '/', '\\', $relative_path ) ), 'The PowerShell gate does not reference evidence: ' . $relative_path );
		$evidence_position = strpos( $runner_source, $relative_path );
		g6_imp04_assert( false !== $evidence_position, 'The aggregate runner does not execute evidence: ' . $relative_path );
		g6_imp04_assert( $evidence_position < $runner_gate_position, 'Acceptance evidence must run before G6-IMP-04: ' . $relative_path );
		$unique_evidence[ $relative_path ] = true;
	}
}
g6_imp04_same( 13, count( $unique_evidence ), 'The frozen G6-IMP-04 evidence set drifted.' );

$plugin_source      = g6_imp04_source( $root, 'oj-ai-dashboard.php' );
$integration_source = g6_imp04_source( $root, 'src/Platform/Addon_Integration.php' );
$catalog_source     = g6_imp04_source( $root, 'src/Database/Foundation/Schema_Catalog.php' );
$plan_source        = g6_imp04_source( $root, 'src/Database/Foundation/Migration_Plan.php' );
preg_match( '/^ \* Version:\s*([^\s]+)$/m', $plugin_source, $header_version );
preg_match( "/define\( 'OJ_AI_DASHBOARD_VERSION', '([^']+)' \)/", $plugin_source, $runtime_version );
g6_imp04_same( $addon_version, $header_version[1] ?? null, 'Plugin release marker is missing: exact header version.' );
g6_imp04_same( $addon_version, $runtime_version[1] ?? null, 'Plugin release marker is missing: exact runtime version.' );
foreach ( array( 'Requires Plugins: oj-platform' ) as $marker ) {
	g6_imp04_assert( str_contains( $plugin_source, $marker ), 'Plugin release marker is missing: ' . $marker );
}
require_once dirname( __DIR__ ) . '/Support/class-source-contract-tokens.php';
foreach ( array(
	'ADDON_API_RANGE' => '>=1.1.0 <2.0.0',
	'MIN_ADDON_API'   => '1.1.0',
	'MAX_ADDON_API'   => '2.0.0',
) as $constant => $expected ) {
	g6_imp04_same( $expected, \OJAI\Dashboard\Tests\Support\Source_Contract_Tokens::string_constant( $integration_source, $constant ), 'Addon API compatibility marker is missing: ' . $constant );
}
g6_imp04_same( '1.0.4', \OJAI\Dashboard\Tests\Support\Source_Contract_Tokens::string_constant( $catalog_source, 'SCHEMA_VERSION' ), 'The target schema is not 1.0.4.' );
foreach ( array(
	'BASELINE_SCHEMA_VERSION' => '1.0.0',
	'PREVIOUS_SCHEMA_VERSION' => '1.0.1',
) as $constant => $expected ) {
	g6_imp04_same( $expected, \OJAI\Dashboard\Tests\Support\Source_Contract_Tokens::string_constant( $plan_source, $constant ), 'Supported upgrade marker is missing: ' . $constant );
}

$upgrade_contract = g6_imp04_source( $root, 'tests/Contract/sequential-upgrade-g5-imp-06-s02.php' );
foreach ( array(
	"'1.0.4'",
	'g5_s02_assert_blocked_without_writes',
	'upgrade:future-20260825',
	'schema_preflight_blocked',
	'upgrade:unknown-record-20260825',
	'upgrade:physical-drift-20260825',
) as $marker ) {
	g6_imp04_assert( str_contains( $upgrade_contract, $marker ), 'Rollback fail-closed marker is missing: ' . $marker );
}
$preservation_contract = g6_imp04_source( $root, 'tests/Contract/schema-data-preservation-g5-imp-06-s05.php' );
foreach ( array(
	'Sequential upgrade changed protected rows, settings, files, or receipts.',
	'Partial upgrade changed protected data before C75.',
	'C75 replay changed rows, files, settings, options, or receipts.',
	'Target reactivation changed protected data.',
) as $marker ) {
	g6_imp04_assert( str_contains( $preservation_contract, $marker ), 'Data preservation marker is missing: ' . $marker );
}

$destructive_sources = array(
	'src/Database/Foundation/Migration_Plan.php',
	'src/Database/Foundation/Schema_Installation_Runner.php',
	'src/Database/Foundation/Infrastructure/WordPress_Schema_Statement_Executor.php',
	'src/Operations/SchemaRepair/Application/Repair_Addon_Schema.php',
);
foreach ( $destructive_sources as $relative_path ) {
	$source = g6_imp04_source( $root, $relative_path );
	foreach ( array( 'DROP TABLE', 'RENAME TABLE', 'TRUNCATE TABLE', 'function down(', 'rollback_schema' ) as $forbidden ) {
		g6_imp04_assert( ! str_contains( $source, $forbidden ), 'Rollback source contains a destructive marker: ' . $relative_path . ' -> ' . $forbidden );
	}
}

$activation_contract    = g6_imp04_source( $root, 'tests/Contract/activation-database-integration.php' );
$configuration_contract = g6_imp04_source( $root, 'tests/Contract/assessment-configuration-bootstrap-s01.php' );
foreach ( array( 'A verified reactivation must be idempotent.', 'Reactivation must refresh the code version after verification.' ) as $marker ) {
	g6_imp04_assert( str_contains( $activation_contract, $marker ), 'Reactivation marker is missing: ' . $marker );
}
foreach ( array( 'Reactivation must preserve existing revisions.', 'Reactivation must not duplicate policy revisions.' ) as $marker ) {
	g6_imp04_assert( str_contains( $configuration_contract, $marker ), 'Configuration reactivation marker is missing: ' . $marker );
}

$composition_source  = g6_imp04_source( $root, 'src/Core/Composition_Root.php' );
$deactivation_source = g6_imp04_source( $root, 'src/Core/Deactivation.php' );
$workers             = array(
	'Legacy_Migration_Worker_Module'              => array( 'src/Operations/LegacyMigration/Infrastructure/Legacy_Migration_Worker_Module.php', '$legacy_migration_worker', false ),
	'Purge_Worker_Module'                         => array( 'src/Operations/Purge/Infrastructure/Purge_Worker_Module.php', '$purge_worker', false ),
	'Report_Export_Worker_Module'                 => array( 'src/Reporting/ReportExport/Infrastructure/Report_Export_Worker_Module.php', '$report_export_worker', true ),
	'Result_Pdf_Worker_Module'                    => array( 'src/Reporting/ResultPdf/Infrastructure/Result_Pdf_Worker_Module.php', '$result_pdf_worker', true ),
	'Submission_Bundle_Worker_Module'             => array( 'src/Reporting/SubmissionBundle/Infrastructure/Submission_Bundle_Worker_Module.php', '$submission_bundle_worker', true ),
	'Worksheet_Package_Worker_Module'             => array( 'src/Reporting/WorksheetPackage/Infrastructure/Worksheet_Package_Worker_Module.php', '$package_worker', true ),
	'Submission_Pdf_Worker_Module'                => array( 'src/Reporting/SubmissionPdf/Infrastructure/Submission_Pdf_Worker_Module.php', '$submission_pdf_worker', true ),
	'Worksheet_Pdf_Worker_Module'                 => array( 'src/Reporting/WorksheetPdf/Infrastructure/Worksheet_Pdf_Worker_Module.php', '$pdf_worker', true ),
	'Submission_Evaluation_Worker_Module'         => array( 'src/Submission/Assessment/Infrastructure/Submission_Evaluation_Worker_Module.php', '$submission_evaluation_worker', true ),
	'Submission_Feedback_Worker_Module'           => array( 'src/Submission/Assessment/Infrastructure/Submission_Feedback_Worker_Module.php', '$submission_feedback_worker', true ),
	'Student_Note_Worker_Module'                  => array( 'src/Submission/Assessment/Infrastructure/Student_Note_Worker_Module.php', '$student_note_worker', true ),
	'Submission_Attachment_Cleanup_Worker_Module' => array( 'src/Submission/Student/Infrastructure/Submission_Attachment_Cleanup_Worker_Module.php', '$attachment_cleanup_worker', true ),
	'Submission_Attachment_Scan_Worker_Module'    => array( 'src/Submission/Student/Infrastructure/Submission_Attachment_Scan_Worker_Module.php', '$attachment_scan_worker', true ),
	'Submission_Zip_Cleanup_Worker_Module'        => array( 'src/Submission/ZipImport/Infrastructure/Submission_Zip_Cleanup_Worker_Module.php', '$submission_zip_cleanup_worker', true ),
	'Submission_Zip_Scan_Worker_Module'           => array( 'src/Submission/ZipImport/Infrastructure/Submission_Zip_Scan_Worker_Module.php', '$submission_zip_scan_worker', true ),
	'Worksheet_Evaluation_Worker_Module'          => array( 'src/Worksheet/Evaluation/Infrastructure/Worksheet_Evaluation_Worker_Module.php', '$evaluation_worker', true ),
);
$recurring_workers   = 0;
foreach ( $workers as $class_name => $worker ) {
	list( $relative_path, $variable, $recurring ) = $worker;
	$source                                       = g6_imp04_source( $root, $relative_path );
	foreach ( array( 'class ' . $class_name, 'function register', 'function clear' ) as $marker ) {
		g6_imp04_assert( str_contains( $source, $marker ), 'Worker lifecycle marker is missing: ' . $class_name . ' -> ' . $marker );
	}
	g6_imp04_assert( str_contains( $composition_source, 'new ' . $class_name . '(' ), 'Worker is not constructed by Composition Root: ' . $class_name );
	g6_imp04_assert( str_contains( $composition_source, 'modules->add( ' . $variable . ' )' ), 'Worker is not registered by Composition Root: ' . $class_name );
	g6_imp04_assert( str_contains( $deactivation_source, $class_name . '::clear();' ), 'Worker is not cleared on deactivation: ' . $class_name );
	if ( $recurring ) {
		++$recurring_workers;
		foreach ( array( 'SWEEP_HOOK', 'ensure_scheduled', 'wp_schedule_event' ) as $marker ) {
			g6_imp04_assert( str_contains( $source, $marker ), 'Recurring worker does not restore its sweep: ' . $class_name . ' -> ' . $marker );
		}
	}
}
g6_imp04_same( 16, count( $workers ), 'The active worker lifecycle set drifted.' );
g6_imp04_same( 14, $recurring_workers, 'The recurring worker restoration set drifted.' );
$migration_batch_source = strtolower( g6_imp04_source( $root, 'src/Operations/LegacyMigration/Application/Process_Legacy_Migration_Batch.php' ) );
foreach ( array( 'checkpoint', 'commit_batch', 'committed checkpoint remains authoritative for recovery' ) as $marker ) {
	g6_imp04_assert( str_contains( $migration_batch_source, strtolower( $marker ) ), 'Explicit migration recovery marker is missing: ' . $marker );
}
foreach ( array( '구 code로 rollback할 때 새 schema를 자동으로 내리지 않는다.', '전체 preflight·owner·fingerprint·미완료 checkpoint 검산 뒤 schedule 재등록' ) as $marker ) {
	g6_imp04_assert( str_contains( $operations_source, $marker ), 'A-DS-15 lifecycle marker is missing: ' . $marker );
}

$release_source = g6_imp04_source( $root, 'scripts/build-release.ps1' );
foreach ( array(
	"throw 'Release packaging requires source_dirty=false.",
	'$requiredEntries = @(',
	"'oj-ai-dashboard.php'",
	"'uninstall.php'",
	"'src'",
	'--no-dev --no-scripts --no-plugins',
	'validate-production-vendor.php',
	'$forbiddenDirectories',
	"'node_modules'",
) as $marker ) {
	g6_imp04_assert( str_contains( $release_source, $marker ), 'Release evidence marker is missing: ' . $marker );
}

$evidence_entries = array();
foreach ( array_keys( $unique_evidence ) as $relative_path ) {
	$hash = hash_file( 'sha256', $root . '/' . $relative_path );
	g6_imp04_assert( is_string( $hash ) && 1 === preg_match( '/^[a-f0-9]{64}$/', $hash ), 'Evidence hash failed: ' . $relative_path );
	$evidence_entries[] = array(
		'path'   => $relative_path,
		'sha256' => $hash,
	);
}
$manifest      = g6_imp04_canonicalize(
	array(
		'addon_version'            => $addon_version,
		'contract'                 => 'oj_ai.g6_upgrade_rollback_evidence@1',
		'dimensions'               => array_keys( $dimension_evidence ),
		'evidence'                 => $evidence_entries,
		'operator_sequence'        => array( 'clean_install', 'supported_upgrade', 'rollback_fail_closed', 'reactivate', 'verify_evidence' ),
		'platform_addon_api_range' => '>=1.1.0 <2.0.0',
		'schema_version'           => '1.0.4',
	)
);
$schema_marker = "SCHEMA_VERSION = '" . $manifest['schema_version'] . "'";
g6_imp04_assert( str_contains( $catalog_source, $schema_marker ), 'Evidence manifest schema differs from the target catalog.' );
$manifest_json = json_encode( $manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR );
$manifest_hash = hash( 'sha256', $manifest_json );
$evidence_dir  = sys_get_temp_dir() . '/oj-ai-g6-imp04-' . bin2hex( random_bytes( 8 ) );
g6_imp04_assert( mkdir( $evidence_dir, 0700 ), 'Could not create the temporary evidence package.' );
try {
	$manifest_path = $evidence_dir . '/manifest.json';
	$checksum_path = $evidence_dir . '/checksums.sha256';
	g6_imp04_same( strlen( $manifest_json ), file_put_contents( $manifest_path, $manifest_json ), 'Evidence manifest write failed.' );
	$checksum_line = $manifest_hash . "  manifest.json\n";
	g6_imp04_same( strlen( $checksum_line ), file_put_contents( $checksum_path, $checksum_line ), 'Evidence checksum write failed.' );
	g6_imp04_same( $manifest_json, file_get_contents( $manifest_path ), 'Evidence manifest read-back drifted.' );
	g6_imp04_same( $checksum_line, file_get_contents( $checksum_path ), 'Evidence checksum read-back drifted.' );
	g6_imp04_same( $manifest_hash, hash_file( 'sha256', $manifest_path ), 'Evidence package hash verification failed.' );
} finally {
	foreach ( array( $evidence_dir . '/manifest.json', $evidence_dir . '/checksums.sha256' ) as $path ) {
		if ( is_file( $path ) ) {
			unlink( $path );
		}
	}
	if ( is_dir( $evidence_dir ) ) {
		rmdir( $evidence_dir );
	}
}

$runtime_files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/src', FilesystemIterator::SKIP_DOTS ) );
foreach ( $runtime_files as $file ) {
	if ( ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) {
		continue;
	}
	$source = file_get_contents( $file->getPathname() );
	g6_imp04_assert( is_string( $source ), 'Runtime source could not be read: ' . $file->getPathname() );
	foreach ( array( '$wpdb->prefix', '$wpdb->base_prefix', 'wp_oj_ai_', 'CodingPanda\\OjPlatform\\Kernel\\' ) as $forbidden ) {
		g6_imp04_assert( ! str_contains( $source, $forbidden ), 'Runtime crossed a frozen boundary: ' . $file->getPathname() . ' -> ' . $forbidden );
	}
}

foreach ( array( 'G6-IMP-04-S01', 'G6-IMP-04-S05', 'id="amendment-12"', '13 existing gates', 'G6 4/5' ) as $marker ) {
	g6_imp04_assert( str_contains( $design_source, $marker ), 'A-DS-16 is missing G6-IMP-04 marker: ' . $marker );
}
foreach ( array( 'API-AUD-2026-08-26-R129-OBSERVED', 'G6-IMP-04', 'Platform API change: 0', 'G6-IMP-05' ) as $marker ) {
	g6_imp04_assert( str_contains( $api_audit_source, $marker ), 'Platform API audit is missing G6-IMP-04 marker: ' . $marker );
}
foreach ( array( 'check:upgrade-rollback-g6', 'check-upgrade-rollback-g6-imp-04.ps1' ) as $marker ) {
	g6_imp04_assert( str_contains( $package_source, $marker ), 'The package command is missing G6-IMP-04 marker: ' . $marker );
}
foreach ( array( 'G6-IMP-04 upgrade and rollback passed.', '-SkipConstituentChecks' ) as $marker ) {
	g6_imp04_assert( str_contains( $runner_source, $marker ), 'The aggregate runner is missing G6-IMP-04 marker: ' . $marker );
}

fwrite( STDOUT, "G6-IMP-04 passed: five acceptance dimensions map to 13 existing gates; clean install, supported upgrade, rollback fail-closed, reactivation, worker restoration, and reproducible evidence remain enforced.\n" );
