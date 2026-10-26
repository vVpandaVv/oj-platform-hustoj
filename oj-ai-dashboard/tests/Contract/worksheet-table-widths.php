<?php
/**
 * Content-table sizing survives canonicalization, previews, screen and PDF output.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

require __DIR__ . '/worksheet-document-profile-s03-contract.php';
require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Preview_Projector;
use OJAI\Dashboard\Reporting\WorksheetPdf\Domain\Worksheet_Print_Html_Serializer;
use OJAI\Dashboard\Reporting\SubmissionPdf\Domain\Submission_Print_Html_Serializer;
use OJAI\Dashboard\Reporting\SubmissionPdf\Application\Build_Submission_Print_Projection;

$table    = array_values( array_filter( $canonical['nodes'], static fn ( $node ) => 'table' === $node['type'] ) )[0];
$document = s03_document( array( $table, s03_element( 'h1', array(), array( array( 'text' => 'Table widths' ) ) ) ) );
$legacy   = s03_canonicalizer()->canonicalize( $document );
s03_assert( ! isset( $legacy->document()['nodes'][0]['column_widths'] ), 'Old table shape must not change.' );
$document['nodes'][0]['column_widths'] = array( 180, 420 );
$sized                                 = s03_canonicalizer()->canonicalize( $document );
s03_same( array( 180, 420 ), $sized->document()['nodes'][0]['column_widths'], 'Save widths.' );
s03_same( $sized->document_hash(), s03_canonicalizer()->canonicalize( $sized->document() )->document_hash(), 'Stable roundtrip hash.' );
s03_assert( $legacy->document_hash() !== $sized->document_hash(), 'Width edits must change document hash.' );
foreach ( array(
	array(),
	array( 180 ),
	array( 0, 420 ),
	array( 40, 1601 ),
	array( 180.5, 420 ),
	array( '180', 420 ),
	array(
		'width' => 180,
		'other' => 420,
	),
	'600px',
) as $invalidWidths ) {
	$invalidDocument                              = $document;
	$invalidDocument['nodes'][0]['column_widths'] = $invalidWidths;
	try {
		s03_canonicalizer()->canonicalize( $invalidDocument );
		throw new RuntimeException( 'Invalid table widths were accepted.' );
	} catch ( \OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Validation_Failure ) {
		// Invalid widths must be rejected before persistence.
		continue;
	}
}
$projector = new Worksheet_Preview_Projector( new Canonical_Json() );
$student   = $projector->student( $sized )->projection();
s03_same( array( 180, 420 ), $student['student_document']['payload']['nodes'][0]['column_widths'], 'Student projection widths.' );
$print = $projector->print( $sized, 'student_blank' )->projection();
$html  = ( new Worksheet_Print_Html_Serializer() )->serialize( $print, 'table-width-test' );
s03_assert( str_contains( $html, 'width:600px;max-width:100%' ) && str_contains( $html, 'width:30%' ) && str_contains( $html, 'width:70%' ), 'Print proportions.' );
$payload    = array(
	'profile'     => Build_Submission_Print_Projection::PROFILE,
	'document'    => $student['student_document']['payload'],
	'assignment'  => array( 'title' => 'Table widths' ),
	'learner'     => array(),
	'submission'  => array(),
	'provenance'  => array(),
	'answers'     => array(),
	'attachments' => array(),
);
$submission = array(
	'kind'                        => 'submission_print',
	'profile'                     => Build_Submission_Print_Projection::PROFILE,
	'submission_print_projection' => array(
		'schema_id'         => Build_Submission_Print_Projection::SCHEMA,
		'schema_version'    => Build_Submission_Print_Projection::VERSION,
		'projector_version' => Build_Submission_Print_Projection::PROJECTOR_VERSION,
		'payload'           => $payload,
		'payload_hash'      => hash( 'sha256', ( new Canonical_Json() )->encode( $payload ) ),
	),
);
$screen     = ( new Submission_Print_Html_Serializer( new Canonical_Json() ) )->serialize( $submission, 'table-width-test', true );
s03_assert( str_contains( $screen, 'width:600px;max-width:100%' ) && str_contains( $screen, 'width:30%' ), 'Submission screen widths.' );

foreach ( array( 600, 2400 ) as $total ) {
	$document['nodes'][0]['column_widths'] = array( (int) ( $total * 0.4 ), (int) ( $total * 0.6 ) );
	$print                                 = $projector->print( s03_canonicalizer()->canonicalize( $document ), 'student_blank' )->projection();
	$html                                  = ( new Worksheet_Print_Html_Serializer() )->serialize( $print, 'table-width-test' );
	$dompdf                                = new \Dompdf\Dompdf(
		array(
			'isRemoteEnabled' => false,
			'defaultFont'     => 'Helvetica',
		)
	);
	$cells                                 = array();
	$dompdf->setCallbacks(
		array(
			array(
				'event' => 'end_frame',
				'f'     => static function ( $frame ) use ( &$cells ): void {
					if ( in_array( $frame->get_node()->nodeName, array( 'td', 'th' ), true ) ) {
						$cells[] = $frame->get_border_box();
					}
				},
			),
		)
	);
	$dompdf->loadHtml( $html );
	$dompdf->setPaper( 'A4' );
	$dompdf->render();
	s03_same( 4, count( $cells ), 'Render all PDF cells.' );
	$width = $cells[0]['w'] + $cells[1]['w'];
	s03_assert( abs( $cells[0]['w'] / $width - 0.4 ) < 0.02, 'PDF column ratio must match.' );
	s03_assert( $width < 520, 'Wide PDF tables must fit A4 content.' );
	if ( 600 === $total ) {
		s03_assert( abs( $width - 450 ) < 4, '600 CSS px should render as 450 PDF points.' );
	}
}
$percentDocument = $document;
unset( $percentDocument['nodes'][0]['column_widths'] );
$percentDocument['nodes'][0]['width_percent'] = 80;
$percentDocument['nodes'][0]['column_ratios'] = array( 3000, 7000 );
$relative                                     = s03_canonicalizer()->canonicalize( $percentDocument );
s03_same( $relative->document_hash(), s03_canonicalizer()->canonicalize( $relative->document() )->document_hash(), 'Percentage roundtrip hash.' );
$relativePublic = $projector->student( $relative )->projection()['student_document']['payload'];
s03_same( 80, $relativePublic['nodes'][0]['width_percent'], 'Public table percentage.' );
s03_same( array( 3000, 7000 ), $relativePublic['nodes'][0]['column_ratios'], 'Public column ratios.' );
$payload['document']                                       = $relativePublic;
$submission['submission_print_projection']['payload']      = $payload;
$submission['submission_print_projection']['payload_hash'] = hash( 'sha256', ( new Canonical_Json() )->encode( $payload ) );
$screen = ( new Submission_Print_Html_Serializer( new Canonical_Json() ) )->serialize( $submission, 'relative-width-test', true );
s03_assert( str_contains( $screen, 'width:80%;max-width:100%' ) && str_contains( $screen, 'width:30%' ), 'Submission screen relative widths.' );
$pageWidths = array();
foreach ( array( 100, 80 ) as $percent ) {
	$percentDocument['nodes'][0]['width_percent'] = $percent;
	$print                                        = $projector->print( s03_canonicalizer()->canonicalize( $percentDocument ), 'student_blank' )->projection();
	$dompdf                                       = new \Dompdf\Dompdf(
		array(
			'isRemoteEnabled' => false,
			'defaultFont'     => 'Helvetica',
		)
	);
	$cells                                        = array();
	$dompdf->setCallbacks(
		array(
			array(
				'event' => 'end_frame',
				'f'     => static function ( $frame ) use ( &$cells ): void {
					if ( in_array( $frame->get_node()->nodeName, array( 'td', 'th' ), true ) ) {
						$cells[] = $frame->get_border_box();
					}
				},
			),
		)
	);
	$dompdf->loadHtml( ( new Worksheet_Print_Html_Serializer() )->serialize( $print, 'relative-width-test' ) );
	$dompdf->setPaper( 'A4' );
	$dompdf->render();
	s03_same( 4, count( $cells ), 'Render relative PDF cells.' );
	$pageWidths[ $percent ] = $cells[0]['w'] + $cells[1]['w'];
	s03_assert( abs( $cells[0]['w'] / $pageWidths[ $percent ] - 0.3 ) < 0.02, 'PDF preserves 30:70 column ratio.' );
}
s03_assert( $pageWidths[100] > 450 && $pageWidths[100] < 520, '100% PDF table fills the printable width.' );
s03_assert( abs( $pageWidths[80] / $pageWidths[100] - 0.8 ) < 0.01, '80% PDF table uses 80% of printable width.' );
foreach ( array(
	array( 'width_percent' => 0 ),
	array( 'width_percent' => 101 ),
	array( 'width_percent' => '80' ),
	array( 'width_percent' => 80.5 ),
	array( 'width_percent' => null ),
	array( 'column_ratios' => null ),
	array( 'column_ratios' => array( 10000 ) ),
	array( 'column_ratios' => array( 0, 10000 ) ),
	array( 'column_ratios' => array( 3000, 6000 ) ),
	array( 'column_ratios' => array( 3000.5, 6999.5 ) ),
	array( 'column_ratios' => array( '3000', 7000 ) ),
	array( 'column_widths' => array( 180, 420 ) ),
) as $invalidProperties ) {
	$invalidDocument             = $percentDocument;
	$invalidDocument['nodes'][0] = array_replace( $invalidDocument['nodes'][0], $invalidProperties );
	try {
		s03_canonicalizer()->canonicalize( $invalidDocument );
		throw new RuntimeException( 'Invalid table percentage/ratios were accepted.' );
	} catch ( \OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Validation_Failure ) {
		// Invalid percentages and mixed units must be rejected before persistence.
		continue;
	}
}
echo "Table widths passed: legacy/px/percent roundtrip, validation, public/screen projections, actual PDF proportions and page bounds.\n";
