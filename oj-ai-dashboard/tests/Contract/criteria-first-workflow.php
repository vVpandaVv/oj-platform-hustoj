<?php
/**
 * Criteria first workflow.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Preparation;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Id_Rewriter;

require __DIR__ . '/worksheet-document-profile-s03-contract.php';
require __DIR__ . '/worksheet-workbench-s08.php';

$document = s03_document( array( s03_element( 'h1', array(), array( array( 'text' => 'Criteria first' ) ) ) ) );
$old      = s03_canonicalizer()->canonicalize( $document );
s03_assert( Worksheet_Preparation::ready( $old->document() ), 'Legacy revision compatibility.' );
$document['settings']['preparation'] = Worksheet_Preparation::draft();
$draft                               = s03_canonicalizer()->canonicalize( $document );
s03_same( Worksheet_Preparation::draft(), $draft->document()['settings']['preparation'], 'Preparation must survive canonicalization.' );
s03_assert( ! Worksheet_Preparation::ready( $draft->document() ), 'New draft must not pass review.' );
$pin                                 = array(
	'state'               => 'confirmed',
	'rubric_revision_id'  => s03_id(),
	'rubric_content_hash' => str_repeat( 'b', 64 ),
);
$document['settings']['preparation'] = $pin;
try {
	s03_canonicalizer()->canonicalize( $document );
	throw new RuntimeException( 'Empty criteria were accepted.' ); } catch ( \OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Validation_Failure $expected ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Preserve the documented recovery or optional-operation outcome; the catch body explains the fallback.
	// The fixture intentionally contains this expected or cleanup failure.
 // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- The fixture intentionally contains this expected or cleanup failure.
	}
	$document['settings']['learning_goals']      = array(
		array(
			'goal_id'     => s03_id(),
			'text'        => 'Explain the evidence.',
			'scope'       => 'worksheet',
			'response_id' => null,
		),
	);
	$document['settings']['assessment_bindings'] = array(
		array(
			'criterion_id' => s03_id(),
			'scope'        => 'worksheet',
			'response_id'  => null,
		),
	);
	$confirmed                                   = s03_canonicalizer()->canonicalize( $document )->document();
	Worksheet_Preparation::assert_ready(
		$confirmed,
		array(
			'revision_id'  => $pin['rubric_revision_id'],
			'content_hash' => $pin['rubric_content_hash'],
		)
	);
	try {
		Worksheet_Preparation::assert_ready(
			$confirmed,
			array(
				'revision_id'  => $pin['rubric_revision_id'],
				'content_hash' => str_repeat( 'c', 64 ),
			)
		);
		throw new RuntimeException( 'Changed rubric was accepted.' ); } catch ( InvalidArgumentException $expected ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Preserve the documented recovery or optional-operation outcome; the catch body explains the fallback.
		// The fixture intentionally contains this expected or cleanup failure.
 // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- The fixture intentionally contains this expected or cleanup failure.
		}
		$copy   = ( new Worksheet_Document_Id_Rewriter() )->rewrite( $confirmed, array(), array(), str_repeat( 'a', 64 ) );
		$copied = s03_canonicalizer()->canonicalize( $copy['teacher_document'] )->document();
		s03_same( 'draft', $copied['settings']['preparation']['state'], 'Copies must require reconfirmation.' );
		s03_assert( $copied['document_id'] !== $confirmed['document_id'], 'Copy must have new document identity.' );
		s03_same( $confirmed['settings']['assessment_bindings'], $copied['settings']['assessment_bindings'], 'Criterion references must survive copying.' );
		s03_same( 'confirmed', $confirmed['settings']['preparation']['state'], 'Copy must not mutate source.' );
		$dom = new DOMDocument();
		@$dom->loadHTML( '<meta charset="utf-8">' . $edit_body ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- The fixture deliberately parses browser HTML without emitting libxml diagnostics.
		$xp = new DOMXPath( $dom );
		s03_same( 0, $xp->query( '//nav[contains(@class,"__phases")]/ol/li' )->length, 'Embedded editor must not duplicate project workflow navigation.' );
		s03_assert( ! str_contains( $edit_body, 'data-action="confirm-criteria"' ), 'Retired standalone confirmation action remains.' );
		s03_assert( ! str_contains( $edit_body, '만들기</span>' ), 'Old workflow must be replaced.' );
		echo "Criteria compatibility passed: canonical round trip, confirmation, changed rubric rejection, copy isolation, no retired standalone UI.\n";

		require __DIR__ . '/worksheet-evaluation-input-s02.php';
		$prepared_document                               = $source->document();
		$prepared_document['settings']['learning_goals'] = array(
			array(
				'goal_id'     => eval_s02_id(),
				'text'        => 'Explain claims using observed evidence.',
				'scope'       => 'worksheet',
				'response_id' => null,
			),
		);
		$prepared_document['settings']['preparation']    = array(
			'state'               => 'confirmed',
			'rubric_revision_id'  => $configuration['grading_rubric']['revision_id'],
			'rubric_content_hash' => $configuration['grading_rubric']['content_hash'],
		);
		$preparation_links                               = array(
			array(
				'scope'              => 'worksheet',
				'response_id'        => null,
				'standard_public_id' => $standard_id,
				'alignment_type'     => 'primary',
			),
		);
		$preparation_snapshots                           = array(
			array(
				'standard_public_id'      => $standard_id,
				'standard_source_version' => '2022',
				'standard_code_snapshot'  => 'SCI-EVIDENCE-01',
				'standard_label_snapshot' => 'Explain claims using evidence',
			),
		);
		$prepare_input                                   = static function ( array $document, array $config ) use ( $canonical, $builder, $worksheet_id, $revision_id, $asset_snapshots, $preparation_links, $preparation_snapshots ) {
			$source     = ( new \OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Canonicalizer( $canonical, eval_s02_generator( 3000 ) ) )->canonicalize( $document );
			$projection = ( new \OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Revision_Projector( $canonical, eval_s02_generator( 4000 ) ) )->project( $source, $preparation_links, $preparation_snapshots );
			return $builder->build( $worksheet_id, $revision_id, $source, $projection, $asset_snapshots, $config );
		};
		$prepared_input                                  = $prepare_input( $prepared_document, $configuration )->input();
		s03_same( $prepared_document['settings']['learning_goals'], $prepared_input['worksheet']['payload']['document']['settings']['learning_goals'], 'Self-review must use worksheet-specific goals.' );
		s03_same( 1, count( $prepared_input['worksheet']['payload']['grading_rubric']['criteria'] ), 'Unselected global criteria must not enter self-review.' );
		$prepared_document['settings']['preparation'] = Worksheet_Preparation::draft();
		try {
			$prepare_input( $prepared_document, $configuration );
			throw new RuntimeException( 'Unconfirmed self-review was accepted.' ); } catch ( \OJAI\Dashboard\Worksheet\Evaluation\Domain\Worksheet_Evaluation_Input_Failure $expected ) {
			s03_same( '$.document.settings.preparation', $expected->diagnostic_path(), 'Unconfirmed criteria must produce the specific user-facing gate.' );
			}
			echo "Criteria-first AI input passed: worksheet goals, selected criteria only, draft review blocked.\n";
