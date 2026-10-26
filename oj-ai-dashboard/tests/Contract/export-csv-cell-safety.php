<?php
/**
 * CSV safety must be applied to actual report and submission-manifest bytes.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Reporting\SubmissionBundle\Domain\Submission_Bundle_Request_Snapshot;

ob_start();
require __DIR__ . '/report-export-job-download-g5-imp-03-s04.php';
ob_end_clean();

/**
 * Oj ai dashboard csv rows.
 *
 * @param string $bytes Bytes.
 * @return list<list<string|null>>
 * @throws \Throwable Propagates validation or dependency failures.
 */
function oj_ai_dashboard_csv_rows( string $bytes ): array {
	$stream = fopen( 'php://temp', 'w+b' );
	if ( false === $stream ) {
		throw new RuntimeException( 'CSV fixture unavailable.' );
	}
	try {
		fwrite( $stream, str_starts_with( $bytes, "\xEF\xBB\xBF" ) ? substr( $bytes, 3 ) : $bytes );
		rewind( $stream );
		$rows = array();
		$row  = fgetcsv( $stream, null, ',', '"', '' );
		while ( false !== $row ) {
			$rows[] = $row;
			$row    = fgetcsv( $stream, null, ',', '"', '' );
		}
		return $rows;
	} finally {
		fclose( $stream );
	}
}

$csv_cases = array();
foreach ( array( '=', '+', '-', '@', "\t", "\r", "\n", "\0", "\u{FF1D}", "\u{FF0B}", "\u{FF0D}", "\u{FF20}" ) as $prefix ) {
	$value       = $prefix . 'SUM(1,2)';
	$csv_cases[] = array( $value, "'" . $value );
}
foreach ( array( 'Normal title', '0', "\u{D65C}\u{B3D9}\u{C9C0}", "line 1\nline 2", 'quoted ",=SUM(1,2)', "'already text" ) as $value ) {
	$csv_cases[] = array( $value, $value );
}
$csv_files  = array();
$csv_checks = 0;
try {
	foreach ( $csv_cases as [ $value, $expected ] ) {
		$snapshot    = g5s04_report_snapshot( $g5s04_report_snapshot, 'report_csv', $g5s04_report_canonical, $value );
		$artifact    = $report_writer->write( $snapshot, $g5s04_report_manifest_json );
		$csv_files[] = $artifact->path();
		$rows        = oj_ai_dashboard_csv_rows( (string) file_get_contents( $artifact->path() ) );
		g5s04_assert( count( $rows ) === $snapshot->row_count() + 1, 'Report CSV row boundaries changed.' );
		g5s04_assert( count( $rows[0] ) === count( $rows[1] ), 'Report CSV column boundaries changed.' );
		g5s04_same( $expected, $rows[1][1], 'Report CSV unsafe or changed cell: ' . bin2hex( $value ) );
		$csv_checks += 3;

		$source = $bundle_source;
		unset( $source['source_hash'] );
		$source['submissions'][0]['target']['display_name'] = $value;
		$source['source_hash']                              = hash( 'sha256', $g5s04_submission_canonical->encode( $source ) );
		$bundle      = Submission_Bundle_Request_Snapshot::freeze( $source, $source['context'], 'ko-KR', false, $g5s04_submission_canonical );
		$artifact    = $bundle_writer->write( $bundle );
		$csv_files[] = $artifact->path();
		$entries     = g5s04_zip_entries( $artifact->path() );
		$rows        = oj_ai_dashboard_csv_rows( $entries['manifest.csv'] );
		g5s04_assert( 2 === ( count( $rows ) ), 'Submission manifest row boundaries changed.' );
		g5s04_assert( count( $rows[0] ) === count( $rows[1] ), 'Submission manifest column boundaries changed.' );
		g5s04_same( $expected, $rows[1][1], 'Submission manifest unsafe or changed cell: ' . bin2hex( $value ) );
		$csv_checks += 3;
	}
} finally {
	foreach ( $csv_files as $file ) {
		if ( is_file( $file ) ) {
			unlink( $file );
		}
	}
}
echo 'PASS: CSV cell safety ', (int) $csv_checks, ' checks across both actual export writers.', PHP_EOL;
