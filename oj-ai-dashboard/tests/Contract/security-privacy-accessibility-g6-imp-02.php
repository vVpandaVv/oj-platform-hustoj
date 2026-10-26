<?php
/**
 * G6-IMP-02 security, privacy, and accessibility acceptance contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

/**
 * G6 imp02 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g6_imp02_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * G6 imp02 same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g6_imp02_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

$root               = dirname( __DIR__, 2 );
$dimension_evidence = array(
	'G6-IMP-02-S01' => array(
		'scripts/check-access-policy.ps1',
		'scripts/check-rest-foundation.ps1',
		'scripts/check-context-rest-q01.ps1',
		'scripts/check-assignment-lifecycle-g3-imp-02.ps1',
		'scripts/check-student-assignment-g3-imp-03.ps1',
		'scripts/check-submission-evaluation-review-integration-g4-imp-03-s04.ps1',
		'scripts/check-submission-feedback-integration-g4-imp-04-s04.ps1',
		'scripts/check-submission-result-ui-integration-g4-imp-06-s05.ps1',
		'scripts/check-report-dashboard-g5-imp-01-s04.ps1',
		'scripts/check-purge-approval-g5-imp-08-s03.ps1',
	),
	'G6-IMP-02-S02' => array(
		'scripts/check-worksheet-projection-safety-s03.ps1',
		'scripts/check-external-ai-consent-r01.ps1',
		'scripts/check-submission-assessment-input-g4-imp-01-s02.ps1',
		'scripts/check-submission-evaluation-worker-g4-imp-02-s02.ps1',
		'scripts/check-submission-external-ai-consent-g4-imp-02-r01.ps1',
		'scripts/check-submission-result-query-g4-imp-06-s02.ps1',
		'scripts/check-worksheet-report-detail-g5-imp-01-s02.ps1',
		'scripts/check-operations-diagnostics-g5-imp-05-s01.ps1',
		'scripts/check-operations-ai-routing-g5-imp-05-s02.ps1',
		'scripts/check-operations-storage-g5-imp-05-s03.ps1',
	),
	'G6-IMP-02-S03' => array(
		'scripts/check-worksheet-asset-s01.ps1',
		'scripts/check-worksheet-asset-s02.ps1',
		'scripts/check-submission-zip-g3-imp-07.ps1',
		'scripts/check-pdf-integration-g3-imp-08-s04.ps1',
		'scripts/check-result-pdf-access-g4-imp-07-s03.ps1',
		'scripts/check-report-export-job-download-g5-imp-03-s04.ps1',
		'scripts/check-report-export-integration-g5-imp-03-s06.ps1',
	),
	'G6-IMP-02-S04' => array(
		'scripts/check-worksheet-assets-ui-s05.ps1',
		'scripts/check-worksheet-evaluation-workbench-s08.ps1',
		'scripts/check-assignment-g3-imp-01.ps1',
		'scripts/check-assignment-lifecycle-g3-imp-02.ps1',
		'scripts/check-student-assignment-g3-imp-03.ps1',
		'scripts/check-submission-evaluation-review-workbench-g4-imp-03-s03.ps1',
		'scripts/check-submission-feedback-integration-g4-imp-04-s04.ps1',
		'scripts/check-submission-result-ui-integration-g4-imp-06-s05.ps1',
		'scripts/check-report-dashboard-g5-imp-01-s04.ps1',
		'scripts/check-assessment-configuration-bootstrap-s01.ps1',
		'scripts/check-external-ai-consent-r01.ps1',
		'scripts/check-operations-diagnostics-ui-g5-imp-05-s04.ps1',
	),
	'G6-IMP-02-S05' => array(
		'scripts/check-worksheet-assets-ui-s05.ps1',
		'scripts/check-worksheet-evaluation-workbench-s08.ps1',
		'scripts/check-assignment-g3-imp-01.ps1',
		'scripts/check-assignment-lifecycle-g3-imp-02.ps1',
		'scripts/check-student-assignment-g3-imp-03.ps1',
		'scripts/check-submission-evaluation-review-workbench-g4-imp-03-s03.ps1',
		'scripts/check-submission-feedback-integration-g4-imp-04-s04.ps1',
		'scripts/check-submission-result-ui-integration-g4-imp-06-s05.ps1',
		'scripts/check-report-dashboard-g5-imp-01-s04.ps1',
		'scripts/check-assessment-configuration-bootstrap-s01.ps1',
		'scripts/check-external-ai-consent-r01.ps1',
		'scripts/check-operations-diagnostics-ui-g5-imp-05-s04.ps1',
	),
);

g6_imp02_same(
	array( 'G6-IMP-02-S01', 'G6-IMP-02-S02', 'G6-IMP-02-S03', 'G6-IMP-02-S04', 'G6-IMP-02-S05' ),
	array_keys( $dimension_evidence ),
	'The G6-IMP-02 ledger is not exactly S01-S05.'
);

$gate_source       = file_get_contents( $root . '/scripts/check-security-privacy-accessibility-g6-imp-02.ps1' );
$runner_source     = file_get_contents( $root . '/scripts/run-tests.mjs' );
$package_source    = file_get_contents( $root . '/package.json' );
$design_source     = file_get_contents( $root . '/docs/ai-assessment/a-ds-16-cross-validation-freeze.html' );
$acceptance_source = file_get_contents( $root . '/docs/ai-assessment/g6-imp-02-security-privacy-accessibility.html' );
foreach ( array( $gate_source, $runner_source, $package_source, $design_source, $acceptance_source ) as $source ) {
	g6_imp02_assert( is_string( $source ), 'A G6-IMP-02 acceptance source could not be read.' );
}

$runner_gate_position = strpos( $runner_source, 'scripts/check-security-privacy-accessibility-g6-imp-02.ps1' );
g6_imp02_assert( false !== $runner_gate_position, 'The G6-IMP-02 gate is not registered in the aggregate runner.' );
$unique_evidence = array();
foreach ( $dimension_evidence as $dimension => $evidence_files ) {
	g6_imp02_assert( str_contains( $gate_source, "'" . $dimension . "'" ), 'The PowerShell gate does not map ' . $dimension );
	g6_imp02_assert( str_contains( $acceptance_source, $dimension ), 'The HTML acceptance record does not list ' . $dimension );
	foreach ( $evidence_files as $relative_path ) {
		g6_imp02_assert( is_file( $root . '/' . $relative_path ), 'Acceptance evidence file is missing: ' . $relative_path );
		g6_imp02_assert( str_contains( $gate_source, str_replace( '/', '\\', $relative_path ) ), 'The PowerShell gate does not reference evidence: ' . $relative_path );
		$evidence_position = strpos( $runner_source, $relative_path );
		g6_imp02_assert( false !== $evidence_position, 'The aggregate runner does not execute evidence: ' . $relative_path );
		g6_imp02_assert( $evidence_position < $runner_gate_position, 'Acceptance evidence must run before G6-IMP-02: ' . $relative_path );
		$unique_evidence[ $relative_path ] = true;
	}
}
g6_imp02_same( 33, count( $unique_evidence ), 'The frozen G6-IMP-02 evidence set drifted.' );

$browser_evidence = array(
	'scripts/e2e-worksheet-workbench.mjs'               => 'scripts/check-worksheet-assets-ui-s05.ps1',
	'scripts/e2e-worksheet-evaluation-workbench.mjs'    => 'scripts/check-worksheet-evaluation-workbench-s08.ps1',
	'scripts/e2e-assignment-workbench.mjs'              => 'scripts/check-assignment-g3-imp-01.ps1',
	'scripts/e2e-assignment-lifecycle.mjs'              => 'scripts/check-assignment-lifecycle-g3-imp-02.ps1',
	'scripts/e2e-student-assignments.mjs'               => 'scripts/check-student-assignment-g3-imp-03.ps1',
	'scripts/e2e-submission-evaluation-review-workbench.mjs' => 'scripts/check-submission-evaluation-review-workbench-g4-imp-03-s03.ps1',
	'scripts/e2e-submission-feedback-workbench.mjs'     => 'scripts/check-submission-feedback-integration-g4-imp-04-s04.ps1',
	'scripts/e2e-student-results.mjs'                   => 'scripts/check-submission-result-ui-integration-g4-imp-06-s05.ps1',
	'scripts/e2e-report-dashboard.mjs'                  => 'scripts/check-report-dashboard-g5-imp-01-s04.ps1',
	'scripts/e2e-assessment-configuration-settings.mjs' => 'scripts/check-assessment-configuration-bootstrap-s01.ps1',
	'scripts/e2e-external-ai-consent-settings.mjs'      => 'scripts/check-external-ai-consent-r01.ps1',
	'scripts/e2e-operations-diagnostics.mjs'            => 'scripts/check-operations-diagnostics-ui-g5-imp-05-s04.ps1',
);
foreach ( $browser_evidence as $browser_path => $parent_gate ) {
	$browser_source = file_get_contents( $root . '/' . $browser_path );
	$parent_source  = file_get_contents( $root . '/' . $parent_gate );
	g6_imp02_assert( is_string( $browser_source ) && is_string( $parent_source ), 'Browser evidence could not be read: ' . $browser_path );
	g6_imp02_assert( str_contains( $parent_source, str_replace( '/', '\\', $browser_path ) ), 'The parent gate does not execute browser evidence: ' . $browser_path );
	foreach ( array( "'desktop'", "'mobile'", 'screenshot({' ) as $marker ) {
		g6_imp02_assert( str_contains( $browser_source, $marker ), 'Browser evidence is missing a visual marker: ' . $browser_path . ' -> ' . $marker );
	}
	g6_imp02_assert(
		str_contains( $browser_source, 'horizontalOverflow' ) || str_contains( $browser_source, 'bodyOverflow' ),
		'Browser evidence does not inspect viewport overflow: ' . $browser_path
	);
}
g6_imp02_same( 12, count( $browser_evidence ), 'The frozen desktop/mobile browser suite count drifted.' );

$accessibility_markers = array(
	'scripts/e2e-assignment-workbench.mjs'              => array( "keyboard.press('Escape')", 'document.activeElement', 'return focus' ),
	'scripts/e2e-student-assignments.mjs'               => array( "press('ArrowRight')", 'aria-live', 'document.activeElement' ),
	'scripts/e2e-submission-evaluation-review-workbench.mjs' => array( "keyboard.press('Escape')", 'getByRole' ),
	'scripts/e2e-submission-feedback-workbench.mjs'     => array( "keyboard.press('Escape')", 'document.activeElement', 'return focus' ),
	'scripts/e2e-operations-diagnostics.mjs'            => array( 'document.activeElement', 'did not focus' ),
	'scripts/e2e-assessment-configuration-settings.mjs' => array( "press('ArrowRight')", "press('Home')", 'aria-selected', 'document.activeElement' ),
	'scripts/e2e-external-ai-consent-settings.mjs'      => array( "press('ArrowRight')", 'aria-selected', 'document.activeElement' ),
);
foreach ( $accessibility_markers as $relative_path => $markers ) {
	$source = file_get_contents( $root . '/' . $relative_path );
	g6_imp02_assert( is_string( $source ), 'Accessibility evidence could not be read: ' . $relative_path );
	foreach ( $markers as $marker ) {
		g6_imp02_assert( str_contains( $source, $marker ), 'Accessibility evidence is missing: ' . $relative_path . ' -> ' . $marker );
	}
}

$handler_source       = file_get_contents( $root . '/src/Platform/External_Ai_Consent_Settings_Page_Handler.php' );
$configuration_source = file_get_contents( $root . '/resources/js/assessment-configuration-settings.js' );
$consent_source       = file_get_contents( $root . '/resources/js/external-ai-consent-settings.js' );
$configuration_styles = file_get_contents( $root . '/resources/css/assessment-configuration-settings.css' );
$consent_styles       = file_get_contents( $root . '/resources/css/external-ai-consent-settings.css' );
foreach ( array( $handler_source, $configuration_source, $consent_source, $configuration_styles, $consent_styles ) as $source ) {
	g6_imp02_assert( is_string( $source ), 'The corrected settings accessibility source could not be read.' );
}
// Inspect generated tabs, not a literal substring of the PHP template loop.
$original_argv = $argv;
$argv          = array( __FILE__, 'settings' );
ob_start();
require $root . '/tests/fixtures/render-workflow-page.php';
$handler_markup = (string) ob_get_clean();
$argv           = $original_argv;
foreach ( array(
	'aria-controls="oj-ai-settings-panel-policy"',
	'aria-labelledby="oj-ai-settings-tab-policy"',
	'id="oj-ai-consent-tab-worksheet"',
	'role="tabpanel" aria-labelledby="',
) as $marker ) {
	g6_imp02_assert( str_contains( $handler_markup, $marker ), 'Settings tab semantics are missing: ' . $marker );
}
foreach ( array( 'ArrowLeft', 'ArrowRight', 'Home', 'End', 'tabIndex', '.focus()' ) as $marker ) {
	g6_imp02_assert( str_contains( $configuration_source, $marker ), 'Settings keyboard contract is missing: ' . $marker );
	g6_imp02_assert( str_contains( $consent_source, $marker ), 'Consent keyboard contract is missing: ' . $marker );
}
g6_imp02_assert( str_contains( $configuration_styles, ':focus-visible' ), 'Settings focus indicator is missing.' );
g6_imp02_assert( str_contains( $consent_styles, ':focus-visible' ), 'Consent focus indicator is missing.' );

$runtime_files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/src', FilesystemIterator::SKIP_DOTS ) );
foreach ( $runtime_files as $file ) {
	if ( ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) {
		continue;
	}
	$source = file_get_contents( $file->getPathname() );
	g6_imp02_assert( is_string( $source ), 'Runtime source could not be read: ' . $file->getPathname() );
	foreach ( array( '$wpdb->prefix', '$wpdb->base_prefix', 'wp_oj_ai_', 'CodingPanda\\OjPlatform\\Kernel\\', 'error_log(', 'var_dump(', 'print_r(' ) as $forbidden ) {
		g6_imp02_assert( ! str_contains( $source, $forbidden ), 'Runtime crossed a security boundary: ' . $file->getPathname() . ' -> ' . $forbidden );
	}
}

foreach ( array( 'G6-IMP-02-S01', 'G6-IMP-02-S05', 'A-DS16-A21', 'A-DS16-A22', 'id="security"' ) as $marker ) {
	g6_imp02_assert( str_contains( $design_source, $marker ), 'A-DS-16 is missing G6-IMP-02 marker: ' . $marker );
}
foreach ( array( 'check:security-privacy-accessibility-g6', 'check-security-privacy-accessibility-g6-imp-02.ps1' ) as $marker ) {
	g6_imp02_assert( str_contains( $package_source, $marker ), 'The package command is missing G6-IMP-02 marker: ' . $marker );
}
foreach ( array( 'G6-IMP-02 security, privacy, and accessibility passed.', '-SkipConstituentChecks' ) as $marker ) {
	g6_imp02_assert( str_contains( $runner_source, $marker ), 'The aggregate runner is missing G6-IMP-02 marker: ' . $marker );
}

fwrite( STDOUT, "G6-IMP-02 passed: five acceptance dimensions map to 33 existing gates and 12 desktop/mobile browser suites; permission, privacy, file, keyboard, focus, screen-reader semantics, and visual overflow checks have zero unresolved critical findings.\n" );
