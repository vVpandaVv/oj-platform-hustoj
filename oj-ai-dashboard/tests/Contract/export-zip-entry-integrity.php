<?php
/**
 * Completed ZIP contents must match the identities checked before assembly.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Reporting\Export\Infrastructure\Zip_Entry_Integrity;
use OJAI\Dashboard\Reporting\SubmissionBundle\Application\Submission_Bundle_Failure;

ob_start();
require __DIR__ . '/report-export-job-download-g5-imp-03-s04.php';
ob_end_clean();

/**
 * Oj ai dashboard zip identities.
 *
 * @param array $entries Entries.
 * @return array<string,array{byte_size:int,sha256:string}>
 */
function oj_ai_dashboard_zip_identities( array $entries ): array {
	return array_map(
		static fn ( string $bytes ): array => array(
			'byte_size' => strlen( $bytes ),
			'sha256'    => hash( 'sha256', $bytes ),
		),
		$entries
	);
}

$zip_integrity_files  = array();
$zip_integrity_checks = 0;
$zip_integrity_assert = static function ( bool $condition, string $message ) use ( &$zip_integrity_checks ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
	++$zip_integrity_checks;
};
try {
	$path = tempnam( sys_get_temp_dir(), 'oj-ai-zip-integrity-' );
	if ( false === $path ) {
		throw new RuntimeException( 'Integrity fixture unavailable.' );
	}
	$zip_integrity_files[] = $path;
	$contents              = array(
		'sample.txt' => 'original',
		'empty.txt'  => '',
	);
	$zip                   = new ZipArchive();
	$zip_integrity_assert( true === $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ), 'Open ZIP fixture.' );
	foreach ( $contents as $name => $bytes ) {
		$zip->addFromString( $name, $bytes );
	}
	$zip->close();
	$expected = oj_ai_dashboard_zip_identities( $contents );
	$before   = hash_file( 'sha256', $path );
	$zip_integrity_assert( Zip_Entry_Integrity::matches( $path, $expected ), 'Valid and empty entries match.' );
	$zip_integrity_assert( $before === hash_file( 'sha256', $path ), 'Verification must not change archive bytes.' );
	$wrong_size = $expected;
	++$wrong_size['sample.txt']['byte_size'];
	$zip_integrity_assert( ! Zip_Entry_Integrity::matches( $path, $wrong_size ), 'Wrong uncompressed size is rejected.' );
	$wrong_hash                         = $expected;
	$wrong_hash['sample.txt']['sha256'] = hash( 'sha256', 'modified' );
	$zip_integrity_assert( ! Zip_Entry_Integrity::matches( $path, $wrong_hash ), 'Same-size content change is rejected.' );
	$missing = $expected;
	unset( $missing['empty.txt'] );
	$zip_integrity_assert( ! Zip_Entry_Integrity::matches( $path, $missing ), 'Unexpected ZIP entries are rejected.' );
	$extra                = $expected;
	$extra['missing.txt'] = $expected['sample.txt'];
	$zip_integrity_assert( ! Zip_Entry_Integrity::matches( $path, $extra ), 'Missing ZIP entries are rejected.' );
	$renamed                = $expected;
	$renamed['renamed.txt'] = $renamed['sample.txt'];
	unset( $renamed['sample.txt'] );
	$zip_integrity_assert( ! Zip_Entry_Integrity::matches( $path, $renamed ), 'Renamed entries are rejected even when count matches.' );
	file_put_contents( $path, 'not a ZIP' );
	$zip_integrity_assert( ! Zip_Entry_Integrity::matches( $path, $expected ), 'Invalid archive is rejected.' );

	// Simulate an attachment changing after its identity was captured but before addFile reads it.
	$source_path = tempnam( sys_get_temp_dir(), 'oj-ai-zip-source-' );
	if ( false === $source_path ) {
		throw new RuntimeException( 'Race fixture unavailable.' );
	}
	$zip_integrity_files[] = $source_path;
	$captured              = array();
	foreach ( $bundle_entries as $name => $bytes ) {
		$captured[ $name ] = array(
			'kind'      => 'string',
			'value'     => $bytes,
			'sha256'    => hash( 'sha256', $bytes ),
			'byte_size' => strlen( $bytes ),
		);
	}
	$pdf_name = array_values( array_filter( array_keys( $captured ), static fn ( string $name ): bool => str_ends_with( $name, '.pdf' ) ) )[0];
	$original = $captured[ $pdf_name ]['value'];
	file_put_contents( $source_path, $original );
	$captured[ $pdf_name ]['kind']  = 'file';
	$captured[ $pdf_name ]['value'] = $source_path;
	$zip_method                     = new ReflectionMethod( $bundle_writer, 'zip' );
	$good                           = $zip_method->invoke( $bundle_writer, $bundle_snapshot, $captured, 0, 2, 'fixture' );
	$zip_integrity_files[]          = $good->path();
	$zip_integrity_assert( is_file( $good->path() ), 'Actual bundle writer accepts unchanged captured source.' );
	file_put_contents( $source_path, str_repeat( 'x', strlen( $original ) ) );
	$before_failed_write = (array) glob( sys_get_temp_dir() . '/oj-ai-submissions-*' );
	try {
		$bad                   = $zip_method->invoke( $bundle_writer, $bundle_snapshot, $captured, 0, 2, 'fixture' );
		$zip_integrity_files[] = $bad->path();
		throw new RuntimeException( 'Changed source incorrectly produced a downloadable bundle.' );
	} catch ( Submission_Bundle_Failure $failure ) {
		$zip_integrity_assert( Submission_Bundle_Failure::SOURCE_STALE === $failure->reason(), 'Race returns stable source-stale failure.' );
	}
	$after_failed_write = (array) glob( sys_get_temp_dir() . '/oj-ai-submissions-*' );
	$zip_integrity_assert( $before_failed_write === $after_failed_write, 'Failed archive leaves no generated file behind.' );
} finally {
	foreach ( $zip_integrity_files as $file ) {
		if ( is_file( $file ) ) {
			unlink( $file );
		}
	}
}
echo 'PASS: ZIP entry integrity ', (int) $zip_integrity_checks, ' checks and existing export writer regression.', PHP_EOL;
