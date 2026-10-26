<?php
/**
 * Question section rendering.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Reporting\WorksheetPdf\Domain\Worksheet_Print_Html_Serializer;
use OJAI\Dashboard\Reporting\SubmissionPdf\Domain\Submission_Print_Html_Serializer;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Profile;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Preview_Projector;

require __DIR__ . '/submission-print-projection-g3-imp-08-s01.php';

$snapshot   = $canonical->encode( $result );
$projection = $result['print']['projection'];
$payload    = &$projection['submission_print_projection']['payload'];
$quote      = array(
	'type'     => 'blockquote',
	'children' => array(
		array(
			'type'     => 'p',
			'children' => array( array( 'text' => 'Quoted source remains a quotation.' ) ),
		),
	),
);
$notice     = array(
	'type'     => 'callout',
	'tone'     => 'notice',
	'icon'     => 'idea',
	'children' => array(
		array(
			'type'     => 'p',
			'children' => array( array( 'text' => 'Notice without an answer.' ) ),
		),
	),
);
$question   = array(
	'type'     => 'callout',
	'tone'     => 'notice',
	'icon'     => 'idea',
	'children' => array(
		array(
			'type'     => 'h2',
			'children' => array( array( 'text' => 'Explain your answer.' ) ),
		),
		$quote,
		array(
			'type'     => 'column',
			'children' => $payload['document']['nodes'],
		),
	),
);
g3s01_assert( Worksheet_Document_Profile::is_question_node( $question ), 'Nested responses must identify the existing question encoding.' );
g3s01_assert( ! Worksheet_Document_Profile::is_question_node( $quote ), 'Real quotations must stay quotations.' );
g3s01_assert( ! Worksheet_Document_Profile::is_question_node( $notice ), 'Notices without responses must not become questions.' );
g3s01_assert( ! Worksheet_Document_Profile::is_question_node( array_replace( $question, array( 'tone' => 'warning' ) ) ), 'Ordinary callouts must preserve their meaning.' );
$payload['document']['nodes']                              = array( $quote, $notice, $question );
$projection['submission_print_projection']['payload_hash'] = hash( 'sha256', $canonical->encode( $payload ) );
$submission_html = ( new Submission_Print_Html_Serializer( $canonical ) )->serialize( $projection, $submission_revision_id );
$worksheet_html  = ( new Worksheet_Print_Html_Serializer() )->serialize(
	array(
		'print_projection' => array(
			'schema_id' => Worksheet_Preview_Projector::PRINT_SCHEMA,
			'payload'   => array(
				'profile'  => 'student_blank',
				'document' => $payload['document'],
			),
		),
	),
	$document_id
);
$output          = dirname( __DIR__ ) . '/artifacts/question-sections';
if ( ! is_dir( $output ) ) {
	mkdir( $output, 0777, true );
}
foreach ( array(
	'worksheet-print'  => $worksheet_html,
	'submission-print' => $submission_html,
) as $name => $html ) {
	$dom = new DOMDocument();
	@$dom->loadHTML( $html ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- The fixture deliberately parses browser HTML without emitting libxml diagnostics.
	$xpath = new DOMXPath( $dom );
	g3s01_same( 1, $xpath->query( '//section[@class="question"]' )->length, 'One independent question section required.' );
	g3s01_same( 1, $xpath->query( '//aside[@class="callout"]' )->length, 'Only the real notice may be a callout.' );
	g3s01_same( 2, $xpath->query( '//blockquote' )->length, 'Both standalone and nested quotes must remain.' );
	g3s01_same( 10, $xpath->query( '//section[@class="question"]//section[@class="response"]' )->length, 'All answer kinds and associations must remain.' );
	g3s01_same( 0, $xpath->query( '//aside//section[@class="response"]' )->length, 'Answers must not be wrapped in a notice.' );
	file_put_contents( $output . '/' . $name . '.html', $html );
}
g3s01_same( $snapshot, $canonical->encode( $result ), 'Rendering must not mutate stored document snapshots.' );
echo "Question sections passed: both print serializers preserve responses and real quotations.\n";
