<?php
/**
 * D3-D6 historical assessment aggregate writer.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\LegacyMigration\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Approve_Legacy_Migration;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Legacy_Migration_Failure;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Legacy_Target_Transformation_Failure;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Migration_Evaluation_Projector;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Migration_Plan;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Source_Item;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Target_Write_Result;
use OJAI\Dashboard\Operations\LegacyMigration\Port\Legacy_Migration_Evaluation_Writer_Port_Interface;
use OJAI\Dashboard\Platform\Addon_Manifest;
use OJAI\Dashboard\Security\Addon_Capabilities;
use OJAI\Dashboard\Submission\Assessment\Application\Review_Submission_Evaluation;
use OJAI\Dashboard\Submission\Assessment\Domain\Student_Note_Input_Builder;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Evaluation_Output_Normalizer;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Feedback_Input_Builder;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Result_Projection;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Score_Input_Builder;
use OJAI\Dashboard\Submission\Student\Domain\Submission_Revision_Integrity;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Restores current-contract evaluation history without replaying AI or owning a transaction.
 */
final class WordPress_Legacy_Migration_Evaluation_Writer implements Legacy_Migration_Evaluation_Writer_Port_Interface {
	private const TABLE_IMPORT_RUNS  = 'oj_ai_import_runs';
	private const TABLE_IMPORT_ITEMS = 'oj_ai_import_items';
	private const TABLE_ASSIGNMENTS  = 'oj_ai_assignments';
	private const TABLE_SUBMISSIONS  = 'oj_ai_submissions';
	private const TABLE_REVISIONS    = 'oj_ai_submission_revisions';
	private const TABLE_PUBLICATIONS = 'oj_ai_worksheet_publications';
	private const TABLE_QUESTIONS    = 'oj_ai_worksheet_revision_questions';
	private const TABLE_EVALUATIONS  = 'oj_ai_submission_evaluations';
	private const TABLE_INPUTS       = 'oj_ai_submission_evaluation_inputs';
	private const TABLE_RUNS         = 'oj_ai_evaluation_runs';
	private const TABLE_RESULTS      = 'oj_ai_submission_evaluation_results';
	private const TABLE_ITEMS        = 'oj_ai_submission_evaluation_items';
	private const TABLE_REVIEWS      = 'oj_ai_submission_evaluation_reviews';
	private const TABLE_FEEDBACK     = 'oj_ai_submission_feedback_revisions';
	private const TABLE_RELEASES     = 'oj_ai_submission_release_revisions';
	private const TABLE_NOTES        = 'oj_ai_student_note_revisions';

	private const ITEM_SCHEMA        = 'oj_ai.legacy_item_payload';
	private const ITEM_PROFILE       = 'oj_ai.legacy_item_payload@1.0.0';
	private const PROFILE_VERSION    = 1;
	private const MAXIMUM_RELATIONS  = 250;
	private const MAXIMUM_JSON_BYTES = 4194304;
	private const SOURCE_TYPES       = array(
		'assessment_plans',
		'assessment_runs',
		'grading_inputs',
		'rubric_snapshots',
		'grading_items',
		'assessment_results',
		'regrade_reviews',
		'feedback',
		'feedback_approvals',
		'special_notes',
	);
	private const AGGREGATE_TYPES    = array(
		'assessment_runs',
		'grading_inputs',
		'rubric_snapshots',
		'grading_items',
		'assessment_results',
		'regrade_reviews',
		'feedback',
		'feedback_approvals',
		'special_notes',
	);

	/**
	 * Profile.
	 *
	 * @var Legacy_Source_Profile_0_1_2
	 */
	private Legacy_Source_Profile_0_1_2 $profile;
	/**
	 * Projector.
	 *
	 * @var Legacy_Migration_Evaluation_Projector
	 */
	private Legacy_Migration_Evaluation_Projector $projector;

	/**
	 * Initialize WordPress legacy migration evaluation writer dependencies and state.
	 *
	 * @param Canonical_Json                             $canonical Canonical JSON encoder used for stable digests.
	 * @param object|null                                $database Database.
	 * @param Public_Id_Generator|null                   $ids Ids.
	 * @param Legacy_Source_Profile_0_1_2|null           $profile Profile.
	 * @param Legacy_Migration_Evaluation_Projector|null $projector Projector.
	 */
	public function __construct(
		private Canonical_Json $canonical,
		private ?object $database = null,
		private ?Public_Id_Generator $ids = null,
		?Legacy_Source_Profile_0_1_2 $profile = null,
		?Legacy_Migration_Evaluation_Projector $projector = null
	) {
		$this->profile   = $profile ?? new Legacy_Source_Profile_0_1_2();
		$this->projector = $projector ?? new Legacy_Migration_Evaluation_Projector( $canonical );
	}

	/**
	 * Write.
	 *
	 * @param int                $run_database_id Run database id.
	 * @param Legacy_Source_Item $item Item.
	 * @param int                $actor_user_id Authenticated actor from the access context.
	 * @param string             $created_at_utc Created at utc.
	 * @return Legacy_Target_Write_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function write(
		int $run_database_id,
		Legacy_Source_Item $item,
		int $actor_user_id,
		string $created_at_utc
	): Legacy_Target_Write_Result {
		if ( ! in_array( $item->source_type(), self::SOURCE_TYPES, true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::UNSUPPORTED );
		}
		if ( $run_database_id < 1 || $actor_user_id < 1 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		$this->utc( $created_at_utc );

		try {
			$plan   = $this->run_plan( $run_database_id );
			$tables = $this->source_tables( $plan );
			$this->assert_exact_source_row( $item->source_type(), $item->record() );
			$this->assert_source_checksum( $item, $plan );
			$current = $this->source_row_by_id( $tables[ $item->source_type() ], $item->source_type(), $item->source_id() );
			if (
				null !== ( $current['deleted_at'] ?? null )
				|| ! hash_equals( $item->source_checksum(), $this->source_checksum( $plan, $item->source_type(), $item->source_id(), $current ) )
			) {
				throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::SOURCE_INVALID );
			}
			if ( 'assessment_plans' === $item->source_type() ) {
				return $this->write_plan( $run_database_id, $item, $plan, $tables );
			}

			if ( 'rubric_snapshots' === $item->source_type() ) {
				$submission_source_id = $this->snapshot_submission_id( $this->positive_integer( $current['id'] ?? null ), $tables );
				if ( null === $submission_source_id ) {
					return $this->verify_plan_snapshot( $run_database_id, $item, $current, $tables );
				}
			} else {
				$submission_source_id = $this->parent_submission_id( $item->source_type(), $current, $tables );
			}
			$owner_id = $this->owner_run_id( $submission_source_id, $tables );
			if ( 'assessment_runs' === $item->source_type() && $owner_id === $item->source_id() ) {
				return $this->write_evaluation( $run_database_id, $item, $actor_user_id, $created_at_utc, $plan, $tables, $submission_source_id );
			}
			return $this->verify_relation( $run_database_id, $item, $plan, $tables, $submission_source_id, $owner_id );
		} catch ( Legacy_Target_Transformation_Failure | Legacy_Migration_Failure $failure ) {
			throw $failure;
		} catch ( InvalidArgumentException | JsonException $error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::SOURCE_INVALID );
		} catch ( Throwable $error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE, $error );
		}
	}

	/**
	 * Write plan.
	 *
	 * @param int                  $run_database_id Run database id.
	 * @param Legacy_Source_Item   $item Item.
	 * @param array<string,mixed>  $plan Plan.
	 * @param array<string,string> $tables Tables.
	 * @return Legacy_Target_Write_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function write_plan(
		int $run_database_id,
		Legacy_Source_Item $item,
		array $plan,
		array $tables
	): Legacy_Target_Write_Result {
		$record            = $item->record();
		$assignment_id     = $this->positive_integer( $record['assignment_id'] ?? null );
		$assignment_source = $this->source_row_by_id( $tables['worksheet_assignments'], 'worksheet_assignments', $assignment_id );
		$assignment        = $this->import_receipt( $run_database_id, 'worksheet_assignments', $assignment_id, 'assignment' );
		if ( ! hash_equals( $assignment['source_checksum'], $this->source_checksum( $plan, 'worksheet_assignments', $assignment_id, $assignment_source ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::SOURCE_INVALID );
		}
		if ( null !== ( $assignment_source['deleted_at'] ?? null ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::DEPENDENCY_MISSING );
		}
		$assignment_template_id = $this->positive_integer( $assignment_source['template_id'] ?? null );
		$plan_template_id       = $this->non_negative_integer( $record['template_id'] ?? null );
		if ( $plan_template_id > 0 && $plan_template_id !== $assignment_template_id ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::SOURCE_INVALID );
		}
		$template_id      = $assignment_template_id;
		$snapshots        = $this->source_relations( $tables['rubric_snapshots'], 'rubric_snapshots', 'template_id', $template_id, '`id` ASC' );
		$source_hashes    = $this->source_hash_map( $plan, 'rubric_snapshots', $snapshots );
		$metadata         = $assignment['result']->metadata();
		$publication_id   = $metadata['publication_public_id'] ?? null;
		$publication_hash = $metadata['publication_hash'] ?? null;
		if ( ! is_string( $publication_id ) || ! Public_Id::is_valid( $publication_id ) || ! is_string( $publication_hash ) || ! $this->is_hash( $publication_hash ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		$result_metadata = array(
			'assignment_public_id'  => $assignment['result']->target_public_id(),
			'publication_public_id' => $publication_id,
			'publication_hash'      => $publication_hash,
			'plan_source_key'       => $item->source_key(),
		);
		if ( array() !== $source_hashes ) {
			$result_metadata['rubric_snapshot_source_hashes'] = $source_hashes;
		}
		return new Legacy_Target_Write_Result(
			'assessment_plan_snapshot',
			$assignment['result']->target_public_id(),
			$item->source_checksum(),
			$result_metadata
		);
	}

	/**
	 * Write evaluation.
	 *
	 * @param int                  $run_database_id Run database id.
	 * @param Legacy_Source_Item   $item Item.
	 * @param int                  $actor_user_id Authenticated actor from the access context.
	 * @param string               $created_at_utc Created at utc.
	 * @param array<string,mixed>  $plan Plan.
	 * @param array<string,string> $tables Tables.
	 * @param int                  $submission_source_id Submission source id.
	 * @return Legacy_Target_Write_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function write_evaluation(
		int $run_database_id,
		Legacy_Source_Item $item,
		int $actor_user_id,
		string $created_at_utc,
		array $plan,
		array $tables,
		int $submission_source_id
	): Legacy_Target_Write_Result {
		$aggregate = $this->aggregate( $submission_source_id, $plan, $tables );
		$runs      = $aggregate['assessment_runs'];
		if ( array() === $runs || $this->positive_integer( $runs[0]['id'] ?? null ) !== $item->source_id() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::SOURCE_INVALID );
		}

		$source_submission  = $aggregate['submission'];
		$submission_receipt = $this->import_receipt( $run_database_id, 'worksheet_submissions', $submission_source_id, 'submission' );
		if ( ! hash_equals( $submission_receipt['source_checksum'], $this->source_checksum( $plan, 'worksheet_submissions', $submission_source_id, $source_submission ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::SOURCE_INVALID );
		}
		$target               = $this->target_submission( $submission_receipt['result'] );
		$assignment_source_id = $this->positive_integer( $source_submission['assignment_id'] ?? null );
		$assignment_source    = $this->source_row_by_id( $tables['worksheet_assignments'], 'worksheet_assignments', $assignment_source_id );
		$question_map         = $this->legacy_question_response_ids(
			$run_database_id,
			$this->positive_integer( $assignment_source['template_id'] ?? null ),
			$target['worksheet_revision_id'],
			$tables
		);

		$evaluation_id        = $this->ids()->create()->value();
		$evaluation_reference = $this->evaluation_reference( $plan, $submission_source_id, $item->source_id() );
		$score_input          = $this->projector->score_input(
			$evaluation_reference,
			$target['submission_revision_hash'],
			$target['publication_hash'],
			$target['grading_schema_hash'],
			$target['grading_payload'],
			$target['response_payload'],
			$target['answers'],
			'ko-KR'
		);
		$input_public_id      = $this->ids()->create()->value();
		$run_projections      = array();
		$run_public_ids       = array();
		foreach ( $runs as $index => $source_run ) {
			$source_id                     = $this->positive_integer( $source_run['id'] ?? null );
			$public_id                     = $this->ids()->create()->value();
			$run_projections[ $source_id ] = array(
				'database_id'      => null,
				'public_id'        => $public_id,
				'run_no'           => $index + 1,
				'source'           => $source_run,
				'input_digest'     => $score_input['input_hash'],
				'result_type'      => null,
				'result_public_id' => null,
			);
			$run_public_ids[ hash( 'sha256', (string) $source_id ) ] = $public_id;
		}

		$result_projections   = array();
		$claimed_result_runs  = array();
		$result_public_ids    = array();
		$result_target_hashes = array();
		foreach ( $aggregate['assessment_results'] as $source_result ) {
			$source_result_id = $this->positive_integer( $source_result['id'] ?? null );
			$source_run_id    = $this->positive_integer( $source_result['run_id'] ?? null );
			if ( ! isset( $run_projections[ $source_run_id ] ) || 'score' !== ( $run_projections[ $source_run_id ]['source']['run_type'] ?? null ) || isset( $claimed_result_runs[ $source_run_id ] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::SOURCE_INVALID );
			}
			if ( 'succeeded' !== ( $run_projections[ $source_run_id ]['source']['status'] ?? null ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::SOURCE_INVALID );
			}
			$normalized                                       = $this->projector->score_result(
				$source_result,
				$aggregate['grading_items_by_result'][ $source_result_id ] ?? array(),
				$score_input,
				$question_map
			);
			$result_id                                        = $this->ids()->create()->value();
			$provider_digest                                  = $this->provider_digest( $source_result['raw_ai_response'] ?? null, $normalized->provider_output_digest() );
			$content_hash                                     = hash(
				'sha256',
				$this->canonical->encode(
					array(
						'evaluation_id'          => $evaluation_id,
						'normalized_result_hash' => $normalized->normalized_hash(),
						'provider_output_digest' => $provider_digest,
						'run_id'                 => $run_projections[ $source_run_id ]['public_id'],
					)
				)
			);
			$result_projections[ $source_result_id ]          = array(
				'content_hash'    => $content_hash,
				'database_id'     => null,
				'normalized'      => $normalized,
				'provider_digest' => $provider_digest,
				'public_id'       => $result_id,
				'run_source_id'   => $source_run_id,
				'source'          => $source_result,
			);
			$receipt_key                                      = hash( 'sha256', (string) $source_result_id );
			$claimed_result_runs[ $source_run_id ]            = true;
			$result_public_ids[ $receipt_key ]                = $result_id;
			$result_target_hashes[ $receipt_key ]             = $content_hash;
			$run_projections[ $source_run_id ]['result_type'] = 'submission_evaluation_result';
			$run_projections[ $source_run_id ]['result_public_id'] = $result_id;
		}

		$review_projection = $this->reviews( $aggregate, $result_projections, $evaluation_id, $actor_user_id );
		$official          = $review_projection['official'];
		if ( ( array() !== $aggregate['feedback'] || array() !== $aggregate['special_notes'] ) && null === $official ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::SOURCE_INVALID );
		}
		$feedback_projection = $this->feedback(
			$aggregate,
			$result_projections,
			$review_projection['by_result'],
			$official,
			$score_input,
			$evaluation_id,
			$target['submission_revision_hash'],
			$target['assignment_title']
		);
		$note_projection     = $this->notes(
			$aggregate,
			$review_projection['by_result'],
			$score_input,
			$evaluation_id,
			$target['submission_revision_hash'],
			$actor_user_id
		);
		$this->bind_auxiliary_runs( $run_projections, $feedback_projection['rows'], $note_projection['rows'] );

		$state              = null !== $official ? 'reviewed' : ( array() !== $result_projections ? 'review_required' : $this->empty_evaluation_state( $runs ) );
		$requested_at       = $this->utc_value( $runs[0]['created_at'] ?? $created_at_utc );
		$updated_at         = $this->latest_timestamp( $aggregate, $created_at_utc );
		$authorization      = $this->authorization_envelope( $actor_user_id, $requested_at, $target );
		$authorization_json = $this->canonical->encode( $authorization );
		$evaluation_hash    = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'evaluation_id'        => $evaluation_id,
					'official_review_hash' => null === $official ? null : $official['content_hash'],
					'publication_hash'     => $target['publication_hash'],
					'relation_hash'        => hash( 'sha256', $this->canonical->encode( $aggregate['source_hashes'] ) ),
					'source_hash'          => $target['submission_revision_hash'],
					'state'                => $state,
				)
			)
		);

		$this->insert_or_fail(
			self::TABLE_EVALUATIONS,
			array(
				'public_id'                       => $evaluation_id,
				'lock_version'                    => 0,
				'created_by_user_id'              => $actor_user_id,
				'created_at_utc'                  => $requested_at,
				'updated_by_user_id'              => $actor_user_id,
				'updated_at_utc'                  => $updated_at,
				'submission_revision_public_id'   => $target['submission_revision_id'],
				'submission_revision_hash'        => $target['submission_revision_hash'],
				'worksheet_publication_public_id' => $target['publication_id'],
				'publication_hash'                => $target['publication_hash'],
				'policy_revision_public_id'       => $score_input['policy_revision_id'],
				'policy_hash'                     => $score_input['policy_hash'],
				'rubric_revision_public_id'       => $score_input['rubric_revision_id'],
				'rubric_hash'                     => $score_input['rubric_hash'],
				'review_rule_revision_public_id'  => $score_input['review_rule_revision_id'],
				'review_rule_hash'                => $score_input['review_rule_hash'],
				'prompt_contract_version'         => Submission_Score_Input_Builder::PROMPT_CONTRACT,
				'input_schema_version'            => Submission_Score_Input_Builder::REQUEST_VERSION,
				'output_schema_version'           => Submission_Score_Input_Builder::OUTPUT_VERSION,
				'state'                           => $state,
				'idempotency_key'                 => 'eval:' . hash( 'sha256', $plan['source']['profile'] . ':' . $submission_source_id . ':' . $item->source_id() ),
				'requested_at_utc'                => $requested_at,
				'authorization_envelope_version'  => '1.0.0',
				'authorization_envelope_json'     => $authorization_json,
				'authorization_envelope_hash'     => hash( 'sha256', $authorization_json ),
				'enqueue_correlation_id'          => 'legacy-migration:' . substr( hash( 'sha256', $evaluation_id ), 0, 32 ),
			)
		);
		$evaluation_database_id = $this->insert_id();
		$this->insert_input( $evaluation_database_id, $input_public_id, $evaluation_id, $actor_user_id, $requested_at, $score_input );
		$this->insert_runs( $evaluation_id, $actor_user_id, $run_projections, $score_input['input_hash'] );
		$this->insert_results( $evaluation_database_id, $actor_user_id, $result_projections );
		$this->insert_reviews( $evaluation_database_id, $actor_user_id, $review_projection['rows'] );
		$this->insert_feedback( $evaluation_database_id, $actor_user_id, $feedback_projection['rows'] );
		$this->insert_releases( $evaluation_database_id, $actor_user_id, $feedback_projection['releases'] );
		$this->insert_notes( $evaluation_database_id, $actor_user_id, $note_projection['rows'] );

		$current_release = $feedback_projection['current_release'];
		$this->update_submission_pointers( $target, $official, $current_release, $actor_user_id, $updated_at );
		$this->assert_postcondition(
			$evaluation_database_id,
			$evaluation_id,
			$target['submission_database_id'],
			$official,
			$current_release,
			count( $run_projections ),
			count( $result_projections ),
			count( $review_projection['rows'] ),
			count( $feedback_projection['rows'] ),
			count( $feedback_projection['releases'] ),
			count( $note_projection['rows'] )
		);

		$metadata = array(
			'evaluation_public_id'            => $evaluation_id,
			'evaluation_hash'                 => $evaluation_hash,
			'evaluation_source_key'           => $item->source_key(),
			'submission_public_id'            => $target['submission_id'],
			'relation_count'                  => $aggregate['relation_count'],
			'assessment_run_source_hashes'    => $aggregate['source_hashes']['assessment_runs'],
			'grading_input_source_hashes'     => $aggregate['source_hashes']['grading_inputs'],
			'rubric_snapshot_source_hashes'   => $aggregate['source_hashes']['rubric_snapshots'],
			'grading_item_source_hashes'      => $aggregate['source_hashes']['grading_items'],
			'assessment_result_source_hashes' => $aggregate['source_hashes']['assessment_results'],
			'regrade_review_source_hashes'    => $aggregate['source_hashes']['regrade_reviews'],
			'feedback_source_hashes'          => $aggregate['source_hashes']['feedback'],
			'feedback_approval_source_hashes' => $aggregate['source_hashes']['feedback_approvals'],
			'special_note_source_hashes'      => $aggregate['source_hashes']['special_notes'],
			'assessment_run_public_ids'       => $run_public_ids,
			'assessment_result_public_ids'    => $result_public_ids,
			'assessment_result_target_hashes' => $result_target_hashes,
			'regrade_review_public_ids'       => $review_projection['source_public_ids'],
			'regrade_review_target_hashes'    => $review_projection['source_target_hashes'],
			'feedback_public_ids'             => $feedback_projection['source_public_ids'],
			'feedback_target_hashes'          => $feedback_projection['source_target_hashes'],
			'release_public_ids'              => $feedback_projection['release_public_ids'],
			'release_target_hashes'           => $feedback_projection['release_target_hashes'],
			'special_note_public_ids'         => $note_projection['source_public_ids'],
			'special_note_target_hashes'      => $note_projection['source_target_hashes'],
		);
		if ( null !== $official ) {
			$metadata['official_review_public_id'] = $official['public_id'];
		}
		if ( null !== $current_release ) {
			$metadata['current_release_public_id'] = $current_release['public_id'];
			$metadata['current_release_hash']      = $current_release['content_hash'];
		}
		return new Legacy_Target_Write_Result( 'submission_evaluation', $evaluation_id, $evaluation_hash, $metadata );
	}

	/**
	 * Verify relation.
	 *
	 * @param int                  $run_database_id Run database id.
	 * @param Legacy_Source_Item   $item Item.
	 * @param array<string,mixed>  $plan Plan.
	 * @param array<string,string> $tables Tables.
	 * @param int                  $submission_source_id Submission source id.
	 * @param int                  $owner_id Owner id.
	 * @return Legacy_Target_Write_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function verify_relation(
		int $run_database_id,
		Legacy_Source_Item $item,
		array $plan,
		array $tables,
		int $submission_source_id,
		int $owner_id
	): Legacy_Target_Write_Result {
		$receipt        = $this->import_receipt( $run_database_id, 'assessment_runs', $owner_id, 'submission_evaluation' );
		$metadata       = $receipt['result']->metadata();
		$source_key     = hash( 'sha256', $item->source_key() );
		$source_map_key = $this->source_map_key( $item->source_type() );
		$source_map     = $metadata[ $source_map_key ] ?? null;
		$expected       = is_array( $source_map ) ? ( $source_map[ $source_key ] ?? null ) : null;
		if ( ! is_string( $expected ) || ! hash_equals( $expected, $item->source_checksum() ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::SOURCE_INVALID );
		}
		$evaluation_id = $receipt['result']->target_public_id();
		$this->assert_evaluation_root( $evaluation_id );

		$public_map_key = $this->public_map_key( $item->source_type() );
		if ( null === $public_map_key ) {
			return new Legacy_Target_Write_Result(
				'submission_evaluation_relation',
				$evaluation_id,
				$item->source_checksum(),
				array(
					'evaluation_public_id'  => $evaluation_id,
					'evaluation_source_key' => (string) $owner_id,
					'submission_source_key' => (string) $submission_source_id,
				)
			);
		}
		$public_map = $metadata[ $public_map_key ] ?? null;
		$target_id  = is_array( $public_map ) ? ( $public_map[ $source_key ] ?? null ) : null;
		if ( ! is_string( $target_id ) ) {
			return new Legacy_Target_Write_Result(
				'submission_evaluation_relation',
				$evaluation_id,
				$item->source_checksum(),
				array(
					'evaluation_public_id'  => $evaluation_id,
					'evaluation_source_key' => (string) $owner_id,
					'submission_source_key' => (string) $submission_source_id,
				)
			);
		}
		$hash_map_key = $this->target_hash_map_key( $item->source_type() );
		$hash_map     = null === $hash_map_key ? null : ( $metadata[ $hash_map_key ] ?? null );
		$target_hash  = is_array( $hash_map ) ? ( $hash_map[ $source_key ] ?? null ) : null;
		$this->assert_target_relation( $item->source_type(), $target_id, $target_hash, $evaluation_id );
		return new Legacy_Target_Write_Result(
			$this->target_type( $item->source_type() ),
			$target_id,
			is_string( $target_hash ) ? $target_hash : $item->source_checksum(),
			array(
				'evaluation_public_id'  => $evaluation_id,
				'evaluation_source_key' => (string) $owner_id,
				'submission_source_key' => (string) $submission_source_id,
			)
		);
	}

	/**
	 * Reviews.
	 *
	 * @param array<string,mixed>            $aggregate Aggregate.
	 * @param array<int,array<string,mixed>> $results Results.
	 * @param string                         $evaluation_id Evaluation id.
	 * @param int                            $actor_user_id Authenticated actor from the access context.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function reviews( array $aggregate, array $results, string $evaluation_id, int $actor_user_id ): array {
		$events  = array();
		$claimed = array();
		foreach ( $aggregate['regrade_reviews'] as $source ) {
			$status = $source['status'] ?? null;
			if ( ! in_array( $status, array( 'accepted', 'rejected', 'superseded' ), true ) ) {
				continue;
			}
			$result_id = $this->positive_integer( $source['proposed_result_id'] ?? null );
			if ( ! isset( $results[ $result_id ] ) ) {
				throw new InvalidArgumentException( 'Legacy regrade review result is missing.' );
			}
			$events[]              = array(
				'decision'         => 'accepted' === $status ? 'accepted' : 'rejected',
				'result_source_id' => $result_id,
				'source'           => $source,
				'source_type'      => 'regrade_reviews',
				'timestamp'        => $source['reviewed_at'] ?? $source['updated_at'] ?? $source['created_at'],
			);
			$claimed[ $result_id ] = true;
		}
		foreach ( $aggregate['assessment_results'] as $source ) {
			$result_id = $this->positive_integer( $source['id'] ?? null );
			$status    = $source['status'] ?? null;
			if ( isset( $claimed[ $result_id ] ) || ! in_array( $status, array( 'accepted', 'rejected' ), true ) ) {
				continue;
			}
			$events[] = array(
				'decision'         => $status,
				'result_source_id' => $result_id,
				'source'           => $source,
				'source_type'      => 'assessment_results',
				'timestamp'        => $source['accepted_at'] ?? $source['updated_at'] ?? $source['created_at'],
			);
		}
		usort( $events, fn ( array $left, array $right ): int => array( $this->utc_value( $left['timestamp'] ), (int) $left['source']['id'] ) <=> array( $this->utc_value( $right['timestamp'] ), (int) $right['source']['id'] ) );

		$rows                 = array();
		$by_result            = array();
		$source_public_ids    = array();
		$source_target_hashes = array();
		$previous_public_id   = null;
		$official             = null;
		foreach ( $events as $index => $event ) {
			$result          = $results[ $event['result_source_id'] ];
			$decision        = $event['decision'];
			$score_decisions = null;
			$score_json      = null;
			$score_hash      = null;
			$official_score  = null;
			if ( 'accepted' === $decision ) {
				$score_decisions = $this->projector->accepted_score_decisions( $result['normalized'] );
				$score_payload   = array(
					'schema_id'      => Review_Submission_Evaluation::SCORE_DECISIONS_SCHEMA,
					'schema_version' => '1.0.0',
					'items'          => $score_decisions,
				);
				$score_json      = $this->canonical->encode( $score_payload );
				$score_hash      = hash( 'sha256', $score_json );
				$official_score  = $result['normalized']->candidate_score();
			}
			$reason                                  = $this->optional_target_text(
				'regrade_reviews' === $event['source_type'] ? ( $event['source']['decision_note'] ?? null ) : ( $event['source']['review_comment'] ?? null ),
				3000
			);
			$public_id                               = $this->ids()->create()->value();
			$content_hash                            = hash(
				'sha256',
				$this->canonical->encode(
					array(
						'decision'             => $decision,
						'evaluation_id'        => $evaluation_id,
						'official_score'       => $official_score,
						'previous_review_id'   => $previous_public_id,
						'reason'               => $reason,
						'result_hash'          => $result['content_hash'],
						'result_id'            => $result['public_id'],
						'review_no'            => $index + 1,
						'score_decisions_hash' => $score_hash,
					)
				)
			);
			$row                                     = array(
				'content_hash'         => $content_hash,
				'created_at_utc'       => $this->utc_value( $event['timestamp'] ),
				'database_id'          => null,
				'decision'             => $decision,
				'official_score'       => $official_score,
				'previous_database_id' => null,
				'previous_public_id'   => $previous_public_id,
				'public_id'            => $public_id,
				'reason'               => $reason,
				'result'               => $result,
				'review_no'            => $index + 1,
				'score_decisions'      => $score_decisions,
				'score_json'           => $score_json,
				'score_hash'           => $score_hash,
			);
			$rows[]                                  = $row;
			$by_result[ $event['result_source_id'] ] = $row;
			if ( 'regrade_reviews' === $event['source_type'] ) {
				$key                          = hash( 'sha256', (string) $event['source']['id'] );
				$source_public_ids[ $key ]    = $public_id;
				$source_target_hashes[ $key ] = $content_hash;
			}
			if ( 'accepted' === $decision ) {
				$official = $row;
			}
			$previous_public_id = $public_id;
		}
		return compact( 'rows', 'by_result', 'source_public_ids', 'source_target_hashes', 'official' );
	}

	/**
	 * Feedback.
	 *
	 * @param array<string,mixed>            $aggregate Aggregate.
	 * @param array<int,array<string,mixed>> $results Results.
	 * @param array<int,array<string,mixed>> $reviews Reviews.
	 * @param array<string,mixed>|null       $official Official.
	 * @param array<string,mixed>            $score_input Score input.
	 * @param string                         $evaluation_id Evaluation id.
	 * @param string                         $submission_hash Submission hash.
	 * @param string                         $worksheet_title Worksheet title.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function feedback(
		array $aggregate,
		array $results,
		array $reviews,
		?array $official,
		array $score_input,
		string $evaluation_id,
		string $submission_hash,
		string $worksheet_title
	): array {
		$rows                          = array();
		$releases                      = array();
		$source_public_ids             = array();
		$source_target_hashes          = array();
		$release_public_ids            = array();
		$release_target_hashes         = array();
		$previous_feedback_id          = null;
		$previous_feedback_database_id = null;
		$previous_release_id           = null;
		$previous_release_database_id  = null;
		$current_release               = null;
		$feedback_rows                 = $aggregate['feedback'];
		usort( $feedback_rows, fn ( array $left, array $right ): int => array( $this->utc_value( $left['created_at'] ), (int) $left['id'] ) <=> array( $this->utc_value( $right['created_at'] ), (int) $right['id'] ) );

		foreach ( $feedback_rows as $source ) {
			$result_source_id = $this->positive_integer( $source['assessment_result_id'] ?? null );
			$review           = $reviews[ $result_source_id ] ?? null;
			$result           = $results[ $result_source_id ] ?? null;
			if ( ! is_array( $review ) || ! is_array( $result ) ) {
				throw new InvalidArgumentException( 'Legacy feedback review source is missing.' );
			}
			$review_source = $this->review_source( $evaluation_id, $review, $result, $submission_hash, $score_input );
			$projected     = $this->projector->feedback( $review_source, $source['feedback_text'] ?? null );
			$state         = match ( $source['status'] ?? null ) {
				'approved', 'published' => 'approved',
				'rejected' => 'rejected',
				'unpublished' => 'stale',
				'teacher_draft', 'pending_approval' => 'teacher_draft',
				default => throw new InvalidArgumentException( 'Legacy feedback state is invalid.' ),
			};
			$public_id                    = $this->ids()->create()->value();
			$revision_no                  = count( $rows ) + 1;
			$content_hash                 = hash(
				'sha256',
				$this->canonical->encode(
					array(
						'audience'             => 'student',
						'evaluation_id'        => $evaluation_id,
						'feedback_hash'        => $projected['result']->profile_hash(),
						'previous_revision_id' => $previous_feedback_id,
						'review_id'            => $review['public_id'],
						'revision_no'          => $revision_no,
						'source_run_id'        => null,
						'state'                => $state,
					)
				)
			);
			$row                          = array(
				'content_hash'         => $content_hash,
				'created_at_utc'       => $this->utc_value( $source['created_at'] ?? null ),
				'database_id'          => null,
				'input_hash'           => $projected['input']->gateway_input_hash(),
				'previous_database_id' => $previous_feedback_database_id,
				'previous_public_id'   => $previous_feedback_id,
				'profile_hash'         => $projected['result']->profile_hash(),
				'profile_json'         => $projected['result']->profile_json(),
				'public_id'            => $public_id,
				'review'               => $review,
				'review_source'        => $review_source,
				'revision_no'          => $revision_no,
				'source'               => $source,
				'state'                => $state,
			);
			$rows[]                       = $row;
			$key                          = hash( 'sha256', (string) $source['id'] );
			$source_public_ids[ $key ]    = $public_id;
			$source_target_hashes[ $key ] = $content_hash;
			$previous_feedback_id         = $public_id;

			if ( 'published' === ( $source['status'] ?? null ) && null !== $official && $official['public_id'] === $review['public_id'] ) {
				$released_at                   = $this->utc_value( $source['published_at'] ?? $source['updated_at'] ?? $source['created_at'] );
				$feedback_profile              = array(
					'state'   => 'approved',
					'schema'  => Submission_Feedback_Input_Builder::RESULT_SCHEMA,
					'version' => 1,
					'json'    => $projected['result']->profile_json(),
					'hash'    => $projected['result']->profile_hash(),
				);
				$release_state                 = array() === $releases ? 'released' : 'corrected';
				$projection                    = $this->projector->release( $review_source, $worksheet_title, $feedback_profile, $release_state, $released_at );
				$release_id                    = $this->ids()->create()->value();
				$release_no                    = count( $releases ) + 1;
				$release_hash                  = hash(
					'sha256',
					$this->canonical->encode(
						array(
							'evaluation_id'              => $evaluation_id,
							'feedback_id'                => $public_id,
							'previous_release_id'        => $previous_release_id,
							'release_no'                 => $release_no,
							'release_reason_code'        => null,
							'release_reason_note'        => null,
							'released_at_utc'            => $released_at,
							'review_id'                  => $review['public_id'],
							'state'                      => $release_state,
							'student_projection_hash'    => $projection->hash(),
							'student_projection_schema'  => Submission_Result_Projection::SCHEMA,
							'student_projection_version' => Submission_Result_Projection::VERSION,
							'visible_score'              => $projection->visible_score(),
						)
					)
				);
				$release                       = array(
					'content_hash'         => $release_hash,
					'feedback'             => $row,
					'previous_database_id' => $previous_release_database_id,
					'previous_public_id'   => $previous_release_id,
					'projection'           => $projection,
					'public_id'            => $release_id,
					'release_no'           => $release_no,
					'released_at_utc'      => $released_at,
					'review'               => $review,
					'state'                => $release_state,
				);
				$releases[]                    = $release;
				$release_public_ids[ $key ]    = $release_id;
				$release_target_hashes[ $key ] = $release_hash;
				$current_release               = $release;
				$previous_release_id           = $release_id;
			}
		}
		return compact( 'rows', 'releases', 'source_public_ids', 'source_target_hashes', 'release_public_ids', 'release_target_hashes', 'current_release' );
	}

	/**
	 * Notes.
	 *
	 * @param array<string,mixed>            $aggregate Aggregate.
	 * @param array<int,array<string,mixed>> $reviews Reviews.
	 * @param array<string,mixed>            $score_input Score input.
	 * @param string                         $evaluation_id Evaluation id.
	 * @param string                         $submission_hash Submission hash.
	 * @param int                            $actor_user_id Authenticated actor from the access context.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function notes(
		array $aggregate,
		array $reviews,
		array $score_input,
		string $evaluation_id,
		string $submission_hash,
		int $actor_user_id
	): array {
		$rows                 = array();
		$source_public_ids    = array();
		$source_target_hashes = array();
		if ( array() === $aggregate['special_notes'] ) {
			return compact( 'rows', 'source_public_ids', 'source_target_hashes' );
		}
		$source_rows = $aggregate['special_notes'];
		usort( $source_rows, fn ( array $left, array $right ): int => array( $this->utc_value( $left['created_at'] ), (int) $left['id'] ) <=> array( $this->utc_value( $right['created_at'] ), (int) $right['id'] ) );
		$note_root_id         = $this->ids()->create()->value();
		$previous_public_id   = null;
		$previous_database_id = null;
		foreach ( $source_rows as $index => $source ) {
			$result_source_id = $this->positive_integer( $source['assessment_result_id'] ?? null );
			$review           = $reviews[ $result_source_id ] ?? null;
			if ( ! is_array( $review ) ) {
				throw new InvalidArgumentException( 'Legacy student note review source is missing.' );
			}
			$review_source = $this->review_source( $evaluation_id, $review, $review['result'], $submission_hash, $score_input );
			$projected     = $this->projector->student_note( $review_source, $source['note_text'] ?? null );
			$state         = match ( $source['status'] ?? null ) {
				'draft', 'reviewed' => 'teacher_draft',
				'approved', 'included_in_report' => 'approved',
				default => throw new InvalidArgumentException( 'Legacy student note state is invalid.' ),
			};
			$include                      = 'included_in_report' === ( $source['status'] ?? null );
			$public_id                    = $this->ids()->create()->value();
			$revision_no                  = $index + 1;
			$content_hash                 = hash(
				'sha256',
				$this->canonical->encode(
					array(
						'evaluation_id'        => $evaluation_id,
						'include_in_reports'   => $include,
						'note_hash'            => $projected['result']->profile_hash(),
						'note_root_id'         => $note_root_id,
						'previous_revision_id' => $previous_public_id,
						'review_id'            => $review['public_id'],
						'revision_no'          => $revision_no,
						'source_run_id'        => null,
						'state'                => $state,
					)
				)
			);
			$approved                     = 'approved' === $state;
			$row                          = array(
				'approved_at_utc'      => $approved ? $this->utc_value( $source['approved_at'] ?? $source['updated_at'] ?? $source['created_at'] ) : null,
				'approved_by_user_id'  => $approved ? ( $this->optional_positive_integer( $source['approved_by'] ?? null ) ?? $actor_user_id ) : null,
				'content_hash'         => $content_hash,
				'created_at_utc'       => $this->utc_value( $source['created_at'] ?? null ),
				'database_id'          => null,
				'include_in_reports'   => $include,
				'input_hash'           => $projected['input']->gateway_input_hash(),
				'note_root_id'         => $note_root_id,
				'previous_database_id' => $previous_database_id,
				'previous_public_id'   => $previous_public_id,
				'profile_hash'         => $projected['result']->profile_hash(),
				'profile_json'         => $projected['result']->profile_json(),
				'public_id'            => $public_id,
				'revision_no'          => $revision_no,
				'review'               => $review,
				'source'               => $source,
				'state'                => $state,
			);
			$rows[]                       = $row;
			$key                          = hash( 'sha256', (string) $source['id'] );
			$source_public_ids[ $key ]    = $public_id;
			$source_target_hashes[ $key ] = $content_hash;
			$previous_public_id           = $public_id;
		}
		return compact( 'rows', 'source_public_ids', 'source_target_hashes' );
	}

	/**
	 * Bind auxiliary runs.
	 *
	 * @param array<int,array<string,mixed>> &$run_projections Run projections.
	 * @param array<int,array<string,mixed>> $feedback Feedback.
	 * @param array<int,array<string,mixed>> $notes Notes.
	 * @return void
	 */
	private function bind_auxiliary_runs( array &$run_projections, array $feedback, array $notes ): void {
		$feedback_runs = array_values( array_filter( array_keys( $run_projections ), static fn ( int $id ): bool => 'feedback' === ( $run_projections[ $id ]['source']['run_type'] ?? null ) && 'succeeded' === ( $run_projections[ $id ]['source']['status'] ?? null ) ) );
		$note_runs     = array_values( array_filter( array_keys( $run_projections ), static fn ( int $id ): bool => 'special_note' === ( $run_projections[ $id ]['source']['run_type'] ?? null ) && 'succeeded' === ( $run_projections[ $id ]['source']['status'] ?? null ) ) );
		foreach ( $feedback as $index => $row ) {
			$run_id = $feedback_runs[ $index ] ?? null;
			if ( null === $run_id ) {
				continue;
			}
			$run_projections[ $run_id ]['input_digest']     = $row['input_hash'];
			$run_projections[ $run_id ]['result_type']      = 'submission_feedback_revision';
			$run_projections[ $run_id ]['result_public_id'] = $row['public_id'];
		}
		foreach ( $notes as $index => $row ) {
			$run_id = $note_runs[ $index ] ?? null;
			if ( null === $run_id ) {
				continue;
			}
			$run_projections[ $run_id ]['input_digest']     = $row['input_hash'];
			$run_projections[ $run_id ]['result_type']      = 'student_note_revision';
			$run_projections[ $run_id ]['result_public_id'] = $row['public_id'];
		}
	}

	/**
	 * Review source.
	 *
	 * @param string              $evaluation_id Evaluation id.
	 * @param array<string,mixed> $review Review.
	 * @param array<string,mixed> $result Result.
	 * @param string              $submission_hash Submission hash.
	 * @param array<string,mixed> $score_input Score input.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function review_source( string $evaluation_id, array $review, array $result, string $submission_hash, array $score_input ): array {
		$score_decisions = $review['score_decisions'] ?? null;
		if ( ! is_array( $score_decisions ) ) {
			throw new InvalidArgumentException( 'Legacy feedback source review has no official score.' );
		}
		return array(
			'evaluation_id'            => $evaluation_id,
			'official_review_id'       => $review['public_id'],
			'official_review_hash'     => $review['content_hash'],
			'result_id'                => $result['public_id'],
			'result_hash'              => $result['content_hash'],
			'submission_revision_hash' => $submission_hash,
			'score_input'              => $score_input['gateway_input'],
			'score_decisions'          => $score_decisions,
		);
	}

	/**
	 * Insert input.
	 *
	 * @param int                 $evaluation_pk Evaluation pk.
	 * @param string              $public_id Public id.
	 * @param string              $evaluation_id Evaluation id.
	 * @param int                 $actor Actor.
	 * @param string              $created Created.
	 * @param array<string,mixed> $score_input Score input.
	 * @return void
	 */
	private function insert_input( int $evaluation_pk, string $public_id, string $evaluation_id, int $actor, string $created, array $score_input ): void {
		$content_hash = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'artifact_references_hash' => $score_input['artifact_references_hash'],
					'binding_manifest_hash'    => $score_input['binding_manifest_hash'],
					'evaluation_public_id'     => $evaluation_id,
					'input_byte_size'          => strlen( $score_input['input_json'] ),
					'input_hash'               => $score_input['input_hash'],
					'redaction_version'        => Submission_Score_Input_Builder::REDACTION_VERSION,
					'representation_set_hash'  => $score_input['representation_set_hash'],
					'source_snapshot_hash'     => $score_input['source_snapshot_hash'],
				)
			)
		);
		$this->insert_or_fail(
			self::TABLE_INPUTS,
			array(
				'public_id'                   => $public_id,
				'content_hash'                => $content_hash,
				'created_by_user_id'          => $actor,
				'created_at_utc'              => $created,
				'submission_evaluation_id'    => $evaluation_pk,
				'projection_schema_version'   => Submission_Score_Input_Builder::OPERATION_CONTRACT,
				'input_schema'                => Submission_Score_Input_Builder::REQUEST_SCHEMA,
				'input_version'               => self::PROFILE_VERSION,
				'input_json'                  => $score_input['input_json'],
				'input_hash'                  => $score_input['input_hash'],
				'artifact_references_schema'  => 'oj_ai.submission_artifact_references',
				'artifact_references_version' => self::PROFILE_VERSION,
				'artifact_references_json'    => $score_input['artifact_references_json'],
				'artifact_references_hash'    => $score_input['artifact_references_hash'],
				'input_byte_size'             => strlen( $score_input['input_json'] ),
				'redaction_version'           => Submission_Score_Input_Builder::REDACTION_VERSION,
			)
		);
	}

	/**
	 * Insert runs.
	 *
	 * @param string                         $evaluation_id Evaluation id.
	 * @param int                            $actor Actor.
	 * @param array<int,array<string,mixed>> &$projections Projections.
	 * @param string                         $fallback_digest Fallback digest.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function insert_runs( string $evaluation_id, int $actor, array &$projections, string $fallback_digest ): void {
		foreach ( $projections as $source_id => &$projection ) {
			$source   = $projection['source'];
			$type     = $source['run_type'] ?? null;
			$contract = match ( $type ) {
				'score' => array( Submission_Score_Input_Builder::OPERATION, Submission_Score_Input_Builder::OUTER_INPUT_CONTRACT, Submission_Score_Input_Builder::REQUEST_VERSION, Submission_Score_Input_Builder::OUTPUT_VERSION ),
				'feedback' => array( Submission_Feedback_Input_Builder::OPERATION, Submission_Feedback_Input_Builder::OUTER_INPUT_CONTRACT, Submission_Feedback_Input_Builder::REQUEST_VERSION, Submission_Feedback_Input_Builder::OUTPUT_VERSION ),
				'special_note' => array( Student_Note_Input_Builder::OPERATION, Student_Note_Input_Builder::OUTER_INPUT_CONTRACT, Student_Note_Input_Builder::REQUEST_VERSION, Student_Note_Input_Builder::OUTPUT_VERSION ),
				default => throw new InvalidArgumentException( 'Legacy evaluation run type is invalid.' ),
			};
			$has_result   = null !== $projection['result_public_id'];
			$source_state = $source['status'] ?? null;
			$state        = $has_result ? 'succeeded' : ( 'failed' === $source_state ? 'failed' : 'cancelled' );
			$error        = match ( true ) {
				'failed' === $state => 'legacy_run_failed',
				in_array( $source_state, array( 'queued', 'running' ), true ) => 'legacy_inflight_cancelled',
				'succeeded' === $source_state && ! $has_result => 'legacy_result_missing',
				default => null,
			};
			$queued   = $this->utc_value( $source['created_at'] ?? null );
			$started  = null === ( $source['started_at'] ?? null ) ? null : $this->utc_value( $source['started_at'] );
			$finished = $has_result || in_array( $state, array( 'failed', 'cancelled' ), true )
				? $this->utc_value( $source['finished_at'] ?? $source['updated_at'] ?? $source['created_at'] )
				: null;
			$this->insert_or_fail(
				self::TABLE_RUNS,
				array(
					'public_id'             => $projection['public_id'],
					'lock_version'          => 0,
					'created_by_user_id'    => $actor,
					'created_at_utc'        => $queued,
					'updated_by_user_id'    => $actor,
					'updated_at_utc'        => $finished ?? $queued,
					'subject_type'          => 'submission_evaluation',
					'evaluation_public_id'  => $evaluation_id,
					'run_no'                => $projection['run_no'],
					'state'                 => $state,
					'task_key'              => Addon_Manifest::TASK_STUDENT_ASSESSMENT,
					'contract_version'      => $contract[1],
					'operation_key'         => $contract[0],
					'input_schema_version'  => $contract[2],
					'output_schema_version' => $contract[3],
					'input_digest'          => $projection['input_digest'] ?? $fallback_digest,
					'result_type'           => $projection['result_type'],
					'result_public_id'      => $projection['result_public_id'],
					'idempotency_key'       => 'run:' . hash( 'sha256', 'legacy:' . $evaluation_id . ':' . $source_id ),
					'correlation_id'        => 'legacy:' . substr( hash( 'sha256', $evaluation_id . ':' . $source_id ), 0, 32 ),
					'provider_key'          => null,
					'model_key'             => null,
					'profile_fingerprint'   => null,
					'routing_revision'      => null,
					'available_at_utc'      => $queued,
					'queued_at_utc'         => $queued,
					'lease_token'           => null,
					'lease_expires_at_utc'  => null,
					'started_at_utc'        => $started,
					'finished_at_utc'       => $finished,
					'last_error_code'       => $error,
				)
			);
			$projection['database_id'] = $this->insert_id();
		}
		unset( $projection );
	}

	/**
	 * Insert results.
	 *
	 * @param int                            $evaluation_pk Evaluation pk.
	 * @param int                            $actor Actor.
	 * @param array<int,array<string,mixed>> &$results Results.
	 * @return void
	 */
	private function insert_results( int $evaluation_pk, int $actor, array &$results ): void {
		foreach ( $results as &$projection ) {
			$run        = $this->row( $this->prepare( 'SELECT id FROM ' . self::TABLE_RUNS . ' WHERE public_id = %s LIMIT 1 FOR UPDATE', array( $this->run_public_id_for_result( $projection ) ) ) );
			$run_pk     = $this->positive_integer( $run['id'] ?? null );
			$normalized = $projection['normalized'];
			$this->insert_or_fail(
				self::TABLE_RESULTS,
				array(
					'public_id'                 => $projection['public_id'],
					'content_hash'              => $projection['content_hash'],
					'created_by_user_id'        => $actor,
					'created_at_utc'            => $this->utc_value( $projection['source']['created_at'] ?? null ),
					'submission_evaluation_id'  => $evaluation_pk,
					'evaluation_run_id'         => $run_pk,
					'output_schema_version'     => Submission_Score_Input_Builder::OUTPUT_VERSION,
					'candidate_score'           => $normalized->candidate_score(),
					'maximum_score'             => $normalized->maximum_score(),
					'overall_confidence'        => $normalized->overall_confidence(),
					'abstained'                 => $normalized->abstained() ? 1 : 0,
					'safety_flagged'            => $normalized->safety_flagged() ? 1 : 0,
					'summary'                   => $normalized->summary(),
					'normalized_result_schema'  => Submission_Evaluation_Output_Normalizer::RESULT_SCHEMA,
					'normalized_result_version' => self::PROFILE_VERSION,
					'normalized_result_json'    => $normalized->normalized_json(),
					'normalized_result_hash'    => $normalized->normalized_hash(),
					'provider_output_digest'    => $projection['provider_digest'],
				)
			);
			$projection['database_id'] = $this->insert_id();
			foreach ( $normalized->items() as $item ) {
				$evidence_json = $this->canonical->encode( $item['evidence'] );
				$flags         = array() === $item['flags'] ? null : implode( ',', $item['flags'] );
				$content_hash  = hash(
					'sha256',
					$this->canonical->encode(
						array(
							'candidate_points' => $item['candidate_points'],
							'confidence'       => $item['confidence'],
							'criterion_key'    => $item['criterion_key'],
							'evidence_hash'    => hash( 'sha256', $evidence_json ),
							'flags'            => $flags,
							'maximum_points'   => $item['maximum_points'],
							'position'         => $item['position'],
							'question_key'     => $item['question_key'],
						)
					)
				);
				$this->insert_or_fail(
					self::TABLE_ITEMS,
					array(
						'content_hash'                    => $content_hash,
						'created_by_user_id'              => $actor,
						'created_at_utc'                  => $this->utc_value( $projection['source']['created_at'] ?? null ),
						'submission_evaluation_result_id' => $projection['database_id'],
						'criterion_key'                   => $item['criterion_key'],
						'question_key'                    => $item['question_key'],
						'position'                        => $item['position'],
						'candidate_points'                => $item['candidate_points'],
						'maximum_points'                  => $item['maximum_points'],
						'confidence'                      => $item['confidence'],
						'evidence_schema'                 => Submission_Evaluation_Output_Normalizer::ITEM_EVIDENCE_SCHEMA,
						'evidence_version'                => self::PROFILE_VERSION,
						'evidence_json'                   => $evidence_json,
						'evidence_hash'                   => hash( 'sha256', $evidence_json ),
						'flags'                           => $flags,
					)
				);
			}
		}
		unset( $projection );
	}

	/**
	 * Insert reviews.
	 *
	 * @param int                            $evaluation_pk Evaluation pk.
	 * @param int                            $actor Actor.
	 * @param array<int,array<string,mixed>> &$rows Rows.
	 * @return void
	 */
	private function insert_reviews( int $evaluation_pk, int $actor, array &$rows ): void {
		$previous_database_id = null;
		foreach ( $rows as &$row ) {
			$row['previous_database_id'] = $previous_database_id;
			$this->insert_or_fail(
				self::TABLE_REVIEWS,
				array(
					'public_id'                       => $row['public_id'],
					'content_hash'                    => $row['content_hash'],
					'created_by_user_id'              => $actor,
					'created_at_utc'                  => $row['created_at_utc'],
					'submission_evaluation_id'        => $evaluation_pk,
					'submission_evaluation_result_id' => $this->result_database_id( $row['result']['public_id'] ),
					'review_no'                       => $row['review_no'],
					'decision'                        => $row['decision'],
					'official_score'                  => $row['official_score'],
					'score_overrides_schema'          => null === $row['score_json'] ? null : Review_Submission_Evaluation::SCORE_DECISIONS_SCHEMA,
					'score_overrides_version'         => null === $row['score_json'] ? null : self::PROFILE_VERSION,
					'score_overrides_json'            => $row['score_json'],
					'score_overrides_hash'            => $row['score_hash'],
					'reason'                          => $row['reason'],
					'previous_review_id'              => $previous_database_id,
				)
			);
			$row['database_id']   = $this->insert_id();
			$previous_database_id = $row['database_id'];
		}
		unset( $row );
	}

	/**
	 * Insert feedback.
	 *
	 * @param int                            $evaluation_pk Evaluation pk.
	 * @param int                            $actor Actor.
	 * @param array<int,array<string,mixed>> &$rows Rows.
	 * @return void
	 */
	private function insert_feedback( int $evaluation_pk, int $actor, array &$rows ): void {
		$previous = null;
		foreach ( $rows as &$row ) {
			$row['previous_database_id'] = $previous;
			$this->insert_or_fail(
				self::TABLE_FEEDBACK,
				array(
					'public_id'                       => $row['public_id'],
					'content_hash'                    => $row['content_hash'],
					'created_by_user_id'              => $actor,
					'created_at_utc'                  => $row['created_at_utc'],
					'submission_evaluation_id'        => $evaluation_pk,
					'submission_review_id'            => $this->review_database_id( $row['review']['public_id'] ),
					'revision_no'                     => $row['revision_no'],
					'audience'                        => 'student',
					'feedback_schema'                 => Submission_Feedback_Input_Builder::RESULT_SCHEMA,
					'feedback_version'                => self::PROFILE_VERSION,
					'feedback_json'                   => $row['profile_json'],
					'feedback_hash'                   => $row['profile_hash'],
					'state'                           => $row['state'],
					'previous_revision_id'            => $previous,
					'source_evaluation_run_public_id' => null,
				)
			);
			$row['database_id'] = $this->insert_id();
			$previous           = $row['database_id'];
		}
		unset( $row );
	}

	/**
	 * Insert releases.
	 *
	 * @param int                            $evaluation_pk Evaluation pk.
	 * @param int                            $actor Actor.
	 * @param array<int,array<string,mixed>> &$rows Rows.
	 * @return void
	 */
	private function insert_releases( int $evaluation_pk, int $actor, array &$rows ): void {
		$previous = null;
		foreach ( $rows as &$row ) {
			$this->insert_or_fail(
				self::TABLE_RELEASES,
				array(
					'public_id'                  => $row['public_id'],
					'content_hash'               => $row['content_hash'],
					'created_by_user_id'         => $actor,
					'created_at_utc'             => $row['released_at_utc'],
					'submission_evaluation_id'   => $evaluation_pk,
					'submission_review_id'       => $this->review_database_id( $row['review']['public_id'] ),
					'feedback_revision_id'       => $this->feedback_database_id( $row['feedback']['public_id'] ),
					'release_no'                 => $row['release_no'],
					'state'                      => $row['state'],
					'visible_score'              => $row['projection']->visible_score(),
					'student_projection_schema'  => Submission_Result_Projection::SCHEMA,
					'student_projection_version' => Submission_Result_Projection::VERSION,
					'student_projection_json'    => $row['projection']->json(),
					'student_projection_hash'    => $row['projection']->hash(),
					'previous_release_id'        => $previous,
					'release_reason_code'        => null,
					'release_reason_note'        => null,
					'released_at_utc'            => $row['released_at_utc'],
				)
			);
			$row['database_id'] = $this->insert_id();
			$previous           = $row['database_id'];
		}
		unset( $row );
	}

	/**
	 * Insert notes.
	 *
	 * @param int                            $evaluation_pk Evaluation pk.
	 * @param int                            $actor Actor.
	 * @param array<int,array<string,mixed>> &$rows Rows.
	 * @return void
	 */
	private function insert_notes( int $evaluation_pk, int $actor, array &$rows ): void {
		$previous = null;
		foreach ( $rows as &$row ) {
			$this->insert_or_fail(
				self::TABLE_NOTES,
				array(
					'public_id'                       => $row['public_id'],
					'content_hash'                    => $row['content_hash'],
					'created_by_user_id'              => $actor,
					'created_at_utc'                  => $row['created_at_utc'],
					'student_note_public_id'          => $row['note_root_id'],
					'submission_evaluation_id'        => $evaluation_pk,
					'submission_review_id'            => $this->review_database_id( $row['review']['public_id'] ),
					'source_evaluation_run_public_id' => null,
					'revision_no'                     => $row['revision_no'],
					'state'                           => $row['state'],
					'include_in_reports'              => $row['include_in_reports'] ? 1 : 0,
					'note_schema'                     => Student_Note_Input_Builder::RESULT_SCHEMA,
					'note_version'                    => self::PROFILE_VERSION,
					'note_json'                       => $row['profile_json'],
					'note_hash'                       => $row['profile_hash'],
					'previous_revision_id'            => $previous,
					'approved_by_user_id'             => $row['approved_by_user_id'],
					'approved_at_utc'                 => $row['approved_at_utc'],
				)
			);
			$row['database_id'] = $this->insert_id();
			$previous           = $row['database_id'];
		}
		unset( $row );
	}

	/**
	 * Update submission pointers.
	 *
	 * @param array<string,mixed>      $target Target.
	 * @param array<string,mixed>|null $official Official.
	 * @param array<string,mixed>|null $release Release.
	 * @param int                      $actor Actor.
	 * @param string                   $updated Updated.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function update_submission_pointers( array $target, ?array $official, ?array $release, int $actor, string $updated ): void {
		if ( null === $official ) {
			if ( null !== $release ) {
				throw new InvalidArgumentException( 'Legacy release lacks an official review.' );
			}
			return;
		}
		$values = array( $official['public_id'], $official['content_hash'] );
		$sql    = 'UPDATE ' . self::TABLE_SUBMISSIONS . ' SET official_review_public_id = %s, official_review_hash = %s';
		if ( null === $release ) {
			$sql .= ', current_release_public_id = NULL, current_release_hash = NULL';
		} else {
			$sql     .= ', current_release_public_id = %s, current_release_hash = %s';
			$values[] = $release['public_id'];
			$values[] = $release['content_hash'];
		}
		$sql   .= ', lock_version = lock_version + 1, updated_by_user_id = %d, updated_at_utc = %s WHERE id = %d AND official_revision_public_id = %s AND official_review_public_id IS NULL AND official_review_hash IS NULL AND current_release_public_id IS NULL AND current_release_hash IS NULL';
		$values = array_merge( $values, array( $actor, $updated, $target['submission_database_id'], $target['submission_revision_id'] ) );
		if ( 1 !== $this->query( $this->prepare( $sql, $values ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Run public id for result.
	 *
	 * @param array<string,mixed> $result Result.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function run_public_id_for_result( array $result ): string {
		$row = $this->row(
			$this->prepare(
				'SELECT public_id FROM ' . self::TABLE_RUNS . ' WHERE result_public_id = %s AND result_type = %s LIMIT 1 FOR UPDATE',
				array( $result['public_id'], 'submission_evaluation_result' )
			)
		);
		$id  = $row['public_id'] ?? null;
		if ( ! is_string( $id ) || ! Public_Id::is_valid( $id ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		return $id;
	}

	/**
	 * Review database id.
	 *
	 * @param string $public_id Public id.
	 * @return int
	 */
	private function review_database_id( string $public_id ): int {
		$row = $this->row( $this->prepare( 'SELECT id FROM ' . self::TABLE_REVIEWS . ' WHERE public_id = %s LIMIT 1 FOR UPDATE', array( $public_id ) ) );
		return $this->positive_integer( $row['id'] ?? null );
	}

	/**
	 * Feedback database id.
	 *
	 * @param string $public_id Public id.
	 * @return int
	 */
	private function feedback_database_id( string $public_id ): int {
		$row = $this->row( $this->prepare( 'SELECT id FROM ' . self::TABLE_FEEDBACK . ' WHERE public_id = %s LIMIT 1 FOR UPDATE', array( $public_id ) ) );
		return $this->positive_integer( $row['id'] ?? null );
	}


	/**
	 * Result database id.
	 *
	 * @param string $public_id Public id.
	 * @return int
	 */
	private function result_database_id( string $public_id ): int {
		$row = $this->row( $this->prepare( 'SELECT id FROM ' . self::TABLE_RESULTS . ' WHERE public_id = %s LIMIT 1 FOR UPDATE', array( $public_id ) ) );
		return $this->positive_integer( $row['id'] ?? null );
	}
	/**
	 * Latest timestamp.
	 *
	 * @param array<string,mixed> $aggregate Aggregate.
	 * @param string              $fallback Fallback.
	 * @return string
	 */
	private function latest_timestamp( array $aggregate, string $fallback ): string {
		$latest = $this->utc_value( $fallback );
		foreach ( self::AGGREGATE_TYPES as $type ) {
			foreach ( $aggregate[ $type ] as $row ) {
				$value = $this->utc_value( $row['updated_at'] ?? $row['created_at'] ?? $fallback );
				if ( $value > $latest ) {
					$latest = $value;
				}
			}
		}
		return $latest;
	}

	/**
	 * Empty evaluation state.
	 *
	 * @param array<int,array<string,mixed>> $runs Runs.
	 * @return string
	 */
	private function empty_evaluation_state( array $runs ): string {
		foreach ( $runs as $run ) {
			if ( 'failed' === ( $run['status'] ?? null ) ) {
				return 'failed';
			}
		}
		return 'cancelled';
	}

	/**
	 * Authorization envelope.
	 *
	 * @param int                 $actor Actor.
	 * @param string              $checked Checked.
	 * @param array<string,mixed> $target Target.
	 * @return array<string,mixed>
	 */
	private function authorization_envelope( int $actor, string $checked, array $target ): array {
		return array(
			'schema_id'             => 'oj_ai.authorization_envelope',
			'schema_version'        => '1.0.0',
			'actor_user_id'         => $actor,
			'shell'                 => 'console',
			'surface'               => 'rest',
			'action_key'            => 'submission.evaluation.enqueue',
			'required_capabilities' => array( Addon_Capabilities::ASSESS_SUBMISSIONS ),
			'context'               => array(
				'type'      => $target['context_type'],
				'id'        => $target['context_id'],
				'revision'  => $target['context_revision'],
				'operation' => 'evaluate',
			),
			'object'                => array(
				'type'                   => 'submission',
				'id'                     => $target['submission_id'],
				'action'                 => 'evaluate',
				'authorized_revision'    => $target['context_revision'],
				'authorized_state'       => $target['submission_state'],
				'actor_relation'         => 'context_staff',
				'official_revision_id'   => $target['submission_revision_id'],
				'official_revision_hash' => $target['submission_revision_hash'],
			),
			'checked_at_utc'        => $checked,
		);
	}

	/**
	 * Target submission.
	 *
	 * @param Legacy_Target_Write_Result $result Result.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function target_submission( Legacy_Target_Write_Result $result ): array {
		$metadata    = $result->metadata();
		$official_id = $metadata['official_revision_public_id'] ?? null;
		if ( ! is_string( $official_id ) || ! Public_Id::is_valid( $official_id ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::DEPENDENCY_MISSING );
		}
		$row = $this->row(
			$this->prepare(
				'SELECT s.id AS submission_database_id, s.public_id AS submission_id, s.state AS submission_state, s.official_revision_public_id, s.official_review_public_id, s.official_review_hash, s.current_release_public_id, s.current_release_hash, r.id AS submission_revision_database_id, r.public_id AS submission_revision_id, r.content_hash AS submission_revision_hash, r.response_schema_version, r.response_schema, r.response_version, r.response_json, r.response_hash, r.attachment_set_hash, a.id AS assignment_database_id, a.public_id AS assignment_id, a.context_type, a.context_id, a.context_revision, a.title_snapshot AS assignment_title, a.worksheet_publication_public_id AS publication_id, a.publication_hash AS assignment_publication_hash, p.worksheet_revision_id, p.content_hash AS publication_content_hash, p.publication_hash, p.state AS publication_state, p.grading_schema_schema, p.grading_schema_version, p.grading_schema_json, p.grading_schema_hash, p.response_schema_schema, p.response_schema_version AS publication_response_version, p.response_schema_json, p.response_schema_hash FROM ' . self::TABLE_SUBMISSIONS . ' s INNER JOIN ' . self::TABLE_REVISIONS . ' r ON r.public_id = s.official_revision_public_id '
					. 'INNER JOIN ' . self::TABLE_ASSIGNMENTS . ' a ON a.id = s.assignment_id INNER JOIN ' . self::TABLE_PUBLICATIONS . ' p '
					. 'ON p.public_id = a.worksheet_publication_public_id WHERE s.public_id = %s LIMIT 1 FOR UPDATE',
				array( $result->target_public_id() )
			)
		);
		if (
			null === $row
			|| ! hash_equals( $result->target_public_id(), (string) ( $row['submission_id'] ?? '' ) )
			|| ! hash_equals( $official_id, (string) ( $row['official_revision_public_id'] ?? '' ) )
			|| null !== ( $row['official_review_public_id'] ?? null ) || null !== ( $row['official_review_hash'] ?? null )
			|| null !== ( $row['current_release_public_id'] ?? null ) || null !== ( $row['current_release_hash'] ?? null )
			|| ! in_array( $row['submission_state'] ?? null, array( 'submitted', 'auto_submitted', 'proxy_submitted', 'locked' ), true )
			|| ! in_array( $row['publication_state'] ?? null, array( 'active', 'retired' ), true )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::DEPENDENCY_MISSING );
		}
		foreach ( array( 'submission_revision_hash', 'attachment_set_hash', 'assignment_publication_hash', 'publication_content_hash', 'publication_hash', 'grading_schema_hash', 'response_schema_hash', 'response_hash' ) as $field ) {
			if ( ! $this->is_hash( (string) ( $row[ $field ] ?? '' ) ) ) {
				throw new InvalidArgumentException( 'Target evaluation dependency hash is invalid.' );
			}
		}
		if (
			! hash_equals( (string) $row['assignment_publication_hash'], (string) $row['publication_hash'] )
			|| ! hash_equals( (string) $row['publication_content_hash'], (string) $row['publication_hash'] )
			|| Submission_Revision_Integrity::RESPONSE_SCHEMA !== ( $row['response_schema'] ?? null )
			|| 1 !== $this->positive_integer( $row['response_version'] ?? null )
			|| Submission_Revision_Integrity::RESPONSE_SCHEMA_VERSION !== ( $row['response_schema_version'] ?? null )
		) {
			throw new InvalidArgumentException( 'Target evaluation dependency drifted.' );
		}
		$response         = $this->decode_stored( (string) $row['response_json'], (string) $row['response_hash'] );
		$grading          = $this->decode_stored( (string) $row['grading_schema_json'], (string) $row['grading_schema_hash'] );
		$response_profile = $this->decode_stored( (string) $row['response_schema_json'], (string) $row['response_schema_hash'] );
		foreach ( array( $grading, $response_profile ) as $profile ) {
			$this->exact_keys( $profile, array( 'payload', 'payload_hash', 'projector_version', 'schema_id', 'schema_version', 'source_hash' ) );
			if ( ! is_array( $profile['payload'] ?? null ) || array_is_list( $profile['payload'] ) || ! hash_equals( (string) $profile['payload_hash'], hash( 'sha256', $this->canonical->encode( $profile['payload'] ) ) ) ) {
				throw new InvalidArgumentException( 'Target publication profile is invalid.' );
			}
		}
		$this->exact_keys( $response, array( 'answers', 'publication_hash', 'publication_id', 'response_schema_version' ) );
		if (
			! hash_equals( (string) $row['publication_hash'], (string) ( $response['publication_hash'] ?? '' ) )
			|| ! hash_equals( (string) $row['publication_id'], (string) ( $response['publication_id'] ?? '' ) )
			|| ! is_array( $response['answers'] ?? null )
			|| ( array() !== $response['answers'] && array_is_list( $response['answers'] ) )
		) {
			throw new InvalidArgumentException( 'Target submission response drifted.' );
		}

		return array(
			'assignment_title'         => $this->target_text( $row['assignment_title'] ?? null, 255 ),
			'answers'                  => $response['answers'],
			'context_id'               => $this->target_token( $row['context_id'] ?? null, 191, true ),
			'context_revision'         => $this->target_token( $row['context_revision'] ?? null, 191, true ),
			'context_type'             => $this->target_context_type( $row['context_type'] ?? null ),
			'grading_payload'          => $grading['payload'],
			'grading_schema_hash'      => (string) $row['grading_schema_hash'],
			'publication_hash'         => (string) $row['publication_hash'],
			'publication_id'           => (string) $row['publication_id'],
			'response_payload'         => $response_profile['payload'],
			'submission_database_id'   => $this->positive_integer( $row['submission_database_id'] ?? null ),
			'submission_id'            => (string) $row['submission_id'],
			'submission_revision_hash' => (string) $row['submission_revision_hash'],
			'submission_revision_id'   => (string) $row['submission_revision_id'],
			'submission_state'         => (string) $row['submission_state'],
			'worksheet_revision_id'    => $this->positive_integer( $row['worksheet_revision_id'] ?? null ),
		);
	}

	/**
	 * Legacy question response ids.
	 *
	 * @param int                  $run_id Run id.
	 * @param int                  $template_id Template id.
	 * @param int                  $target_revision_id Target revision id.
	 * @param array<string,string> $tables Tables.
	 * @return array<int,string>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function legacy_question_response_ids( int $run_id, int $template_id, int $target_revision_id, array $tables ): array {
		$receipt    = $this->import_receipt( $run_id, 'worksheet_templates', $template_id, 'worksheet' );
		$public_ids = $receipt['result']->metadata()['question_public_ids'] ?? null;
		if ( ! is_array( $public_ids ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		$source_rows = $this->source_relations( $tables['worksheet_questions'], 'worksheet_questions', 'template_id', $template_id, '`id` ASC' );
		$result      = array();
		foreach ( $source_rows as $source ) {
			$source_id = $this->positive_integer( $source['id'] ?? null );
			$public_id = $public_ids[ hash( 'sha256', (string) $source_id ) ] ?? null;
			if ( ! is_string( $public_id ) || ! Public_Id::is_valid( $public_id ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
			}
			$row = $this->row( $this->prepare( 'SELECT public_id, question_key, worksheet_revision_id FROM ' . self::TABLE_QUESTIONS . ' WHERE public_id = %s LIMIT 1 FOR UPDATE', array( $public_id ) ) );
			if ( null === $row || $target_revision_id !== $this->positive_integer( $row['worksheet_revision_id'] ?? null ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
			}
			$key = $row['question_key'] ?? null;
			if ( ! is_string( $key ) || ! Public_Id::is_valid( $key ) ) {
				throw new InvalidArgumentException( 'Target question response ID is invalid.' );
			}
			$result[ (string) $source_id ] = $key;
		}
		return $result;
	}

	/**
	 * Aggregate.
	 *
	 * @param int                  $submission_id Submission public identifier.
	 * @param array<string,mixed>  $plan Plan.
	 * @param array<string,string> $tables Tables.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function aggregate( int $submission_id, array $plan, array $tables ): array {
		$result                            = array( 'submission' => $this->source_row_by_id( $tables['worksheet_submissions'], 'worksheet_submissions', $submission_id ) );
		$result['assessment_runs']         = $this->source_relations( $tables['assessment_runs'], 'assessment_runs', 'submission_id', $submission_id, '`id` ASC' );
		$result['grading_inputs']          = $this->source_relations( $tables['grading_inputs'], 'grading_inputs', 'submission_id', $submission_id, '`id` ASC' );
		$result['assessment_results']      = $this->source_relations( $tables['assessment_results'], 'assessment_results', 'submission_id', $submission_id, '`id` ASC' );
		$result['rubric_snapshots']        = array();
		$result['grading_items']           = array();
		$result['grading_items_by_result'] = array();
		$result['regrade_reviews']         = array();
		$result['feedback']                = array();
		$result['feedback_approvals']      = array();
		$result['special_notes']           = array();

		$regrade  = array();
		$feedback = array();
		foreach ( $result['assessment_results'] as $source_result ) {
			$result_id   = $this->positive_integer( $source_result['id'] ?? null );
			$snapshot_id = $this->non_negative_integer( $source_result['rubric_snapshot_id'] ?? null );
			if ( $snapshot_id > 0 && ! isset( $result['rubric_snapshots'][ $snapshot_id ] ) ) {
				$result['rubric_snapshots'][ $snapshot_id ] = $this->source_row_by_id( $tables['rubric_snapshots'], 'rubric_snapshots', $snapshot_id );
			}
			$items = $this->source_relations( $tables['grading_items'], 'grading_items', 'result_id', $result_id, '`id` ASC' );
			$result['grading_items_by_result'][ $result_id ] = $items;
			foreach ( $items as $row ) {
				$result['grading_items'][ $this->positive_integer( $row['id'] ) ] = $row;
			}
			foreach ( array( 'current_result_id', 'proposed_result_id' ) as $column ) {
				foreach ( $this->source_relations( $tables['regrade_reviews'], 'regrade_reviews', $column, $result_id, '`id` ASC' ) as $row ) {
					$regrade[ $this->positive_integer( $row['id'] ) ] = $row;
				}
			}
			foreach ( $this->source_relations( $tables['feedback'], 'feedback', 'assessment_result_id', $result_id, '`id` ASC' ) as $row ) {
				$feedback[ $this->positive_integer( $row['id'] ) ] = $row;
			}
			foreach ( $this->source_relations( $tables['special_notes'], 'special_notes', 'assessment_result_id', $result_id, '`id` ASC' ) as $row ) {
				$result['special_notes'][ $this->positive_integer( $row['id'] ) ] = $row;
			}
		}
		$result['regrade_reviews'] = array_values( $regrade );
		$result['feedback']        = array_values( $feedback );
		foreach ( $result['feedback'] as $source_feedback ) {
			$feedback_id = $this->positive_integer( $source_feedback['id'] ?? null );
			foreach ( $this->source_relations( $tables['feedback_approvals'], 'feedback_approvals', 'feedback_id', $feedback_id, '`id` ASC' ) as $row ) {
				$result['feedback_approvals'][ $this->positive_integer( $row['id'] ) ] = $row;
			}
		}
		foreach ( array( 'rubric_snapshots', 'grading_items', 'feedback_approvals', 'special_notes' ) as $type ) {
			$result[ $type ] = array_values( $result[ $type ] );
		}
		foreach ( self::AGGREGATE_TYPES as $type ) {
			usort( $result[ $type ], static fn ( array $left, array $right ): int => (int) $left['id'] <=> (int) $right['id'] );
		}
		$count         = 0;
		$source_hashes = array();
		foreach ( self::AGGREGATE_TYPES as $type ) {
			$count                 += count( $result[ $type ] );
			$source_hashes[ $type ] = $this->source_hash_map( $plan, $type, $result[ $type ] );
		}
		if ( $count > self::MAXIMUM_RELATIONS ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::SOURCE_INVALID );
		}
		$result['relation_count'] = $count;
		$result['source_hashes']  = $source_hashes;
		return $result;
	}

	/**
	 * Source hash map.
	 *
	 * @param array<string,mixed>            $plan Plan.
	 * @param string                         $type Type.
	 * @param array<int,array<string,mixed>> $rows Rows.
	 * @return array<string,string>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function source_hash_map( array $plan, string $type, array $rows ): array {
		if ( count( $rows ) > self::MAXIMUM_RELATIONS ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::SOURCE_INVALID );
		}
		$result = array();
		foreach ( $rows as $row ) {
			$this->assert_exact_source_row( $type, $row );
			if ( null !== ( $row['deleted_at'] ?? null ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::SOURCE_INVALID );
			}
			$id             = $this->positive_integer( $row['id'] ?? null );
			$key            = hash( 'sha256', (string) $id );
			$result[ $key ] = $this->source_checksum( $plan, $type, $id, $row );
		}
		return $result;
	}

	/**
	 * Parent submission id.
	 *
	 * @param string               $type Type.
	 * @param array                $row Row.
	 * @param array<string,string> $tables Tables.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function parent_submission_id( string $type, array $row, array $tables ): int {
		if ( in_array( $type, array( 'assessment_runs', 'grading_inputs', 'assessment_results' ), true ) ) {
			return $this->positive_integer( $row['submission_id'] ?? null );
		}
		if ( 'grading_items' === $type ) {
			$result = $this->source_row_by_id( $tables['assessment_results'], 'assessment_results', $this->positive_integer( $row['result_id'] ?? null ) );
			return $this->positive_integer( $result['submission_id'] ?? null );
		}
		if ( 'rubric_snapshots' === $type ) {
			$submission_id = $this->snapshot_submission_id( $this->positive_integer( $row['id'] ?? null ), $tables );
			if ( null === $submission_id ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::DEPENDENCY_MISSING );
			}
			return $submission_id;
		}
		if ( 'regrade_reviews' === $type ) {
			$result = $this->source_row_by_id( $tables['assessment_results'], 'assessment_results', $this->positive_integer( $row['proposed_result_id'] ?? null ) );
			return $this->positive_integer( $result['submission_id'] ?? null );
		}
		if ( in_array( $type, array( 'feedback', 'special_notes' ), true ) ) {
			$result = $this->source_row_by_id( $tables['assessment_results'], 'assessment_results', $this->positive_integer( $row['assessment_result_id'] ?? null ) );
			return $this->positive_integer( $result['submission_id'] ?? null );
		}
		if ( 'feedback_approvals' === $type ) {
			$feedback = $this->source_row_by_id( $tables['feedback'], 'feedback', $this->positive_integer( $row['feedback_id'] ?? null ) );
			$result   = $this->source_row_by_id( $tables['assessment_results'], 'assessment_results', $this->positive_integer( $feedback['assessment_result_id'] ?? null ) );
			return $this->positive_integer( $result['submission_id'] ?? null );
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
		throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::UNSUPPORTED );
	}

	/**
	 * Snapshot submission id.
	 *
	 * @param int                  $snapshot_id Snapshot id.
	 * @param array<string,string> $tables Tables.
	 * @return int|null
	 */
	private function snapshot_submission_id( int $snapshot_id, array $tables ): ?int {
		$found = $this->row( $this->prepare( 'SELECT submission_id FROM `' . $tables['assessment_results'] . '` WHERE rubric_snapshot_id = %d AND deleted_at IS NULL ORDER BY id ASC LIMIT 1 FOR UPDATE', array( $snapshot_id ) ) );
		return null === $found ? null : $this->positive_integer( $found['submission_id'] ?? null );
	}

	/**
	 * Verify plan snapshot.
	 *
	 * @param int                  $run_id Run id.
	 * @param Legacy_Source_Item   $item Item.
	 * @param array<string,mixed>  $snapshot Snapshot.
	 * @param array<string,string> $tables Tables.
	 * @return Legacy_Target_Write_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function verify_plan_snapshot( int $run_id, Legacy_Source_Item $item, array $snapshot, array $tables ): Legacy_Target_Write_Result {
		$template_id = $this->positive_integer( $snapshot['template_id'] ?? null );
		$plan        = $this->row(
			$this->prepare(
				'SELECT plan.id FROM `' . $tables['assessment_plans'] . '` plan INNER JOIN `' . $tables['worksheet_assignments'] . '` assignment '
					. 'ON assignment.id = plan.assignment_id WHERE plan.deleted_at IS NULL AND assignment.deleted_at IS NULL '
					. 'AND (plan.template_id = %d OR (plan.template_id = 0 AND assignment.template_id = %d)) ORDER BY plan.id ASC LIMIT 1 FOR UPDATE',
				array( $template_id, $template_id )
			)
		);
		if ( null === $plan ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::DEPENDENCY_MISSING );
		}
		$plan_id       = $this->positive_integer( $plan['id'] ?? null );
		$receipt       = $this->import_receipt( $run_id, 'assessment_plans', $plan_id, 'assessment_plan_snapshot' );
		$source_hashes = $receipt['result']->metadata()['rubric_snapshot_source_hashes'] ?? null;
		$key           = hash( 'sha256', (string) $item->source_id() );
		$expected      = is_array( $source_hashes ) ? ( $source_hashes[ $key ] ?? null ) : null;
		if ( ! is_string( $expected ) || ! hash_equals( $expected, $item->source_checksum() ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::SOURCE_INVALID );
		}
		return new Legacy_Target_Write_Result(
			'assessment_plan_rubric_snapshot',
			$receipt['result']->target_public_id(),
			$item->source_checksum(),
			array(
				'assessment_plan_source_key' => (string) $plan_id,
				'rubric_snapshot_source_key' => $item->source_key(),
			)
		);
	}

	/**
	 * Owner run id.
	 *
	 * @param int                  $submission_id Submission public identifier.
	 * @param array<string,string> $tables Tables.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function owner_run_id( int $submission_id, array $tables ): int {
		$row = $this->row( $this->prepare( 'SELECT id FROM `' . $tables['assessment_runs'] . '` WHERE submission_id = %d AND deleted_at IS NULL ORDER BY id ASC LIMIT 1 FOR UPDATE', array( $submission_id ) ) );
		if ( null === $row ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::DEPENDENCY_MISSING );
		}
		return $this->positive_integer( $row['id'] ?? null );
	}

	/**
	 * Reject invalid postcondition.
	 *
	 * @param int                      $evaluation_pk Evaluation pk.
	 * @param string                   $evaluation_id Evaluation id.
	 * @param int                      $submission_pk Submission pk.
	 * @param array<string,mixed>|null $official Official.
	 * @param array<string,mixed>|null $release Release.
	 * @param int                      $run_count Run count.
	 * @param int                      $result_count Result count.
	 * @param int                      $review_count Review count.
	 * @param int                      $feedback_count Feedback count.
	 * @param int                      $release_count Release count.
	 * @param int                      $note_count Note count.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_postcondition(
		int $evaluation_pk,
		string $evaluation_id,
		int $submission_pk,
		?array $official,
		?array $release,
		int $run_count,
		int $result_count,
		int $review_count,
		int $feedback_count,
		int $release_count,
		int $note_count
	): void {
		$row = $this->row(
			$this->prepare(
				'SELECT e.public_id, s.official_review_public_id, s.official_review_hash, s.current_release_public_id, s.current_release_hash, (SELECT COUNT(*) FROM ' . self::TABLE_RUNS . ' r WHERE r.evaluation_public_id = e.public_id) AS run_count, '
					. '(SELECT COUNT(*) FROM ' . self::TABLE_RESULTS . ' x WHERE x.submission_evaluation_id = e.id) AS result_count, '
					. '(SELECT COUNT(*) FROM ' . self::TABLE_REVIEWS . ' v WHERE v.submission_evaluation_id = e.id) AS review_count, '
					. '(SELECT COUNT(*) FROM ' . self::TABLE_FEEDBACK . ' f WHERE f.submission_evaluation_id = e.id) AS feedback_count, '
					. '(SELECT COUNT(*) FROM ' . self::TABLE_RELEASES . ' l WHERE l.submission_evaluation_id = e.id) AS release_count, '
					. '(SELECT COUNT(*) FROM ' . self::TABLE_NOTES . ' n WHERE n.submission_evaluation_id = e.id) AS note_count '
					. 'FROM ' . self::TABLE_EVALUATIONS . ' e INNER JOIN ' . self::TABLE_SUBMISSIONS . ' s ON s.id = %d WHERE e.id = %d LIMIT 1 FOR UPDATE',
				array( $submission_pk, $evaluation_pk )
			)
		);
		if (
			null === $row || ! hash_equals( $evaluation_id, (string) ( $row['public_id'] ?? '' ) )
			|| ( null === $official ? null : $official['public_id'] ) !== ( $row['official_review_public_id'] ?? null )
			|| ( null === $official ? null : $official['content_hash'] ) !== ( $row['official_review_hash'] ?? null )
			|| ( null === $release ? null : $release['public_id'] ) !== ( $row['current_release_public_id'] ?? null )
			|| ( null === $release ? null : $release['content_hash'] ) !== ( $row['current_release_hash'] ?? null )
			|| $run_count !== $this->non_negative_integer( $row['run_count'] ?? null )
			|| $result_count !== $this->non_negative_integer( $row['result_count'] ?? null )
			|| $review_count !== $this->non_negative_integer( $row['review_count'] ?? null )
			|| $feedback_count !== $this->non_negative_integer( $row['feedback_count'] ?? null )
			|| $release_count !== $this->non_negative_integer( $row['release_count'] ?? null )
			|| $note_count !== $this->non_negative_integer( $row['note_count'] ?? null )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Reject invalid evaluation root.
	 *
	 * @param string $public_id Public id.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_evaluation_root( string $public_id ): void {
		$row = $this->row( $this->prepare( 'SELECT public_id FROM ' . self::TABLE_EVALUATIONS . ' WHERE public_id = %s LIMIT 1 FOR UPDATE', array( $public_id ) ) );
		if ( null === $row || ! hash_equals( $public_id, (string) ( $row['public_id'] ?? '' ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Reject invalid target relation.
	 *
	 * @param string $source_type Source type.
	 * @param string $target_id Target id.
	 * @param mixed  $target_hash Target hash.
	 * @param string $evaluation_id Evaluation id.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_target_relation( string $source_type, string $target_id, mixed $target_hash, string $evaluation_id ): void {
		new Public_Id( $target_id );
		$table = match ( $source_type ) {
			'assessment_runs' => self::TABLE_RUNS,
			'assessment_results' => self::TABLE_RESULTS,
			'regrade_reviews' => self::TABLE_REVIEWS,
			'feedback' => self::TABLE_FEEDBACK,
			'special_notes' => self::TABLE_NOTES,
			default => throw new InvalidArgumentException( 'Legacy relation target is unsupported.' ),
		};
		if ( 'assessment_runs' === $source_type ) {
			$row = $this->row( $this->prepare( 'SELECT public_id, evaluation_public_id FROM ' . $table . ' WHERE public_id = %s LIMIT 1 FOR UPDATE', array( $target_id ) ) );
			if ( null === $row || ! hash_equals( $evaluation_id, (string) ( $row['evaluation_public_id'] ?? '' ) ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
			}
			return;
		}
		if ( ! is_string( $target_hash ) || ! $this->is_hash( $target_hash ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		$row = $this->row( $this->prepare( 'SELECT public_id, content_hash FROM ' . $table . ' WHERE public_id = %s LIMIT 1 FOR UPDATE', array( $target_id ) ) );
		if ( null === $row || ! hash_equals( $target_hash, (string) ( $row['content_hash'] ?? '' ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Source map key.
	 *
	 * @param string $type Type.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function source_map_key( string $type ): string {
		return match ( $type ) {
			'assessment_runs' => 'assessment_run_source_hashes',
			'grading_inputs' => 'grading_input_source_hashes',
			'rubric_snapshots' => 'rubric_snapshot_source_hashes',
			'grading_items' => 'grading_item_source_hashes',
			'assessment_results' => 'assessment_result_source_hashes',
			'regrade_reviews' => 'regrade_review_source_hashes',
			'feedback' => 'feedback_source_hashes',
			'feedback_approvals' => 'feedback_approval_source_hashes',
			'special_notes' => 'special_note_source_hashes',
			default => throw new InvalidArgumentException( 'Legacy evaluation relation is unsupported.' ),
		};
	}

	/**
	 * Public map key.
	 *
	 * @param string $type Type.
	 * @return string|null
	 */
	private function public_map_key( string $type ): ?string {
		return match ( $type ) {
			'assessment_runs' => 'assessment_run_public_ids',
			'assessment_results' => 'assessment_result_public_ids',
			'regrade_reviews' => 'regrade_review_public_ids',
			'feedback' => 'feedback_public_ids',
			'special_notes' => 'special_note_public_ids',
			default => null,
		};
	}

	/**
	 * Target hash map key.
	 *
	 * @param string $type Type.
	 * @return string|null
	 */
	private function target_hash_map_key( string $type ): ?string {
		return match ( $type ) {
			'assessment_results' => 'assessment_result_target_hashes',
			'regrade_reviews' => 'regrade_review_target_hashes',
			'feedback' => 'feedback_target_hashes',
			'special_notes' => 'special_note_target_hashes',
			default => null,
		};
	}

	/**
	 * Target type.
	 *
	 * @param string $type Type.
	 * @return string
	 */
	private function target_type( string $type ): string {
		return match ( $type ) {
			'assessment_runs' => 'submission_evaluation_run',
			'assessment_results' => 'submission_evaluation_result',
			'regrade_reviews' => 'submission_evaluation_review',
			'feedback' => 'submission_feedback_revision',
			'special_notes' => 'student_note_revision',
			default => 'submission_evaluation_relation',
		};
	}

	/**
	 * Run plan.
	 *
	 * @param int $run_database_id Run database id.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function run_plan( int $run_database_id ): array {
		$row = $this->row(
			$this->prepare(
				'SELECT state, source_kind, source_version, source_fingerprint, preview_schema, preview_version, preview_json, preview_hash FROM '
					. self::TABLE_IMPORT_RUNS . ' WHERE id = %d LIMIT 1 FOR UPDATE',
				array( $run_database_id )
			)
		);
		if (
			null === $row || 'running' !== ( $row['state'] ?? null )
			|| Approve_Legacy_Migration::PREVIEW_SCHEMA !== ( $row['preview_schema'] ?? null )
			|| Approve_Legacy_Migration::PREVIEW_DATABASE_VERSION !== $this->positive_integer( $row['preview_version'] ?? null )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		$preview = $this->decode_stored( (string) ( $row['preview_json'] ?? '' ), (string) ( $row['preview_hash'] ?? '' ) );
		$this->exact_keys( $preview, array( 'plan', 'profile', 'state' ) );
		$plan = $preview['plan'] ?? null;
		if ( Approve_Legacy_Migration::PREVIEW_PROFILE !== ( $preview['profile'] ?? null ) || 'approved' !== ( $preview['state'] ?? null ) || ! is_array( $plan ) || array_is_list( $plan ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		try {
			( new Legacy_Migration_Plan( $this->canonical ) )->assert_executable( $plan ); } catch ( InvalidArgumentException $error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE, $error );
			}
			$source  = $plan['source'] ?? null;
			$profile = is_array( $source ) ? ( $source['profile'] ?? null ) : null;
			$kind    = is_string( $profile ) ? strstr( $profile, '@', true ) : false;
			if (
			Legacy_Migration_Plan::PROFILE !== ( $plan['profile'] ?? null ) || Legacy_Source_Profile_0_1_2::PROFILE !== $profile
			|| ! is_string( $kind ) || ! hash_equals( $kind, (string) ( $row['source_kind'] ?? '' ) )
			|| ! hash_equals( (string) ( $source['version'] ?? '' ), (string) ( $row['source_version'] ?? '' ) )
			|| ! hash_equals( (string) ( $source['fingerprint'] ?? '' ), (string) ( $row['source_fingerprint'] ?? '' ) )
			) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
			}
			return $plan;
	}

	/**
	 * Source tables.
	 *
	 * @param array<string,mixed> $plan Plan.
	 * @return array<string,string>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function source_tables( array $plan ): array {
		$tables = $plan['inventory']['tables'] ?? null;
		if ( ! is_array( $tables ) || ! array_is_list( $tables ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		$required = array_merge( self::SOURCE_TYPES, array( 'worksheet_assignments', 'worksheet_submissions', 'worksheet_questions' ) );
		$result   = array();
		$profiles = $this->profile->tables();
		foreach ( $tables as $table ) {
			$logical = is_array( $table ) ? ( $table['logical_table'] ?? null ) : null;
			if ( ! is_string( $logical ) || ! in_array( $logical, $required, true ) ) {
				continue;
			}
			$physical = $table['source_table'] ?? null;
			$suffix   = $profiles[ $logical ]['suffix'] ?? null;
			if (
				isset( $result[ $logical ] ) || 'migrate' !== ( $table['disposition'] ?? null )
				|| ! is_string( $physical ) || 1 !== preg_match( '/^[A-Za-z0-9_]{4,191}$/D', $physical )
				|| ! is_string( $suffix ) || $physical === $suffix || ! str_ends_with( $physical, $suffix )
			) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
			}
			$result[ $logical ] = $physical;
		}
		$expected = $required;
		sort( $expected, SORT_STRING );
		$actual = array_keys( $result );
		sort( $actual, SORT_STRING );
		if ( $expected !== $actual ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		return $result;
	}

	/**
	 * Import receipt.
	 *
	 * @param int    $run_id Run id.
	 * @param string $source_type Source type.
	 * @param int    $source_id Source id.
	 * @param string $target_type Target type.
	 * @return array{result:Legacy_Target_Write_Result,source_checksum:string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function import_receipt( int $run_id, string $source_type, int $source_id, string $target_type ): array {
		$row = $this->row(
			$this->prepare(
				'SELECT state, target_type, target_public_id, payload_schema, payload_version, payload_json, payload_hash FROM '
					. self::TABLE_IMPORT_ITEMS . ' WHERE import_run_id = %d AND source_type = %s AND source_key = %s LIMIT 1 FOR UPDATE',
				array( $run_id, $source_type, (string) $source_id )
			)
		);
		if (
			null === $row || 'success' !== ( $row['state'] ?? null ) || $target_type !== ( $row['target_type'] ?? null )
			|| self::ITEM_SCHEMA !== ( $row['payload_schema'] ?? null ) || self::PROFILE_VERSION !== $this->positive_integer( $row['payload_version'] ?? null )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::DEPENDENCY_MISSING );
		}
		$payload = $this->decode_stored( (string) $row['payload_json'], (string) $row['payload_hash'] );
		$source  = $payload['source'] ?? null;
		$target  = $payload['target'] ?? null;
		if (
			self::ITEM_PROFILE !== ( $payload['profile'] ?? null ) || 'success' !== ( $payload['state'] ?? null ) || null !== ( $payload['error_code'] ?? null )
			|| ! is_array( $source ) || ! is_array( $target ) || $source_type !== ( $source['type'] ?? null ) || (string) $source_id !== ( $source['key'] ?? null )
			|| $target_type !== ( $target['type'] ?? null ) || ! is_array( $target['metadata'] ?? null )
			|| ! hash_equals( (string) ( $target['public_id'] ?? '' ), (string) ( $row['target_public_id'] ?? '' ) )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		$result   = new Legacy_Target_Write_Result( $target_type, (string) $target['public_id'], (string) $target['content_hash'], $target['metadata'] );
		$checksum = $source['checksum'] ?? null;
		if ( ! is_string( $checksum ) || ! $this->is_hash( $checksum ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		return array(
			'result'          => $result,
			'source_checksum' => $checksum,
		);
	}

	/**
	 * Source row by id.
	 *
	 * @param string $table Table.
	 * @param string $logical Logical.
	 * @param int    $source_id Source id.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function source_row_by_id( string $table, string $logical, int $source_id ): array {
		$columns = $this->profile->columns( $logical );
		$select  = implode( ', ', array_map( static fn ( string $column ): string => '`' . $column . '`', $columns ) );
		$row     = $this->row( $this->prepare( 'SELECT ' . $select . ' FROM `' . $table . '` WHERE `id` = %d LIMIT 1 FOR UPDATE', array( $source_id ) ) );
		if ( null === $row ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::DEPENDENCY_MISSING );
		}
		$this->assert_exact_source_row( $logical, $row );
		return $row;
	}

	/**
	 * Source relations.
	 *
	 * @param string $table Table.
	 * @param string $logical Logical.
	 * @param string $foreign_key Foreign key.
	 * @param int    $source_id Source id.
	 * @param string $order Order.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function source_relations( string $table, string $logical, string $foreign_key, int $source_id, string $order ): array {
		if ( ! in_array( $foreign_key, $this->profile->columns( $logical ), true ) || ! in_array( $order, array( '`id` ASC', 'revision ASC, `id` ASC' ), true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		$columns = $this->profile->columns( $logical );
		$select  = implode( ', ', array_map( static fn ( string $column ): string => '`' . $column . '`', $columns ) );
		$rows    = $this->rows( $this->prepare( 'SELECT ' . $select . ' FROM `' . $table . '` WHERE `' . $foreign_key . '` = %d AND deleted_at IS NULL ORDER BY ' . $order . ' LIMIT 251 FOR UPDATE', array( $source_id ) ) );
		if ( count( $rows ) > self::MAXIMUM_RELATIONS ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::SOURCE_INVALID );
		}
		foreach ( $rows as $row ) {
			$this->assert_exact_source_row( $logical, $row );
		}
		return $rows;
	}

	/**
	 * Reject invalid source checksum.
	 *
	 * @param Legacy_Source_Item $item Item.
	 * @param array              $plan Plan.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_source_checksum( Legacy_Source_Item $item, array $plan ): void {
		if ( ! hash_equals( $item->source_checksum(), $this->source_checksum( $plan, $item->source_type(), $item->source_id(), $item->record() ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::SOURCE_INVALID );
		}
	}

	/**
	 * Reject invalid exact source row.
	 *
	 * @param string              $logical Logical.
	 * @param array<string,mixed> $row Row.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_exact_source_row( string $logical, array $row ): void {
		if ( array_keys( $row ) !== $this->profile->columns( $logical ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::SOURCE_INVALID );
		}
	}

	/**
	 * Source checksum.
	 *
	 * @param array<string,mixed> $plan Plan.
	 * @param string              $type Type.
	 * @param int                 $source_id Source id.
	 * @param array<string,mixed> $record Record.
	 * @return string
	 */
	private function source_checksum( array $plan, string $type, int $source_id, array $record ): string {
		return hash(
			'sha256',
			$this->canonical->encode(
				array(
					'profile'     => $plan['source']['profile'],
					'source_key'  => (string) $source_id,
					'source_type' => $type,
					'record'      => $record,
				)
			)
		);
	}

	/**
	 * Evaluation reference.
	 *
	 * @param array $plan Plan.
	 * @param int   $submission_id Submission public identifier.
	 * @param int   $run_id Run id.
	 * @return string
	 */
	private function evaluation_reference( array $plan, int $submission_id, int $run_id ): string {
		$hex = substr( hash( 'sha256', $plan['source']['profile'] . ':' . $submission_id . ':' . $run_id ), 0, 32 );
		return 'EV_' . strtr( strtoupper( $hex ), '0123456789', 'ABCDEFGHJK' );
	}

	/**
	 * Provider digest.
	 *
	 * @param mixed  $raw Raw.
	 * @param string $fallback Fallback.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function provider_digest( mixed $raw, string $fallback ): string {
		if ( null === $raw || ( is_string( $raw ) && '' === trim( $raw ) ) ) {
			return $fallback;
		}
		if ( ! is_string( $raw ) || strlen( $raw ) > self::MAXIMUM_JSON_BYTES ) {
			throw new InvalidArgumentException( 'Legacy provider response is invalid.' );
		}
		return hash( 'sha256', $raw );
	}

	/**
	 * Optional target text.
	 *
	 * @param mixed $value Value.
	 * @param int   $maximum Maximum.
	 * @return string|null
	 */
	private function optional_target_text( mixed $value, int $maximum ): ?string {
		if ( null === $value || ( is_string( $value ) && '' === trim( $value ) ) ) {
			return null;
		}
		return $this->target_text( $value, $maximum );
	}

	/**
	 * Target text.
	 *
	 * @param mixed $value Value.
	 * @param int   $maximum Maximum.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function target_text( mixed $value, int $maximum ): string {
		if ( ! is_string( $value ) || '' === trim( $value ) || strlen( $value ) > $maximum || 1 === preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value ) ) {
			throw new InvalidArgumentException( 'Target migration text is invalid.' );
		}
		return trim( $value );
	}

	/**
	 * Target token.
	 *
	 * @param mixed $value Value.
	 * @param int   $maximum Maximum.
	 * @param bool  $slash Slash.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function target_token( mixed $value, int $maximum, bool $slash = false ): string {
		$pattern = $slash ? '/^[A-Za-z0-9][A-Za-z0-9_.:@\/-]*$/D' : '/^[A-Za-z0-9][A-Za-z0-9_.:@-]*$/D';
		if ( ! is_string( $value ) || strlen( $value ) > $maximum || 1 !== preg_match( $pattern, $value ) ) {
			throw new InvalidArgumentException( 'Target migration token is invalid.' );
		}
		return $value;
	}

	/**
	 * Target context type.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function target_context_type( mixed $value ): string {
		if ( ! is_string( $value ) || ! in_array( $value, array( 'class', 'group' ), true ) ) {
			throw new InvalidArgumentException( 'Target Context type is invalid.' );
		}
		return $value;
	}

	/**
	 * Utc value.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function utc_value( mixed $value ): string {
		if ( ! is_string( $value ) ) {
			throw new InvalidArgumentException( 'Legacy UTC value is invalid.' );
		}
		$this->utc( $value );
		return $value;
	}

	/**
	 * Utc.
	 *
	 * @param string $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function utc( string $value ): void {
		$date   = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new DateTimeZone( 'UTC' ) );
		$errors = DateTimeImmutable::getLastErrors();
		if ( false === $date || ( false !== $errors && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) || $date->format( 'Y-m-d H:i:s' ) !== $value ) {
			throw new InvalidArgumentException( 'Legacy UTC value is invalid.' );
		}
	}

	/**
	 * Positive integer.
	 *
	 * @param mixed $value Value.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function positive_integer( mixed $value ): int {
		if ( is_string( $value ) && 1 === preg_match( '/^[1-9][0-9]*$/D', $value ) ) {
			$value = (int) $value;
		}
		if ( ! is_int( $value ) || $value < 1 ) {
			throw new InvalidArgumentException( 'Legacy positive integer is invalid.' );
		}
		return $value;
	}

	/**
	 * Optional positive integer.
	 *
	 * @param mixed $value Value.
	 * @return int|null
	 */
	private function optional_positive_integer( mixed $value ): ?int {
		if ( null === $value || 0 === $value || '0' === $value ) {
			return null;
		}
		return $this->positive_integer( $value );
	}

	/**
	 * Non negative integer.
	 *
	 * @param mixed $value Value.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function non_negative_integer( mixed $value ): int {
		if ( is_string( $value ) && 1 === preg_match( '/^(?:0|[1-9][0-9]*)$/D', $value ) ) {
			$value = (int) $value;
		}
		if ( ! is_int( $value ) || $value < 0 ) {
			throw new InvalidArgumentException( 'Legacy non-negative integer is invalid.' );
		}
		return $value;
	}

	/**
	 * Is hash.
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	private function is_hash( string $value ): bool {
		return 1 === preg_match( '/^[a-f0-9]{64}$/D', $value );
	}

	/**
	 * Decode stored.
	 *
	 * @param string $json Json.
	 * @param string $hash Hash.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function decode_stored( string $json, string $hash ): array {
		if ( ! $this->is_hash( $hash ) || ! hash_equals( $hash, hash( 'sha256', $json ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		$value = json_decode( $json, true, 128, JSON_THROW_ON_ERROR );
		if ( ! is_array( $value ) || array_is_list( $value ) || ! hash_equals( $json, $this->canonical->encode( $value ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		return $value;
	}

	/**
	 * Exact keys.
	 *
	 * @param array<string,mixed> $value Value.
	 * @param array<int,string>   $expected Expected.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function exact_keys( array $value, array $expected ): void {
		$actual = array_keys( $value );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Prepare.
	 *
	 * @param string           $sql Sql.
	 * @param array<int,mixed> $values Values.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function prepare( string $sql, array $values ): string {
		$prepared = $this->database()->prepare( $sql, ...$values );
		if ( ! is_string( $prepared ) || '' === $prepared ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		return $prepared;
	}

	/**
	 * Row.
	 *
	 * @param string $sql Sql.
	 * @return array<string,mixed>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function row( string $sql ): ?array {
		$value = $this->database()->get_row( $sql, ARRAY_A );
		if ( '' !== (string) ( $this->database()->last_error ?? '' ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		if ( null === $value ) {
			return null;
		}
		if ( ! is_array( $value ) || array_is_list( $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		return $value;
	}

	/**
	 * Rows.
	 *
	 * @param string $sql Sql.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function rows( string $sql ): array {
		$value = $this->database()->get_results( $sql, ARRAY_A );
		if ( '' !== (string) ( $this->database()->last_error ?? '' ) || ! is_array( $value ) || ! array_is_list( $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		foreach ( $value as $row ) {
			if ( ! is_array( $row ) || array_is_list( $row ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
			}
		}
		return $value;
	}

	/**
	 * Query.
	 *
	 * @param string $sql Sql.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function query( string $sql ): int {
		$result = $this->database()->query( $sql );
		if ( false === $result || '' !== (string) ( $this->database()->last_error ?? '' ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		return (int) $result;
	}

	/**
	 * Insert or fail.
	 *
	 * @param string              $table Table.
	 * @param array<string,mixed> $data Data.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function insert_or_fail( string $table, array $data ): void {
		$result = $this->database()->insert( $table, $data );
		if ( false === $result || 1 !== (int) $result || '' !== (string) ( $this->database()->last_error ?? '' ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Insert id.
	 *
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function insert_id(): int {
		$id = (int) ( $this->database()->insert_id ?? 0 );
		if ( $id < 1 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		return $id;
	}

	/**
	 * Ids.
	 *
	 * @return Public_Id_Generator
	 */
	private function ids(): Public_Id_Generator {
		return $this->ids ??= new Public_Id_Generator();
	}

	/**
	 * Database.
	 *
	 * @return object
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function database(): object {
		$database = $this->database ?? ( $GLOBALS['wpdb'] ?? null );
		if ( ! is_object( $database ) || ! method_exists( $database, 'prepare' ) || ! method_exists( $database, 'get_row' ) || ! method_exists( $database, 'get_results' ) || ! method_exists( $database, 'query' ) || ! method_exists( $database, 'insert' ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		return $database;
	}
}
