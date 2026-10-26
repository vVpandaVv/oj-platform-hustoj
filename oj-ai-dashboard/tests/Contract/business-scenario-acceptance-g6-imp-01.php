<?php
/**
 * G6-IMP-01 business scenario acceptance contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Core\Autoloader;
use OJAI\Dashboard\Database\Foundation\Schema_Catalog;

$root = dirname( __DIR__, 2 );
defined( 'ABSPATH' ) || define( 'ABSPATH', $root . DIRECTORY_SEPARATOR );
require_once $root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core' . DIRECTORY_SEPARATOR . 'Autoloader.php';
( new Autoloader( $root . DIRECTORY_SEPARATOR . 'src' ) )->register();

/**
 * G6 imp01 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g6_imp01_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * G6 imp01 same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g6_imp01_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

$scenario_evidence = array(
	'A-DS16-S01' => array(
		'scripts/check-addon-api-consumer.ps1',
		'scripts/check-fresh-install-g5-imp-06-s01.ps1',
	),
	'A-DS16-S02' => array(
		'scripts/check-worksheet-approval-publication-ui-s05.ps1',
	),
	'A-DS16-S03' => array(
		'scripts/check-worksheet-evaluation-integration-s09.ps1',
	),
	'A-DS16-S04' => array(
		'scripts/check-assignment-lifecycle-g3-imp-02.ps1',
	),
	'A-DS16-S05' => array(
		'scripts/check-student-assignment-g3-imp-03.ps1',
	),
	'A-DS16-S06' => array(
		'scripts/check-submission-evaluation-integration-g4-imp-02-s05.ps1',
	),
	'A-DS16-S07' => array(
		'scripts/check-submission-evaluation-review-integration-g4-imp-03-s04.ps1',
		'scripts/check-submission-feedback-integration-g4-imp-04-s04.ps1',
		'scripts/check-submission-result-ui-integration-g4-imp-06-s05.ps1',
	),
	'A-DS16-S08' => array(
		'scripts/check-report-dashboard-g5-imp-01-s04.ps1',
		'scripts/check-report-snapshot-integration-g5-imp-02-s04.ps1',
		'scripts/check-report-export-integration-g5-imp-03-s06.ps1',
	),
	'A-DS16-S09' => array(
		'scripts/check-worksheet-pdf-package-g2-imp-07.ps1',
		'scripts/check-pdf-integration-g3-imp-08-s04.ps1',
		'scripts/check-result-pdf-ui-integration-g4-imp-07-s04.ps1',
		'scripts/check-report-export-integration-g5-imp-03-s06.ps1',
	),
	'A-DS16-S10' => array(
		'scripts/check-legacy-migration-integration-g5-imp-07-s06.ps1',
	),
	'A-DS16-S11' => array(
		'scripts/check-fresh-install-g5-imp-06-s01.ps1',
		'scripts/check-sequential-upgrade-g5-imp-06-s02.ps1',
		'scripts/check-failure-restart-g5-imp-06-s03.ps1',
		'scripts/check-forward-repair-g5-imp-06-s04.ps1',
		'scripts/check-data-preservation-g5-imp-06-s05.ps1',
	),
	'A-DS16-S12' => array(
		'scripts/check-purge-integration-g5-imp-08-s06.ps1',
	),
);

$expected_scenarios = array();
for ( $number = 1; $number <= 12; ++$number ) {
	$expected_scenarios[] = sprintf( 'A-DS16-S%02d', $number );
}
g6_imp01_same( $expected_scenarios, array_keys( $scenario_evidence ), 'The G6 scenario ledger is not exactly S01-S12.' );

$a_ds_16             = file_get_contents( $root . '/docs/ai-assessment/a-ds-16-cross-validation-freeze.html' );
$gate_source         = file_get_contents( $root . '/scripts/check-business-scenarios-g6-imp-01.ps1' );
$runner_source       = file_get_contents( $root . '/scripts/run-tests.mjs' );
$package_source      = file_get_contents( $root . '/package.json' );
$acceptance_document = file_get_contents( $root . '/docs/ai-assessment/g6-imp-01-business-scenario-acceptance.html' );
foreach ( array( $a_ds_16, $gate_source, $runner_source, $package_source, $acceptance_document ) as $source ) {
	g6_imp01_assert( is_string( $source ), 'A G6 acceptance source could not be read.' );
}

$scenario_table_start = strpos( $a_ds_16, '<h2 id="scenarios">' );
$scenario_table_end   = false === $scenario_table_start ? false : strpos( $a_ds_16, '</table>', $scenario_table_start );
g6_imp01_assert( false !== $scenario_table_start && false !== $scenario_table_end, 'The A-DS-16 scenario table is missing.' );
$scenario_table = substr( $a_ds_16, $scenario_table_start, $scenario_table_end - $scenario_table_start + strlen( '</table>' ) );

$runner_gate_position = strpos( $runner_source, 'scripts/check-business-scenarios-g6-imp-01.ps1' );
g6_imp01_assert( false !== $runner_gate_position, 'The G6 acceptance gate is not registered in the aggregate runner.' );
$unique_evidence = array();
foreach ( $scenario_evidence as $scenario_id => $evidence_files ) {
	g6_imp01_same( 1, substr_count( $scenario_table, '<code>' . $scenario_id . '</code>' ), 'A-DS-16 scenario row is missing or duplicated: ' . $scenario_id );
	g6_imp01_assert( str_contains( $gate_source, "'" . $scenario_id . "'" ), 'The G6 gate does not map scenario ' . $scenario_id );
	g6_imp01_assert( str_contains( $acceptance_document, $scenario_id ), 'The G6 HTML evidence does not list scenario ' . $scenario_id );
	foreach ( $evidence_files as $relative_path ) {
		g6_imp01_assert( is_file( $root . '/' . $relative_path ), 'Scenario evidence file is missing: ' . $relative_path );
		g6_imp01_assert( str_contains( $gate_source, str_replace( '/', '\\', $relative_path ) ), 'The G6 gate does not reference evidence: ' . $relative_path );
		$evidence_position = strpos( $runner_source, $relative_path );
		g6_imp01_assert( false !== $evidence_position, 'The aggregate runner does not execute evidence: ' . $relative_path );
		g6_imp01_assert( $evidence_position < $runner_gate_position, 'Scenario evidence must run before the G6 acceptance gate: ' . $relative_path );
		$unique_evidence[ $relative_path ] = true;
	}
}
g6_imp01_same( 22, count( $unique_evidence ), 'The frozen G6 evidence set drifted.' );

$catalog = new Schema_Catalog();
g6_imp01_same( 48, count( $catalog->table_names() ), 'The accepted schema is not exactly 48 literal tables.' );
g6_imp01_same( 10, count( $catalog->owner_counts() ), 'The accepted schema is not exactly 10 owners.' );
foreach ( $catalog->table_names() as $table_name ) {
	g6_imp01_assert( str_starts_with( $table_name, 'oj_ai_' ), 'A schema table crossed the literal oj_ai_* boundary: ' . $table_name );
}

$runtime_files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/src', FilesystemIterator::SKIP_DOTS ) );
foreach ( $runtime_files as $file ) {
	if ( ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) {
		continue;
	}
	$source = file_get_contents( $file->getPathname() );
	g6_imp01_assert( is_string( $source ), 'Runtime source could not be read: ' . $file->getPathname() );
	foreach ( array( '$wpdb->prefix', '$wpdb->base_prefix', 'wp_oj_ai_', 'CodingPanda\\OjPlatform\\Kernel\\' ) as $forbidden ) {
		g6_imp01_assert( ! str_contains( $source, $forbidden ), 'Runtime crossed a frozen boundary: ' . $file->getPathname() . ' -> ' . $forbidden );
	}
}

foreach ( array(
	'"check:business-scenarios-g6"',
	'check-business-scenarios-g6-imp-01.ps1',
) as $marker ) {
	g6_imp01_assert( str_contains( $package_source, $marker ), 'The package command is missing G6 marker: ' . $marker );
}
foreach ( array(
	'G6-IMP-01 business scenarios passed.',
	'-SkipConstituentChecks',
) as $marker ) {
	g6_imp01_assert( str_contains( $runner_source, $marker ), 'The aggregate runner is missing G6 marker: ' . $marker );
}

fwrite( STDOUT, "G6-IMP-01 passed: A-DS16-S01-S12 are mapped to 22 existing acceptance gates, run before the final scenario ledger, and preserve 48 literal tables, 10 owners, Addon API boundaries, and platform source isolation.\n" );
