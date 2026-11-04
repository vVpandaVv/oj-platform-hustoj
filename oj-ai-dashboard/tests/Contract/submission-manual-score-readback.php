<?php
/**
 * Read and approve the actual persisted teacher-first score sheet.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Submission\Assessment\Application\Review_Submission_Evaluation;
use OJAI\Dashboard\Submission\Assessment\Application\Review_Submission_Evaluation_Command;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Evaluation_Query_Projector;
use OJAI\Dashboard\Submission\Assessment\Infrastructure\WordPress_Submission_Evaluation_Review_Repository;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

require __DIR__ . '/submission-evaluation-enqueue-g4-imp-01-s03.php';
$persisted_manual = $manual_db->tables;
require __DIR__ . '/submission-evaluation-review-storage-g4-imp-03-s01.php';

$projector     = new Submission_Evaluation_Query_Projector( $canonical );
$input_reader  = new ReflectionMethod( $projector, 'input' );
$result_reader = new ReflectionMethod( $projector, 'result' );
$item_reader   = new ReflectionMethod( $projector, 'result_items' );
$review_reader = new ReflectionMethod( new WordPress_Submission_Evaluation_Review_Repository( $canonical ), 'result_items' );

foreach ( array( false, true ) as $legacy ) {
	$tables     = $persisted_manual;
	$root       = $tables['oj_ai_submission_evaluations'][0];
	$input      = $tables['oj_ai_submission_evaluation_inputs'][0];
	$run        = $tables['oj_ai_evaluation_runs'][0];
	$result     = $tables['oj_ai_submission_evaluation_results'][0];
	$items      = $tables['oj_ai_submission_evaluation_items'];
	$normalized = json_decode( $result['normalized_result_json'], true, 128, JSON_THROW_ON_ERROR );

	if ( $legacy ) {
		// Reconstruct only the old writer's decimal representation; keep the source immutable.
		foreach ( $normalized['items'] as $position => &$item ) {
			$item['maximum_points']               = rtrim( rtrim( $item['maximum_points'], '0' ), '.' );
			$items[ $position ]['maximum_points'] = $item['maximum_points'];
			$values                               = array_intersect_key( $items[ $position ], array_flip( array( 'candidate_points', 'confidence', 'criterion_key', 'evidence_hash', 'flags', 'maximum_points', 'position', 'question_key' ) ) );
			$items[ $position ]['content_hash']   = hash( 'sha256', $canonical->encode( $values ) );
		}
		unset( $item );
		$normalized['maximum_score']      = rtrim( rtrim( $normalized['maximum_score'], '0' ), '.' );
		$result['normalized_result_json'] = $canonical->encode( $normalized );
		$result['normalized_result_hash'] = hash( 'sha256', $result['normalized_result_json'] );
		$result['content_hash']           = hash(
			'sha256',
			$canonical->encode(
				array(
					'evaluation_id'          => $root['public_id'],
					'normalized_result_hash' => $result['normalized_result_hash'],
					'provider_output_digest' => $result['provider_output_digest'],
					'run_id'                 => $run['public_id'],
				)
			)
		);
	} else {
		g4_s03_same( '10.0000', $normalized['maximum_score'], 'New manual total must use storage precision.' );
		g4_s03_same( array( '7.0000', '3.0000' ), array_column( $normalized['items'], 'maximum_points' ), 'New manual item hashes must use storage precision.' );
	}

	$auth                  = json_decode( $root['authorization_envelope_json'], true, 128, JSON_THROW_ON_ERROR );
	$root['submission_id'] = $auth['object']['id'];
	foreach ( array( 'submission_revision', 'worksheet_publication', 'policy_revision', 'rubric_revision', 'review_rule_revision' ) as $field ) {
		$root[ $field . '_id' ] = $root[ $field . '_public_id' ];
	}
	$root['official_revision_id'] = $root['submission_revision_id'];
	$root['source_revision_no']   = 1;
	foreach ( array( 'type', 'id', 'revision' ) as $field ) {
		$root[ 'context_' . $field ] = $auth['context'][ $field ];
	}
	$input['input_content_hash'] = $input['content_hash'];
	$source                      = $input_reader->invoke( $projector, $root, $input );
	$result_row                  = $result;
	foreach ( array(
		'public_id'             => 'id',
		'content_hash'          => 'content_hash',
		'created_at_utc'        => 'created_at_utc',
		'output_schema_version' => 'output_schema_version',
		'candidate_score'       => 'candidate_score',
		'maximum_score'         => 'maximum_score',
		'overall_confidence'    => 'overall_confidence',
		'abstained'             => 'abstained',
		'safety_flagged'        => 'safety_flagged',
		'summary'               => 'summary',
	) as $from => $to ) {
		$result_row[ 'result_' . $to ] = $result[ $from ];
	}
	$result_row['result_run_id'] = $run['public_id'];
	$query_items                 = array();
	foreach ( $items as &$item ) {
		// MySQL DECIMAL columns return padded strings even when the original JSON did not.
		$item['maximum_points']    = number_format( (float) $item['maximum_points'], 4, '.', '' );
		$item['item_content_hash'] = $item['content_hash'];
		$query_item                = $item;
		foreach ( array( 'criterion_key', 'question_key', 'position', 'candidate_points', 'maximum_points', 'confidence', 'flags' ) as $field ) {
			$query_item[ 'item_' . $field ] = $item[ $field ];
		}
		$query_items[] = $query_item;
	}
	unset( $item );
	$projected = $result_reader->invoke( $projector, array( 'id' => $root['public_id'] ), $source, $result_row, $query_items );
	g4_s03_same( 'not_requested', $projected['provider_status'], 'Manual result must remain explicitly AI-free.' );
	g4_s03_same( array( '7.0000', '3.0000' ), array_column( $projected['items'], 'maximum_points' ), 'Current and old manual sheets must display identical rubric values.' );

	$fixture    = g4_s01_storage_fixture( $canonical );
	$snapshot   = array_replace(
		$fixture['snapshot'],
		$result,
		array(
			'evaluation_id'               => $root['public_id'],
			'evaluation_lock_version'     => '0',
			'submission_id'               => $root['submission_id'],
			'submission_revision_id'      => $root['submission_revision_id'],
			'submission_revision_hash'    => $root['submission_revision_hash'],
			'source_revision_hash'        => $root['submission_revision_hash'],
			'official_revision_public_id' => $root['submission_revision_id'],
			'result_id'                   => $result['public_id'],
			'result_hash'                 => $result['content_hash'],
			'result_run_id'               => $run['public_id'],
			'run_result_id'               => $result['public_id'],
		)
	);
	$database   = new G4_S01_Storage_Database( $snapshot, $items );
	$repository = new WordPress_Submission_Evaluation_Review_Repository( $canonical, $database );
	$app        = new Review_Submission_Evaluation( $repository, new Public_Id_Generator(), $canonical, static fn (): string => '2026-11-04 01:00:00' );
	$decisions  = array_map(
		static fn ( array $item ): array => array(
			'binding_key'     => $item['binding_key'],
			'source'          => 'teacher_assigned',
			'official_points' => '0.0000',
			'reason_code'     => 'manual_assessment',
			'reason_note'     => null,
		),
		$normalized['items']
	);
	$command    = new Review_Submission_Evaluation_Command( 42, $root['public_id'], 'manual-readback-001', $result['public_id'], $result['content_hash'], null, null, 0, 'adjusted', $decisions, null, str_repeat( 'f', 64 ), 'manual-readback', '0', 'review_required', 'context_staff', $canonical );
	$draft_db   = new G4_S01_Storage_Database( $snapshot, $items );
	$draft_repo = new WordPress_Submission_Evaluation_Review_Repository( $canonical, $draft_db );
	$draft_app  = new Review_Submission_Evaluation( $draft_repo, new Public_Id_Generator(), $canonical, static fn (): string => '2026-11-04 01:00:00' );
	$partial    = new Review_Submission_Evaluation_Command( 42, $root['public_id'], 'manual-partial-draft', $result['public_id'], $result['content_hash'], null, null, 0, 'draft', array_slice( $decisions, 1 ), null, str_repeat( 'f', 64 ), 'manual-readback', '0', 'review_required', 'context_staff', $canonical );
	$saved_part = $draft_app->execute( $partial );
	g4_s03_same( false, $saved_part->official_pointer_advanced(), 'A partially completed rubric remains non-official.' );
	g4_s03_same( 1, $saved_part->score_decisions()[0]['position'], 'Partial drafts preserve original rubric positions instead of assigning missing scores.' );
	$profile_reader  = new ReflectionMethod( $draft_repo, 'score_profile' );
	$partial_profile = $profile_reader->invoke( $draft_repo, $draft_db->inserts['oj_ai_submission_evaluation_reviews'][0], true );
	g4_s03_same( 1, $partial_profile['items'][0]['position'], 'Partial drafts must read back with sparse original positions.' );
	$approved = $app->execute( $command );
	g4_s03_same( '0.0000', $approved->official_score(), 'Teacher must be able to assign zero to both current and legacy manual sheets.' );
	g4_s03_same( 1, count( $database->inserts['oj_ai_submission_evaluation_reviews'] ?? array() ), 'Manual approval appends exactly one review.' );
	g4_s03_same( $tables, $persisted_manual, 'Read and approval must not rewrite the original preparation or source.' );

	foreach ( array( 'maximum_points', 'content_hash', 'evidence_hash' ) as $field ) {
		$bad_items                        = $query_items;
		$review_items                     = $items;
		$bad_value                        = 'maximum_points' === $field ? '8.0000' : str_repeat( '0', 64 );
		$query_field                      = 'evidence_hash' === $field ? $field : 'item_' . $field;
		$review_field                     = 'content_hash' === $field ? 'item_content_hash' : $field;
		$bad_items[0][ $query_field ]     = $bad_value;
		$review_items[0][ $review_field ] = $bad_value;
		foreach ( array(
			static fn () => $result_reader->invoke( $projector, array( 'id' => $root['public_id'] ), $source, $result_row, $bad_items ),
			static fn () => $review_reader->invoke( $repository, $normalized['items'], $review_items, true ),
		) as $read ) {
			$rejected = false;
			try {
				$read();
			} catch ( InvalidArgumentException | OJAI\Dashboard\Submission\Assessment\Application\Submission_Evaluation_Failure ) {
				$rejected = true;
			}
			g4_s03_assert( $rejected, 'Tampered manual item must fail closed: ' . $field );
		}
	}
	if ( $legacy ) {
		foreach ( array(
			static fn () => $item_reader->invoke( $projector, $normalized['items'], $query_items, false ),
			static fn () => $review_reader->invoke( $repository, $normalized['items'], $items, false ),
		) as $read ) {
			$rejected = false;
			try {
				$read();
			} catch ( InvalidArgumentException | OJAI\Dashboard\Submission\Assessment\Application\Submission_Evaluation_Failure ) {
				$rejected = true;
			}
			g4_s03_assert( $rejected, 'Legacy manual decimal compatibility must not relax AI result hashes.' );
		}
	}
}
echo "Manual score readback passed: real preparation, persisted query, legacy decimals, teacher approval, zero score, immutable source and tamper rejection.\n";
