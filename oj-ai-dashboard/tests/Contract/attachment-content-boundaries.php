<?php
/**
 * Regression checks for bounded attachment reads and archive validation.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

require __DIR__ . '/student-submission-attachment-scan-g3-imp-05-s03.php';

use OJAI\Dashboard\Submission\Student\Application\Run_Submission_Attachment_Scan_Result;
use OJAI\Dashboard\Submission\Student\Domain\Submission_Attachment_Malware_Verdict;
use OJAI\Dashboard\Submission\ZipImport\Domain\Submission_Zip_Entry_Reader;
use OJAI\Dashboard\Submission\ZipImport\Domain\Submission_Zip_Item_Content_Inspector;
use OJAI\Dashboard\Submission\ZipImport\Domain\Submission_Zip_Item_Content_Verdict;
use OJAI\Dashboard\Submission\ZipImport\Domain\Submission_Zip_Upload_Profile;

$pdf     = "%PDF-1.4\n";
$offsets = array( 0 );
foreach ( array(
	'<< /Type /Catalog /Pages 2 0 R >>',
	'<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
	'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] >>',
) as $index => $object ) {
	$offsets[] = strlen( $pdf );
	$pdf      .= ( $index + 1 ) . " 0 obj\n" . $object . "\nendobj\n";
}
$xref = strlen( $pdf );
$pdf .= "xref\n0 4\n0000000000 65535 f \n";
foreach ( array_slice( $offsets, 1 ) as $offset ) {
	$pdf .= sprintf( "%010d 00000 n \n", $offset );
}
$pdf .= "trailer\n<< /Size 4 /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
g3_assert( strlen( $pdf ) < 1024, 'Small PDF fixture must exercise the previous negative seek.' );

$boundary_path = tempnam( sys_get_temp_dir(), 'ojai-content-boundary-' );
g3_assert( is_string( $boundary_path ), 'Could not create attachment fixture.' );
try {
	$zip_inspector = new Submission_Zip_Item_Content_Inspector( new Submission_Zip_Entry_Reader( new Submission_Zip_Upload_Profile() ) );
	foreach ( array(
		'small'              => array( $pdf, true ),
		'exact-window'       => array( $pdf . str_repeat( "\n", 1024 - strlen( $pdf ) ), true ),
		'large'              => array( str_replace( 'trailer', '%' . str_repeat( 'padding', 400 ) . "\ntrailer", $pdf ), true ),
		'truncated'          => array( substr( $pdf, 0, -6 ), false ),
		'misleading-header'  => array( str_replace( '%PDF-', 'plain', $pdf ), false ),
		'eof-outside-window' => array( $pdf . str_repeat( 'x', 1024 ), false ),
	) as $case => [ $bytes, $accepted ] ) {
		file_put_contents( $boundary_path, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Isolated CLI fixture, never a WordPress upload.
		clearstatcache( true, $boundary_path );
		$item       = c26_item(
			$boundary_path,
			array(
				'attachment_mime_type'     => 'application/pdf',
				'attachment_original_name' => 'answer.pdf',
				'file_mime_type'           => 'application/pdf',
				'file_original_name'       => 'answer.pdf',
			)
		);
		[ $result ] = c26_run( $item, $boundary_path, Submission_Attachment_Malware_Verdict::clean() );
		g3_same(
			$accepted ? Run_Submission_Attachment_Scan_Result::READY : Run_Submission_Attachment_Scan_Result::REJECTED,
			$result->state(),
			'PDF inspection boundary failed: ' . $case
		);
		$zip_result = $zip_inspector->inspect( $boundary_path, 'answer.pdf', strlen( $bytes ), hash( 'sha256', $bytes ) );
		g3_same(
			$accepted ? Submission_Zip_Item_Content_Verdict::CLEAN : Submission_Zip_Item_Content_Verdict::REJECTED,
			$zip_result->state(),
			'ZIP PDF inspection boundary failed: ' . $case
		);
	}

	g3_assert( class_exists( ZipArchive::class ), 'ZIP extension is required for archive boundary tests.' );
	foreach ( array(
		'valid'            => array( '[Content_Types].xml', 'word/document.xml' ),
		'one-entry'        => array( '[Content_Types].xml' ),
		'path-traversal'   => array( '[Content_Types].xml', 'word/../payload.xml' ),
		'forbidden-script' => array( '[Content_Types].xml', 'word/payload.js' ),
		'wrong-root'       => array( '[Content_Types].xml', 'xl/workbook.xml' ),
	) as $case => $entries ) {
		$archive = new ZipArchive();
		g3_same( true, $archive->open( $boundary_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ), 'Could not create archive fixture.' );
		foreach ( $entries as $entry ) {
			$archive->addFromString( $entry, '<root/>' );
		}
		$archive->close();
		clearstatcache( true, $boundary_path );
		$mime       = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
		$item       = c26_item(
			$boundary_path,
			array(
				'attachment_mime_type'     => $mime,
				'attachment_original_name' => 'answer.docx',
				'file_mime_type'           => $mime,
				'file_original_name'       => 'answer.docx',
			)
		);
		[ $result ] = c26_run( $item, $boundary_path, Submission_Attachment_Malware_Verdict::clean() );
		g3_same(
			'valid' === $case ? Run_Submission_Attachment_Scan_Result::READY : Run_Submission_Attachment_Scan_Result::REJECTED,
			$result->state(),
			'Archive inspection boundary failed: ' . $case
		);
	}
} finally {
	unlink( $boundary_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only this test's tempnam-owned file.
}

echo "Attachment content boundaries PASS: small PDF, bounded tail, truncation and unsafe archives.\n";
