<?php
/**
 * Safe Q16 and Q17 student submission evaluation projections.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Domain;

use InvalidArgumentException;
use JsonException;
use OJAI\Dashboard\Assessment\Configuration\Domain\Assessment_Configuration_Profile;
use OJAI\Dashboard\Submission\Assessment\Application\Submission_Evaluation_Failure;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Safe Q16 and Q17 student submission evaluation projections.
 */
final class Submission_Evaluation_Query_Projector {
	private const EVALUATION_STATES = array(
		'requested',
		'queued',
		'running',
		'review_required',
		'reviewed',
		'failed',
		'cancelled',
	);
	private const RUN_STATES        = array(
		'queued',
		'leased',
		'running',
		'succeeded',
		'retry_wait',
		'failed',
		'cancelled',
	);
	private const RUN_OPERATIONS    = array( 'score', 'feedback_draft', 'special_note_draft', 'manual_prepare' );
	private const REVIEW_DECISIONS  = array( 'accepted', 'adjusted', 'rejected', 'withdrawn' );
	private const FEEDBACK_STATES   = array( 'ai_draft', 'teacher_draft', 'rejected', 'approved', 'stale' );
	private const RELEASE_STATES    = array( 'released', 'corrected', 'withdrawn', 'stale' );
	private const MAXIMUM_HISTORY   = 500;

	private const FORBIDDEN_PROJECTION_KEYS = array(
		'raw_response',
		'raw_prompt',
		'chain_of_thought',
		'hidden_reasoning',
		'system_prompt',
		'api_key',
		'secret',
		'authorization',
		'cookie',
		'endpoint',
		'provider_key',
		'model_key',
		'profile_fingerprint',
		'routing_revision',
		'gateway_correlation_id',
		'enqueue_correlation_id',
		'created_by_user_id',
		'updated_by_user_id',
	);

	private const FORBIDDEN_STUDENT_KEYS = array(
		'candidate_score',
		'candidate_points',
		'confidence',
		'overall_confidence',
		'abstained',
		'abstain_reason',
		'safety_flagged',
		'safety_flags',
		'rubric',
		'correct_answer',
		'example_answer',
		'answer_key',
		'teacher_note',
		'teacher_memo',
		'adjustment_reason',
	);

	/**
	 * Initialize submission evaluation query projector dependencies and state.
	 *
	 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
	 */
	public function __construct( private Canonical_Json $canonical ) {
	}

	/**
	 * List item.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function list_item( array $row ): array {
		try {
			$evaluation_id = $this->public_id( $row['evaluation_id'] ?? null );
			$state         = $this->one_of( $row['evaluation_state'] ?? null, self::EVALUATION_STATES );
			$this->non_negative_integer( $row['evaluation_lock_version'] ?? null );
			$this->one_of( $row['source_revision_state'] ?? null, array( 'saved', 'reopened_fork' ) );
			$source_id   = $this->public_id( $row['submission_revision_id'] ?? null );
			$source_hash = $this->sha256( $row['submission_revision_hash'] ?? null );
			if ( ! hash_equals( $source_hash, $this->sha256( $row['source_revision_content_hash'] ?? null ) ) ) {
				throw new InvalidArgumentException( 'Submission evaluation source hash drifted.' );
			}

			$assignment_id    = $this->public_id( $row['assignment_id'] ?? null );
			$assignment_state = $this->one_of(
				$row['assignment_state'] ?? null,
				array( 'draft', 'scheduled', 'open', 'closed', 'withdrawn' )
			);
			$context          = array(
				'type'     => $this->one_of( $row['context_type'] ?? null, array( 'class', 'group' ) ),
				'id'       => $this->token( $row['context_id'] ?? null, 191, true ),
				'revision' => $this->token( $row['context_revision'] ?? null, 191, true ),
			);
			if (
				'withdrawn' === $assignment_state
				|| 'active' !== $this->one_of( $row['target_state'] ?? null, array( 'active', 'revoked' ) )
			) {
				throw new InvalidArgumentException( 'Submission evaluation access snapshot is inactive.' );
			}

			$publication_id   = $this->public_id( $row['worksheet_publication_id'] ?? null );
			$publication_hash = $this->sha256( $row['publication_hash'] ?? null );
			if (
				! hash_equals( $publication_id, $this->public_id( $row['assignment_publication_id'] ?? null ) )
				|| ! hash_equals( $publication_hash, $this->sha256( $row['assignment_publication_hash'] ?? null ) )
			) {
				throw new InvalidArgumentException( 'Submission evaluation publication drifted.' );
			}

			$learner              = $this->learner( $row );
			$submission_id        = $this->public_id( $row['submission_id'] ?? null );
			$submission_state     = $this->one_of(
				$row['submission_state'] ?? null,
				array( 'draft', 'conflict', 'submitted', 'auto_submitted', 'proxy_submitted', 'reopened', 'locked' )
			);
			$official_revision_id = $this->nullable_public_id( $row['official_revision_id'] ?? null );
			$is_source_current    = null !== $official_revision_id && hash_equals( $source_id, $official_revision_id );

			$latest_run = $this->latest_run( $row );
			$candidate  = $this->candidate( $row );
			$review     = $this->review_summary( $row );
			$feedback   = $this->feedback_summary( $row );
			$release    = $this->release_summary( $row );
			$priority   = $this->priority( $row, $candidate );

			return array(
				'id'               => $evaluation_id,
				'state'            => $state,
				'assignment'       => array(
					'id'         => $assignment_id,
					'title'      => $this->text( $row['assignment_title'] ?? null, 255, false ),
					'state'      => $assignment_state,
					'due_at_utc' => $this->nullable_datetime( $row['assignment_due_at_utc'] ?? null ),
				),
				'context'          => $context,
				'learner'          => $learner,
				'submission'       => array(
					'id'                 => $submission_id,
					'state'              => $submission_state,
					'source_revision_id' => $source_id,
					'is_source_current'  => $is_source_current,
					'submitted_at_utc'   => $this->nullable_datetime( $row['submission_submitted_at_utc'] ?? null ),
				),
				'candidate'        => $candidate,
				'review'           => $review,
				'feedback'         => $feedback,
				'release'          => $release,
				'priority'         => $priority,
				'latest_run'       => $latest_run,
				'requested_at_utc' => $this->datetime( $row['requested_at_utc'] ?? null ),
				'updated_at_utc'   => $this->datetime( $row['evaluation_updated_at_utc'] ?? null ),
			);
		} catch ( Submission_Evaluation_Failure $failure ) {
			throw $failure;
		} catch ( Throwable ) {
			throw $this->stored_data_invalid();
		}
	}

	/**
	 * Detail.
	 *
	 * @param array<string,mixed>            $root Root.
	 * @param array<string,mixed>            $input Input.
	 * @param array<string,mixed>|null       $result Result.
	 * @param array<int,array<string,mixed>> $items Items.
	 * @param array<int,array<string,mixed>> $runs Runs.
	 * @param array<int,array<string,mixed>> $reviews Reviews.
	 * @param array<int,array<string,mixed>> $feedback Feedback.
	 * @param array<int,array<string,mixed>> $releases Releases.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function detail(
		array $root,
		array $input,
		?array $result,
		array $items,
		array $runs,
		array $reviews,
		array $feedback,
		array $releases
	): array {
		$stage = 'evaluation';
		try {
			$evaluation        = $this->list_item( $root );
			$stage             = 'input';
			$input_projection  = $this->input( $root, $input );
			$result_projection = null;
			$stage             = 'result';
			if ( null !== $result ) {
				$result_projection = $this->result( $evaluation, $input_projection, $result, $items );
			} elseif ( array() !== $items ) {
				throw new InvalidArgumentException( 'Submission evaluation items exist without a result.' );
			}

			$stage               = 'runs';
			$run_history         = $this->runs( $runs );
			$stage               = 'reviews';
			$review_history      = $this->reviews( $root, $reviews, $run_history );
			$stage               = 'feedback';
			$feedback_history    = $this->feedback( $feedback, $review_history );
			$stage               = 'releases';
			$release_history     = $this->releases( $root, $releases, $review_history, $feedback_history );
			$stage               = 'review_precondition';
			$review_precondition = $this->review_precondition( $root, $result, $review_history );
			$stage               = 'links';
			$this->assert_links( $evaluation, $result_projection, $run_history );

			return array(
				'evaluation'          => $evaluation,
				'source'              => $input_projection['source'],
				'configuration'       => $input_projection['configuration'],
				'selected_result'     => $result_projection,
				'runs'                => $run_history,
				'reviews'             => $review_history,
				'feedback'            => $feedback_history,
				'releases'            => $release_history,
				'review_precondition' => $review_precondition,
			);
		} catch ( Submission_Evaluation_Failure $failure ) {
			throw $failure;
		} catch ( Throwable ) {
			throw Submission_Evaluation_Failure::because(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				Submission_Evaluation_Failure::STORED_DATA_INVALID,
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				array( 'projection_stage' => $stage )
			);
		}
	}

	/**
	 * Learner.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function learner( array $row ): array {
		foreach ( array( 'target_content_hash', 'display_snapshot_hash', 'target_snapshot_hash' ) as $key ) {
			$this->sha256( $row[ $key ] ?? null );
		}
		if (
			'oj_ai.assignment_target_display' !== ( $row['display_snapshot_schema'] ?? null )
			|| 1 !== $this->positive_integer( $row['display_snapshot_version'] ?? null )
		) {
			throw new InvalidArgumentException( 'Assignment target display schema drifted.' );
		}
		$display = $this->decode_canonical(
			$row['display_snapshot_json'] ?? null,
			$this->sha256( $row['display_snapshot_hash'] ?? null )
		);
		$this->exact_keys( $display, array( 'display_name' ) );

		return array(
			'user_id'             => $this->positive_integer( $row['learner_user_id'] ?? null ),
			'display_name'        => $this->text( $display['display_name'] ?? null, 255, false ),
			'membership_revision' => $this->token( $row['membership_revision'] ?? null, 191, true ),
		);
	}

	/**
	 * Latest run.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 */
	private function latest_run( array $row ): array {
		return array(
			'id'             => $this->public_id( $row['latest_run_id'] ?? null ),
			'run_no'         => $this->positive_integer( $row['latest_run_no'] ?? null ),
			'operation'      => $this->one_of( $row['latest_run_operation_key'] ?? 'score', self::RUN_OPERATIONS ),
			'state'          => $this->one_of( $row['latest_run_state'] ?? null, self::RUN_STATES ),
			'queued_at_utc'  => $this->datetime( $row['latest_run_queued_at_utc'] ?? null ),
			'updated_at_utc' => $this->datetime( $row['latest_run_updated_at_utc'] ?? null ),
		);
	}

	/**
	 * Candidate.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function candidate( array $row ): ?array {
		$id = $this->nullable_public_id( $row['latest_result_id'] ?? null );
		if ( null === $id ) {
			foreach ( array( 'latest_candidate_score', 'latest_maximum_score', 'latest_overall_confidence', 'latest_abstained', 'latest_safety_flagged', 'latest_result_summary' ) as $key ) {
				if ( null !== ( $row[ $key ] ?? null ) ) {
					throw new InvalidArgumentException( 'Partial submission evaluation candidate projection.' );
				}
			}
			return null;
		}

		$this->sha256( $row['latest_result_content_hash'] ?? null );
		$score      = $this->nullable_decimal( $row['latest_candidate_score'] ?? null );
		$maximum    = $this->decimal( $row['latest_maximum_score'] ?? null );
		$confidence = $this->confidence( $row['latest_overall_confidence'] ?? null );
		$abstained  = $this->database_boolean( $row['latest_abstained'] ?? null );
		$safety     = $this->database_boolean( $row['latest_safety_flagged'] ?? null );
		if ( ( $abstained && null !== $score ) || ( null !== $score && (float) $score > (float) $maximum ) ) {
			throw new InvalidArgumentException( 'Submission evaluation candidate score is inconsistent.' );
		}

		return array(
			'id'             => $id,
			'score'          => $score,
			'maximum_score'  => $maximum,
			'confidence'     => $confidence,
			'abstained'      => $abstained,
			'safety_flagged' => $safety,
			'summary'        => $this->text( $row['latest_result_summary'] ?? null, 3000, true ),
			'created_at_utc' => $this->datetime( $row['latest_result_created_at_utc'] ?? null ),
		);
	}

	/**
	 * Review summary.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function review_summary( array $row ): array {
		$count           = $this->non_negative_integer( $row['review_count'] ?? null );
		$latest_id       = $this->nullable_public_id( $row['latest_review_id'] ?? null );
		$latest_no       = $this->nullable_positive_integer( $row['latest_review_no'] ?? null );
		$latest_decision = null === $latest_id ? null : $this->one_of( $row['latest_review_decision'] ?? null, self::REVIEW_DECISIONS );
		if ( ( 0 === $count ) !== ( null === $latest_id ) || ( null === $latest_id ) !== ( null === $latest_no ) ) {
			throw new InvalidArgumentException( 'Submission evaluation review summary is inconsistent.' );
		}
		if ( null !== $latest_no && $latest_no !== $count ) {
			throw new InvalidArgumentException( 'Submission evaluation latest review number drifted.' );
		}
		$official          = $this->pointer( $row['official_review_id'] ?? null, $row['official_review_hash'] ?? null );
		$official_local_no = $this->nullable_positive_integer( $row['official_local_review_no'] ?? null );
		if ( null === $official && null !== $official_local_no ) {
			throw new InvalidArgumentException( 'Submission evaluation official review summary drifted.' );
		}

		return array(
			'count'           => $count,
			'latest_id'       => $latest_id,
			'latest_no'       => $latest_no,
			'latest_decision' => $latest_decision,
			'official_id'     => null === $official ? null : $official['id'],
			'official_scope'  => null === $official ? 'none' : ( null === $official_local_no ? 'other_evaluation' : 'this_evaluation' ),
		);
	}

	/**
	 * Feedback summary.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function feedback_summary( array $row ): array {
		$count       = $this->non_negative_integer( $row['feedback_count'] ?? null );
		$id          = $this->nullable_public_id( $row['latest_feedback_id'] ?? null );
		$revision_no = $this->nullable_positive_integer( $row['latest_feedback_revision_no'] ?? null );
		$state       = null === $id ? null : $this->one_of( $row['latest_feedback_state'] ?? null, self::FEEDBACK_STATES );
		if ( ( 0 === $count ) !== ( null === $id ) || ( null === $id ) !== ( null === $revision_no ) || ( null !== $revision_no && $revision_no !== $count ) ) {
			throw new InvalidArgumentException( 'Submission feedback summary is inconsistent.' );
		}
		return array(
			'count'              => $count,
			'latest_id'          => $id,
			'latest_revision_no' => $revision_no,
			'state'              => $state,
		);
	}

	/**
	 * Release summary.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function release_summary( array $row ): array {
		$count      = $this->non_negative_integer( $row['release_count'] ?? null );
		$id         = $this->nullable_public_id( $row['latest_release_id'] ?? null );
		$release_no = $this->nullable_positive_integer( $row['latest_release_no'] ?? null );
		$state      = null === $id ? null : $this->one_of( $row['latest_release_state'] ?? null, self::RELEASE_STATES );
		if ( ( 0 === $count ) !== ( null === $id ) || ( null === $id ) !== ( null === $release_no ) || ( null !== $release_no && $release_no !== $count ) ) {
			throw new InvalidArgumentException( 'Submission release summary is inconsistent.' );
		}
		$current          = $this->pointer( $row['current_release_id'] ?? null, $row['current_release_hash'] ?? null );
		$current_local_no = $this->nullable_positive_integer( $row['current_local_release_no'] ?? null );
		if ( null === $current && null !== $current_local_no ) {
			throw new InvalidArgumentException( 'Submission current release summary drifted.' );
		}
		return array(
			'count'         => $count,
			'latest_id'     => $id,
			'latest_no'     => $release_no,
			'state'         => $state,
			'current_id'    => null === $current ? null : $current['id'],
			'current_hash'  => null === $current ? null : $current['hash'],
			'current_scope' => null === $current ? 'none' : ( null === $current_local_no ? 'other_evaluation' : 'this_evaluation' ),
		);
	}

	/**
	 * Priority.
	 *
	 * @param array<string,mixed>      $row Row.
	 * @param array<string,mixed>|null $candidate Candidate.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function priority( array $row, ?array $candidate ): array {
		$threshold = $this->confidence( $row['review_rule_confidence_threshold'] ?? null );
		if (
			! hash_equals( $this->public_id( $row['review_rule_revision_id'] ?? null ), $this->public_id( $row['joined_review_rule_id'] ?? null ) )
			|| ! hash_equals( $this->sha256( $row['review_rule_hash'] ?? null ), $this->sha256( $row['joined_review_rule_hash'] ?? null ) )
			|| ! in_array( $row['joined_review_rule_state'] ?? null, array( 'active', 'retired' ), true )
		) {
			throw new InvalidArgumentException( 'Submission evaluation review rule snapshot drifted.' );
		}
		$overdue = $this->database_boolean( $row['overdue_flag'] ?? null );
		$rank    = 0;
		$reasons = array();
		if ( null !== $candidate && $candidate['safety_flagged'] ) {
			$rank      = 5;
			$reasons[] = 'safety_review';
		} elseif ( null !== $candidate && $candidate['abstained'] ) {
			$rank      = 4;
			$reasons[] = 'abstained';
		} elseif ( $overdue ) {
			$rank      = 3;
			$reasons[] = 'overdue';
		} elseif ( null !== $candidate && (float) $candidate['confidence'] < (float) $threshold ) {
			$rank      = 2;
			$reasons[] = 'low_confidence';
		} elseif ( in_array( $row['evaluation_state'] ?? null, array( 'review_required', 'failed' ), true ) ) {
			$rank      = 1;
			$reasons[] = 'waiting_review';
		}
		if ( $rank !== $this->non_negative_integer( $row['priority_rank'] ?? null ) ) {
			throw new InvalidArgumentException( 'Submission evaluation priority rank drifted.' );
		}
		$item_count    = $this->non_negative_integer( $row['item_count'] ?? null );
		$review_needed = $this->non_negative_integer( $row['review_needed_count'] ?? null );
		if ( $review_needed > $item_count || ( null === $candidate && 0 !== $item_count ) ) {
			throw new InvalidArgumentException( 'Submission evaluation review-needed count drifted.' );
		}

		return array(
			'rank'                => $rank,
			'reasons'             => $reasons,
			'review_needed_count' => $review_needed,
			'item_count'          => $item_count,
		);
	}

	/**
	 * Input.
	 *
	 * @param array<string,mixed> $root Root.
	 * @param array<string,mixed> $row Row.
	 * @return array{source:array<string,mixed>,configuration:array<string,mixed>}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function input( array $root, array $row ): array {
		$input_hash = $this->sha256( $row['input_hash'] ?? null );
		$json       = $row['input_json'] ?? null;
		if (
			! is_string( $json )
			|| strlen( $json ) !== $this->positive_integer( $row['input_byte_size'] ?? null )
			|| ! hash_equals( $input_hash, hash( 'sha256', $json ) )
			|| Submission_Score_Input_Builder::OPERATION_CONTRACT !== ( $row['projection_schema_version'] ?? null )
			|| Submission_Score_Input_Builder::REQUEST_SCHEMA !== ( $row['input_schema'] ?? null )
			|| 1 !== $this->positive_integer( $row['input_version'] ?? null )
			|| Submission_Score_Input_Builder::REDACTION_VERSION !== ( $row['redaction_version'] ?? null )
		) {
			throw new InvalidArgumentException( 'Submission evaluation input snapshot drifted.' );
		}
		$this->sha256( $row['input_content_hash'] ?? null );
		$gateway = $this->decode_canonical( $json, $input_hash );
		$this->exact_keys( $gateway, array( 'operation', 'operation_contract', 'operation_payload', 'operation_schema', 'payload_hash' ) );
		$payload = $gateway['operation_payload'] ?? null;
		if ( ! is_array( $payload ) || array_is_list( $payload ) ) {
			throw new InvalidArgumentException( 'Submission evaluation operation payload is invalid.' );
		}
		$payload_keys = array( 'artifacts', 'bindings', 'instruction', 'operation', 'output_contract', 'policy', 'responses', 'rubric', 'schema_id', 'schema_version', 'subject' );
		if ( array_key_exists( 'evidence_catalog', $payload ) ) {
			$payload_keys[] = 'evidence_catalog';
			$catalog        = $this->list_value( $payload['evidence_catalog'], Submission_Score_Evidence_Catalog::MAXIMUM_ENTRIES );
			$expected       = Submission_Score_Evidence_Catalog::build(
				$this->list_value( $payload['responses'] ?? null, Submission_Score_Input_Builder::MAXIMUM_RESPONSES ),
				$this->list_value( $payload['artifacts'] ?? null, Submission_Score_Input_Builder::MAXIMUM_ARTIFACTS )
			);
			if ( $this->canonical->encode( $catalog ) !== $this->canonical->encode( $expected ) ) {
				throw new InvalidArgumentException( 'Submission evaluation evidence catalog drifted.' );
			}
		}
		// Older immutable inputs predate worksheet context. Keep both stored shapes readable.
		if ( array_key_exists( 'worksheet_context', $payload ) ) {
			$payload_keys[] = 'worksheet_context';
			$context        = $this->object( $payload['worksheet_context'] );
			$this->exact_keys( $context, array( 'title', 'blocks' ) );
			if ( ! is_string( $context['title'] ) || ! is_array( $context['blocks'] ) || ! array_is_list( $context['blocks'] ) ) {
				throw new InvalidArgumentException( 'Submission evaluation worksheet context is invalid.' );
			}
			$this->assert_no_forbidden_keys( $context, self::FORBIDDEN_PROJECTION_KEYS );
		}
		$this->exact_keys( $payload, $payload_keys );
		if (
			Submission_Score_Input_Builder::OPERATION !== ( $gateway['operation'] ?? null )
			|| Submission_Score_Input_Builder::OPERATION_CONTRACT !== ( $gateway['operation_contract'] ?? null )
			|| Submission_Score_Input_Builder::OPERATION_SCHEMA !== ( $gateway['operation_schema'] ?? null )
			|| Submission_Score_Input_Builder::OPERATION !== ( $payload['operation'] ?? null )
			|| Submission_Score_Input_Builder::REQUEST_SCHEMA !== ( $payload['schema_id'] ?? null )
			|| Submission_Score_Input_Builder::REQUEST_VERSION !== ( $payload['schema_version'] ?? null )
			|| ! hash_equals( $this->sha256( $gateway['payload_hash'] ?? null ), hash( 'sha256', $this->canonical->encode( $payload ) ) )
		) {
			throw new InvalidArgumentException( 'Submission evaluation operation contract drifted.' );
		}

		$output      = $this->object( $payload['output_contract'] ?? null );
		$output_keys = array( 'schema_id', 'schema_version' );
		if ( array_key_exists( 'allowed_binding_keys', $output ) ) {
			$output_keys[] = 'allowed_binding_keys';
			$binding_rows  = $this->list_value( $payload['bindings'] ?? null, Submission_Score_Input_Builder::MAXIMUM_BINDINGS );
			$refs          = array_column( $binding_rows, 'binding_ref' );
			if ( count( $refs ) !== count( $binding_rows ) || $refs !== $output['allowed_binding_keys'] || count( array_unique( $refs ) ) !== count( $refs ) ) {
				throw new InvalidArgumentException( 'Submission evaluation binding reference contract drifted.' );
			}
		}
		if ( array_key_exists( 'json_schema', $output ) ) {
			$output_keys[] = 'json_schema';
			$schema        = $this->object( $output['json_schema'] );
			$properties    = $this->object( $schema['properties'] ?? null );
			if ( 'object' !== ( $schema['type'] ?? null ) ) {
				throw new InvalidArgumentException( 'Submission evaluation output schema is invalid.' );
			}
			// Read immutable provider metadata without exposing it or rebuilding historical schemas.
			foreach ( array(
				'schema_id'      => Submission_Score_Input_Builder::OUTPUT_SCHEMA,
				'schema_version' => Submission_Score_Input_Builder::OUTPUT_VERSION,
				'evaluation_ref' => $payload['subject']['evaluation_ref'] ?? null,
				'source_hash'    => $payload['subject']['source_hash'] ?? null,
			) as $key => $value ) {
				$property = $this->object( $properties[ $key ] ?? null );
				if ( $value !== ( $property['const'] ?? null ) ) {
					throw new InvalidArgumentException( 'Submission evaluation output schema identity drifted.' );
				}
			}
		}
		$this->exact_keys( $output, $output_keys );
		if ( Submission_Score_Input_Builder::OUTPUT_SCHEMA !== ( $output['schema_id'] ?? null ) || Submission_Score_Input_Builder::OUTPUT_VERSION !== ( $output['schema_version'] ?? null ) ) {
			throw new InvalidArgumentException( 'Submission evaluation output contract drifted.' );
		}

		$subject = $this->object( $payload['subject'] ?? null );
		$this->exact_keys( $subject, array( 'evaluation_ref', 'grading_schema_hash', 'locale', 'publication_hash', 'representation_set_hash', 'source_hash' ) );
		if (
			1 !== preg_match( '/^EV_[A-Z2-7]{32}$/D', (string) ( $subject['evaluation_ref'] ?? '' ) )
			|| ! hash_equals( $this->sha256( $root['submission_revision_hash'] ?? null ), $this->sha256( $subject['source_hash'] ?? null ) )
			|| ! hash_equals( $this->sha256( $root['publication_hash'] ?? null ), $this->sha256( $subject['publication_hash'] ?? null ) )
		) {
			throw new InvalidArgumentException( 'Submission evaluation input subject drifted.' );
		}

		$rubric = $this->object( $payload['rubric'] ?? null );
		$this->exact_keys( $rubric, array( 'content_hash', 'criteria', 'revision_ref', 'total_points' ) );
		if (
			! hash_equals( $this->public_id( $root['rubric_revision_id'] ?? null ), $this->public_id( $rubric['revision_ref'] ?? null ) )
			|| ! hash_equals( $this->sha256( $root['rubric_hash'] ?? null ), $this->sha256( $rubric['content_hash'] ?? null ) )
		) {
			throw new InvalidArgumentException( 'Submission evaluation rubric snapshot drifted.' );
		}

		$responses = $this->list_value( $payload['responses'] ?? null, Submission_Score_Input_Builder::MAXIMUM_RESPONSES );
		$bindings  = $this->list_value( $payload['bindings'] ?? null, Submission_Score_Input_Builder::MAXIMUM_BINDINGS );
		$this->assert_no_forbidden_keys( $responses, self::FORBIDDEN_PROJECTION_KEYS );
		$this->assert_no_forbidden_keys( $bindings, self::FORBIDDEN_PROJECTION_KEYS );
		$this->assert_no_forbidden_keys( $rubric, self::FORBIDDEN_PROJECTION_KEYS );
		$this->authorization_envelope( $root );

		return array(
			'source'        => array(
				'evaluation_ref'          => $subject['evaluation_ref'],
				'submission_revision'     => array(
					'id'           => $this->public_id( $root['submission_revision_id'] ?? null ),
					'content_hash' => $this->sha256( $root['submission_revision_hash'] ?? null ),
					'revision_no'  => $this->positive_integer( $root['source_revision_no'] ?? null ),
					'is_official'  => $this->public_id( $root['submission_revision_id'] ?? null ) === $this->nullable_public_id( $root['official_revision_id'] ?? null ),
				),
				'worksheet_publication'   => array(
					'id'           => $this->public_id( $root['worksheet_publication_id'] ?? null ),
					'content_hash' => $this->sha256( $root['publication_hash'] ?? null ),
				),
				'locale'                  => $this->locale( $subject['locale'] ?? null ),
				'grading_schema_hash'     => $this->sha256( $subject['grading_schema_hash'] ?? null ),
				'representation_set_hash' => $this->sha256( $subject['representation_set_hash'] ?? null ),
				'responses'               => $responses,
				'bindings'                => $bindings,
				'rubric'                  => $rubric,
			),
			'configuration' => array(
				'policy'                  => array(
					'revision_id'  => $this->public_id( $root['policy_revision_id'] ?? null ),
					'content_hash' => $this->sha256( $root['policy_hash'] ?? null ),
				),
				'rubric'                  => array(
					'revision_id'  => $this->public_id( $root['rubric_revision_id'] ?? null ),
					'content_hash' => $this->sha256( $root['rubric_hash'] ?? null ),
				),
				'review_rule'             => array(
					'revision_id'  => $this->public_id( $root['review_rule_revision_id'] ?? null ),
					'content_hash' => $this->sha256( $root['review_rule_hash'] ?? null ),
				),
				'prompt_contract_version' => $this->token( $root['prompt_contract_version'] ?? null, 64 ),
				'input_schema_version'    => $this->token( $root['input_schema_version'] ?? null, 32 ),
				'output_schema_version'   => $this->token( $root['output_schema_version'] ?? null, 32 ),
			),
		);
	}

	/**
	 * Authorization envelope.
	 *
	 * @param array<string,mixed> $root Root.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function authorization_envelope( array $root ): void {
		$hash          = $this->sha256( $root['authorization_envelope_hash'] ?? null );
		$authorization = $this->decode_canonical( $root['authorization_envelope_json'] ?? null, $hash );
		$manual        = 'submission.evaluation.manual' === ( $authorization['action_key'] ?? null );
		$this->exact_keys( $authorization, array( 'schema_id', 'schema_version', 'actor_user_id', 'shell', 'surface', 'action_key', 'required_capabilities', 'context', 'object', 'checked_at_utc' ) );
		$context = $this->object( $authorization['context'] ?? null );
		$object  = $this->object( $authorization['object'] ?? null );
		$this->exact_keys( $context, array( 'type', 'id', 'revision', 'operation' ) );
		$this->exact_keys( $object, array( 'type', 'id', 'action', 'authorized_revision', 'authorized_state', 'actor_relation', 'official_revision_id', 'official_revision_hash' ) );
		if (
			'1.0.0' !== ( $root['authorization_envelope_version'] ?? null )
			|| 'oj_ai.authorization_envelope' !== ( $authorization['schema_id'] ?? null )
			|| '1.0.0' !== ( $authorization['schema_version'] ?? null )
			|| $this->positive_integer( $authorization['actor_user_id'] ?? null ) < 1
			|| 'console' !== ( $authorization['shell'] ?? null )
			|| 'rest' !== ( $authorization['surface'] ?? null )
			|| ( ! $manual && 'submission.evaluation.enqueue' !== ( $authorization['action_key'] ?? null ) )
			|| array( $manual ? 'oj_ai_dashboard_review_assessments' : 'oj_ai_dashboard_assess_submissions' ) !== ( $authorization['required_capabilities'] ?? null )
			|| ( $root['context_type'] ?? null ) !== ( $context['type'] ?? null )
			|| ( $root['context_id'] ?? null ) !== ( $context['id'] ?? null )
			|| ( $root['context_revision'] ?? null ) !== ( $context['revision'] ?? null )
			|| 'evaluate' !== ( $context['operation'] ?? null )
			|| 'submission' !== ( $object['type'] ?? null )
			|| ( $root['submission_id'] ?? null ) !== ( $object['id'] ?? null )
			|| 'evaluate' !== ( $object['action'] ?? null )
			|| 'context_staff' !== ( $object['actor_relation'] ?? null )
			|| ( $root['submission_revision_id'] ?? null ) !== ( $object['official_revision_id'] ?? null )
			|| ( $root['submission_revision_hash'] ?? null ) !== ( $object['official_revision_hash'] ?? null )
		) {
			throw new InvalidArgumentException( 'Submission evaluation authorization envelope drifted.' );
		}
		$this->token( $object['authorized_revision'] ?? null, 191 );
		$this->one_of( $object['authorized_state'] ?? null, array( 'draft', 'conflict', 'submitted', 'auto_submitted', 'proxy_submitted', 'reopened', 'locked' ) );
		$this->datetime( $authorization['checked_at_utc'] ?? null );
	}

	/**
	 * Result.
	 *
	 * @param array<string,mixed>            $evaluation Evaluation.
	 * @param array<string,mixed>            $input Input.
	 * @param array<string,mixed>            $row Row.
	 * @param array<int,array<string,mixed>> $item_rows Item rows.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function result( array $evaluation, array $input, array $row, array $item_rows ): array {
		$id              = $this->public_id( $row['result_id'] ?? null );
		$run_id          = $this->public_id( $row['result_run_id'] ?? null );
		$normalized_hash = $this->sha256( $row['normalized_result_hash'] ?? null );
		$provider_digest = $this->sha256( $row['provider_output_digest'] ?? null );
		$normalized      = $this->decode_canonical( $row['normalized_result_json'] ?? null, $normalized_hash );
		$this->exact_keys( $normalized, array( 'schema_id', 'schema_version', 'evaluation_ref', 'source_hash', 'provider_status', 'candidate_score', 'maximum_score', 'overall_confidence', 'abstained', 'safety_flagged', 'summary', 'items', 'limitations' ) );
		if (
			Submission_Evaluation_Output_Normalizer::RESULT_SCHEMA !== ( $row['normalized_result_schema'] ?? null )
			|| 1 !== $this->positive_integer( $row['normalized_result_version'] ?? null )
			|| Submission_Evaluation_Output_Normalizer::RESULT_SCHEMA !== ( $normalized['schema_id'] ?? null )
			|| Submission_Evaluation_Output_Normalizer::RESULT_VERSION !== ( $normalized['schema_version'] ?? null )
			|| Submission_Score_Input_Builder::OUTPUT_VERSION !== ( $row['result_output_schema_version'] ?? null )
			|| ! hash_equals( (string) $input['source']['evaluation_ref'], (string) ( $normalized['evaluation_ref'] ?? '' ) )
			|| ! hash_equals( (string) $input['source']['submission_revision']['content_hash'], $this->sha256( $normalized['source_hash'] ?? null ) )
		) {
			throw new InvalidArgumentException( 'Submission evaluation normalized result contract drifted.' );
		}

		$score      = $this->nullable_decimal( $normalized['candidate_score'] ?? null );
		$maximum    = $this->decimal( $normalized['maximum_score'] ?? null );
		$confidence = $this->confidence( $normalized['overall_confidence'] ?? null );
		$abstained  = $this->boolean( $normalized['abstained'] ?? null );
		$safety     = $this->boolean( $normalized['safety_flagged'] ?? null );
		$summary    = $this->text( $normalized['summary'] ?? null, 3000, true );
		if (
			$score !== $this->nullable_decimal( $row['result_candidate_score'] ?? null )
			|| $maximum !== $this->decimal( $row['result_maximum_score'] ?? null )
			|| $confidence !== $this->confidence( $row['result_overall_confidence'] ?? null )
			|| $abstained !== $this->database_boolean( $row['result_abstained'] ?? null )
			|| $safety !== $this->database_boolean( $row['result_safety_flagged'] ?? null )
			|| $summary !== ( $row['result_summary'] ?? null )
			|| ( $abstained && null !== $score )
		) {
			throw new InvalidArgumentException( 'Submission evaluation normalized result columns drifted.' );
		}

		$items        = $this->result_items( $normalized['items'] ?? null, $item_rows );
		$content_hash = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'evaluation_id'          => $evaluation['id'],
					'normalized_result_hash' => $normalized_hash,
					'provider_output_digest' => $provider_digest,
					'run_id'                 => $run_id,
				)
			)
		);
		if ( ! hash_equals( $content_hash, $this->sha256( $row['result_content_hash'] ?? null ) ) ) {
			throw new InvalidArgumentException( 'Submission evaluation result content hash drifted.' );
		}

		return array(
			'id'                 => $id,
			'run_id'             => $run_id,
			'provider_status'    => $this->one_of( $normalized['provider_status'] ?? null, array( 'complete', 'partial', 'abstained', 'not_requested' ) ),
			'candidate_score'    => $score,
			'maximum_score'      => $maximum,
			'overall_confidence' => $confidence,
			'abstained'          => $abstained,
			'safety_flagged'     => $safety,
			'summary'            => $summary,
			'items'              => $items,
			'limitations'        => $this->text_list( $normalized['limitations'] ?? null, 20, 500 ),
			'created_at_utc'     => $this->datetime( $row['result_created_at_utc'] ?? null ),
		);
	}

	/**
	 * Result items.
	 *
	 * @param mixed                          $normalized Normalized.
	 * @param array<int,array<string,mixed>> $rows Rows.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function result_items( $normalized, array $rows ): array {
		if ( ! is_array( $normalized ) || ! array_is_list( $normalized ) || count( $normalized ) !== count( $rows ) ) {
			throw new InvalidArgumentException( 'Submission evaluation result item count drifted.' );
		}
		$result = array();
		foreach ( $normalized as $position => $item ) {
			if ( ! is_array( $item ) || array_is_list( $item ) ) {
				throw new InvalidArgumentException( 'Submission evaluation result item is invalid.' );
			}
			$this->exact_keys( $item, array( 'binding_key', 'criterion_id', 'response_id', 'scope', 'decision', 'candidate_points', 'maximum_points', 'levels', 'confidence', 'rationale', 'abstain_reason', 'evidence', 'flags', 'position' ) );
			$row            = $rows[ $position ];
			$binding_key    = $this->sha256( $item['binding_key'] ?? null );
			$criterion_id   = $this->public_id( $item['criterion_id'] ?? null );
			$response_id    = $this->nullable_public_id( $item['response_id'] ?? null );
			$scope          = $this->one_of( $item['scope'] ?? null, array( 'response', 'submission' ) );
			$decision       = $this->one_of( $item['decision'] ?? null, array( 'score', 'abstain' ) );
			$points         = $this->nullable_decimal( $item['candidate_points'] ?? null );
			$maximum        = $this->decimal( $item['maximum_points'] ?? null );
			$levels         = $this->nullable_levels( $item['levels'] ?? null );
			$confidence     = $this->confidence( $item['confidence'] ?? null );
			$rationale      = $this->nullable_text( $item['rationale'] ?? null, 1500, true );
			$abstain_reason = $this->nullable_token( $item['abstain_reason'] ?? null, 64 );
			$evidence       = $this->evidence( $item['evidence'] ?? null );
			$flags          = $this->flags( $item['flags'] ?? null );
			$stored_flags   = array() === $flags ? null : implode( ',', $flags );
			if (
				$position !== $this->non_negative_integer( $item['position'] ?? null )
				|| $criterion_id !== $this->public_id( $row['item_criterion_key'] ?? null )
				|| $response_id !== $this->nullable_public_id( $row['item_question_key'] ?? null )
				|| $position !== $this->non_negative_integer( $row['item_position'] ?? null )
				|| $points !== $this->nullable_decimal( $row['item_candidate_points'] ?? null )
				|| $maximum !== $this->decimal( $row['item_maximum_points'] ?? null )
				|| $confidence !== $this->confidence( $row['item_confidence'] ?? null )
				|| $stored_flags !== ( $row['item_flags'] ?? null )
				|| ( 'score' === $decision && ( null === $points || null === $rationale || null !== $abstain_reason ) )
				|| ( 'abstain' === $decision && ( null !== $points || null !== $rationale || null === $abstain_reason ) )
				|| ( 'response' === $scope ) !== ( null !== $response_id )
			) {
				throw new InvalidArgumentException( 'Submission evaluation result item columns drifted.' );
			}

			$evidence_hash   = $this->sha256( $row['evidence_hash'] ?? null );
			$stored_evidence = $this->decode_canonical( $row['evidence_json'] ?? null, $evidence_hash );
			$this->exact_keys( $stored_evidence, array( 'schema_id', 'schema_version', 'binding_key', 'decision', 'rationale', 'abstain_reason', 'evidence' ) );
			$stored_evidence_items = $this->evidence( $stored_evidence['evidence'] ?? null );
			if (
				Submission_Evaluation_Output_Normalizer::ITEM_EVIDENCE_SCHEMA !== ( $row['evidence_schema'] ?? null )
				|| 1 !== $this->positive_integer( $row['evidence_version'] ?? null )
				|| Submission_Evaluation_Output_Normalizer::ITEM_EVIDENCE_SCHEMA !== ( $stored_evidence['schema_id'] ?? null )
				|| Submission_Evaluation_Output_Normalizer::RESULT_VERSION !== ( $stored_evidence['schema_version'] ?? null )
				|| $binding_key !== ( $stored_evidence['binding_key'] ?? null )
				|| $decision !== ( $stored_evidence['decision'] ?? null )
				|| $rationale !== ( $stored_evidence['rationale'] ?? null )
				|| $abstain_reason !== ( $stored_evidence['abstain_reason'] ?? null )
				|| $evidence !== $stored_evidence_items
			) {
				throw new InvalidArgumentException( 'Submission evaluation stored evidence drifted.' );
			}
			$item_hash = hash(
				'sha256',
				$this->canonical->encode(
					array(
						'candidate_points' => $points,
						'confidence'       => $confidence,
						'criterion_key'    => $criterion_id,
						'evidence_hash'    => $evidence_hash,
						'flags'            => $stored_flags,
						'maximum_points'   => $maximum,
						'position'         => $position,
						'question_key'     => $response_id,
					)
				)
			);
			if ( ! hash_equals( $item_hash, $this->sha256( $row['item_content_hash'] ?? null ) ) ) {
				throw new InvalidArgumentException( 'Submission evaluation item content hash drifted.' );
			}
			$result[] = array(
				'binding_key'      => $binding_key,
				'criterion_id'     => $criterion_id,
				'response_id'      => $response_id,
				'scope'            => $scope,
				'decision'         => $decision,
				'candidate_points' => $points,
				'maximum_points'   => $maximum,
				'levels'           => $levels,
				'confidence'       => $confidence,
				'rationale'        => $rationale,
				'abstain_reason'   => $abstain_reason,
				'evidence'         => $evidence,
				'flags'            => $flags,
				'position'         => $position,
			);
		}
		return $result;
	}

	/**
	 * Evidence.
	 *
	 * @param mixed $value Value.
	 * @return array<int,array<string,mixed>>
	 */
	private function evidence( $value ): array {
		$list   = $this->list_value( $value, Submission_Evaluation_Output_Normalizer::MAXIMUM_EVIDENCE_PER_ITEM );
		$result = array();
		foreach ( $list as $item ) {
			$item = $this->object( $item );
			$this->exact_keys( $item, array( 'source', 'anchor', 'quote' ) );
			$result[] = array(
				'source' => $this->one_of( $item['source'] ?? null, array( 'response', 'artifact' ) ),
				'anchor' => $this->token( $item['anchor'] ?? null, 191, true ),
				'quote'  => $this->nullable_text( $item['quote'] ?? null, 240, true ),
			);
		}
		return $result;
	}

	/**
	 * Flags.
	 *
	 * @param mixed $value Value.
	 * @return array<int,string>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function flags( $value ): array {
		$list   = $this->list_value( $value, count( Submission_Evaluation_Output_Normalizer::FLAGS ) );
		$result = array();
		foreach ( $list as $flag ) {
			$flag = $this->one_of( $flag, Submission_Evaluation_Output_Normalizer::FLAGS );
			if ( in_array( $flag, $result, true ) ) {
				throw new InvalidArgumentException( 'Submission evaluation item flag is duplicated.' );
			}
			$result[] = $flag;
		}
		sort( $result, SORT_STRING );
		return $result;
	}

	/**
	 * Return opaque C34 compare-and-swap values for the review screen.
	 *
	 * These values are transported to the command endpoint but are never
	 * rendered as product copy.
	 *
	 * @param array<string,mixed>            $root Root query row.
	 * @param array<string,mixed>|null       $result Selected result row.
	 * @param array<int,array<string,mixed>> $reviews Safe review history.
	 * @return array<string,mixed>|null
	 */
	private function review_precondition( array $root, ?array $result, array $reviews ): ?array {
		if ( null === $result ) {
			return null;
		}

		$official = $this->pointer( $root['official_review_id'] ?? null, $root['official_review_hash'] ?? null );

		return array(
			'expected_result_id'            => $this->public_id( $result['result_id'] ?? null ),
			'expected_result_hash'          => $this->sha256( $result['result_content_hash'] ?? null ),
			'expected_official_review_id'   => null === $official ? null : $official['id'],
			'expected_official_review_hash' => null === $official ? null : $official['hash'],
			'expected_review_no'            => count( $reviews ),
		);
	}

	/**
	 * Runs.
	 *
	 * @param array<int,array<string,mixed>> $rows Rows.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function runs( array $rows ): array {
		if ( array() === $rows || count( $rows ) > self::MAXIMUM_HISTORY ) {
			throw new InvalidArgumentException( 'Submission evaluation run history is invalid.' );
		}
		$result = array();
		foreach ( $rows as $index => $row ) {
			$run_no = $this->positive_integer( $row['run_no'] ?? null );
			if ( $run_no !== $index + 1 ) {
				throw new InvalidArgumentException( 'Submission evaluation run history is not contiguous.' );
			}
			$state     = $this->one_of( $row['run_state'] ?? null, self::RUN_STATES );
			$result_id = $this->nullable_public_id( $row['run_result_id'] ?? null );
			if ( ( 'succeeded' === $state ) !== ( null !== $result_id ) ) {
				throw new InvalidArgumentException( 'Submission evaluation run result pointer drifted.' );
			}
			$result[] = array(
				'id'                 => $this->public_id( $row['run_id'] ?? null ),
				'run_no'             => $run_no,
				'state'              => $state,
				'operation'          => $this->one_of( $row['run_operation_key'] ?? null, self::RUN_OPERATIONS ),
				'result_id'          => $result_id,
				'attempt_count'      => $this->non_negative_integer( $row['attempt_count'] ?? null ),
				'available_at_utc'   => $this->datetime( $row['run_available_at_utc'] ?? null ),
				'queued_at_utc'      => $this->datetime( $row['run_queued_at_utc'] ?? null ),
				'started_at_utc'     => $this->nullable_datetime( $row['run_started_at_utc'] ?? null ),
				'finished_at_utc'    => $this->nullable_datetime( $row['run_finished_at_utc'] ?? null ),
				'last_error_code'    => $this->nullable_token( $row['run_last_error_code'] ?? null, 100 ),
				'last_error_message' => 'worker_exception' === ( $row['run_last_error_code'] ?? null )
					? null
					: $this->nullable_text( $this->single_line( $row['run_last_error_message'] ?? null ), 500, false ),
			);
		}
		return array_reverse( $result );
	}

	/**
	 * Reviews.
	 *
	 * @param array<string,mixed>            $root Root.
	 * @param array<int,array<string,mixed>> $rows Rows.
	 * @param array<int,array<string,mixed>> $runs Runs.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function reviews( array $root, array $rows, array $runs ): array {
		if ( count( $rows ) > self::MAXIMUM_HISTORY ) {
			throw new InvalidArgumentException( 'Submission evaluation review history is too large.' );
		}
		$known_results  = array_values( array_filter( array_column( $runs, 'result_id' ) ) );
		$official       = $this->pointer( $root['official_review_id'] ?? null, $root['official_review_hash'] ?? null );
		$result         = array();
		$previous       = null;
		$official_count = 0;
		foreach ( $rows as $index => $row ) {
			$id           = $this->public_id( $row['review_id'] ?? null );
			$content_hash = $this->sha256( $row['review_content_hash'] ?? null );
			$review_no    = $this->positive_integer( $row['review_no'] ?? null );
			$previous_id  = $this->nullable_public_id( $row['previous_review_id'] ?? null );
			$result_id    = $this->public_id( $row['review_result_id'] ?? null );
			$decision     = $this->one_of( $row['review_decision'] ?? null, self::REVIEW_DECISIONS );
			$score        = $this->nullable_decimal( $row['official_score'] ?? null );
			if (
				$review_no !== $index + 1 || $previous_id !== $previous
				|| ! in_array( $result_id, $known_results, true )
				|| in_array( $decision, array( 'rejected', 'withdrawn' ), true ) !== ( null === $score )
			) {
				throw new InvalidArgumentException( 'Submission evaluation review chain drifted.' );
			}
			$overrides = $this->nullable_profile( $row, 'score_overrides', 'oj_ai.submission_score_decisions' );
			if ( in_array( $decision, array( 'rejected', 'withdrawn' ), true ) !== ( null === $overrides ) ) {
				throw new InvalidArgumentException( 'Submission evaluation review score decisions drifted.' );
			}
			$is_official     = null !== $official && hash_equals( $official['id'], $id ) && hash_equals( $official['hash'], $content_hash );
			$official_count += $is_official ? 1 : 0;
			$result[]        = array(
				'id'              => $id,
				'review_no'       => $review_no,
				'result_id'       => $result_id,
				'decision'        => $decision,
				'official_score'  => $score,
				'score_decisions' => $overrides,
				'reason'          => $this->nullable_text( $row['review_reason'] ?? null, 3000, true ),
				'previous_id'     => $previous_id,
				'is_official'     => $is_official,
				'created_at_utc'  => $this->datetime( $row['review_created_at_utc'] ?? null ),
			);
			$previous        = $id;
		}
		if ( $official_count > 1 ) {
			throw new InvalidArgumentException( 'Submission evaluation has multiple official reviews.' );
		}
		return $result;
	}

	/**
	 * Feedback.
	 *
	 * @param array<int,array<string,mixed>> $rows Rows.
	 * @param array<int,array<string,mixed>> $reviews Reviews.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function feedback( array $rows, array $reviews ): array {
		if ( count( $rows ) > self::MAXIMUM_HISTORY ) {
			throw new InvalidArgumentException( 'Submission feedback history is too large.' );
		}
		$known_reviews = array_column( $reviews, 'id' );
		$result        = array();
		$previous      = null;
		foreach ( $rows as $index => $row ) {
			$id          = $this->public_id( $row['feedback_id'] ?? null );
			$revision_no = $this->positive_integer( $row['feedback_revision_no'] ?? null );
			$previous_id = $this->nullable_public_id( $row['previous_feedback_id'] ?? null );
			$review_id   = $this->public_id( $row['feedback_review_id'] ?? null );
			if ( $revision_no !== $index + 1 || $previous_id !== $previous || ! in_array( $review_id, $known_reviews, true ) ) {
				throw new InvalidArgumentException( 'Submission feedback chain drifted.' );
			}
			$profile = $this->required_profile( $row, 'feedback' );
			$this->assert_no_forbidden_keys( $profile['value'], self::FORBIDDEN_PROJECTION_KEYS );
			$content_hash = $this->sha256( $row['feedback_content_hash'] ?? null );
			$result[]     = array(
				'id'             => $id,
				'hash'           => $content_hash,
				'revision_no'    => $revision_no,
				'review_id'      => $review_id,
				'audience'       => $this->token( $row['feedback_audience'] ?? null, 32 ),
				'state'          => $this->one_of( $row['feedback_state'] ?? null, self::FEEDBACK_STATES ),
				'profile'        => $profile,
				'previous_id'    => $previous_id,
				'source_run_id'  => $this->nullable_public_id( $row['feedback_source_run_id'] ?? null ),
				'created_at_utc' => $this->datetime( $row['feedback_created_at_utc'] ?? null ),
			);
			$previous     = $id;
		}
		return $result;
	}

	/**
	 * Releases.
	 *
	 * @param array<string,mixed>            $root Root.
	 * @param array<int,array<string,mixed>> $rows Rows.
	 * @param array<int,array<string,mixed>> $reviews Reviews.
	 * @param array<int,array<string,mixed>> $feedback Feedback.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function releases( array $root, array $rows, array $reviews, array $feedback ): array {
		if ( count( $rows ) > self::MAXIMUM_HISTORY ) {
			throw new InvalidArgumentException( 'Submission release history is too large.' );
		}
		$known_reviews  = array_column( $reviews, 'id' );
		$known_feedback = array_column( $feedback, 'id' );
		$current        = $this->pointer( $root['current_release_id'] ?? null, $root['current_release_hash'] ?? null );
		$result         = array();
		$previous       = null;
		$current_count  = 0;
		foreach ( $rows as $index => $row ) {
			$id           = $this->public_id( $row['release_id'] ?? null );
			$content_hash = $this->sha256( $row['release_content_hash'] ?? null );
			$release_no   = $this->positive_integer( $row['release_no'] ?? null );
			$previous_id  = $this->nullable_public_id( $row['previous_release_id'] ?? null );
			$review_id    = $this->public_id( $row['release_review_id'] ?? null );
			$feedback_id  = $this->nullable_public_id( $row['release_feedback_id'] ?? null );
			if (
				$release_no !== $index + 1 || ( $previous_id !== $previous && ! ( null !== $previous_id && $this->positive_integer( $row['previous_evaluation_pk'] ?? null ) !== $this->positive_integer( $root['evaluation_pk'] ?? null ) && $this->public_id( $row['previous_revision_id'] ?? null ) === $this->public_id( $root['submission_revision_id'] ?? null ) ) )
				|| ! in_array( $review_id, $known_reviews, true )
				|| ( null !== $feedback_id && ! in_array( $feedback_id, $known_feedback, true ) )
			) {
				throw new InvalidArgumentException( 'Submission release chain drifted.' );
			}
			$profile = $this->required_profile( $row, 'student_projection' );
			$this->assert_no_forbidden_keys( $profile['value'], array_merge( self::FORBIDDEN_PROJECTION_KEYS, self::FORBIDDEN_STUDENT_KEYS ) );
			$is_current     = null !== $current && hash_equals( $current['id'], $id ) && hash_equals( $current['hash'], $content_hash );
			$current_count += $is_current ? 1 : 0;
			$result[]       = array(
				'id'                 => $id,
				'release_no'         => $release_no,
				'state'              => $this->one_of( $row['release_state'] ?? null, self::RELEASE_STATES ),
				'review_id'          => $review_id,
				'feedback_id'        => $feedback_id,
				'visible_score'      => $this->nullable_decimal( $row['visible_score'] ?? null ),
				'student_projection' => $profile,
				'previous_id'        => $previous_id,
				'reason_code'        => $this->nullable_token( $row['release_reason_code'] ?? null, 64 ),
				'reason_note'        => $this->nullable_text( $row['release_reason_note'] ?? null, 3000, true ),
				'released_at_utc'    => $this->datetime( $row['released_at_utc'] ?? null ),
				'is_current'         => $is_current,
			);
			$previous       = $id;
		}
		if ( $current_count > 1 ) {
			throw new InvalidArgumentException( 'Submission evaluation has multiple current releases.' );
		}
		return $result;
	}

	/**
	 * Nullable profile.
	 *
	 * @param array<string,mixed> $row Row.
	 * @param string              $prefix Prefix.
	 * @param string              $expected_schema Expected schema.
	 * @return array{schema_id:string,schema_version:string,value:array<string,mixed>}|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function nullable_profile( array $row, string $prefix, string $expected_schema ): ?array {
		$values  = array( $row[ $prefix . '_schema' ] ?? null, $row[ $prefix . '_version' ] ?? null, $row[ $prefix . '_json' ] ?? null, $row[ $prefix . '_hash' ] ?? null );
		$present = array_filter( $values, static fn ( $value ): bool => null !== $value );
		if ( array() === $present ) {
			return null;
		}
		if ( 4 !== ( count( $present ) ) ) {
			throw new InvalidArgumentException( 'Nullable submission evaluation profile is partial.' );
		}
		$profile = $this->required_profile( $row, $prefix );
		if ( ! hash_equals( $expected_schema, $profile['schema_id'] ) ) {
			throw new InvalidArgumentException( 'Submission evaluation profile schema drifted.' );
		}
		return $profile;
	}

	/**
	 * Required profile.
	 *
	 * @param array<string,mixed> $row Row.
	 * @param string              $prefix Prefix.
	 * @return array{schema_id:string,schema_version:string,value:array<string,mixed>}
	 */
	private function required_profile( array $row, string $prefix ): array {
		$schema  = $this->token( $row[ $prefix . '_schema' ] ?? null, 100 );
		$version = $this->positive_integer( $row[ $prefix . '_version' ] ?? null );
		$value   = $this->decode_canonical( $row[ $prefix . '_json' ] ?? null, $this->sha256( $row[ $prefix . '_hash' ] ?? null ) );
		return array(
			'schema_id'      => $schema,
			'schema_version' => $version . '.0.0',
			'value'          => $value,
		);
	}

	/**
	 * Reject invalid links.
	 *
	 * @param array<string,mixed>            $evaluation Evaluation.
	 * @param array<string,mixed>|null       $result Result.
	 * @param array<int,array<string,mixed>> $runs Runs.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_links( array $evaluation, ?array $result, array $runs ): void {
		$latest = $runs[0] ?? null;
		if (
			! is_array( $latest )
			|| $latest['id'] !== $evaluation['latest_run']['id']
			|| $latest['run_no'] !== $evaluation['latest_run']['run_no']
			|| $latest['state'] !== $evaluation['latest_run']['state']
		) {
			throw new InvalidArgumentException( 'Submission evaluation latest run projection drifted.' );
		}
		if ( null === $result ) {
			return;
		}
		foreach ( $runs as $run ) {
			if ( $run['id'] === $result['run_id'] && $run['result_id'] === $result['id'] && 'succeeded' === $run['state'] ) {
				if ( ( 'not_requested' === $result['provider_status'] ) !== ( 'manual_prepare' === $run['operation'] ) ) {
					throw new InvalidArgumentException( 'Manual score sheet provenance drifted.' );
				}
				return;
			}
		}
		throw new InvalidArgumentException( 'Selected submission evaluation result is not linked to a successful run.' );
	}

	/**
	 * Pointer.
	 *
	 * @param mixed $id Id.
	 * @param mixed $hash Hash.
	 * @return array{id:string,hash:string}|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function pointer( $id, $hash ): ?array {
		if ( null === $id && null === $hash ) {
			return null;
		}
		if ( null === $id || null === $hash ) {
			throw new InvalidArgumentException( 'Submission pointer is partial.' );
		}
		return array(
			'id'   => $this->public_id( $id ),
			'hash' => $this->sha256( $hash ),
		);
	}

	/**
	 * Object.
	 *
	 * @param mixed $value Value.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function object( $value ): array {
		if ( ! is_array( $value ) || array_is_list( $value ) ) {
			throw new InvalidArgumentException( 'Stored object projection is invalid.' );
		}
		return $value;
	}

	/**
	 * List value.
	 *
	 * @param mixed $value Value.
	 * @param int   $maximum Maximum.
	 * @return array<int,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function list_value( $value, int $maximum ): array {
		if ( ! is_array( $value ) || ! array_is_list( $value ) || count( $value ) > $maximum ) {
			throw new InvalidArgumentException( 'Stored list projection is invalid.' );
		}
		return $value;
	}

	/**
	 * Reject invalid no forbidden keys.
	 *
	 * @param mixed             $value Value.
	 * @param array<int,string> $forbidden Forbidden.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_no_forbidden_keys( $value, array $forbidden ): void {
		if ( ! is_array( $value ) ) {
			return;
		}
		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) ) {
				$snake      = preg_replace( '/([a-z0-9])([A-Z])/', '$1_$2', $key );
				$normalized = strtolower( str_replace( '-', '_', is_string( $snake ) ? $snake : $key ) );
				if ( in_array( $normalized, $forbidden, true ) ) {
					throw new InvalidArgumentException( 'Stored projection contains a forbidden field.' );
				}
			}
			$this->assert_no_forbidden_keys( $item, $forbidden );
		}
	}

	/**
	 * Decode canonical.
	 *
	 * @param mixed  $value Value.
	 * @param string $expected_hash Expected hash.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function decode_canonical( $value, string $expected_hash ): array {
		if ( ! is_string( $value ) || ! hash_equals( $expected_hash, hash( 'sha256', $value ) ) ) {
			throw new InvalidArgumentException( 'Stored canonical JSON hash is invalid.' );
		}
		try {
			$decoded = json_decode( $value, true, 128, JSON_THROW_ON_ERROR );
		} catch ( JsonException $error ) {
			throw new InvalidArgumentException( 'Stored canonical JSON is invalid.', 0, $error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The diagnostic text is literal; a chained Throwable is not HTML output.
		}
		if ( ! is_array( $decoded ) || array_is_list( $decoded ) || ! hash_equals( $value, $this->canonical->encode( $decoded ) ) ) {
			throw new InvalidArgumentException( 'Stored canonical JSON representation drifted.' );
		}
		return $decoded;
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
			throw new InvalidArgumentException( 'Stored projection contains unknown or missing fields.' );
		}
	}

	/**
	 * Public id.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function public_id( $value ): string {
		if ( ! is_string( $value ) ) {
			throw new InvalidArgumentException( 'Stored public ID is invalid.' );
		}
		new Public_Id( $value );
		return $value;
	}
	/**
	 * Nullable public id.
	 *
	 * @param mixed $value Value.
	 * @return string|null
	 */
	private function nullable_public_id( $value ): ?string {
		return null === $value ? null : $this->public_id( $value );
	}
	/**
	 * Sha256.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function sha256( $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $value ) ) {
			throw new InvalidArgumentException( 'Stored SHA-256 is invalid.' );
		} return $value;
	}
	/**
	 * Positive integer.
	 *
	 * @param mixed $value Value.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function positive_integer( $value ): int {
		if ( is_string( $value ) && 1 === preg_match( '/^[1-9][0-9]*$/D', $value ) ) {
			$value = (int) $value;
		} if ( ! is_int( $value ) || $value < 1 ) {
			throw new InvalidArgumentException( 'Stored positive integer is invalid.' );
		} return $value;
	}
	/**
	 * Nullable positive integer.
	 *
	 * @param mixed $value Value.
	 * @return int|null
	 */
	private function nullable_positive_integer( $value ): ?int {
		return null === $value ? null : $this->positive_integer( $value );
	}
	/**
	 * Non negative integer.
	 *
	 * @param mixed $value Value.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function non_negative_integer( $value ): int {
		if ( is_string( $value ) && 1 === preg_match( '/^(?:0|[1-9][0-9]*)$/D', $value ) ) {
			$value = (int) $value;
		} if ( ! is_int( $value ) || $value < 0 ) {
			throw new InvalidArgumentException( 'Stored non-negative integer is invalid.' );
		} return $value;
	}
	/**
	 * Boolean.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function boolean( $value ): bool {
		if ( ! is_bool( $value ) ) {
			throw new InvalidArgumentException( 'Stored boolean is invalid.' );
		} return $value;
	}
	/**
	 * Database boolean.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function database_boolean( $value ): bool {
		$integer = $this->non_negative_integer( $value );
		if ( $integer > 1 ) {
			throw new InvalidArgumentException( 'Stored boolean integer is invalid.' );
		} return 1 === $integer;
	}
	/**
	 * One of.
	 *
	 * @param mixed             $value Value.
	 * @param array<int,string> $allowed Allowed.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function one_of( $value, array $allowed ): string {
		if ( ! is_string( $value ) || ! in_array( $value, $allowed, true ) ) {
			throw new InvalidArgumentException( 'Stored enum is invalid.' );
		} return $value;
	}
	/**
	 * Decimal.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function decimal( $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,4})?$/D', $value ) ) {
			throw new InvalidArgumentException( 'Stored decimal is invalid.' );
		} return number_format( (float) $value, 4, '.', '' );
	}
	/**
	 * Nullable decimal.
	 *
	 * @param mixed $value Value.
	 * @return string|null
	 */
	private function nullable_decimal( $value ): ?string {
		return null === $value ? null : $this->decimal( $value );
	}

	/**
	 * Nullable levels.
	 *
	 * @param mixed $value Value.
	 * @return array<int,array<string,mixed>>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function nullable_levels( $value ): ?array {
		if ( null === $value ) {
			return null;
		}
		$list = $this->list_value( $value, 10 );
		if ( count( $list ) < 2 ) {
			throw new InvalidArgumentException( 'Submission evaluation criterion levels are invalid.' );
		}
		$result      = array();
		$seen_keys   = array();
		$seen_points = array();
		foreach ( $list as $position => $level ) {
			$level = $this->object( $level );
			$this->exact_keys( $level, array( 'label', 'level_key', 'points', 'position' ) );
			$id = $this->public_id( $level['level_key'] ?? null );
			if ( isset( $seen_keys[ $id ] ) || $position !== ( $level['position'] ?? null ) ) {
				throw new InvalidArgumentException( 'Submission evaluation criterion level identity is invalid.' );
			}
			$points = $this->decimal( $level['points'] ?? null );
			if ( isset( $seen_points[ $points ] ) ) {
				throw new InvalidArgumentException( 'Submission evaluation criterion level points must be unique.' );
			}
			$seen_keys[ $id ]       = true;
			$seen_points[ $points ] = true;
			$label                  = $this->text( $level['label'] ?? null, Assessment_Configuration_Profile::MAXIMUM_LEVEL_LABEL_LENGTH, true );
			$length                 = preg_match_all( '/./us', $label );
			if ( false === $length || $length > Assessment_Configuration_Profile::MAXIMUM_LEVEL_LABEL_LENGTH ) {
				throw new InvalidArgumentException( 'Submission evaluation performance description is too long.' );
			}
			$result[] = array(
				'label'     => $label,
				'level_key' => $id,
				'points'    => $points,
				'position'  => $position,
			);
		}
		return $result;
	}
	/**
	 * Confidence.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function confidence( $value ): string {
		$value = $this->decimal( $value );
		if ( (float) $value < 0 || (float) $value > 1 ) {
			throw new InvalidArgumentException( 'Stored confidence is invalid.' );
		} return $value;
	}
	/**
	 * Datetime.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function datetime( $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value ) ) {
			throw new InvalidArgumentException( 'Stored UTC datetime is invalid.' );
		} return $value;
	}
	/**
	 * Nullable datetime.
	 *
	 * @param mixed $value Value.
	 * @return string|null
	 */
	private function nullable_datetime( $value ): ?string {
		return null === $value ? null : $this->datetime( $value );
	}
	/**
	 * Token.
	 *
	 * @param mixed $value Value.
	 * @param int   $maximum Maximum.
	 * @param bool  $allow_slash Allow slash.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function token( $value, int $maximum, bool $allow_slash = false ): string {
		$pattern = $allow_slash ? '/^[A-Za-z0-9][A-Za-z0-9_.:@\/-]*$/D' : '/^[A-Za-z0-9][A-Za-z0-9_.:@-]*$/D';
		if ( ! is_string( $value ) || '' === $value || strlen( $value ) > $maximum || 1 !== preg_match( $pattern, $value ) ) {
			throw new InvalidArgumentException( 'Stored token is invalid.' );
		} return $value;
	}
	/**
	 * Nullable token.
	 *
	 * @param mixed $value Value.
	 * @param int   $maximum Maximum.
	 * @return string|null
	 */
	private function nullable_token( $value, int $maximum ): ?string {
		return null === $value ? null : $this->token( $value, $maximum );
	}
	/**
	 * Text.
	 *
	 * @param mixed $value Value.
	 * @param int   $maximum Maximum.
	 * @param bool  $allow_newline Allow newline.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function text( $value, int $maximum, bool $allow_newline ): string {
		if ( ! is_string( $value ) || '' === trim( $value ) || strlen( $value ) > $maximum * 4 || ( ! $allow_newline && str_contains( $value, "\n" ) ) || 1 === preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value ) ) {
			throw new InvalidArgumentException( 'Stored text is invalid.' );
		} return $value;
	}
	/**
	 * Nullable text.
	 *
	 * @param mixed $value Value.
	 * @param int   $maximum Maximum.
	 * @param bool  $allow_newline Allow newline.
	 * @return string|null
	 */
	private function nullable_text( $value, int $maximum, bool $allow_newline ): ?string {
		return null === $value ? null : $this->text( $value, $maximum, $allow_newline );
	}

	/**
	 * Normalizes a loosely-validated stored value (e.g. a worker error
	 * message, which is written with a laxer check than this class's own
	 * strict readers use) into a single line safe for the strict reader —
	 * mirrors the worksheet evaluation projector's helper of the same name.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private function single_line( $value ) {
		if ( ! is_string( $value ) ) {
			return $value;
		}
		if ( '' !== $value && ! mb_check_encoding( $value, 'UTF-8' ) ) {
			$value = mb_convert_encoding( $value, 'UTF-8', 'UTF-8' );
		}
		$collapsed = preg_replace( '/\s+/u', ' ', $value );
		$value     = null === $collapsed ? $value : trim( $collapsed );
		$stripped  = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value );
		$value     = null === $stripped ? $value : $stripped;
		$value     = mb_substr( $value, 0, 500, 'UTF-8' );
		while ( strlen( $value ) > 2000 ) { // phpcs:ignore Squiz.PHP.DisallowSizeFunctionsInLoops.Found -- The UTF-8 truncation changes the byte length on every iteration.
			$value = mb_substr( $value, 0, -1, 'UTF-8' );
		}
		return '' === $value ? null : $value;
	}
	/**
	 * Text list.
	 *
	 * @param mixed $value Value.
	 * @param int   $maximum_items Maximum items.
	 * @param int   $maximum_text Maximum text.
	 * @return array<int,string>
	 */
	private function text_list( $value, int $maximum_items, int $maximum_text ): array {
		$list   = $this->list_value( $value, $maximum_items );
		$result = array();
		foreach ( $list as $item ) {
			$result[] = $this->text( $item, $maximum_text, true );
		} return $result;
	}
	/**
	 * Locale.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function locale( $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^[a-z]{2,3}(?:-[A-Z]{2})?$/D', $value ) ) {
			throw new InvalidArgumentException( 'Stored locale is invalid.' );
		} return $value;
	}

	/**
	 * Stored data invalid.
	 *
	 * @return Submission_Evaluation_Failure
	 */
	private function stored_data_invalid(): Submission_Evaluation_Failure {
		return Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORED_DATA_INVALID );
	}
}
