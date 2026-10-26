<?php
/**
 * G4-IMP-06-S04 C43 corrected student result release contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

ob_start();
require __DIR__ . '/submission-result-release-g4-imp-06-s01.php';
ob_end_clean();

use OJAI\Dashboard\Submission\Assessment\Application\Submission_Evaluation_Failure;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

$correction_canonical   = new Canonical_Json();
$correction_fixture     = g4_release_fixture( $correction_canonical );
$correction_source      = $correction_fixture['source'];
$correction_repository  = new G4_Result_Release_Repository( $correction_fixture['snapshot'] );
$correction_application = g4_release_application( $correction_source, $correction_repository, $correction_canonical, '2026-08-24 23:30:00' );
$first_release          = $correction_application->execute(
	g4_release_command( $correction_source, $correction_repository->snapshot, $correction_canonical, 'command-g4-correction-source', str_repeat( '1', 64 ) )
);

$corrected = $correction_application->execute(
	g4_release_command(
		$correction_source,
		$correction_repository->snapshot,
		$correction_canonical,
		'command-g4-correction',
		str_repeat( '2', 64 ),
		'total',
		'shown',
		'correction',
		'data_correction',
		'Criterion total was corrected after teacher review.'
	)
);

g4_release_same( 'corrected', $corrected->state(), 'C43 correction state drifted.' );
g4_release_same( 2, $corrected->release_no(), 'C43 correction must append the next release number.' );
g4_release_same( 2, $correction_repository->append_count, 'First release and correction must append two immutable revisions.' );
$correction_record = $correction_repository->record;
if ( null === $correction_record ) {
	throw new RuntimeException( 'C43 correction record is missing.' );
}
g4_release_same( $first_release->release_id(), $correction_record['previous_release_id'], 'Correction must link the exact previous current release.' );
g4_release_same( 'data_correction', $correction_record['reason_code'], 'Correction reason code drifted.' );
g4_release_same( 'Criterion total was corrected after teacher review.', $correction_record['reason_note'], 'Correction reason note drifted.' );
$corrected_projection = json_decode( $correction_record['projection_json'], true, 128, JSON_THROW_ON_ERROR );
g4_release_same( 'corrected', $corrected_projection['release']['state'], 'Student projection must expose only the corrected state.' );
g4_release_assert( ! str_contains( $correction_record['projection_json'], 'data_correction' ) && ! str_contains( $correction_record['projection_json'], 'Criterion total was corrected' ), 'Correction reason must not enter the student projection.' );

$correction_event_count = $correction_repository->append_count;
$correction_replay      = $correction_application->execute(
	g4_release_command(
		$correction_source,
		array_merge(
			$correction_repository->snapshot,
			array(
				'current_release_id'      => $first_release->release_id(),
				'current_release_hash'    => $first_release->release_hash(),
				'current_release_no'      => 1,
				'current_release_state'   => 'released',
				'release_count'           => 1,
				'submission_lock_version' => $correction_fixture['snapshot']['submission_lock_version'] + 1,
			)
		),
		$correction_canonical,
		'command-g4-correction',
		str_repeat( '2', 64 ),
		'total',
		'shown',
		'correction',
		'data_correction',
		'Criterion total was corrected after teacher review.'
	)
);
g4_release_same( true, $correction_replay->replayed(), 'Exact correction retry must replay before current pointer comparison.' );
g4_release_same( $correction_event_count, $correction_repository->append_count, 'Exact correction retry must append no release.' );

$stale_fixture                                      = g4_release_fixture( $correction_canonical );
$stale_repository                                   = new G4_Result_Release_Repository( $stale_fixture['snapshot'] );
$stale_application                                  = g4_release_application( $stale_fixture['source'], $stale_repository, $correction_canonical, '2026-08-24 23:31:00' );
$stale_first                                        = $stale_application->execute( g4_release_command( $stale_fixture['source'], $stale_repository->snapshot, $correction_canonical, 'command-g4-stale-first', str_repeat( '3', 64 ) ) );
$stale_command                                      = g4_release_command( $stale_fixture['source'], $stale_repository->snapshot, $correction_canonical, 'command-g4-stale-race', str_repeat( '4', 64 ), 'total', 'hidden', 'correction', 'publication_error', null );
$stale_repository->snapshot['current_release_hash'] = str_repeat( 'f', 64 );
g4_release_failure( static fn () => $stale_application->execute( $stale_command ), Submission_Evaluation_Failure::RELEASE_SOURCE_STALE, 'Changed current release must reject a stale correction.' );
g4_release_same( 1, $stale_repository->append_count, 'Stale correction must append no release.' );

$replacement_fixture    = g4_release_fixture( $correction_canonical );
$replacement_repository = new G4_Result_Release_Repository( $replacement_fixture['snapshot'] );
$replacement_first_app  = g4_release_application( $replacement_fixture['source'], $replacement_repository, $correction_canonical, '2026-08-24 23:32:00' );
$replacement_first      = $replacement_first_app->execute( g4_release_command( $replacement_fixture['source'], $replacement_repository->snapshot, $correction_canonical, 'command-g4-replacement-first', str_repeat( '5', 64 ) ) );
$ids                    = new Public_Id_Generator();
$stale_release_id       = $ids->create()->value();
$replacement_repository->snapshot['current_release_id']    = $stale_release_id;
$replacement_repository->snapshot['current_release_hash']  = hash( 'sha256', $replacement_first->release_hash() . '|stale' );
$replacement_repository->snapshot['current_release_no']    = 2;
$replacement_repository->snapshot['current_release_state'] = 'stale';
$replacement_repository->snapshot['release_count']         = 2;
++$replacement_repository->snapshot['submission_lock_version'];
$replacement_source                         = $replacement_fixture['source'];
$replacement_source['official_review_id']   = $ids->create()->value();
$replacement_source['official_review_hash'] = hash( 'sha256', $replacement_source['official_review_id'] );
++$replacement_source['evaluation_lock_version'];
$replacement_repository->snapshot['official_review_id']      = $replacement_source['official_review_id'];
$replacement_repository->snapshot['official_review_hash']    = $replacement_source['official_review_hash'];
$replacement_repository->snapshot['evaluation_lock_version'] = $replacement_source['evaluation_lock_version'];
$replacement_repository->snapshot['latest_feedback_state']   = 'stale';
$replacement_application                                     = g4_release_application( $replacement_source, $replacement_repository, $correction_canonical, '2026-08-24 23:33:00' );
$replacement = $replacement_application->execute(
	g4_release_command( $replacement_source, $replacement_repository->snapshot, $correction_canonical, 'command-g4-replacement-correction', str_repeat( '6', 64 ), 'total', 'hidden', 'correction', 'data_correction', null )
);
g4_release_same( 'corrected', $replacement->state(), 'A stale release must be recoverable through a corrected release.' );
g4_release_same( 3, $replacement->release_no(), 'Correction after a stale revision must preserve the full release chain.' );
g4_release_same( null, $replacement->feedback_revision_id(), 'Feedback from a replaced official review must not be reused.' );
g4_release_same( $stale_release_id, $replacement_repository->record['previous_release_id'], 'Correction must link the current stale revision.' );

try {
	g4_release_command( $correction_source, $correction_repository->snapshot, $correction_canonical, 'command-g4-correction-no-reason', str_repeat( '7', 64 ), 'total', 'hidden', 'correction' );
	throw new RuntimeException( 'Correction must require a reason code.' ); } catch ( InvalidArgumentException ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Preserve the documented recovery or optional-operation outcome; the catch body explains the fallback.
	// The fixture intentionally contains this expected or cleanup failure.
 // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- The fixture intentionally contains this expected or cleanup failure.
	}
	try {
		g4_release_command( $correction_source, $correction_repository->snapshot, $correction_canonical, 'command-g4-correction-other', str_repeat( '8', 64 ), 'total', 'hidden', 'correction', 'other', null );
		throw new RuntimeException( 'Correction reason other must require a note.' ); } catch ( InvalidArgumentException ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Preserve the documented recovery or optional-operation outcome; the catch body explains the fallback.
		// The fixture intentionally contains this expected or cleanup failure.
 // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- The fixture intentionally contains this expected or cleanup failure.
		}
		try {
			g4_release_command( $correction_source, $correction_repository->snapshot, $correction_canonical, 'command-g4-release-reason', str_repeat( '9', 64 ), 'total', 'hidden', 'release', 'data_correction', null );
			throw new RuntimeException( 'First release must reject correction reason fields.' ); } catch ( InvalidArgumentException ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Preserve the documented recovery or optional-operation outcome; the catch body explains the fallback.
			// The fixture intentionally contains this expected or cleanup failure.
 // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- The fixture intentionally contains this expected or cleanup failure.
			}

			fwrite( STDOUT, "G4-IMP-06-S04 C43 correction passed: immutable previous chain, corrected projection, stale pointer rejection, official source replacement, reason validation, and durable replay are frozen.\n" );
