<?php
/**
 * Editable teacher scores remain non-official until explicitly confirmed.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Submission\Assessment\Application\Submission_Evaluation_Failure;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Evaluation_Query_Projector;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;

define( 'OJAI_G4_S02_FIXTURES_ONLY', true );
require __DIR__ . '/submission-evaluation-review-storage-g4-imp-03-s02.php';

$canonical = new Canonical_Json();
foreach ( array( false, true ) as $manual ) {
	$fixture       = g4_s02_storage_fixture( $canonical, false, $manual );
	$database      = new G4_S02_Storage_Database( $fixture['snapshot'], $fixture['items'] );
	$app           = g4_s02_storage_application( $canonical, $database );
	$original      = $database->snapshot;
	$items         = array(
		array(
			'binding_key'     => str_repeat( 'a', 64 ),
			'source'          => 'teacher_assigned',
			'official_points' => '5.0000',
			'reason_code'     => 'manual_assessment',
			'reason_note'     => null,
		),
	);
	$first_command = g4_s02_storage_command( $canonical, $fixture, 'draft', 'draft-first', null, $items );
	if ( ! $manual ) {
		$unchanged       = array(
			array(
				'binding_key'     => str_repeat( 'a', 64 ),
				'source'          => 'ai_accepted',
				'official_points' => '3.0000',
				'reason_code'     => null,
				'reason_note'     => null,
			),
		);
		$unchanged_db    = new G4_S02_Storage_Database( $fixture['snapshot'], $fixture['items'] );
		$unchanged_saved = g4_s02_storage_application( $canonical, $unchanged_db )->execute( g4_s02_storage_command( $canonical, $fixture, 'draft', 'draft-unchanged', null, $unchanged ) );
		g4_s02_storage_same( false, $unchanged_saved->official_pointer_advanced(), 'Saving unchanged AI recommendations is not an approval.' );
	}
	$first = $app->execute( $first_command );
	g4_s02_storage_same( false, $first->official_pointer_advanced(), 'Saving a draft cannot approve the score.' );
	g4_s02_storage_same( null, $first->official_review_id(), 'Draft receipt must not claim an official score.' );
	g4_s02_storage_same( $original, $database->snapshot, 'Draft save must not change roots, locks, release or official pointers.' );
	g4_s02_storage_same( '5.0000', $first->official_score(), 'Draft score is calculated from rubric choices.' );
	g4_s02_storage_same( 0, count( $database->inserts['oj_ai_submission_release_revisions'] ?? array() ), 'Saving cannot change student publication.' );
	g4_s02_storage_failure( static fn () => $app->execute( $first_command ), Submission_Evaluation_Failure::REVIEW_SOURCE_STALE, 'A stale draft review number must reject concurrent edits.' );

	$fixture['snapshot']['review_count'] = 1;
	$items[0]['official_points']         = '0.0000';
	$second                              = $app->execute( g4_s02_storage_command( $canonical, $fixture, 'draft', 'draft-second', null, $items ) );
	g4_s02_storage_same( '0.0000', $second->official_score(), 'Saved teacher grades can be changed to zero before confirmation.' );
	g4_s02_storage_same( 2, $second->review_no(), 'Repeated saves append history.' );

	$rows = array();
	foreach ( $database->inserts['oj_ai_submission_evaluation_reviews'] as $index => $review ) {
		$rows[] = array_merge(
			$review,
			array(
				'review_id'             => $review['public_id'],
				'review_content_hash'   => $review['content_hash'],
				'review_result_id'      => $fixture['result_id'],
				'review_decision'       => $review['decision'],
				'review_reason'         => $review['reason'],
				'review_created_at_utc' => $review['created_at_utc'],
				'previous_review_id'    => 0 === $index ? null : $first->review_id(),
			)
		);
	}
	$projector = new Submission_Evaluation_Query_Projector( $canonical );
	$reader    = new ReflectionMethod( $projector, 'reviews' );
	$projected = $reader->invoke( $projector, $database->snapshot, $rows, array( array( 'result_id' => $fixture['result_id'] ) ) );
	g4_s02_storage_same( array( 'draft', 'draft' ), array_column( $projected, 'decision' ), 'Saved drafts must read back without failing evaluation detail.' );
	g4_s02_storage_same( array( false, false ), array_column( $projected, 'is_official' ), 'Draft history is never official.' );

	$fixture['snapshot']['review_count'] = 2;
	$approved                            = $app->execute( g4_s02_storage_command( $canonical, $fixture, 'adjusted', 'draft-confirm', null, $items ) );
	g4_s02_storage_same( $approved->review_id(), $database->snapshot['official_review_id'], 'Only final confirmation advances the official pointer.' );
	$fixture['snapshot']                 = $database->snapshot;
	$fixture['snapshot']['review_count'] = 3;
	g4_s02_storage_failure( static fn () => $app->execute( g4_s02_storage_command( $canonical, $fixture, 'draft', 'draft-after-confirm', null, $items ) ), Submission_Evaluation_Failure::REVIEW_SOURCE_STALE, 'Confirmed scores cannot be changed without withdrawal.' );
	$app->execute( g4_s02_storage_command( $canonical, $fixture, 'withdrawn', 'draft-withdraw', 'Recheck the rubric.' ) );
	$fixture['snapshot']                 = $database->snapshot;
	$fixture['snapshot']['review_count'] = 4;
	$app->execute( g4_s02_storage_command( $canonical, $fixture, 'draft', 'draft-after-withdraw', null, $items ) );
	g4_s02_storage_same( null, $database->snapshot['official_review_id'], 'Withdrawal permits editable draft saving again.' );
}
fwrite( STDOUT, "PASS teacher and AI score drafts: storage/readback, zero, history, CAS, confirmation, withdrawal and publication isolation.\n" );
