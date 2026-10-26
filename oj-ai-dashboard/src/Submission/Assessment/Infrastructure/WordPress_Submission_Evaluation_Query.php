<?php
/**
 * Literal-table Q16 and Q17 student submission evaluation query adapter.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Infrastructure;

use InvalidArgumentException;
use OJAI\Dashboard\Submission\Assessment\Application\Submission_Evaluation_Failure;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Evaluation_Query_Projector;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Evaluation_Candidate_Projector;
use OJAI\Dashboard\Submission\Assessment\Port\Submission_Evaluation_Query_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Infrastructure\Signed_Cursor_Codec;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Literal-table Q16 and Q17 student submission evaluation query adapter.
 */
final class WordPress_Submission_Evaluation_Query implements Submission_Evaluation_Query_Port_Interface {
	private const TABLE_EVALUATIONS     = 'oj_ai_submission_evaluations';
	private const TABLE_INPUTS          = 'oj_ai_submission_evaluation_inputs';
	private const TABLE_RESULTS         = 'oj_ai_submission_evaluation_results';
	private const TABLE_ITEMS           = 'oj_ai_submission_evaluation_items';
	private const TABLE_REVIEWS         = 'oj_ai_submission_evaluation_reviews';
	private const TABLE_FEEDBACK        = 'oj_ai_submission_feedback_revisions';
	private const TABLE_RELEASES        = 'oj_ai_submission_release_revisions';
	private const TABLE_RUNS            = 'oj_ai_evaluation_runs';
	private const TABLE_ATTEMPTS        = 'oj_ai_evaluation_attempts';
	private const TABLE_REVISIONS       = 'oj_ai_submission_revisions';
	private const TABLE_SUBMISSIONS     = 'oj_ai_submissions';
	private const TABLE_ASSIGNMENTS     = 'oj_ai_assignments';
	private const TABLE_TARGETS         = 'oj_ai_assignment_targets';
	private const TABLE_REVIEW_RULES    = 'oj_ai_review_rule_revisions';
	private const MAXIMUM_HISTORY_ROWS  = 501;
	private const CANDIDATE_CURSOR_KIND = 'q16c';
	private const CURSOR_KIND           = 'q16';
	/**
	 * Candidate projector.
	 *
	 * @var Submission_Evaluation_Candidate_Projector
	 */
	private Submission_Evaluation_Candidate_Projector $candidate_projector;

	/**
	 * Projector.
	 *
	 * @var Submission_Evaluation_Query_Projector
	 */
	private Submission_Evaluation_Query_Projector $projector;
	/**
	 * Cursors.
	 *
	 * @var Signed_Cursor_Codec
	 */
	private Signed_Cursor_Codec $cursors;

	/**
	 * Initialize WordPress submission evaluation query dependencies and state.
	 *
	 * @param Canonical_Json           $canonical Canonical JSON encoder used for stable digests.
	 * @param Signed_Cursor_Codec|null $cursors Cursors.
	 * @param object|null              $database Database.
	 */
	public function __construct(
		Canonical_Json $canonical,
		?Signed_Cursor_Codec $cursors = null,
		private ?object $database = null
	) {
		$this->projector           = new Submission_Evaluation_Query_Projector( $canonical );
		$this->candidate_projector = new Submission_Evaluation_Candidate_Projector( $canonical );
		$this->cursors             = $cursors ?? new Signed_Cursor_Codec( $canonical );
	}
	/**
	 * List unevaluated context.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $context_type Context type.
	 * @param string $context_id Context id.
	 * @param string $context_revision Context revision.
	 * @param string $assignment_id Distribution public identifier.
	 * @param string $cursor Continuation cursor from the previous page.
	 * @param int    $limit Maximum number of entries requested.
	 * @return array
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function list_unevaluated_context(
		int $actor_user_id,
		string $context_type,
		string $context_id,
		string $context_revision,
		string $assignment_id,
		string $cursor,
		int $limit
	): array {
		try {
			$scope_hash   = $this->candidate_scope_hash( $actor_user_id, $context_type, $context_id, $context_revision, $assignment_id );
			$cursor_value = $this->cursors->decode( $cursor, self::CANDIDATE_CURSOR_KIND );
			$sql          = $this->candidate_select()
				. ' WHERE a.context_type = %s AND a.context_id = %s AND a.context_revision = %s '
				. 'AND t.state = %s AND a.state <> %s AND s.official_revision_public_id IS NOT NULL '
				. 'AND s.state IN (%s,%s,%s,%s) AND NOT EXISTS (SELECT 1 FROM ' . self::TABLE_EVALUATIONS
				. ' evaluated WHERE evaluated.submission_revision_public_id = s.official_revision_public_id '
				. 'AND evaluated.submission_revision_hash = revision.content_hash)';
			$values       = array(
				$context_type,
				$context_id,
				$context_revision,
				'active',
				'withdrawn',
				'submitted',
				'auto_submitted',
				'proxy_submitted',
				'locked',
			);
			if ( '' !== $assignment_id ) {
				$sql     .= ' AND a.public_id = %s';
				$values[] = $assignment_id;
			}
			if ( array() !== $cursor_value ) {
				$this->exact_keys( $cursor_value, array( 'f', 'i', 't' ) );
				if ( ! hash_equals( $scope_hash, (string) ( $cursor_value['f'] ?? '' ) ) ) {
					throw new InvalidArgumentException( 'Submission evaluation candidate cursor scope does not match.' );
				}
				$cursor_id   = (string) ( $cursor_value['i'] ?? '' );
				$cursor_time = (string) ( $cursor_value['t'] ?? '' );
				new Public_Id( $cursor_id );
				$this->assert_utc( $cursor_time );
				$sql     .= ' AND (s.submitted_at_utc < %s OR (s.submitted_at_utc = %s AND s.public_id > %s))';
				$values[] = $cursor_time;
				$values[] = $cursor_time;
				$values[] = $cursor_id;
			}
			$sql     .= ' ORDER BY s.submitted_at_utc DESC, s.public_id ASC LIMIT %d';
			$values[] = $limit + 1;

			$rows     = $this->rows( $this->prepare( $sql, $values ) );
			$has_more = count( $rows ) > $limit;
			if ( $has_more ) {
				array_pop( $rows );
			}
			$items = array_map( fn ( array $row ): array => $this->candidate_projector->project( $row ), $rows );
			$next  = '';
			if ( $has_more && array() !== $items ) {
				$last = $items[ count( $items ) - 1 ];
				$next = $this->cursors->encode(
					self::CANDIDATE_CURSOR_KIND,
					array(
						'f' => $scope_hash,
						'i' => $last['submission']['id'],
						't' => $last['submission']['submitted_at_utc'],
					)
				);
			}

			return array(
				'items'       => $items,
				'next_cursor' => $next,
			);
		} catch ( InvalidArgumentException | Submission_Evaluation_Failure $failure ) {
			throw $failure;
		} catch ( Throwable ) {
			throw $this->storage_unavailable();
		}
	}


	/**
	 * List context.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $context_type Context type.
	 * @param string $context_id Context id.
	 * @param string $context_revision Context revision.
	 * @param string $assignment_id Distribution public identifier.
	 * @param string $state State.
	 * @param string $cursor Continuation cursor from the previous page.
	 * @param int    $limit Maximum number of entries requested.
	 * @return array
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function list_context(
		int $actor_user_id,
		string $context_type,
		string $context_id,
		string $context_revision,
		string $assignment_id,
		string $state,
		string $cursor,
		int $limit
	): array {
		try {
			$scope_hash   = $this->scope_hash(
				$actor_user_id,
				$context_type,
				$context_id,
				$context_revision,
				$assignment_id,
				$state
			);
			$cursor_value = $this->cursors->decode( $cursor, self::CURSOR_KIND );
			$sql          = $this->list_select()
				. ' WHERE a.context_type = %s AND a.context_id = %s AND a.context_revision = %s '
				. 'AND t.state = %s AND a.state <> %s';
			$values       = array( $context_type, $context_id, $context_revision, 'active', 'withdrawn' );

			if ( 'stale' === $state ) {
				$sql .= ' AND (s.official_revision_public_id IS NULL OR s.official_revision_public_id <> e.submission_revision_public_id)';
			} elseif ( '' !== $state ) {
				$sql     .= ' AND e.state = %s';
				$values[] = $state;
			}
			if ( '' !== $assignment_id ) {
				$sql     .= ' AND a.public_id = %s';
				$values[] = $assignment_id;
			}
			if ( array() !== $cursor_value ) {
				$this->exact_keys( $cursor_value, array( 'f', 'i', 'p', 't' ) );
				if ( ! hash_equals( $scope_hash, (string) ( $cursor_value['f'] ?? '' ) ) ) {
					throw new InvalidArgumentException( 'Submission evaluation cursor scope does not match.' );
				}
				$cursor_id   = (string) ( $cursor_value['i'] ?? '' );
				$cursor_rank = $this->priority_rank( $cursor_value['p'] ?? null );
				$cursor_time = (string) ( $cursor_value['t'] ?? '' );
				new Public_Id( $cursor_id );
				$this->assert_utc( $cursor_time );
				$sql     .= ' HAVING (priority_rank < %d OR (priority_rank = %d AND evaluation_updated_at_utc > %s) '
					. 'OR (priority_rank = %d AND evaluation_updated_at_utc = %s AND evaluation_id > %s))';
				$values[] = $cursor_rank;
				$values[] = $cursor_rank;
				$values[] = $cursor_time;
				$values[] = $cursor_rank;
				$values[] = $cursor_time;
				$values[] = $cursor_id;
			}
			$sql     .= ' ORDER BY priority_rank DESC, evaluation_updated_at_utc ASC, evaluation_id ASC LIMIT %d';
			$values[] = $limit + 1;

			$rows     = $this->rows( $this->prepare( $sql, $values ) );
			$has_more = count( $rows ) > $limit;
			if ( $has_more ) {
				array_pop( $rows );
			}
			$items = array_map( fn ( array $row ): array => $this->projector->list_item( $row ), $rows );
			$next  = '';
			if ( $has_more && array() !== $items ) {
				$last = $items[ count( $items ) - 1 ];
				$next = $this->cursors->encode(
					self::CURSOR_KIND,
					array(
						'f' => $scope_hash,
						'i' => $last['id'],
						'p' => $last['priority']['rank'],
						't' => $last['updated_at_utc'],
					)
				);
			}

			return array(
				'items'       => $items,
				'next_cursor' => $next,
			);
		} catch ( InvalidArgumentException | Submission_Evaluation_Failure $failure ) {
			throw $failure;
		} catch ( Throwable ) {
			throw $this->storage_unavailable();
		}
	}

	/**
	 * Find context.
	 *
	 * @param string $evaluation_id Evaluation id.
	 * @param string $context_type Context type.
	 * @param string $context_id Context id.
	 * @param string $context_revision Context revision.
	 * @param string $result_id Result id.
	 * @return array|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function find_context(
		string $evaluation_id,
		string $context_type,
		string $context_id,
		string $context_revision,
		string $result_id = ''
	): ?array {
		try {
			$root = $this->row(
				$this->prepare(
					$this->list_select()
						. ' WHERE e.public_id = %s AND a.context_type = %s AND a.context_id = %s '
						. 'AND a.context_revision = %s AND t.state = %s AND a.state <> %s LIMIT 1',
					array( $evaluation_id, $context_type, $context_id, $context_revision, 'active', 'withdrawn' )
				)
			);
			if ( null === $root ) {
				return null;
			}
			$evaluation_pk = $this->positive_integer( $root['evaluation_pk'] ?? null );

			$input = $this->row(
				$this->prepare(
					'SELECT content_hash AS input_content_hash, projection_schema_version, input_schema, input_version, '
						. 'input_json, input_hash, input_byte_size, redaction_version FROM ' . self::TABLE_INPUTS
						. ' WHERE submission_evaluation_id = %d LIMIT 1',
					array( $evaluation_pk )
				)
			);
			if ( null === $input ) {
				throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORED_DATA_INVALID );
			}

			$result_values = array( 'submission_evaluation', $evaluation_id, $evaluation_pk );
			$result_where  = '';
			if ( '' !== $result_id ) {
				$result_where    = ' AND result.public_id = %s';
				$result_values[] = $result_id;
			}
			$result = $this->row(
				$this->prepare(
					'SELECT result.id AS result_pk, result.public_id AS result_id, result.content_hash AS result_content_hash, '
						. 'result.created_at_utc AS result_created_at_utc, result.output_schema_version AS result_output_schema_version, '
						. 'result.candidate_score AS result_candidate_score, result.maximum_score AS result_maximum_score, '
						. 'result.overall_confidence AS result_overall_confidence, result.abstained AS result_abstained, '
						. 'result.safety_flagged AS result_safety_flagged, result.summary AS result_summary, '
						. 'result.normalized_result_schema, result.normalized_result_version, result.normalized_result_json, '
						. 'result.normalized_result_hash, result.provider_output_digest, run.public_id AS result_run_id '
						. 'FROM ' . self::TABLE_RESULTS . ' result INNER JOIN ' . self::TABLE_RUNS . ' run ON run.id = result.evaluation_run_id '
						. 'WHERE run.subject_type = %s AND run.evaluation_public_id = %s AND result.submission_evaluation_id = %d'
						. $result_where . ' ORDER BY result.created_at_utc DESC, result.id DESC LIMIT 1',
					$result_values
				)
			);
			if ( '' !== $result_id && null === $result ) {
				return null;
			}

			$item_rows = array();
			if ( null !== $result ) {
				$result_pk = $this->positive_integer( $result['result_pk'] ?? null );
				$item_rows = $this->rows(
					$this->prepare(
						'SELECT content_hash AS item_content_hash, criterion_key AS item_criterion_key, '
							. 'question_key AS item_question_key, position AS item_position, candidate_points AS item_candidate_points, '
							. 'maximum_points AS item_maximum_points, confidence AS item_confidence, evidence_schema, '
							. 'evidence_version, evidence_json, evidence_hash, flags AS item_flags FROM ' . self::TABLE_ITEMS
							. ' WHERE submission_evaluation_result_id = %d ORDER BY position ASC',
						array( $result_pk )
					)
				);
			}

			$runs = $this->rows(
				$this->prepare(
					'SELECT run.public_id AS run_id, run.run_no, run.state AS run_state, run.operation_key AS run_operation_key, run.result_public_id AS run_result_id, '
						. 'run.available_at_utc AS run_available_at_utc, run.queued_at_utc AS run_queued_at_utc, '
						. 'run.started_at_utc AS run_started_at_utc, run.finished_at_utc AS run_finished_at_utc, '
						. 'run.last_error_code AS run_last_error_code, run.last_error_message AS run_last_error_message, '
						. 'COALESCE((SELECT COUNT(*) FROM ' . self::TABLE_ATTEMPTS
						. ' attempt WHERE attempt.evaluation_run_id = run.id), 0) AS attempt_count FROM ' . self::TABLE_RUNS
						. ' run WHERE run.subject_type = %s AND run.evaluation_public_id = %s ORDER BY run.run_no ASC LIMIT %d',
					array( 'submission_evaluation', $evaluation_id, self::MAXIMUM_HISTORY_ROWS )
				)
			);

			$reviews = $this->rows(
				$this->prepare(
					'SELECT review.public_id AS review_id, review.content_hash AS review_content_hash, review.review_no, '
						. 'review.decision AS review_decision, review.official_score, review.score_overrides_schema, '
						. 'review.score_overrides_version, review.score_overrides_json, review.score_overrides_hash, '
						. 'review.reason AS review_reason, review.created_at_utc AS review_created_at_utc, '
						. 'previous.public_id AS previous_review_id, result.public_id AS review_result_id FROM ' . self::TABLE_REVIEWS
						. ' review INNER JOIN ' . self::TABLE_RESULTS . ' result ON result.id = review.submission_evaluation_result_id '
						. 'LEFT JOIN ' . self::TABLE_REVIEWS . ' previous ON previous.id = review.previous_review_id '
						. 'WHERE review.submission_evaluation_id = %d ORDER BY review.review_no ASC LIMIT %d',
					array( $evaluation_pk, self::MAXIMUM_HISTORY_ROWS )
				)
			);

			$feedback = $this->rows(
				$this->prepare(
					'SELECT feedback.public_id AS feedback_id, feedback.content_hash AS feedback_content_hash, '
						. 'feedback.revision_no AS feedback_revision_no, feedback.audience AS feedback_audience, '
						. 'feedback.feedback_schema, feedback.feedback_version, feedback.feedback_json, feedback.feedback_hash, '
						. 'feedback.state AS feedback_state, feedback.source_evaluation_run_public_id AS feedback_source_run_id, '
						. 'feedback.created_at_utc AS feedback_created_at_utc, review.public_id AS feedback_review_id, '
						. 'previous.public_id AS previous_feedback_id FROM ' . self::TABLE_FEEDBACK . ' feedback '
						. 'INNER JOIN ' . self::TABLE_REVIEWS . ' review ON review.id = feedback.submission_review_id '
						. 'LEFT JOIN ' . self::TABLE_FEEDBACK . ' previous ON previous.id = feedback.previous_revision_id '
						. 'WHERE feedback.submission_evaluation_id = %d ORDER BY feedback.revision_no ASC LIMIT %d',
					array( $evaluation_pk, self::MAXIMUM_HISTORY_ROWS )
				)
			);

			$releases = $this->rows(
				$this->prepare(
					'SELECT release_row.public_id AS release_id, release_row.content_hash AS release_content_hash, '
						. 'release_row.release_no, release_row.state AS release_state, release_row.visible_score, '
						. 'release_row.student_projection_schema, release_row.student_projection_version, '
						. 'release_row.student_projection_json, release_row.student_projection_hash, '
						. 'release_row.release_reason_code, release_row.release_reason_note, release_row.released_at_utc, '
						. 'review.public_id AS release_review_id, feedback.public_id AS release_feedback_id, '
						. 'previous.public_id AS previous_release_id, previous.submission_evaluation_id AS previous_evaluation_pk, previous_evaluation.submission_revision_public_id AS previous_revision_id FROM ' . self::TABLE_RELEASES . ' release_row '
						. 'INNER JOIN ' . self::TABLE_REVIEWS . ' review ON review.id = release_row.submission_review_id '
						. 'LEFT JOIN ' . self::TABLE_FEEDBACK . ' feedback ON feedback.id = release_row.feedback_revision_id '
						. 'LEFT JOIN ' . self::TABLE_RELEASES . ' previous ON previous.id = release_row.previous_release_id '
						. 'LEFT JOIN ' . self::TABLE_EVALUATIONS . ' previous_evaluation ON previous_evaluation.id = previous.submission_evaluation_id '
						. 'WHERE release_row.submission_evaluation_id = %d ORDER BY release_row.release_no ASC LIMIT %d',
					array( $evaluation_pk, self::MAXIMUM_HISTORY_ROWS )
				)
			);

			return $this->projector->detail( $root, $input, $result, $item_rows, $runs, $reviews, $feedback, $releases );
		} catch ( InvalidArgumentException | Submission_Evaluation_Failure $failure ) {
			throw $failure;
		} catch ( Throwable ) {
			throw $this->storage_unavailable();
		}
	}

	/**
	 * Authorization snapshot.
	 *
	 * @param string $evaluation_id Evaluation id.
	 * @param string $context_type Context type.
	 * @param string $context_id Context id.
	 * @param string $context_revision Context revision.
	 * @return array|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function authorization_snapshot(
		string $evaluation_id,
		string $context_type,
		string $context_id,
		string $context_revision
	): ?array {
		try {
			$row = $this->row(
				$this->prepare(
					'SELECT e.lock_version, e.state, a.context_type, a.context_id, a.context_revision '
						. 'FROM ' . self::TABLE_EVALUATIONS . ' e INNER JOIN ' . self::TABLE_REVISIONS
						. ' revision ON revision.public_id = e.submission_revision_public_id INNER JOIN '
						. self::TABLE_SUBMISSIONS . ' s ON s.id = revision.submission_id INNER JOIN '
						. self::TABLE_ASSIGNMENTS . ' a ON a.id = s.assignment_id INNER JOIN '
						. self::TABLE_TARGETS . ' t ON t.assignment_id = a.id AND t.learner_user_id = s.learner_user_id '
						. 'WHERE e.public_id = %s AND a.context_type = %s AND a.context_id = %s AND a.context_revision = %s '
						. 'AND t.state = %s AND a.state <> %s AND e.submission_revision_hash = revision.content_hash '
						. 'AND e.worksheet_publication_public_id = a.worksheet_publication_public_id '
						. 'AND e.publication_hash = a.publication_hash LIMIT 1',
					array( $evaluation_id, $context_type, $context_id, $context_revision, 'active', 'withdrawn' )
				)
			);
			if ( null === $row ) {
				return null;
			}
			$state = (string) ( $row['state'] ?? '' );
			if ( ! in_array( $state, array( 'requested', 'queued', 'running', 'review_required', 'reviewed', 'failed', 'cancelled' ), true ) ) {
				throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORED_DATA_INVALID );
			}
			$lock_version = $this->non_negative_integer( $row['lock_version'] ?? null );
			foreach ( array(
				'context_type'     => $context_type,
				'context_id'       => $context_id,
				'context_revision' => $context_revision,
			) as $key => $expected ) {
				if ( ! is_string( $row[ $key ] ?? null ) || ! hash_equals( $expected, (string) $row[ $key ] ) ) {
					throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORED_DATA_INVALID );
				}
			}

			return array(
				'context_type'     => $context_type,
				'context_id'       => $context_id,
				'context_revision' => $context_revision,
				'current_revision' => (string) $lock_version,
				'state'            => $state,
				'relation'         => 'context_staff',
			);
		} catch ( Submission_Evaluation_Failure $failure ) {
			throw $failure;
		} catch ( InvalidArgumentException ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORED_DATA_INVALID );
		} catch ( Throwable ) {
			throw $this->storage_unavailable();
		}
	}

	/**
	 * List select.
	 *
	 * @return string
	 */
	private function list_select(): string {
		return 'SELECT e.id AS evaluation_pk, e.public_id AS evaluation_id, e.lock_version AS evaluation_lock_version, '
			. 'e.state AS evaluation_state, e.submission_revision_public_id AS submission_revision_id, '
			. 'e.submission_revision_hash, e.worksheet_publication_public_id AS worksheet_publication_id, e.publication_hash, '
			. 'e.policy_revision_public_id AS policy_revision_id, e.policy_hash, e.rubric_revision_public_id AS rubric_revision_id, '
			. 'e.rubric_hash, e.review_rule_revision_public_id AS review_rule_revision_id, e.review_rule_hash, '
			. 'e.prompt_contract_version, e.input_schema_version, e.output_schema_version, e.requested_at_utc, '
			. 'e.updated_at_utc AS evaluation_updated_at_utc, e.authorization_envelope_version, '
			. 'e.authorization_envelope_json, e.authorization_envelope_hash, revision.content_hash AS source_revision_content_hash, '
			. 'revision.revision_no AS source_revision_no, revision.state AS source_revision_state, '
			. 's.public_id AS submission_id, s.state AS submission_state, s.official_revision_public_id AS official_revision_id, '
			. 's.official_review_public_id AS official_review_id, s.official_review_hash, '
			. 's.current_release_public_id AS current_release_id, s.current_release_hash, s.submitted_at_utc AS submission_submitted_at_utc, '
			. 'a.public_id AS assignment_id, a.title_snapshot AS assignment_title, a.state AS assignment_state, '
			. 'a.context_type, a.context_id, a.context_revision, a.due_at_utc AS assignment_due_at_utc, '
			. 'a.worksheet_publication_public_id AS assignment_publication_id, a.publication_hash AS assignment_publication_hash, '
			. 't.public_id AS target_id, t.content_hash AS target_content_hash, t.learner_user_id, t.membership_revision, '
			. 't.display_snapshot_schema, t.display_snapshot_version, t.display_snapshot_json, t.display_snapshot_hash, '
			. 't.snapshot_hash AS target_snapshot_hash, t.state AS target_state, '
			. 'review_rule.public_id AS joined_review_rule_id, review_rule.content_hash AS joined_review_rule_hash, '
			. 'review_rule.state AS joined_review_rule_state, review_rule.confidence_threshold AS review_rule_confidence_threshold, '
			. 'latest_run.public_id AS latest_run_id, latest_run.run_no AS latest_run_no, latest_run.state AS latest_run_state, '
			. 'latest_run.operation_key AS latest_run_operation_key, '
			. 'latest_run.queued_at_utc AS latest_run_queued_at_utc, latest_run.updated_at_utc AS latest_run_updated_at_utc, '
			. 'latest_result.public_id AS latest_result_id, latest_result.content_hash AS latest_result_content_hash, '
			. 'latest_result.candidate_score AS latest_candidate_score, latest_result.maximum_score AS latest_maximum_score, '
			. 'latest_result.overall_confidence AS latest_overall_confidence, latest_result.abstained AS latest_abstained, '
			. 'latest_result.safety_flagged AS latest_safety_flagged, latest_result.summary AS latest_result_summary, '
			. 'latest_result.created_at_utc AS latest_result_created_at_utc, '
			. 'COALESCE((SELECT COUNT(*) FROM ' . self::TABLE_ITEMS . ' item_count WHERE item_count.submission_evaluation_result_id = latest_result.id), 0) AS item_count, '
			. 'COALESCE((SELECT COUNT(*) FROM ' . self::TABLE_ITEMS . ' needed WHERE needed.submission_evaluation_result_id = latest_result.id '
			. 'AND (needed.candidate_points IS NULL OR needed.flags IS NOT NULL)), 0) AS review_needed_count, '
			. 'COALESCE((SELECT COUNT(*) FROM ' . self::TABLE_REVIEWS . ' review_count WHERE review_count.submission_evaluation_id = e.id), 0) AS review_count, '
			. 'latest_review.public_id AS latest_review_id, latest_review.review_no AS latest_review_no, latest_review.decision AS latest_review_decision, '
			. '(SELECT official_review.review_no FROM ' . self::TABLE_REVIEWS . ' official_review WHERE official_review.submission_evaluation_id = e.id '
			. 'AND official_review.public_id = s.official_review_public_id AND official_review.content_hash = s.official_review_hash LIMIT 1) AS official_local_review_no, '
			. 'COALESCE((SELECT COUNT(*) FROM ' . self::TABLE_FEEDBACK . ' feedback_count WHERE feedback_count.submission_evaluation_id = e.id), 0) AS feedback_count, '
			. 'latest_feedback.public_id AS latest_feedback_id, latest_feedback.revision_no AS latest_feedback_revision_no, latest_feedback.state AS latest_feedback_state, '
			. 'COALESCE((SELECT COUNT(*) FROM ' . self::TABLE_RELEASES . ' release_count WHERE release_count.submission_evaluation_id = e.id), 0) AS release_count, '
			. 'latest_release.public_id AS latest_release_id, latest_release.release_no AS latest_release_no, latest_release.state AS latest_release_state, '
			. '(SELECT current_release.release_no FROM ' . self::TABLE_RELEASES . ' current_release WHERE current_release.submission_evaluation_id = e.id '
			. 'AND current_release.public_id = s.current_release_public_id AND current_release.content_hash = s.current_release_hash LIMIT 1) AS current_local_release_no, '
			. "CASE WHEN e.state = 'review_required' AND a.due_at_utc IS NOT NULL AND a.due_at_utc < UTC_TIMESTAMP() THEN 1 ELSE 0 END AS overdue_flag, "
			. $this->priority_expression() . ' AS priority_rank FROM ' . self::TABLE_EVALUATIONS . ' e '
			. 'INNER JOIN ' . self::TABLE_REVISIONS . ' revision ON revision.public_id = e.submission_revision_public_id '
			. 'INNER JOIN ' . self::TABLE_SUBMISSIONS . ' s ON s.id = revision.submission_id '
			. 'INNER JOIN ' . self::TABLE_ASSIGNMENTS . ' a ON a.id = s.assignment_id '
			. 'INNER JOIN ' . self::TABLE_TARGETS . ' t ON t.assignment_id = a.id AND t.learner_user_id = s.learner_user_id '
			. 'LEFT JOIN ' . self::TABLE_REVIEW_RULES . ' review_rule ON review_rule.public_id = e.review_rule_revision_public_id '
			. 'LEFT JOIN ' . self::TABLE_RUNS . " latest_run ON latest_run.subject_type = 'submission_evaluation' AND latest_run.evaluation_public_id = e.public_id "
			. 'AND latest_run.run_no = (SELECT MAX(run_no) FROM ' . self::TABLE_RUNS . " run_max WHERE run_max.subject_type = 'submission_evaluation' AND run_max.evaluation_public_id = e.public_id) "
			. 'LEFT JOIN ' . self::TABLE_RESULTS . ' latest_result ON latest_result.id = (SELECT MAX(result_max.id) FROM ' . self::TABLE_RESULTS . ' result_max WHERE result_max.submission_evaluation_id = e.id) '
			. 'LEFT JOIN ' . self::TABLE_REVIEWS . ' latest_review ON latest_review.submission_evaluation_id = e.id AND latest_review.review_no = '
			. '(SELECT MAX(review_no) FROM ' . self::TABLE_REVIEWS . ' review_max WHERE review_max.submission_evaluation_id = e.id) '
			. 'LEFT JOIN ' . self::TABLE_FEEDBACK . ' latest_feedback ON latest_feedback.submission_evaluation_id = e.id AND latest_feedback.revision_no = '
			. '(SELECT MAX(revision_no) FROM ' . self::TABLE_FEEDBACK . ' feedback_max WHERE feedback_max.submission_evaluation_id = e.id) '
			. 'LEFT JOIN ' . self::TABLE_RELEASES . ' latest_release ON latest_release.submission_evaluation_id = e.id AND latest_release.release_no = '
			. '(SELECT MAX(release_no) FROM ' . self::TABLE_RELEASES . ' release_max WHERE release_max.submission_evaluation_id = e.id)';
	}
	/**
	 * Candidate select.
	 *
	 * @return string
	 */
	private function candidate_select(): string {
		return 'SELECT s.public_id AS submission_id, s.state AS submission_state, '
			. 's.official_revision_public_id AS official_revision_id, s.submitted_at_utc, '
			. 'revision.content_hash AS official_revision_hash, a.public_id AS assignment_id, '
			. 'a.title_snapshot AS assignment_title, a.state AS assignment_state, '
			. 't.state AS target_state, t.learner_user_id, t.membership_revision, '
			. 't.display_snapshot_schema, t.display_snapshot_version, t.display_snapshot_json, t.display_snapshot_hash '
			. 'FROM ' . self::TABLE_SUBMISSIONS . ' s INNER JOIN ' . self::TABLE_REVISIONS
			. ' revision ON revision.submission_id = s.id AND revision.public_id = s.official_revision_public_id '
			. 'INNER JOIN ' . self::TABLE_ASSIGNMENTS . ' a ON a.id = s.assignment_id INNER JOIN '
			. self::TABLE_TARGETS . ' t ON t.assignment_id = a.id AND t.learner_user_id = s.learner_user_id';
	}


	/**
	 * Priority expression.
	 *
	 * @return string
	 */
	private function priority_expression(): string {
		return 'CASE WHEN latest_result.safety_flagged = 1 THEN 5 '
			. 'WHEN latest_result.abstained = 1 THEN 4 '
			. "WHEN e.state = 'review_required' AND a.due_at_utc IS NOT NULL AND a.due_at_utc < UTC_TIMESTAMP() THEN 3 "
			. 'WHEN latest_result.id IS NOT NULL AND latest_result.overall_confidence < COALESCE(review_rule.confidence_threshold, 1.0000) THEN 2 '
			. "WHEN e.state IN ('review_required','failed') THEN 1 ELSE 0 END";
	}

	/**
	 * Scope hash.
	 *
	 * @param int    $actor Actor.
	 * @param string $type Type.
	 * @param string $id Id.
	 * @param string $revision Revision.
	 * @param string $assignment Assignment.
	 * @param string $state State.
	 * @return string
	 */
	private function scope_hash( int $actor, string $type, string $id, string $revision, string $assignment, string $state ): string {
		return substr( hash( 'sha256', implode( "\0", array( (string) $actor, $type, $id, $revision, $assignment, $state ) ) ), 0, 32 );
	}
	/**
	 * Candidate scope hash.
	 *
	 * @param int    $actor Actor.
	 * @param string $type Type.
	 * @param string $id Id.
	 * @param string $revision Revision.
	 * @param string $assignment Assignment.
	 * @return string
	 */
	private function candidate_scope_hash( int $actor, string $type, string $id, string $revision, string $assignment ): string {
		return substr( hash( 'sha256', implode( "\0", array( 'candidate', (string) $actor, $type, $id, $revision, $assignment ) ) ), 0, 32 );
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
			throw new InvalidArgumentException( 'Submission evaluation cursor contains unknown fields.' );
		}
	}

	/**
	 * Priority rank.
	 *
	 * @param mixed $value Value.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function priority_rank( $value ): int {
		$rank = $this->non_negative_integer( $value );
		if ( $rank > 5 ) {
			throw new InvalidArgumentException( 'Submission evaluation cursor priority is invalid.' );
		}
		return $rank;
	}

	/**
	 * Reject invalid utc.
	 *
	 * @param string $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_utc( string $value ): void {
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value ) ) {
			throw new InvalidArgumentException( 'Submission evaluation cursor time is invalid.' );
		}
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
		}
		if ( ! is_int( $value ) || $value < 1 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORED_DATA_INVALID );
		}
		return $value;
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
		}
		if ( ! is_int( $value ) || $value < 0 ) {
			throw new InvalidArgumentException( 'Stored non-negative integer is invalid.' );
		}
		return $value;
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
			throw $this->storage_unavailable();
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
		$db = $this->database();
		$this->reset_error( $db );
		$row = $db->get_row( $sql, defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A' );
		$this->assert_read( $db );
		if ( null === $row ) {
			return null;
		}
		if ( ! is_array( $row ) || array_is_list( $row ) ) {
			throw $this->storage_unavailable();
		}
		return $row;
	}

	/**
	 * Rows.
	 *
	 * @param string $sql Sql.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function rows( string $sql ): array {
		$db = $this->database();
		$this->reset_error( $db );
		$rows = $db->get_results( $sql, defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A' );
		$this->assert_read( $db );
		if ( ! is_array( $rows ) || ! array_is_list( $rows ) ) {
			throw $this->storage_unavailable();
		}
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || array_is_list( $row ) ) {
				throw $this->storage_unavailable();
			}
		}
		return $rows;
	}

	/**
	 * Reset error.
	 *
	 * @param object $db Db.
	 * @return void
	 */
	private function reset_error( object $db ): void {
		if ( property_exists( $db, 'last_error' ) ) {
			$db->last_error = '';
		}
	}
	/**
	 * Reject invalid read.
	 *
	 * @param object $db Db.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_read( object $db ): void {
		if ( '' !== trim( (string) ( $db->last_error ?? '' ) ) ) {
			throw $this->storage_unavailable();
		}
	}

	/**
	 * Database.
	 *
	 * @return object
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function database(): object {
		$db = $this->database ?? ( $GLOBALS['wpdb'] ?? null );
		if ( ! is_object( $db ) || ! method_exists( $db, 'prepare' ) || ! method_exists( $db, 'get_row' ) || ! method_exists( $db, 'get_results' ) ) {
			throw $this->storage_unavailable();
		}
		return $db;
	}

	/**
	 * Storage unavailable.
	 *
	 * @return Submission_Evaluation_Failure
	 */
	private function storage_unavailable(): Submission_Evaluation_Failure {
		return Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORAGE_UNAVAILABLE );
	}
}
