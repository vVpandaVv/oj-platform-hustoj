<?php
/**
 * G6-IMP-03 performance and resilience acceptance contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

/**
 * G6 imp03 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g6_imp03_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * G6 imp03 same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g6_imp03_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

/**
 * G6 imp03 source.
 *
 * @param string $root Root.
 * @param string $relative_path Relative path.
 * @return string
 */
function g6_imp03_source( string $root, string $relative_path ): string {
	$source = file_get_contents( $root . '/' . $relative_path );
	g6_imp03_assert( is_string( $source ), 'Acceptance source could not be read: ' . $relative_path );
	return $source;
}

/**
 * G6 imp03 integer constant.
 *
 * @param string $source Source.
 * @param string $name Name.
 * @param int    $expected Expected.
 * @param string $label Label.
 * @return void
 */
function g6_imp03_integer_constant( string $source, string $name, int $expected, string $label ): void {
	$matched = preg_match( '/\bconst\s+' . preg_quote( $name, '/' ) . '\s*=\s*([0-9_]+)\s*;/', $source, $matches );
	g6_imp03_assert( 1 === $matched, $label . ' is missing constant ' . $name );
	$actual = (int) str_replace( '_', '', $matches[1] );
	g6_imp03_same( $expected, $actual, $label . ' constant drifted: ' . $name );
}

$root               = dirname( __DIR__, 2 );
$dimension_evidence = array(
	'G6-IMP-03-S01' => array(
		'scripts/check-assignment-g3-imp-01.ps1',
	),
	'G6-IMP-03-S02' => array(
		'scripts/check-worksheet-evaluation-worker-s04.ps1',
		'scripts/check-submission-evaluation-queue-lease-g4-imp-02-s01.ps1',
		'scripts/check-submission-evaluation-worker-g4-imp-02-s02.ps1',
		'scripts/check-submission-feedback-g4-imp-04-s01.ps1',
		'scripts/check-student-note-draft-g4-imp-05-s01.ps1',
		'scripts/check-worksheet-pdf-package-g2-imp-07.ps1',
		'scripts/check-submission-pdf-g3-imp-08.ps1',
		'scripts/check-result-pdf-job-g4-imp-07-s02.ps1',
		'scripts/check-report-export-job-download-g5-imp-03-s04.ps1',
	),
	'G6-IMP-03-S03' => array(
		'scripts/check-worksheet-document-profile-s03.ps1',
		'scripts/check-worksheet-asset-s01.ps1',
		'scripts/check-student-assignment-g3-imp-03.ps1',
		'scripts/check-submission-zip-g3-imp-07.ps1',
		'scripts/check-submission-bundle-enqueue-g5-imp-03-s02.ps1',
	),
	'G6-IMP-03-S04' => array(
		'scripts/check-worksheet-report-list-g5-imp-01-s01.ps1',
		'scripts/check-worksheet-report-detail-g5-imp-01-s02.ps1',
		'scripts/check-report-snapshot-integration-g5-imp-02-s04.ps1',
		'scripts/check-report-export-enqueue-g5-imp-03-s01.ps1',
		'scripts/check-submission-bundle-enqueue-g5-imp-03-s02.ps1',
		'scripts/check-report-json-package-enqueue-g5-imp-03-s03.ps1',
		'scripts/check-report-export-job-download-g5-imp-03-s04.ps1',
		'scripts/check-report-export-control-g5-imp-03-s05.ps1',
		'scripts/check-report-export-integration-g5-imp-03-s06.ps1',
	),
	'G6-IMP-03-S05' => array(
		'scripts/check-failure-restart-g5-imp-06-s03.ps1',
		'scripts/check-forward-repair-g5-imp-06-s04.ps1',
		'scripts/check-legacy-migration-recovery-g5-imp-07-s03-f.ps1',
		'scripts/check-legacy-migration-integration-g5-imp-07-s06.ps1',
		'scripts/check-purge-execution-g5-imp-08-s04.ps1',
		'scripts/check-purge-integration-g5-imp-08-s06.ps1',
		'scripts/check-submission-evaluation-queue-lease-g4-imp-02-s01.ps1',
		'scripts/check-report-export-job-download-g5-imp-03-s04.ps1',
	),
);

g6_imp03_same(
	array( 'G6-IMP-03-S01', 'G6-IMP-03-S02', 'G6-IMP-03-S03', 'G6-IMP-03-S04', 'G6-IMP-03-S05' ),
	array_keys( $dimension_evidence ),
	'The G6-IMP-03 ledger is not exactly S01-S05.'
);

$gate_source       = g6_imp03_source( $root, 'scripts/check-performance-resilience-g6-imp-03.ps1' );
$runner_source     = g6_imp03_source( $root, 'scripts/run-tests.mjs' );
$package_source    = g6_imp03_source( $root, 'package.json' );
$design_source     = g6_imp03_source( $root, 'docs/ai-assessment/a-ds-16-cross-validation-freeze.html' );
$acceptance_source = g6_imp03_source( $root, 'docs/ai-assessment/g6-imp-03-performance-resilience.html' );

$runner_gate_position = strpos( $runner_source, 'scripts/check-performance-resilience-g6-imp-03.ps1' );
g6_imp03_assert( false !== $runner_gate_position, 'The G6-IMP-03 gate is not registered in the aggregate runner.' );
$unique_evidence = array();
foreach ( $dimension_evidence as $dimension => $evidence_files ) {
	g6_imp03_assert( str_contains( $gate_source, "'" . $dimension . "'" ), 'The PowerShell gate does not map ' . $dimension );
	g6_imp03_assert( str_contains( $acceptance_source, $dimension ), 'The HTML acceptance record does not list ' . $dimension );
	foreach ( $evidence_files as $relative_path ) {
		g6_imp03_assert( is_file( $root . '/' . $relative_path ), 'Acceptance evidence file is missing: ' . $relative_path );
		g6_imp03_assert( str_contains( $gate_source, str_replace( '/', '\\', $relative_path ) ), 'The PowerShell gate does not reference evidence: ' . $relative_path );
		$evidence_position = strpos( $runner_source, $relative_path );
		g6_imp03_assert( false !== $evidence_position, 'The aggregate runner does not execute evidence: ' . $relative_path );
		g6_imp03_assert( $evidence_position < $runner_gate_position, 'Acceptance evidence must run before G6-IMP-03: ' . $relative_path );
		$unique_evidence[ $relative_path ] = true;
	}
}
g6_imp03_same( 28, count( $unique_evidence ), 'The frozen G6-IMP-03 evidence set drifted.' );

$roster_source = g6_imp03_source( $root, 'src/Submission/Assignment/Infrastructure/Platform_Assignment_Roster.php' );
g6_imp03_integer_constant( $roster_source, 'PAGE_LIMIT', 100, 'Exact roster' );
g6_imp03_integer_constant( $roster_source, 'MAXIMUM_PAGES', 50, 'Exact roster' );
g6_imp03_integer_constant( $roster_source, 'MAXIMUM_LEARNERS', 5000, 'Exact roster' );
$roster_contract = g6_imp03_source( $root, 'tests/Contract/assignment-create-g3-imp-01.php' );
foreach ( array( 'g3_same( 5000, $maximum->count()', 'g3_same( 50, count( $maximum_gateway->queries )', 'ROSTER_TOO_LARGE' ) as $marker ) {
	g6_imp03_assert( str_contains( $roster_contract, $marker ), 'The 5,000 learner execution contract is missing: ' . $marker );
}

$ai_workers = array(
	'src/Worksheet/Evaluation/Application/Run_Next_Worksheet_Evaluation.php',
	'src/Submission/Assessment/Application/Run_Next_Submission_Evaluation.php',
	'src/Submission/Assessment/Application/Run_Next_Submission_Feedback.php',
	'src/Submission/Assessment/Application/Run_Next_Student_Note_Draft.php',
);
foreach ( $ai_workers as $relative_path ) {
	$source = g6_imp03_source( $root, $relative_path );
	g6_imp03_integer_constant( $source, 'LEASE_SECONDS', 300, $relative_path );
	g6_imp03_integer_constant( $source, 'MAXIMUM_ATTEMPTS', 3, $relative_path );
	foreach ( array( '60 : 300', 'worker_lost', 'queue_deadline_exceeded' ) as $marker ) {
		g6_imp03_assert( str_contains( $source, $marker ), 'AI worker recovery marker is missing: ' . $relative_path . ' -> ' . $marker );
	}
}

$leased_workers = array(
	'src/Reporting/ReportExport/Application/Run_Next_Report_Export.php',
	'src/Reporting/WorksheetPdf/Application/Run_Next_Worksheet_Pdf.php',
	'src/Reporting/SubmissionPdf/Application/Run_Next_Submission_Pdf.php',
	'src/Reporting/ResultPdf/Application/Run_Next_Result_Pdf.php',
	'src/Reporting/SubmissionBundle/Application/Run_Next_Submission_Bundle.php',
	'src/Reporting/WorksheetPackage/Application/Run_Next_Worksheet_Package.php',
	'src/Submission/ZipImport/Application/Run_Next_Submission_Zip_Scan.php',
	'src/Submission/Student/Application/Run_Next_Submission_Attachment_Scan.php',
);
foreach ( $leased_workers as $relative_path ) {
	g6_imp03_integer_constant( g6_imp03_source( $root, $relative_path ), 'LEASE_SECONDS', 300, $relative_path );
}

$document_profile = g6_imp03_source( $root, 'src/Worksheet/Authoring/Domain/Worksheet_Document_Profile.php' );
foreach ( array(
	'MAX_DOCUMENT_BYTES'        => 2097152,
	'MAX_NODES'                 => 5000,
	'MAX_DEPTH'                 => 32,
	'MAX_TOTAL_TEXT_CHARACTERS' => 500000,
	'MAX_RESPONSES'             => 200,
	'MAX_BODY_ASSETS'           => 100,
	'MAX_FILE_BYTES'            => 20971520,
) as $name => $expected ) {
	g6_imp03_integer_constant( $document_profile, $name, $expected, 'Worksheet document profile' );
}

$zip_profile = g6_imp03_source( $root, 'src/Submission/ZipImport/Domain/Submission_Zip_Upload_Profile.php' );
foreach ( array(
	'MAX_ARCHIVE_BYTES'      => 104857600,
	'MAX_REGULAR_ENTRIES'    => 500,
	'MAX_ENTRY_BYTES'        => 26214400,
	'MAX_UNCOMPRESSED_BYTES' => 524288000,
	'MAX_COMPRESSION_RATIO'  => 20,
) as $name => $expected ) {
	g6_imp03_integer_constant( $zip_profile, $name, $expected, 'Submission ZIP profile' );
}

$bundle_profile = g6_imp03_source( $root, 'src/Reporting/SubmissionBundle/Domain/Submission_Bundle_Request_Snapshot.php' );
g6_imp03_integer_constant( $bundle_profile, 'MAXIMUM_ARCHIVE_FILES', 500, 'Submission bundle' );
g6_imp03_integer_constant( $bundle_profile, 'MAXIMUM_ARCHIVE_BYTES', 524288000, 'Submission bundle' );
g6_imp03_integer_constant( $bundle_profile, 'MAXIMUM_SNAPSHOT_BYTES', 52428800, 'Submission bundle' );

$report_list     = g6_imp03_source( $root, 'src/Reporting/Report/Application/Read_Worksheet_Reports.php' );
$report_detail   = g6_imp03_source( $root, 'src/Reporting/Report/Application/Read_Worksheet_Report_Detail.php' );
$report_snapshot = g6_imp03_source( $root, 'src/Reporting/Report/Application/Create_Report_Snapshot.php' );
$report_export   = g6_imp03_source( $root, 'src/Reporting/ReportExport/Domain/Report_Export_Request_Snapshot.php' );
g6_imp03_integer_constant( $report_list, 'MAXIMUM_LIMIT', 100, 'Report list page' );
g6_imp03_integer_constant( $report_detail, 'MAXIMUM_LIMIT', 100, 'Report detail page' );
g6_imp03_integer_constant( $report_snapshot, 'MAXIMUM_ROWS', 10000, 'Report snapshot' );
g6_imp03_integer_constant( $report_export, 'MAXIMUM_ROWS', 50000, 'Report export' );
g6_imp03_integer_constant( $report_export, 'MAXIMUM_BYTES', 52428800, 'Report export' );

$legacy_execution = g6_imp03_source( $root, 'src/Operations/LegacyMigration/Application/Execute_Legacy_Migration.php' );
$purge_execution  = g6_imp03_source( $root, 'src/Operations/Purge/Application/Execute_Purge.php' );
g6_imp03_integer_constant( $legacy_execution, 'BATCH_SIZE', 250, 'Legacy migration checkpoint' );
g6_imp03_integer_constant( $purge_execution, 'BATCH_SIZE', 250, 'Purge checkpoint' );
g6_imp03_integer_constant( $purge_execution, 'BACKUP_MAXIMUM_AGE_SECONDS', 86400, 'Purge backup evidence' );

$restart_contract = g6_imp03_source( $root, 'tests/Contract/failure-restart-g5-imp-06-s03.php' );
$schema_runner    = g6_imp03_source( $root, 'src/Database/Foundation/Schema_Installation_Runner.php' );
$legacy_batch     = g6_imp03_source( $root, 'src/Operations/LegacyMigration/Application/Process_Legacy_Migration_Batch.php' );
$purge_repository = g6_imp03_source( $root, 'src/Operations/Purge/Infrastructure/WordPress_Purge_Operation_Repository.php' );
foreach ( array( 'repair_required', 'checkpoint', 'restart', 'attempt' ) as $marker ) {
	g6_imp03_assert( str_contains( strtolower( $restart_contract ), $marker ), 'Schema restart contract is missing: ' . $marker );
}
foreach ( array( 'schema_partial_requires_explicit_repair', 'resume_migration' ) as $marker ) {
	g6_imp03_assert( str_contains( $schema_runner, $marker ), 'Schema recovery marker is missing: ' . $marker );
}
foreach ( array( 'source_fingerprint', 'plan_hash', 'checkpoint', 'commit_batch', 'queue->wake', 'committed checkpoint remains authoritative' ) as $marker ) {
	g6_imp03_assert( str_contains( $legacy_batch, $marker ), 'Legacy migration recovery marker is missing: ' . $marker );
}
foreach ( array( 'CHECKPOINT_INVALID', 'BATCH_SIZE', 'FOR UPDATE' ) as $marker ) {
	g6_imp03_assert( str_contains( $purge_repository, $marker ), 'Purge recovery marker is missing: ' . $marker );
}

$runtime_files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/src', FilesystemIterator::SKIP_DOTS ) );
foreach ( $runtime_files as $file ) {
	if ( ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) {
		continue;
	}
	$source = file_get_contents( $file->getPathname() );
	g6_imp03_assert( is_string( $source ), 'Runtime source could not be read: ' . $file->getPathname() );
	foreach ( array( '$wpdb->prefix', '$wpdb->base_prefix', 'wp_oj_ai_', 'CodingPanda\\OjPlatform\\Kernel\\' ) as $forbidden ) {
		g6_imp03_assert( ! str_contains( $source, $forbidden ), 'Runtime crossed a frozen boundary: ' . $file->getPathname() . ' -> ' . $forbidden );
	}
}

foreach ( array( 'G6-IMP-03-S01', 'G6-IMP-03-S05', 'id="amendment-11"', '28 existing gates', 'G6 3/5' ) as $marker ) {
	g6_imp03_assert( str_contains( $design_source, $marker ), 'A-DS-16 is missing G6-IMP-03 marker: ' . $marker );
}
foreach ( array( 'check:performance-resilience-g6', 'check-performance-resilience-g6-imp-03.ps1' ) as $marker ) {
	g6_imp03_assert( str_contains( $package_source, $marker ), 'The package command is missing G6-IMP-03 marker: ' . $marker );
}
foreach ( array( 'G6-IMP-03 performance and resilience passed.', '-SkipConstituentChecks' ) as $marker ) {
	g6_imp03_assert( str_contains( $runner_source, $marker ), 'The aggregate runner is missing G6-IMP-03 marker: ' . $marker );
}

fwrite( STDOUT, "G6-IMP-03 passed: five acceptance dimensions map to 28 existing gates; frozen roster, queue, document, archive, report, export, checkpoint, repair, and restart bounds remain enforced.\n" );
