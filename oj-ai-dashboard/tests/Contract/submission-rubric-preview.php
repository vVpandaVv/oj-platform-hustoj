<?php
/**
 * Opening a teacher rubric reads the official source without persisting a grade.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Submission\Assessment\Application\Read_Submission_Rubric;
use OJAI\Dashboard\Submission\Assessment\Application\Submission_Evaluation_Failure;
use OJAI\Dashboard\Submission\Assessment\Domain\Manual_Submission_Score;

require __DIR__ . '/submission-evaluation-enqueue-g4-imp-01-s03.php';
require_once ABSPATH . 'src/Submission/Assessment/Application/Read_Submission_Rubric.php';

$preview_sources = new G4_S03_Source_Port( $source );
$preview_reader  = new Read_Submission_Rubric( $preview_sources, $builder );
$submission      = array(
	'id'                => $source->submission_id(),
	'official_revision' => $source->official_revision(),
);
$context         = array(
	'context_type'     => $source->context_type(),
	'context_id'       => $source->context_id(),
	'context_revision' => $source->context_revision(),
);
$before          = $manual_db->tables;
$routing_before  = $manual_routing->calls;
$preview         = $preview_reader->read( $submission, $context );
$expected_input  = $builder->build( 'EV_' . str_repeat( 'B', 32 ), $source->submission_id(), $source->official_revision_id(), $source->official_revision(), $source->publication(), $source->configuration(), $source->artifact_representations() );
g4_s03_same( Manual_Submission_Score::prepare( $expected_input )['items'], $preview['items'], 'Preview and saved rubric identities, levels and points must agree.' );
g4_s03_same( array( 'items', 'maximum_score', 'bindings' ), array_keys( $preview ), 'Preview cannot expose evaluation IDs, prompts or command preconditions.' );
g4_s03_assert( ! empty( $preview['bindings'][0]['label'] ), 'Read-only rubric retains criterion labels.' );
g4_s03_same( $preview, $preview_reader->read( $submission, $context ), 'Repeated reads are deterministic.' );
g4_s03_same( $before, $manual_db->tables, 'Viewing cannot append evaluation, run, review or audit records.' );
g4_s03_same( $routing_before, $manual_routing->calls, 'Viewing cannot route an AI request.' );

foreach ( array( 'context_type', 'context_id', 'context_revision' ) as $field ) {
	$invalid           = $context;
	$invalid[ $field ] = 'other';
	g4_s03_failure( static fn () => $preview_reader->read( $submission, $invalid ), Submission_Evaluation_Failure::SOURCE_STALE, 'Cross-context previews must be rejected.' );
}
$changed                                      = $submission;
$changed['official_revision']['content_hash'] = str_repeat( 'f', 64 );
g4_s03_failure( static fn () => $preview_reader->read( $changed, $context ), Submission_Evaluation_Failure::SOURCE_STALE, 'A changed official answer cannot be previewed as the old answer.' );
echo "PASS rubric preview: read-only source, same rubric, safe projection, repeated reads and context/source guards.\n";
