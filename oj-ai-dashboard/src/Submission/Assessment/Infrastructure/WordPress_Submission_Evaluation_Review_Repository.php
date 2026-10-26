<?php
/**
 * Atomic C34 review, official pointer, and stale-release repository.
 *
 * Accepted and adjusted reviews advance both mutable roots with lock-version
 * CAS. A visible current release is copied to one immutable stale revision in
 * the same transaction. Rejected reviews append history without moving roots.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Infrastructure;

use InvalidArgumentException;
use JsonException;
use OJAI\Dashboard\Submission\Assessment\Application\Review_Submission_Evaluation;
use OJAI\Dashboard\Submission\Assessment\Application\Review_Submission_Evaluation_Command;
use OJAI\Dashboard\Submission\Assessment\Application\Submission_Evaluation_Failure;
use OJAI\Dashboard\Submission\Assessment\Application\Submission_Evaluation_Review_Result;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Evaluation_Output_Normalizer;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Score_Input_Builder;
use OJAI\Dashboard\Submission\Assessment\Port\Submission_Evaluation_Review_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Atomic C34 review, official pointer, and stale-release repository.
 */
final class WordPress_Submission_Evaluation_Review_Repository implements Submission_Evaluation_Review_Repository_Port_Interface {
	private const TABLE_EVALUATIONS = 'oj_ai_submission_evaluations';
	private const TABLE_RESULTS     = 'oj_ai_submission_evaluation_results';
	private const TABLE_ITEMS       = 'oj_ai_submission_evaluation_items';
	private const TABLE_REVIEWS     = 'oj_ai_submission_evaluation_reviews';
	private const TABLE_FEEDBACK    = 'oj_ai_submission_feedback_revisions';
	private const TABLE_RELEASES    = 'oj_ai_submission_release_revisions';
	private const TABLE_RUNS        = 'oj_ai_evaluation_runs';
	private const TABLE_REVISIONS   = 'oj_ai_submission_revisions';
	private const TABLE_SUBMISSIONS = 'oj_ai_submissions';
	private const TABLE_ASSIGNMENTS = 'oj_ai_assignments';
	private const TABLE_TARGETS     = 'oj_ai_assignment_targets';
	private const TABLE_AUDIT       = 'oj_ai_audit_events';
	private const TABLE_OUTBOX      = 'oj_ai_outbox_events';

	private const EVENT                = 'submission_evaluation.review_appended';
	private const AUDIT_SCHEMA         = 'oj_ai.submission_evaluation_review_audit';
	private const OUTBOX_SCHEMA        = 'oj_ai.submission_evaluation_review_event';
	private const PROFILE_VERSION      = 1;
	private const STALE_RELEASE_REASON = 'official_review_changed';

	/**
	 * Initialize WordPress submission evaluation review repository dependencies and state.
	 *
	 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
	 * @param object|null    $database Database.
	 */
	public function __construct(
		private Canonical_Json $canonical,
		private ?object $database = null
	) {
	}

	/**
	 * Find command receipt.
	 *
	 * @param string $command_storage_key Command storage key.
	 * @param string $request_digest Request digest.
	 * @return Submission_Evaluation_Review_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function find_command_receipt(
		string $command_storage_key,
		string $request_digest
	): ?Submission_Evaluation_Review_Result {
		$this->assert_storage_key( $command_storage_key );
		$this->assert_hash_argument( $request_digest );
		try {
			return $this->command_receipt( $command_storage_key, $request_digest, false );
		} catch ( InvalidArgumentException ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORED_DATA_INVALID );
		} catch ( Submission_Evaluation_Failure $failure ) {
			throw $failure;
		} catch ( Throwable ) {
			throw $this->storage_failure();
		}
	}

	/**
	 * Find owned snapshot.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $evaluation_id Evaluation id.
	 * @return array<string,mixed>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function find_owned_snapshot( int $actor_user_id, string $evaluation_id ): ?array {
		if ( $actor_user_id < 1 ) {
			throw new InvalidArgumentException( 'Submission evaluation review requires an actor.' );
		}
		new Public_Id( $evaluation_id );
		try {
			return $this->snapshot( $evaluation_id, false );
		} catch ( InvalidArgumentException ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORED_DATA_INVALID );
		} catch ( Submission_Evaluation_Failure $failure ) {
			throw $failure;
		} catch ( Throwable ) {
			throw $this->storage_failure();
		}
	}

	/**
	 * Append review.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Submission_Evaluation_Review_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function append_review( array $record ): Submission_Evaluation_Review_Result {
		$this->assert_record( $record );
		try {
			$receipt = $this->command_receipt( (string) $record['command_storage_key'], (string) $record['request_digest'], false );
		} catch ( InvalidArgumentException ) {
			throw $this->stored_invalid();
		}
		if ( $receipt instanceof Submission_Evaluation_Review_Result ) {
			return $receipt;
		}

		$transaction = false;
		try {
			$this->query_or_fail( 'START TRANSACTION' );
			$transaction = true;
			try {
				$receipt = $this->command_receipt( (string) $record['command_storage_key'], (string) $record['request_digest'], true );
			} catch ( InvalidArgumentException ) {
				throw $this->stored_invalid();
			}
			if ( $receipt instanceof Submission_Evaluation_Review_Result ) {
				$this->query_or_fail( 'COMMIT' );
				$transaction = false;
				return $receipt;
			}

			try {
				$snapshot = $this->snapshot( (string) $record['evaluation_public_id'], true );
			} catch ( InvalidArgumentException ) {
				throw $this->stored_invalid();
			}
			if ( null === $snapshot ) {
				throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::NOT_FOUND );
			}
			$this->assert_transition( $snapshot, $record );
			$this->insert_review( $snapshot, $record );
			$release = $this->stale_release_transition( $snapshot, $record );
			if ( null !== $release ) {
				$this->insert_stale_release( $release );
			}
			if ( 'rejected' !== $record['decision'] ) {
				$this->advance_evaluation( $snapshot, $record );
				$this->advance_submission( $snapshot, $record, $release );
			}
			$result = $this->result_from_record( $record, false );
			$this->insert_audit( $snapshot, $record, $result, $release );
			$this->insert_outbox( $record, $result );
			$this->assert_postcondition( $record, $release );
			$this->query_or_fail( 'COMMIT' );
			$transaction = false;
			return $result;
		} catch ( Submission_Evaluation_Failure $failure ) {
			if ( $transaction ) {
				$this->rollback();
			}
			throw $failure;
		} catch ( Throwable ) {
			if ( $transaction ) {
				$this->rollback();
			}
			$receipt = $this->recover_receipt( $record );
			if ( $receipt instanceof Submission_Evaluation_Review_Result ) {
				return $receipt;
			}
			throw $this->storage_failure();
		}
	}

	/**
	 * Snapshot.
	 *
	 * @param string $evaluation_id Evaluation id.
	 * @param bool   $for_update For update.
	 * @return array<string,mixed>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function snapshot( string $evaluation_id, bool $for_update ): ?array {
		$sql = 'SELECT e.id AS evaluation_pk, e.public_id AS evaluation_id, e.lock_version AS evaluation_lock_version, e.state AS evaluation_state, e.submission_revision_public_id AS submission_revision_id, e.submission_revision_hash, revision.id AS submission_revision_pk, revision.content_hash AS source_revision_hash, revision.state AS submission_revision_state, s.id AS submission_pk, s.public_id AS submission_id, s.lock_version AS submission_lock_version, s.state AS submission_state, s.official_revision_public_id, s.official_review_public_id AS official_review_id, s.official_review_hash, s.current_release_public_id AS current_release_id, s.current_release_hash, a.context_type, a.context_id, a.context_revision, a.state AS assignment_state, t.state AS target_state, result.id AS result_pk, result.public_id AS result_id, result.content_hash AS result_hash, result.candidate_score, result.maximum_score, result.abstained, result.safety_flagged, result.normalized_result_schema, result.normalized_result_version, result.normalized_result_json, result.normalized_result_hash, result.provider_output_digest, run.public_id AS result_run_id, run.state AS result_run_state, run.result_public_id AS run_result_id, review.id AS latest_review_pk, review.public_id AS latest_review_id, review.content_hash AS latest_review_hash, review.review_no AS latest_review_no, review.decision AS latest_review_decision, review.official_score AS latest_official_score, review.score_overrides_schema, review.score_overrides_version, review.score_overrides_json, review.score_overrides_hash, review.reason AS latest_review_reason, previous.public_id AS previous_review_id, review_result.public_id AS latest_review_result_id, review_result.content_hash AS latest_review_result_hash, current_release.id AS current_release_pk, current_release.public_id AS joined_current_release_id, current_release.content_hash AS joined_current_release_hash, current_release.submission_evaluation_id AS current_release_evaluation_pk, release_evaluation.public_id AS current_release_evaluation_id, release_revision.submission_id AS current_release_submission_pk, current_release.submission_review_id AS current_release_review_pk, release_review.public_id AS current_release_review_id, release_review.submission_evaluation_id AS current_release_review_evaluation_pk, current_release.feedback_revision_id AS current_release_feedback_pk, release_feedback.public_id AS current_release_feedback_id, release_feedback.submission_evaluation_id AS current_release_feedback_evaluation_pk, release_feedback.submission_review_id AS current_release_feedback_review_pk, current_release.release_no AS current_release_no, current_release.state AS current_release_state, current_release.visible_score AS current_release_visible_score, current_release.student_projection_schema AS current_student_projection_schema, current_release.student_projection_version AS current_student_projection_version, current_release.student_projection_json AS current_student_projection_json, current_release.student_projection_hash AS current_student_projection_hash, release_previous.public_id AS current_release_previous_id, current_release.release_reason_code AS current_release_reason_code, current_release.release_reason_note AS current_release_reason_note, current_release.released_at_utc AS current_released_at_utc, (SELECT COUNT(*) FROM ' . self::TABLE_RELEASES . ' counted_release WHERE counted_release.submission_evaluation_id = current_release.submission_evaluation_id) AS current_release_count, '
			. '(SELECT COUNT(*) FROM ' . self::TABLE_RESULTS . ' counted_result WHERE counted_result.submission_evaluation_id = e.id) AS result_count, '
			. '(SELECT COUNT(*) FROM ' . self::TABLE_REVIEWS . ' counted_review WHERE counted_review.submission_evaluation_id = e.id) AS review_count '
			. 'FROM ' . self::TABLE_EVALUATIONS . ' e '
			. 'INNER JOIN ' . self::TABLE_REVISIONS . ' revision ON revision.public_id = e.submission_revision_public_id '
			. 'INNER JOIN ' . self::TABLE_SUBMISSIONS . ' s ON s.id = revision.submission_id '
			. 'INNER JOIN ' . self::TABLE_ASSIGNMENTS . ' a ON a.id = s.assignment_id '
			. 'INNER JOIN ' . self::TABLE_TARGETS . ' t ON t.assignment_id = a.id AND t.learner_user_id = s.learner_user_id '
			. 'LEFT JOIN ' . self::TABLE_RESULTS . ' result ON result.id = (SELECT MAX(latest_result.id) FROM '
			. self::TABLE_RESULTS . ' latest_result WHERE latest_result.submission_evaluation_id = e.id) '
			. 'LEFT JOIN ' . self::TABLE_RUNS . ' run ON run.id = result.evaluation_run_id '
			. 'LEFT JOIN ' . self::TABLE_REVIEWS . ' review ON review.submission_evaluation_id = e.id '
			. 'AND review.review_no = (SELECT MAX(latest_review.review_no) FROM ' . self::TABLE_REVIEWS
			. ' latest_review WHERE latest_review.submission_evaluation_id = e.id) '
			. 'LEFT JOIN ' . self::TABLE_REVIEWS . ' previous ON previous.id = review.previous_review_id '
			. 'LEFT JOIN ' . self::TABLE_RESULTS . ' review_result ON review_result.id = review.submission_evaluation_result_id '
			. 'LEFT JOIN ' . self::TABLE_RELEASES . ' current_release ON current_release.public_id = s.current_release_public_id '
			. 'LEFT JOIN ' . self::TABLE_EVALUATIONS . ' release_evaluation ON release_evaluation.id = current_release.submission_evaluation_id '
			. 'LEFT JOIN ' . self::TABLE_REVISIONS . ' release_revision ON release_revision.public_id = release_evaluation.submission_revision_public_id '
			. 'LEFT JOIN ' . self::TABLE_REVIEWS . ' release_review ON release_review.id = current_release.submission_review_id '
			. 'LEFT JOIN ' . self::TABLE_FEEDBACK . ' release_feedback ON release_feedback.id = current_release.feedback_revision_id '
			. 'LEFT JOIN ' . self::TABLE_RELEASES . ' release_previous ON release_previous.id = current_release.previous_release_id '
			. 'WHERE e.public_id = %s AND t.state = %s AND a.state <> %s LIMIT 1';
		if ( $for_update ) {
			$sql .= ' FOR UPDATE';
		}
		$row = $this->row( $this->prepare( $sql, array( $evaluation_id, 'active', 'withdrawn' ) ) );
		if ( null === $row ) {
			return null;
		}

		$evaluation_pk            = $this->positive_integer( $row['evaluation_pk'] ?? null );
		$evaluation_lock          = $this->non_negative_integer( $row['evaluation_lock_version'] ?? null );
		$evaluation_state         = $this->evaluation_state( $row['evaluation_state'] ?? null );
		$submission_id            = $this->public_id( $row['submission_id'] ?? null );
		$submission_revision_id   = $this->public_id( $row['submission_revision_id'] ?? null );
		$submission_revision_hash = $this->hash_value( $row['submission_revision_hash'] ?? null );
		if ( ! hash_equals( $submission_revision_hash, $this->hash_value( $row['source_revision_hash'] ?? null ) ) ) {
			throw $this->stored_invalid( 'snapshot.row (submission_revision_hash != joined revision.content_hash — source changed since evaluation)' );
		}
		if ( ! hash_equals( $submission_revision_id, $this->public_id( $row['official_revision_public_id'] ?? null ) ) ) {
			throw $this->stored_invalid( 'snapshot.row (submission_revision_id != submission.official_revision_public_id — not the official revision)' );
		}
		if ( ! in_array( $row['submission_revision_state'] ?? null, array( 'saved', 'reopened_fork' ), true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw $this->stored_invalid( 'snapshot.row (submission_revision_state: ' . $this->safe_state_label( $row['submission_revision_state'] ?? null ) . ')' );
		}
		if ( ! in_array( $row['submission_state'] ?? null, array( 'submitted', 'auto_submitted', 'proxy_submitted', 'locked' ), true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw $this->stored_invalid( 'snapshot.row (submission_state: ' . $this->safe_state_label( $row['submission_state'] ?? null ) . ')' );
		}
		if ( 'active' !== ( $row['target_state'] ?? null ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw $this->stored_invalid( 'snapshot.row (target_state: ' . $this->safe_state_label( $row['target_state'] ?? null ) . ')' );
		}
		if ( 'withdrawn' === ( $row['assignment_state'] ?? null ) ) {
			throw $this->stored_invalid( 'snapshot.row (assignment_state is withdrawn)' );
		}
		$official        = $this->pointer_value( $row['official_review_id'] ?? null, $row['official_review_hash'] ?? null );
		$current_pointer = $this->pointer_value( $row['current_release_id'] ?? null, $row['current_release_hash'] ?? null );
		$current_release = null;
		if ( null !== $current_pointer['id'] ) {
			$release_pk                     = $this->positive_integer( $row['current_release_pk'] ?? null );
			$release_id                     = $this->public_id( $row['joined_current_release_id'] ?? null );
			$release_hash                   = $this->hash_value( $row['joined_current_release_hash'] ?? null );
			$release_evaluation_pk          = $this->positive_integer( $row['current_release_evaluation_pk'] ?? null );
			$release_evaluation_id          = $this->public_id( $row['current_release_evaluation_id'] ?? null );
			$release_review_pk              = $this->positive_integer( $row['current_release_review_pk'] ?? null );
			$release_review_id              = $this->public_id( $row['current_release_review_id'] ?? null );
			$release_review_evaluation_pk   = $this->positive_integer( $row['current_release_review_evaluation_pk'] ?? null );
			$release_feedback_pk            = null === ( $row['current_release_feedback_pk'] ?? null ) ? null : $this->positive_integer( $row['current_release_feedback_pk'] );
			$release_feedback_id            = $this->nullable_public_id( $row['current_release_feedback_id'] ?? null );
			$release_feedback_evaluation_pk = null === $release_feedback_pk ? null : $this->positive_integer( $row['current_release_feedback_evaluation_pk'] ?? null );
			$release_feedback_review_pk     = null === $release_feedback_pk ? null : $this->positive_integer( $row['current_release_feedback_review_pk'] ?? null );
			$release_no                     = $this->positive_integer( $row['current_release_no'] ?? null );
			$release_count                  = $this->positive_integer( $row['current_release_count'] ?? null );
			$release_state                  = $this->release_state( $row['current_release_state'] ?? null );
			$visible_score                  = $this->nullable_decimal( $row['current_release_visible_score'] ?? null );
			$projection_schema              = $this->nullable_token_value( $row['current_student_projection_schema'] ?? null, 100 );
			$projection_version             = $this->positive_integer( $row['current_student_projection_version'] ?? null );
			$projection_hash                = $this->hash_value( $row['current_student_projection_hash'] ?? null );
			$projection_json                = (string) ( $row['current_student_projection_json'] ?? '' );
			$this->decode_canonical( $projection_json, $projection_hash );
			$previous_release_id     = $this->nullable_public_id( $row['current_release_previous_id'] ?? null );
			$reason_code             = $this->nullable_token_value( $row['current_release_reason_code'] ?? null, 64 );
			$reason_note             = $this->nullable_text_value( $row['current_release_reason_note'] ?? null, 3000 );
			$released_at             = $this->datetime_value( $row['current_released_at_utc'] ?? null );
			$calculated_release_hash = $this->release_content_hash(
				$release_evaluation_id,
				$release_review_id,
				$release_feedback_id,
				$release_no,
				$release_state,
				$visible_score,
				(string) $projection_schema,
				$projection_version,
				$projection_hash,
				$previous_release_id,
				$reason_code,
				$reason_note,
				$released_at
			);
			if (
				! hash_equals( (string) $current_pointer['id'], $release_id )
				|| ! hash_equals( (string) $current_pointer['hash'], $release_hash )
				|| ! hash_equals( $calculated_release_hash, $release_hash )
				|| $release_no !== $release_count
				|| ( 'released' === $release_state ) !== ( null === $previous_release_id )
				|| ( null === $release_feedback_pk ) !== ( null === $release_feedback_id )
				|| $release_review_evaluation_pk !== $release_evaluation_pk
				|| ( null !== $release_feedback_pk && $release_feedback_evaluation_pk !== $release_evaluation_pk )
				|| ( null !== $release_feedback_pk && $release_feedback_review_pk !== $release_review_pk )
				|| $this->positive_integer( $row['current_release_submission_pk'] ?? null ) !== $this->positive_integer( $row['submission_pk'] ?? null )
				|| null === $projection_schema
			) {
				throw $this->stored_invalid( 'snapshot.current_release (pointer or projection consistency)' );
			}
			$current_release = array(
				'pk'                 => $release_pk,
				'public_id'          => $release_id,
				'content_hash'       => $release_hash,
				'evaluation_pk'      => $release_evaluation_pk,
				'evaluation_id'      => $release_evaluation_id,
				'review_pk'          => $release_review_pk,
				'review_id'          => $release_review_id,
				'feedback_pk'        => $release_feedback_pk,
				'feedback_id'        => $release_feedback_id,
				'release_no'         => $release_no,
				'state'              => $release_state,
				'visible_score'      => $visible_score,
				'projection_schema'  => $projection_schema,
				'projection_version' => $projection_version,
				'projection_json'    => $projection_json,
				'projection_hash'    => $projection_hash,
			);
		} elseif ( null !== ( $row['joined_current_release_id'] ?? null ) ) {
			throw $this->stored_invalid( 'snapshot.current_release (pointer present without release row)' );
		}

		$result_count    = $this->positive_integer( $row['result_count'] ?? null );
		$result_pk       = $this->positive_integer( $row['result_pk'] ?? null );
		$result_id       = $this->public_id( $row['result_id'] ?? null );
		$result_hash     = $this->hash_value( $row['result_hash'] ?? null );
		$result_run_id   = $this->public_id( $row['result_run_id'] ?? null );
		$normalized_hash = $this->hash_value( $row['normalized_result_hash'] ?? null );
		$provider_digest = $this->hash_value( $row['provider_output_digest'] ?? null );
		$normalized      = $this->decode_canonical( (string) ( $row['normalized_result_json'] ?? '' ), $normalized_hash );
		$this->exact_keys(
			$normalized,
			array( 'schema_id', 'schema_version', 'evaluation_ref', 'source_hash', 'provider_status', 'candidate_score', 'maximum_score', 'overall_confidence', 'abstained', 'safety_flagged', 'summary', 'items', 'limitations' )
		);
		if (
			$result_count < 1
			|| Submission_Evaluation_Output_Normalizer::RESULT_SCHEMA !== ( $row['normalized_result_schema'] ?? null )
			|| self::PROFILE_VERSION !== $this->positive_integer( $row['normalized_result_version'] ?? null )
			|| Submission_Evaluation_Output_Normalizer::RESULT_SCHEMA !== ( $normalized['schema_id'] ?? null )
			|| Submission_Evaluation_Output_Normalizer::RESULT_VERSION !== ( $normalized['schema_version'] ?? null )
			|| ! hash_equals( $submission_revision_hash, $this->hash_value( $normalized['source_hash'] ?? null ) )
			|| 'succeeded' !== ( $row['result_run_state'] ?? null )
			|| ! hash_equals( $result_id, $this->public_id( $row['run_result_id'] ?? null ) )
		) {
			throw $this->stored_invalid( 'snapshot.result (schema, version, source_hash, or run state)' );
		}
		$expected_result_hash = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'evaluation_id'          => $evaluation_id,
					'normalized_result_hash' => $normalized_hash,
					'provider_output_digest' => $provider_digest,
					'run_id'                 => $result_run_id,
				)
			)
		);
		if ( ! hash_equals( $expected_result_hash, $result_hash ) ) {
			throw $this->stored_invalid( 'snapshot.result_hash (content hash mismatch)' );
		}

		$item_rows            = $this->rows(
			$this->prepare(
				'SELECT content_hash AS item_content_hash, criterion_key, question_key, position, candidate_points, maximum_points, confidence, evidence_schema, evidence_version, evidence_json, evidence_hash, flags FROM ' . self::TABLE_ITEMS
					. ' WHERE submission_evaluation_result_id = %d ORDER BY position ASC',
				array( $result_pk )
			)
		);
		$result_items         = $this->result_items( $normalized['items'] ?? null, $item_rows );
		$normalized_candidate = $this->nullable_decimal( $normalized['candidate_score'] ?? null );
		$normalized_maximum   = $this->decimal( $normalized['maximum_score'] ?? null );
		$normalized_abstained = $this->boolean( $normalized['abstained'] ?? null );
		$normalized_safety    = $this->boolean( $normalized['safety_flagged'] ?? null );
		$derived_abstained    = count( array_filter( $result_items, static fn ( array $item ): bool => null === $item['candidate_points'] ) ) > 0;
		$derived_safety       = count( array_filter( $result_items, static fn ( array $item ): bool => $item['safety_review_required'] ) ) > 0;
		if (
			$normalized_candidate !== $this->nullable_decimal( $row['candidate_score'] ?? null )
			|| $normalized_maximum !== $this->decimal( $row['maximum_score'] ?? null )
			|| $normalized_abstained !== $this->database_boolean( $row['abstained'] ?? null )
			|| $normalized_safety !== $this->database_boolean( $row['safety_flagged'] ?? null )
			|| $normalized_abstained !== $derived_abstained
			|| $normalized_safety !== $derived_safety
			|| ( $normalized_abstained && null !== $normalized_candidate )
		) {
			throw $this->stored_invalid( 'snapshot.result (normalized score/abstain/safety vs stored columns or derived items)' );
		}

		$review_count     = $this->non_negative_integer( $row['review_count'] ?? null );
		$latest_review_pk = null;
		$latest_review_id = null;
		$latest_review_no = 0;
		if ( $review_count > 0 ) {
			$latest_review_pk   = $this->positive_integer( $row['latest_review_pk'] ?? null );
			$latest_review_id   = $this->public_id( $row['latest_review_id'] ?? null );
			$latest_review_no   = $this->positive_integer( $row['latest_review_no'] ?? null );
			$previous_review_id = $this->nullable_public_id( $row['previous_review_id'] ?? null );
			$latest_decision    = $this->review_decision( $row['latest_review_decision'] ?? null );
			$latest_score       = $this->nullable_decimal( $row['latest_official_score'] ?? null );
			$latest_profile     = $this->score_profile( $row );
			$latest_reason      = $this->nullable_reason_value( $row['latest_review_reason'] ?? null );
			$latest_result_id   = $this->public_id( $row['latest_review_result_id'] ?? null );
			$latest_result_hash = $this->hash_value( $row['latest_review_result_hash'] ?? null );
			if (
				$latest_review_no !== $review_count
				|| ( 1 === $latest_review_no ) !== ( null === $previous_review_id )
				|| in_array( $latest_decision, array( 'rejected', 'withdrawn' ), true ) !== ( null === $latest_score )
				|| in_array( $latest_decision, array( 'rejected', 'withdrawn' ), true ) !== ( null === $latest_profile )
			) {
				throw $this->stored_invalid( 'snapshot.latest_review (number, pointer, or decision consistency)' );
			}
			$latest_hash = $this->review_content_hash(
				$evaluation_id,
				$latest_result_id,
				$latest_result_hash,
				$latest_review_no,
				$latest_decision,
				$latest_score,
				null === $latest_profile ? null : $latest_profile['hash'],
				$latest_reason,
				$previous_review_id
			);
			if ( ! hash_equals( $latest_hash, $this->hash_value( $row['latest_review_hash'] ?? null ) ) ) {
				throw $this->stored_invalid( 'snapshot.latest_review_hash (content hash mismatch)' );
			}
		} elseif ( null !== ( $row['latest_review_id'] ?? null ) || null !== ( $row['latest_review_no'] ?? null ) ) {
			throw $this->stored_invalid( 'snapshot.latest_review (pointer present without a review row)' );
		}

		return array(
			'evaluation_pk'            => $evaluation_pk,
			'evaluation_id'            => $evaluation_id,
			'evaluation_lock_version'  => $evaluation_lock,
			'evaluation_state'         => $evaluation_state,
			'context_type'             => $this->context_token( $row['context_type'] ?? null, 16 ),
			'context_id'               => $this->context_token( $row['context_id'] ?? null, 191 ),
			'context_revision'         => $this->context_token( $row['context_revision'] ?? null, 191 ),
			'submission_pk'            => $this->positive_integer( $row['submission_pk'] ?? null ),
			'submission_lock_version'  => $this->non_negative_integer( $row['submission_lock_version'] ?? null ),
			'submission_id'            => $submission_id,
			'submission_revision_id'   => $submission_revision_id,
			'submission_revision_hash' => $submission_revision_hash,
			'official_review_id'       => $official['id'],
			'official_review_hash'     => $official['hash'],
			'current_release_id'       => $current_pointer['id'],
			'current_release_hash'     => $current_pointer['hash'],
			'current_release_state'    => null === $current_release ? null : $current_release['state'],
			'current_release'          => $current_release,
			'result_pk'                => $result_pk,
			'result_id'                => $result_id,
			'result_hash'              => $result_hash,
			'result_items'             => $result_items,
			'latest_review_pk'         => $latest_review_pk,
			'latest_review_id'         => $latest_review_id,
			'latest_review_no'         => $latest_review_no,
		);
	}

	/**
	 * Reject invalid transition.
	 *
	 * @param array<string,mixed> $snapshot Snapshot.
	 * @param array<string,mixed> $record Record.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_transition( array $snapshot, array $record ): void {
		$approved = 'reviewed' === $snapshot['evaluation_state'] && null !== $snapshot['official_review_id'];
		if ( ( 'withdrawn' === $record['decision'] ) !== $approved ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Stable conflict under the root locks.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::REVIEW_SOURCE_STALE, $this->safe_state( $snapshot ) );
		}
		if ( null !== $snapshot['official_review_id'] && 'rejected' !== $record['decision'] ) {
			$official = $this->row(
				$this->prepare(
					'SELECT public_id FROM ' . self::TABLE_REVIEWS . ' WHERE public_id = %s AND content_hash = %s AND submission_evaluation_id = %d AND decision IN (%s, %s) LIMIT 1',
					array( $snapshot['official_review_id'], $snapshot['official_review_hash'], $snapshot['evaluation_pk'], 'accepted', 'adjusted' )
				)
			);
			if ( null === $official ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Cannot replace or withdraw another evaluation's official review.
				throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::REVIEW_SOURCE_STALE, $this->safe_state( $snapshot ) );
			}
		}
		if (
			! hash_equals( (string) $record['evaluation_public_id'], (string) $snapshot['evaluation_id'] )
			|| (int) $snapshot['evaluation_lock_version'] !== (int) $record['expected_evaluation_lock_version']
			|| ! hash_equals( (string) $snapshot['evaluation_state'], (string) $record['expected_evaluation_state'] )
			|| ! hash_equals( (string) $snapshot['submission_id'], (string) $record['submission_public_id'] )
			|| ! hash_equals( (string) $snapshot['submission_revision_id'], (string) $record['submission_revision_public_id'] )
			|| ! hash_equals( (string) $snapshot['submission_revision_hash'], (string) $record['submission_revision_hash'] )
			|| (int) $snapshot['submission_lock_version'] !== (int) $record['expected_submission_lock_version']
			|| ! hash_equals( (string) $snapshot['result_id'], (string) $record['expected_result_public_id'] )
			|| ! hash_equals( (string) $snapshot['result_hash'], (string) $record['expected_result_hash'] )
			|| $snapshot['official_review_id'] !== $record['expected_official_review_public_id']
			|| $snapshot['official_review_hash'] !== $record['expected_official_review_hash']
			|| $snapshot['current_release_id'] !== $record['expected_current_release_public_id']
			|| $snapshot['current_release_hash'] !== $record['expected_current_release_hash']
			|| $snapshot['current_release_state'] !== $record['expected_current_release_state']
			|| (int) $snapshot['latest_review_no'] !== (int) $record['expected_review_no']
			|| $snapshot['latest_review_id'] !== $record['expected_previous_review_public_id']
			|| (int) $record['review_no'] !== (int) $snapshot['latest_review_no'] + 1
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::REVIEW_SOURCE_STALE, $this->safe_state( $snapshot ) );
		}
	}

	/**
	 * Insert review.
	 *
	 * @param array<string,mixed> $snapshot Snapshot.
	 * @param array<string,mixed> $record Record.
	 * @return void
	 */
	private function insert_review( array $snapshot, array $record ): void {
		$this->insert_or_fail(
			self::TABLE_REVIEWS,
			array(
				'public_id'                       => $record['review_public_id'],
				'content_hash'                    => $record['content_hash'],
				'created_by_user_id'              => $record['actor_user_id'],
				'created_at_utc'                  => $record['occurred_at_utc'],
				'submission_evaluation_id'        => $snapshot['evaluation_pk'],
				'submission_evaluation_result_id' => $snapshot['result_pk'],
				'review_no'                       => $record['review_no'],
				'decision'                        => $record['decision'],
				'official_score'                  => $record['official_score'],
				'score_overrides_schema'          => null === $record['score_decisions'] ? null : Review_Submission_Evaluation::SCORE_DECISIONS_SCHEMA,
				'score_overrides_version'         => null === $record['score_decisions'] ? null : Review_Submission_Evaluation::SCORE_DECISIONS_VERSION,
				'score_overrides_json'            => $record['score_decisions_json'],
				'score_overrides_hash'            => $record['score_decisions_hash'],
				'reason'                          => $record['reason'],
				'previous_review_id'              => $snapshot['latest_review_pk'],
			)
		);
	}

	/**
	 * Stale release transition.
	 *
	 * @param array<string,mixed> $snapshot Snapshot.
	 * @param array<string,mixed> $record Record.
	 * @return array<string,mixed>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function stale_release_transition( array $snapshot, array $record ): ?array {
		if (
			'rejected' === $record['decision']
			|| ! in_array( $snapshot['current_release_state'], array( 'released', 'corrected' ), true )
		) {
			return null;
		}
		$current = $snapshot['current_release'] ?? null;
		if ( ! is_array( $current ) || array_is_list( $current ) || null === $record['stale_release_public_id'] ) {
			throw $this->stored_invalid( 'stale_release_transition (missing current release or command stale_release_public_id)' );
		}
		$release_no   = (int) $current['release_no'] + 1;
		$content_hash = $this->release_content_hash(
			(string) $current['evaluation_id'],
			(string) $current['review_id'],
			$current['feedback_id'],
			$release_no,
			'stale',
			$current['visible_score'],
			(string) $current['projection_schema'],
			(int) $current['projection_version'],
			(string) $current['projection_hash'],
			(string) $current['public_id'],
			self::STALE_RELEASE_REASON,
			null,
			(string) $record['occurred_at_utc']
		);
		return array(
			'public_id'                    => $record['stale_release_public_id'],
			'content_hash'                 => $content_hash,
			'created_by_user_id'           => $record['actor_user_id'],
			'created_at_utc'               => $record['occurred_at_utc'],
			'submission_evaluation_id'     => $current['evaluation_pk'],
			'submission_review_id'         => $current['review_pk'],
			'feedback_revision_id'         => $current['feedback_pk'],
			'release_no'                   => $release_no,
			'state'                        => 'stale',
			'visible_score'                => $current['visible_score'],
			'student_projection_schema'    => $current['projection_schema'],
			'student_projection_version'   => $current['projection_version'],
			'student_projection_json'      => $current['projection_json'],
			'student_projection_hash'      => $current['projection_hash'],
			'previous_release_id'          => $current['pk'],
			'previous_release_public_id'   => $current['public_id'],
			'release_evaluation_public_id' => $current['evaluation_id'],
			'release_review_public_id'     => $current['review_id'],
			'release_feedback_public_id'   => $current['feedback_id'],
			'release_reason_code'          => self::STALE_RELEASE_REASON,
			'release_reason_note'          => null,
			'released_at_utc'              => $record['occurred_at_utc'],
		);
	}

	/**
	 * Insert stale release.
	 *
	 * @param array<string,mixed> $release Release.
	 * @return void
	 */
	private function insert_stale_release( array $release ): void {
		$stored = $release;
		unset(
			$stored['previous_release_public_id'],
			$stored['release_evaluation_public_id'],
			$stored['release_review_public_id'],
			$stored['release_feedback_public_id']
		);
		$this->insert_or_fail( self::TABLE_RELEASES, $stored );
	}

	/**
	 * Advance evaluation.
	 *
	 * @param array<string,mixed> $snapshot Snapshot.
	 * @param array<string,mixed> $record Record.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function advance_evaluation( array $snapshot, array $record ): void {
		$changed = $this->change_count(
			$this->prepare(
				'UPDATE ' . self::TABLE_EVALUATIONS . ' SET state = %s, lock_version = lock_version + 1, '
					. 'updated_by_user_id = %d, updated_at_utc = %s WHERE id = %d AND public_id = %s '
					. 'AND lock_version = %d AND state = %s',
				array(
					'withdrawn' === $record['decision'] ? 'review_required' : 'reviewed',
					$record['actor_user_id'],
					$record['occurred_at_utc'],
					$snapshot['evaluation_pk'],
					$record['evaluation_public_id'],
					$record['expected_evaluation_lock_version'],
					$record['expected_evaluation_state'],
				)
			)
		);
		if ( 1 !== $changed ) {
			throw Submission_Evaluation_Failure::because(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				Submission_Evaluation_Failure::REVIEW_SOURCE_STALE,
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				$this->safe_state( $snapshot )
			);
		}
	}

	/**
	 * Advance submission.
	 *
	 * @param array<string,mixed>      $snapshot Snapshot.
	 * @param array<string,mixed>      $record Record.
	 * @param array<string,mixed>|null $release Release.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function advance_submission( array $snapshot, array $record, ?array $release ): void {
		$set    = 'official_review_public_id = %s, official_review_hash = %s';
		$values = array( $record['review_public_id'], $record['content_hash'] );
		if ( 'withdrawn' === $record['decision'] ) {
			$set    = 'official_review_public_id = NULL, official_review_hash = NULL';
			$values = array();
		}
		if ( null !== $release ) {
			$set     .= ', current_release_public_id = %s, current_release_hash = %s';
			$values[] = $release['public_id'];
			$values[] = $release['content_hash'];
		}
		$set     .= ', lock_version = lock_version + 1, updated_by_user_id = %d, updated_at_utc = %s';
		$values[] = $record['actor_user_id'];
		$values[] = $record['occurred_at_utc'];
		$sql      = 'UPDATE ' . self::TABLE_SUBMISSIONS . ' SET ' . $set
			. ' WHERE id = %d AND public_id = %s AND lock_version = %d AND official_revision_public_id = %s';
		$values[] = $snapshot['submission_pk'];
		$values[] = $record['submission_public_id'];
		$values[] = $record['expected_submission_lock_version'];
		$values[] = $record['submission_revision_public_id'];
		$sql     .= ' AND ' . $this->pointer_predicate(
			'official_review_public_id',
			'official_review_hash',
			$record['expected_official_review_public_id'],
			$record['expected_official_review_hash'],
			$values
		);
		$sql     .= ' AND ' . $this->pointer_predicate(
			'current_release_public_id',
			'current_release_hash',
			$record['expected_current_release_public_id'],
			$record['expected_current_release_hash'],
			$values
		);
		if ( 1 !== $this->change_count( $this->prepare( $sql, $values ) ) ) {
			throw Submission_Evaluation_Failure::because(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				Submission_Evaluation_Failure::REVIEW_SOURCE_STALE,
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				$this->safe_state( $snapshot )
			);
		}
	}

	/**
	 * Pointer predicate.
	 *
	 * @param string           $id_column Id column.
	 * @param string           $hash_column Hash column.
	 * @param string|null      $id Id.
	 * @param string|null      $hash Hash.
	 * @param array<int,mixed> &$values Values.
	 * @return string
	 */
	private function pointer_predicate( string $id_column, string $hash_column, ?string $id, ?string $hash, array &$values ): string {
		if ( null === $id || null === $hash ) {
			return $id_column . ' IS NULL AND ' . $hash_column . ' IS NULL';
		}
		$values[] = $id;
		$values[] = $hash;
		return $id_column . ' = %s AND ' . $hash_column . ' = %s';
	}

	/**
	 * Insert audit.
	 *
	 * @param array<string,mixed>                 $snapshot Snapshot.
	 * @param array<string,mixed>                 $record Record.
	 * @param Submission_Evaluation_Review_Result $result Result.
	 * @param array<string,mixed>|null            $release Release.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function insert_audit( array $snapshot, array $record, Submission_Evaluation_Review_Result $result, ?array $release ): void {
		$previous_hash = $this->scalar( 'SELECT event_hash FROM ' . self::TABLE_AUDIT . ' ORDER BY id DESC LIMIT 1 FOR UPDATE' );
		if ( null !== $previous_hash && 1 !== preg_match( '/^[a-f0-9]{64}$/D', (string) $previous_hash ) ) {
			throw $this->storage_failure();
		}
		$before_digest = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'current_release_hash' => $snapshot['current_release_hash'],
					'current_release_id'   => $snapshot['current_release_id'],
					'evaluation_lock'      => $snapshot['evaluation_lock_version'],
					'evaluation_state'     => $snapshot['evaluation_state'],
					'official_review_hash' => $snapshot['official_review_hash'],
					'official_review_id'   => $snapshot['official_review_id'],
					'result_hash'          => $snapshot['result_hash'],
					'result_id'            => $snapshot['result_id'],
					'review_no'            => $snapshot['latest_review_no'],
					'submission_lock'      => $snapshot['submission_lock_version'],
				)
			)
		);
		$after_digest  = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'current_release_hash' => null === $release ? $snapshot['current_release_hash'] : $release['content_hash'],
					'current_release_id'   => null === $release ? $snapshot['current_release_id'] : $release['public_id'],
					'decision'             => $result->decision(),
					'evaluation_lock'      => 'rejected' === $record['decision']
						? $snapshot['evaluation_lock_version'] : (int) $snapshot['evaluation_lock_version'] + 1,
					'evaluation_state'     => 'rejected' === $record['decision'] ? $snapshot['evaluation_state'] : ( 'withdrawn' === $record['decision'] ? 'review_required' : 'reviewed' ),
					'official_review_hash' => $result->official_review_hash(),
					'official_review_id'   => $result->official_review_id(),
					'review_hash'          => $result->review_hash(),
					'review_id'            => $result->review_id(),
					'review_no'            => $result->review_no(),
					'submission_lock'      => 'rejected' === $record['decision']
						? $snapshot['submission_lock_version'] : (int) $snapshot['submission_lock_version'] + 1,
				)
			)
		);
		$metadata      = array(
			'command_id'                => $record['command_id'],
			'decision'                  => $record['decision'],
			'previous_review_id'        => $snapshot['latest_review_id'],
			'request_digest'            => $record['request_digest'],
			'result_hash'               => $record['expected_result_hash'],
			'result_id'                 => $record['expected_result_public_id'],
			'review_hash'               => $record['content_hash'],
			'review_id'                 => $record['review_public_id'],
			'score_decisions_hash'      => $record['score_decisions_hash'],
			'official_pointer_advanced' => $result->official_pointer_advanced(),
			'release_staled'            => null !== $release,
			'stale_release_hash'        => null === $release ? null : $release['content_hash'],
			'stale_release_id'          => null === $release ? null : $release['public_id'],
		);
		$metadata_json = $this->canonical->encode( $metadata );
		$metadata_hash = hash( 'sha256', $metadata_json );
		$event_hash    = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'action_key'          => self::EVENT,
					'actor_user_id'       => $record['actor_user_id'],
					'after_digest'        => $after_digest,
					'before_digest'       => $before_digest,
					'correlation_id'      => $record['correlation_id'],
					'metadata_hash'       => $metadata_hash,
					'object_public_id'    => $record['evaluation_public_id'],
					'object_type'         => 'submission_evaluation',
					'occurred_at_utc'     => $record['occurred_at_utc'],
					'previous_event_hash' => null === $previous_hash ? null : (string) $previous_hash,
				)
			)
		);
		$this->insert_or_fail(
			self::TABLE_AUDIT,
			array(
				'public_id'           => $record['audit_public_id'],
				'occurred_at_utc'     => $record['occurred_at_utc'],
				'actor_user_id'       => $record['actor_user_id'],
				'action_key'          => self::EVENT,
				'object_type'         => 'submission_evaluation',
				'object_public_id'    => $record['evaluation_public_id'],
				'context_type'        => $record['context_type'],
				'context_id'          => $record['context_id'],
				'context_revision'    => $record['context_revision'],
				'correlation_id'      => $record['correlation_id'],
				'before_digest'       => $before_digest,
				'after_digest'        => $after_digest,
				'metadata_schema'     => self::AUDIT_SCHEMA,
				'metadata_version'    => self::PROFILE_VERSION,
				'metadata_json'       => $metadata_json,
				'metadata_hash'       => $metadata_hash,
				'previous_event_hash' => null === $previous_hash ? null : (string) $previous_hash,
				'event_hash'          => $event_hash,
			)
		);
	}

	/**
	 * Insert outbox.
	 *
	 * @param array<string,mixed>                 $record Record.
	 * @param Submission_Evaluation_Review_Result $result Result.
	 * @return void
	 */
	private function insert_outbox( array $record, Submission_Evaluation_Review_Result $result ): void {
		$payload_json = $this->canonical->encode(
			array(
				'command_id'     => $record['command_id'],
				'event'          => self::EVENT,
				'operation'      => 'review',
				'request_digest' => $record['request_digest'],
				'review'         => $this->receipt_reference( $result ),
			)
		);
		$this->insert_or_fail(
			self::TABLE_OUTBOX,
			array(
				'public_id'            => $record['outbox_public_id'],
				'lock_version'         => 0,
				'created_by_user_id'   => $record['actor_user_id'],
				'created_at_utc'       => $record['occurred_at_utc'],
				'updated_by_user_id'   => $record['actor_user_id'],
				'updated_at_utc'       => $record['occurred_at_utc'],
				'aggregate_type'       => 'submission_evaluation',
				'aggregate_public_id'  => $record['evaluation_public_id'],
				'event_type'           => self::EVENT,
				'idempotency_key'      => $record['command_storage_key'],
				'payload_schema'       => self::OUTBOX_SCHEMA,
				'payload_version'      => self::PROFILE_VERSION,
				'payload_json'         => $payload_json,
				'payload_hash'         => hash( 'sha256', $payload_json ),
				'available_at_utc'     => $record['occurred_at_utc'],
				'published_at_utc'     => null,
				'lease_token'          => null,
				'lease_expires_at_utc' => null,
				'attempt_count'        => 0,
				'last_error_code'      => null,
			)
		);
	}

	/**
	 * Command receipt.
	 *
	 * @param string $storage_key Storage key.
	 * @param string $request_digest Request digest.
	 * @param bool   $for_update For update.
	 * @return Submission_Evaluation_Review_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function command_receipt( string $storage_key, string $request_digest, bool $for_update ): ?Submission_Evaluation_Review_Result {
		$sql = 'SELECT aggregate_public_id, event_type, payload_schema, payload_version, payload_json, payload_hash FROM '
			. self::TABLE_OUTBOX . ' WHERE idempotency_key = %s LIMIT 1';
		if ( $for_update ) {
			$sql .= ' FOR UPDATE';
		}
		$row = $this->row( $this->prepare( $sql, array( $storage_key ) ) );
		if ( null === $row ) {
			return null;
		}
		if (
			self::EVENT !== ( $row['event_type'] ?? null ) || self::OUTBOX_SCHEMA !== ( $row['payload_schema'] ?? null )
			|| self::PROFILE_VERSION !== $this->positive_integer( $row['payload_version'] ?? null )
		) {
			throw $this->stored_invalid();
		}
		$payload = $this->decode_canonical( (string) ( $row['payload_json'] ?? '' ), $this->hash_value( $row['payload_hash'] ?? null ) );
		$this->exact_keys( $payload, array( 'command_id', 'event', 'operation', 'request_digest', 'review' ) );
		if ( ! hash_equals( $request_digest, (string) ( $payload['request_digest'] ?? '' ) ) || 'review' !== ( $payload['operation'] ?? null ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::IDEMPOTENCY_CONFLICT );
		}
		$reference = $this->review_reference( $payload['review'] ?? null );
		if ( ! hash_equals( (string) ( $row['aggregate_public_id'] ?? '' ), $reference['evaluation_id'] ) ) {
			throw $this->stored_invalid();
		}
		$stored = $this->row(
			$this->prepare(
				'SELECT review.public_id AS review_id, review.content_hash AS review_hash, review.review_no, review.decision, review.official_score, review.score_overrides_schema, review.score_overrides_version, review.score_overrides_json, review.score_overrides_hash, review.reason, review.created_at_utc, previous.public_id AS previous_review_id, e.public_id AS evaluation_id, result.public_id AS result_id, result.content_hash AS result_hash, s.public_id AS submission_id, revision.public_id AS submission_revision_id, revision.content_hash AS submission_revision_hash FROM ' . self::TABLE_REVIEWS . ' review INNER JOIN ' . self::TABLE_EVALUATIONS . ' e ON e.id = review.submission_evaluation_id '
					. 'INNER JOIN ' . self::TABLE_RESULTS . ' result ON result.id = review.submission_evaluation_result_id '
					. 'INNER JOIN ' . self::TABLE_REVISIONS . ' revision ON revision.public_id = e.submission_revision_public_id '
					. 'INNER JOIN ' . self::TABLE_SUBMISSIONS . ' s ON s.id = revision.submission_id '
					. 'LEFT JOIN ' . self::TABLE_REVIEWS . ' previous ON previous.id = review.previous_review_id '
					. 'WHERE review.public_id = %s AND review.content_hash = %s AND e.public_id = %s LIMIT 1',
				array( $reference['review_id'], $reference['review_hash'], $reference['evaluation_id'] )
			)
		);
		if ( null === $stored ) {
			throw $this->stored_invalid();
		}
		return $this->result_from_stored( $stored, $reference, true );
	}

	/**
	 * Recover receipt.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Submission_Evaluation_Review_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function recover_receipt( array $record ): ?Submission_Evaluation_Review_Result {
		try {
			return $this->command_receipt( (string) $record['command_storage_key'], (string) $record['request_digest'], false ); } catch ( Submission_Evaluation_Failure $failure ) {
			throw $failure;
			} catch ( Throwable ) {
				return null;
			}
	}

	/**
	 * Result from record.
	 *
	 * @param array<string,mixed> $record Record.
	 * @param bool                $replayed Replayed.
	 * @return Submission_Evaluation_Review_Result
	 */
	private function result_from_record( array $record, bool $replayed ): Submission_Evaluation_Review_Result {
		$advanced      = 'rejected' !== $record['decision'];
		$official_id   = 'withdrawn' === $record['decision'] ? null : ( $advanced ? $record['review_public_id'] : $record['expected_official_review_public_id'] );
		$official_hash = 'withdrawn' === $record['decision'] ? null : ( $advanced ? $record['content_hash'] : $record['expected_official_review_hash'] );
		return new Submission_Evaluation_Review_Result(
			(string) $record['evaluation_public_id'],
			(string) $record['submission_public_id'],
			(string) $record['submission_revision_public_id'],
			(string) $record['submission_revision_hash'],
			(string) $record['expected_result_public_id'],
			(string) $record['expected_result_hash'],
			(string) $record['review_public_id'],
			(string) $record['content_hash'],
			(int) $record['review_no'],
			(string) $record['decision'],
			$record['official_score'],
			$record['score_decisions'],
			$record['reason'],
			$official_id,
			$official_hash,
			$advanced,
			(string) $record['occurred_at_utc'],
			$replayed
		);
	}

	/**
	 * Result from stored.
	 *
	 * @param array<string,mixed> $row Row.
	 * @param array<string,mixed> $reference Reference.
	 * @param bool                $replayed Replayed.
	 * @return Submission_Evaluation_Review_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function result_from_stored( array $row, array $reference, bool $replayed ): Submission_Evaluation_Review_Result {
		$evaluation_id = $this->public_id( $row['evaluation_id'] ?? null );
		$result_id     = $this->public_id( $row['result_id'] ?? null );
		$result_hash   = $this->hash_value( $row['result_hash'] ?? null );
		$review_id     = $this->public_id( $row['review_id'] ?? null );
		$review_hash   = $this->hash_value( $row['review_hash'] ?? null );
		$review_no     = $this->positive_integer( $row['review_no'] ?? null );
		$decision      = $this->review_decision( $row['decision'] ?? null );
		$score         = $this->nullable_decimal( $row['official_score'] ?? null );
		$profile       = $this->score_profile( $row );
		$reason        = $this->nullable_reason_value( $row['reason'] ?? null );
		$previous      = $this->nullable_public_id( $row['previous_review_id'] ?? null );
		if (
			! hash_equals( $reference['evaluation_id'], $evaluation_id ) || ! hash_equals( $reference['review_id'], $review_id )
			|| ! hash_equals( $reference['review_hash'], $review_hash ) || $reference['review_no'] !== $review_no
			|| in_array( $decision, array( 'rejected', 'withdrawn' ), true ) !== ( null === $score ) || in_array( $decision, array( 'rejected', 'withdrawn' ), true ) !== ( null === $profile )
		) {
			throw $this->stored_invalid();
		}
		$calculated = $this->review_content_hash(
			$evaluation_id,
			$result_id,
			$result_hash,
			$review_no,
			$decision,
			$score,
			null === $profile ? null : $profile['hash'],
			$reason,
			$previous
		);
		if ( ! hash_equals( $calculated, $review_hash ) ) {
			throw $this->stored_invalid();
		}
		return new Submission_Evaluation_Review_Result(
			$evaluation_id,
			$this->public_id( $row['submission_id'] ?? null ),
			$this->public_id( $row['submission_revision_id'] ?? null ),
			$this->hash_value( $row['submission_revision_hash'] ?? null ),
			$result_id,
			$result_hash,
			$review_id,
			$review_hash,
			$review_no,
			$decision,
			$score,
			null === $profile ? null : $profile['items'],
			$reason,
			$reference['official_review_id'],
			$reference['official_review_hash'],
			$reference['official_pointer_advanced'],
			$this->datetime_value( $row['created_at_utc'] ?? null ),
			$replayed
		);
	}

	/**
	 * Receipt reference.
	 *
	 * @param Submission_Evaluation_Review_Result $result Result.
	 * @return array<string,mixed>
	 */
	private function receipt_reference( Submission_Evaluation_Review_Result $result ): array {
		return array(
			'evaluation_id'             => $result->evaluation_id(),
			'official_pointer_advanced' => $result->official_pointer_advanced(),
			'official_review_hash'      => $result->official_review_hash(),
			'official_review_id'        => $result->official_review_id(),
			'review_hash'               => $result->review_hash(),
			'review_id'                 => $result->review_id(),
			'review_no'                 => $result->review_no(),
		);
	}

	/**
	 * Review reference.
	 *
	 * @param mixed $value Value.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function review_reference( $value ): array {
		$value = $this->object( $value );
		$this->exact_keys( $value, array( 'evaluation_id', 'official_pointer_advanced', 'official_review_hash', 'official_review_id', 'review_hash', 'review_id', 'review_no' ) );
		$official = $this->pointer_value( $value['official_review_id'] ?? null, $value['official_review_hash'] ?? null );
		if ( ! is_bool( $value['official_pointer_advanced'] ?? null ) ) {
			throw $this->stored_invalid();
		}
		return array(
			'evaluation_id'             => $this->public_id( $value['evaluation_id'] ?? null ),
			'official_pointer_advanced' => $value['official_pointer_advanced'],
			'official_review_id'        => $official['id'],
			'official_review_hash'      => $official['hash'],
			'review_hash'               => $this->hash_value( $value['review_hash'] ?? null ),
			'review_id'                 => $this->public_id( $value['review_id'] ?? null ),
			'review_no'                 => $this->positive_integer( $value['review_no'] ?? null ),
		);
	}

	/**
	 * Reject invalid postcondition.
	 *
	 * @param array<string,mixed>      $record Record.
	 * @param array<string,mixed>|null $release Release.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_postcondition( array $record, ?array $release ): void {
		$row                    = $this->row(
			$this->prepare(
				'SELECT e.state AS evaluation_state, e.lock_version AS evaluation_lock_version, review.review_no, review.decision, review.content_hash, review.official_score, review.score_overrides_hash, result.public_id AS result_id, result.content_hash AS result_hash, s.official_review_public_id AS official_review_id, s.official_review_hash, s.current_release_public_id AS current_release_id, s.current_release_hash, s.lock_version AS submission_lock_version, (SELECT COUNT(*) FROM ' . self::TABLE_REVIEWS . ' counted '
					. 'WHERE counted.submission_evaluation_id = e.id) AS review_count FROM ' . self::TABLE_EVALUATIONS . ' e '
					. 'INNER JOIN ' . self::TABLE_REVIEWS . ' review ON review.submission_evaluation_id = e.id '
					. 'INNER JOIN ' . self::TABLE_RESULTS . ' result ON result.id = review.submission_evaluation_result_id '
					. 'INNER JOIN ' . self::TABLE_REVISIONS . ' revision ON revision.public_id = e.submission_revision_public_id '
					. 'INNER JOIN ' . self::TABLE_SUBMISSIONS . ' s ON s.id = revision.submission_id '
					. 'WHERE e.public_id = %s AND review.public_id = %s LIMIT 1',
				array( $record['evaluation_public_id'], $record['review_public_id'] )
			)
		);
		$advanced               = 'rejected' !== $record['decision'];
		$expected_official_id   = 'withdrawn' === $record['decision'] ? null : ( $advanced ? $record['review_public_id'] : $record['expected_official_review_public_id'] );
		$expected_official_hash = 'withdrawn' === $record['decision'] ? null : ( $advanced ? $record['content_hash'] : $record['expected_official_review_hash'] );
		$expected_release_id    = null === $release ? $record['expected_current_release_public_id'] : $release['public_id'];
		$expected_release_hash  = null === $release ? $record['expected_current_release_hash'] : $release['content_hash'];
		if (
			null === $row || (int) $record['review_no'] !== $this->positive_integer( $row['review_no'] ?? null )
			|| (int) $record['review_no'] !== $this->positive_integer( $row['review_count'] ?? null )
			|| $record['decision'] !== ( $row['decision'] ?? null )
			|| ! hash_equals( (string) $record['content_hash'], (string) ( $row['content_hash'] ?? '' ) )
			|| $record['official_score'] !== $this->nullable_decimal( $row['official_score'] ?? null )
			|| $record['score_decisions_hash'] !== ( $row['score_overrides_hash'] ?? null )
			|| ! hash_equals( (string) $record['expected_result_public_id'], (string) ( $row['result_id'] ?? '' ) )
			|| ! hash_equals( (string) $record['expected_result_hash'], (string) ( $row['result_hash'] ?? '' ) )
			|| $expected_official_id !== ( $row['official_review_id'] ?? null )
			|| $expected_official_hash !== ( $row['official_review_hash'] ?? null )
			|| $expected_release_id !== ( $row['current_release_id'] ?? null )
			|| $expected_release_hash !== ( $row['current_release_hash'] ?? null )
			|| ( 'withdrawn' === $record['decision'] ? 'review_required' : ( $advanced ? 'reviewed' : $record['expected_evaluation_state'] ) ) !== ( $row['evaluation_state'] ?? null )
			|| (int) $record['expected_evaluation_lock_version'] + ( $advanced ? 1 : 0 )
				!== $this->non_negative_integer( $row['evaluation_lock_version'] ?? null )
			|| (int) $record['expected_submission_lock_version'] + ( $advanced ? 1 : 0 )
				!== $this->non_negative_integer( $row['submission_lock_version'] ?? null )
		) {
			throw $this->storage_failure();
		}
		if ( null === $release ) {
			return;
		}
		$stored_release = $this->row(
			$this->prepare(
				'SELECT release_row.release_no, release_row.state, release_row.visible_score, release_row.student_projection_schema, release_row.student_projection_version, release_row.student_projection_hash, release_row.release_reason_code, release_row.release_reason_note, release_row.released_at_utc, previous.public_id AS previous_release_id, release_evaluation.public_id AS release_evaluation_id, release_review.public_id AS release_review_id, release_feedback.public_id AS release_feedback_id FROM ' . self::TABLE_RELEASES . ' release_row '
					. 'INNER JOIN ' . self::TABLE_EVALUATIONS . ' release_evaluation ON release_evaluation.id = release_row.submission_evaluation_id '
					. 'INNER JOIN ' . self::TABLE_REVIEWS . ' release_review ON release_review.id = release_row.submission_review_id '
					. 'LEFT JOIN ' . self::TABLE_FEEDBACK . ' release_feedback ON release_feedback.id = release_row.feedback_revision_id '
					. 'INNER JOIN ' . self::TABLE_RELEASES . ' previous ON previous.id = release_row.previous_release_id '
					. 'WHERE release_row.public_id = %s AND release_row.content_hash = %s LIMIT 1',
				array( $release['public_id'], $release['content_hash'] )
			)
		);
		if (
			null === $stored_release
			|| (int) $release['release_no'] !== $this->positive_integer( $stored_release['release_no'] ?? null )
			|| 'stale' !== ( $stored_release['state'] ?? null )
			|| $release['visible_score'] !== $this->nullable_decimal( $stored_release['visible_score'] ?? null )
			|| $release['student_projection_schema'] !== ( $stored_release['student_projection_schema'] ?? null )
			|| (int) $release['student_projection_version'] !== $this->positive_integer( $stored_release['student_projection_version'] ?? null )
			|| $release['student_projection_hash'] !== ( $stored_release['student_projection_hash'] ?? null )
			|| self::STALE_RELEASE_REASON !== ( $stored_release['release_reason_code'] ?? null )
			|| null !== ( $stored_release['release_reason_note'] ?? null )
			|| $release['released_at_utc'] !== ( $stored_release['released_at_utc'] ?? null )
			|| $release['previous_release_public_id'] !== ( $stored_release['previous_release_id'] ?? null )
			|| $release['release_evaluation_public_id'] !== ( $stored_release['release_evaluation_id'] ?? null )
			|| $release['release_review_public_id'] !== ( $stored_release['release_review_id'] ?? null )
			|| $release['release_feedback_public_id'] !== ( $stored_release['release_feedback_id'] ?? null )
		) {
			throw $this->storage_failure();
		}
	}

	/**
	 * Reject invalid record.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_record( array $record ): void {
		$expected = array(
			'actor_user_id',
			'command_id',
			'command_storage_key',
			'request_digest',
			'correlation_id',
			'occurred_at_utc',
			'context_type',
			'context_id',
			'context_revision',
			'evaluation_public_id',
			'expected_evaluation_state',
			'expected_evaluation_lock_version',
			'submission_public_id',
			'submission_revision_public_id',
			'submission_revision_hash',
			'expected_submission_lock_version',
			'expected_result_public_id',
			'expected_result_hash',
			'expected_official_review_public_id',
			'expected_official_review_hash',
			'expected_review_no',
			'expected_current_release_public_id',
			'expected_current_release_hash',
			'expected_current_release_state',
			'expected_previous_review_public_id',
			'review_public_id',
			'stale_release_public_id',
			'review_no',
			'decision',
			'official_score',
			'score_decisions',
			'score_decisions_json',
			'score_decisions_hash',
			'reason',
			'content_hash',
			'audit_public_id',
			'outbox_public_id',
		);
		$this->exact_keys( $record, $expected );
		if (
			! is_int( $record['actor_user_id'] ) || $record['actor_user_id'] < 1
			|| ! is_int( $record['expected_evaluation_lock_version'] ) || $record['expected_evaluation_lock_version'] < 0
			|| ! is_int( $record['expected_submission_lock_version'] ) || $record['expected_submission_lock_version'] < 0
			|| ! is_int( $record['expected_review_no'] ) || $record['expected_review_no'] < 0
			|| ! is_int( $record['review_no'] ) || $record['review_no'] !== $record['expected_review_no'] + 1
			|| ! in_array( $record['expected_evaluation_state'], array( 'review_required', 'reviewed' ), true )
			|| ! in_array( $record['decision'], array( 'accepted', 'adjusted', 'rejected', 'withdrawn' ), true )
		) {
			throw new InvalidArgumentException( 'Submission evaluation review record identity is invalid.' );
		}
		foreach ( array( 'evaluation_public_id', 'submission_public_id', 'submission_revision_public_id', 'expected_result_public_id', 'review_public_id', 'audit_public_id', 'outbox_public_id' ) as $key ) {
			$this->public_id_argument( $record[ $key ] ?? null );
		}
		$this->nullable_public_id_argument( $record['expected_previous_review_public_id'] ?? null );
		$this->nullable_public_id_argument( $record['stale_release_public_id'] ?? null );
		$this->pointer_argument( $record['expected_official_review_public_id'] ?? null, $record['expected_official_review_hash'] ?? null );
		$this->pointer_argument( $record['expected_current_release_public_id'] ?? null, $record['expected_current_release_hash'] ?? null );
		$current_state = $record['expected_current_release_state'];
		$has_current   = null !== $record['expected_current_release_public_id'];
		$should_stale  = 'rejected' !== $record['decision'] && in_array( $current_state, array( 'released', 'corrected' ), true );
		if (
			$has_current !== ( null !== $current_state )
			|| ( null !== $current_state && ! in_array( $current_state, array( 'released', 'corrected', 'withdrawn', 'stale' ), true ) )
			|| $should_stale !== ( null !== $record['stale_release_public_id'] )
		) {
			throw new InvalidArgumentException( 'Submission evaluation release transition is invalid.' );
		}
		foreach ( array( 'submission_revision_hash', 'expected_result_hash', 'request_digest', 'content_hash' ) as $key ) {
			$this->assert_hash_argument( (string) $record[ $key ] );
		}
		$this->assert_storage_key( (string) $record['command_storage_key'] );
		$this->safe_token_argument( $record['command_id'] ?? null, 8, 128 );
		$this->safe_token_argument( $record['correlation_id'] ?? null, 1, 100 );
		$this->context_token_argument( $record['context_type'] ?? null, 16 );
		$this->context_token_argument( $record['context_id'] ?? null, 191 );
		$this->context_token_argument( $record['context_revision'] ?? null, 191 );
		$this->datetime_argument( $record['occurred_at_utc'] ?? null );
		$reason       = $this->nullable_reason_argument( $record['reason'] ?? null );
		$score        = null === $record['official_score'] ? null : $this->decimal_argument( $record['official_score'] );
		$decisions    = $record['score_decisions'];
		$profile_hash = $record['score_decisions_hash'];
		if ( in_array( $record['decision'], array( 'rejected', 'withdrawn' ), true ) ) {
			if ( null !== $score || null !== $decisions || null !== $record['score_decisions_json'] || null !== $profile_hash || null === $reason ) {
				throw new InvalidArgumentException( 'Rejected submission review record is invalid.' );
			}
		} else {
			if ( null === $score || ! is_array( $decisions ) || null === $record['score_decisions_json'] || ! is_string( $profile_hash ) ) {
				throw new InvalidArgumentException( 'Official submission review record is incomplete.' );
			}
			$this->assert_hash_argument( $profile_hash );
			$normalized = $this->score_decisions( $decisions );
			$payload    = $this->decode_canonical_argument( (string) $record['score_decisions_json'], $profile_hash );
			$this->exact_keys( $payload, array( 'schema_id', 'schema_version', 'items' ) );
			if (
				Review_Submission_Evaluation::SCORE_DECISIONS_SCHEMA !== ( $payload['schema_id'] ?? null )
				|| '1.0.0' !== ( $payload['schema_version'] ?? null )
				|| $this->canonical->encode( $normalized ) !== $this->canonical->encode( $payload['items'] ?? null )
				|| ! hash_equals( $score, $this->score_total( $normalized ) )
			) {
				throw new InvalidArgumentException( 'Submission evaluation score decision profile is invalid.' );
			}
		}
		$calculated = $this->review_content_hash(
			(string) $record['evaluation_public_id'],
			(string) $record['expected_result_public_id'],
			(string) $record['expected_result_hash'],
			(int) $record['review_no'],
			(string) $record['decision'],
			$score,
			$profile_hash,
			$reason,
			$record['expected_previous_review_public_id']
		);
		if ( ! hash_equals( (string) $record['content_hash'], $calculated ) ) {
			throw new InvalidArgumentException( 'Submission evaluation review content hash is invalid.' );
		}
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
		if (
			! is_array( $normalized ) || ! array_is_list( $normalized ) || array() === $normalized
			|| count( $normalized ) !== count( $rows ) || count( $normalized ) > Submission_Score_Input_Builder::MAXIMUM_BINDINGS
		) {
			throw $this->stored_invalid( 'result_items (shape or count)' );
		}
		$result = array();
		$seen   = array();
		foreach ( $normalized as $position => $item ) {
			$item = $this->object( $item );
			$this->exact_keys( $item, array( 'binding_key', 'criterion_id', 'response_id', 'scope', 'decision', 'candidate_points', 'maximum_points', 'levels', 'confidence', 'rationale', 'abstain_reason', 'evidence', 'flags', 'position' ) );
			$levels         = $this->stored_levels( $item['levels'] ?? null );
			$row            = $rows[ $position ];
			$binding        = $this->hash_value( $item['binding_key'] ?? null );
			$criterion      = $this->public_id( $item['criterion_id'] ?? null );
			$response       = $this->nullable_public_id( $item['response_id'] ?? null );
			$scope          = $item['scope'] ?? null;
			$decision       = $item['decision'] ?? null;
			$candidate      = $this->nullable_decimal( $item['candidate_points'] ?? null );
			$maximum        = $this->decimal( $item['maximum_points'] ?? null );
			$confidence     = $this->confidence( $item['confidence'] ?? null );
			$rationale      = $this->nullable_text_value( $item['rationale'] ?? null, 1500 );
			$abstain_reason = $this->nullable_token_value( $item['abstain_reason'] ?? null, 64 );
			$flags          = $this->flags_value( $item['flags'] ?? null );
			$stored_flags   = array() === $flags ? null : implode( ',', $flags );
			if (
				isset( $seen[ $binding ] ) || $position !== $this->non_negative_integer( $item['position'] ?? null )
				|| $criterion !== $this->public_id( $row['criterion_key'] ?? null )
				|| $response !== $this->nullable_public_id( $row['question_key'] ?? null )
				|| $position !== $this->non_negative_integer( $row['position'] ?? null )
				|| $candidate !== $this->nullable_decimal( $row['candidate_points'] ?? null )
				|| $maximum !== $this->decimal( $row['maximum_points'] ?? null )
				|| $confidence !== $this->confidence( $row['confidence'] ?? null )
				|| $stored_flags !== ( $row['flags'] ?? null )
				|| ! in_array( $decision, array( 'score', 'abstain' ), true )
				|| ( 'score' === $decision && ( null === $candidate || null === $rationale || null !== $abstain_reason ) )
				|| ( 'abstain' === $decision && ( null !== $candidate || null !== $rationale || null === $abstain_reason ) )
				|| ! in_array( $scope, array( 'response', 'submission' ), true )
				|| ( 'response' === $scope ) !== ( null !== $response )
			) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw $this->stored_invalid( 'result_items[' . $position . '] (field vs stored item column mismatch)' );
			}
			$evidence_hash = $this->hash_value( $row['evidence_hash'] ?? null );
			$evidence      = $this->decode_canonical( (string) ( $row['evidence_json'] ?? '' ), $evidence_hash );
			$this->exact_keys( $evidence, array( 'schema_id', 'schema_version', 'binding_key', 'decision', 'rationale', 'abstain_reason', 'evidence' ) );
			if (
				Submission_Evaluation_Output_Normalizer::ITEM_EVIDENCE_SCHEMA !== ( $row['evidence_schema'] ?? null )
				|| self::PROFILE_VERSION !== $this->positive_integer( $row['evidence_version'] ?? null )
				|| Submission_Evaluation_Output_Normalizer::ITEM_EVIDENCE_SCHEMA !== ( $evidence['schema_id'] ?? null )
				|| Submission_Evaluation_Output_Normalizer::RESULT_VERSION !== ( $evidence['schema_version'] ?? null )
				|| $binding !== ( $evidence['binding_key'] ?? null ) || $decision !== ( $evidence['decision'] ?? null )
				|| $rationale !== ( $evidence['rationale'] ?? null ) || $abstain_reason !== ( $evidence['abstain_reason'] ?? null )
				|| $this->canonical->encode( $item['evidence'] ?? null ) !== $this->canonical->encode( $evidence['evidence'] ?? null )
			) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw $this->stored_invalid( 'result_items[' . $position . '].evidence (stored evidence row vs normalized evidence)' );
			}
			$item_hash = hash(
				'sha256',
				$this->canonical->encode(
					array(
						'candidate_points' => $candidate,
						'confidence'       => $confidence,
						'criterion_key'    => $criterion,
						'evidence_hash'    => $evidence_hash,
						'flags'            => $stored_flags,
						'maximum_points'   => $maximum,
						'position'         => $position,
						'question_key'     => $response,
					)
				)
			);
			if ( ! hash_equals( $item_hash, $this->hash_value( $row['item_content_hash'] ?? null ) ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw $this->stored_invalid( 'result_items[' . $position . '].item_content_hash (content hash mismatch)' );
			}
			$seen[ $binding ] = true;
			$result[]         = array(
				'binding_key'            => $binding,
				'criterion_id'           => $criterion,
				'response_id'            => $response,
				'scope'                  => $scope,
				'position'               => $position,
				'candidate_points'       => $candidate,
				'maximum_points'         => $maximum,
				'levels'                 => $levels,
				'flags'                  => $flags,
				'safety_review_required' => in_array( 'safety_review_required', $flags, true ) || 'safety_review' === $abstain_reason,
			);
		}
		return $result;
	}

	/**
	 * Score profile.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return array{hash:string,items:array<int,array<string,mixed>>}|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function score_profile( array $row ): ?array {
		$values  = array( $row['score_overrides_schema'] ?? null, $row['score_overrides_version'] ?? null, $row['score_overrides_json'] ?? null, $row['score_overrides_hash'] ?? null );
		$present = array_filter( $values, static fn ( $value ): bool => null !== $value );
		if ( array() === $present ) {
			return null;
		}
		if ( 4 !== ( count( $present ) ) ) {
			throw $this->stored_invalid();
		}
		$hash    = $this->hash_value( $row['score_overrides_hash'] ?? null );
		$payload = $this->decode_canonical( (string) $row['score_overrides_json'], $hash );
		$this->exact_keys( $payload, array( 'schema_id', 'schema_version', 'items' ) );
		if (
			Review_Submission_Evaluation::SCORE_DECISIONS_SCHEMA !== ( $row['score_overrides_schema'] ?? null )
			|| Review_Submission_Evaluation::SCORE_DECISIONS_VERSION !== $this->positive_integer( $row['score_overrides_version'] ?? null )
			|| Review_Submission_Evaluation::SCORE_DECISIONS_SCHEMA !== ( $payload['schema_id'] ?? null )
			|| '1.0.0' !== ( $payload['schema_version'] ?? null )
		) {
			throw $this->stored_invalid();
		}
		return array(
			'hash'  => $hash,
			'items' => $this->score_decisions( $payload['items'] ?? null ),
		);
	}

	/**
	 * Score decisions.
	 *
	 * @param mixed $value Value.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function score_decisions( $value ): array {
		if ( ! is_array( $value ) || ! array_is_list( $value ) || array() === $value || count( $value ) > Submission_Score_Input_Builder::MAXIMUM_BINDINGS ) {
			throw new InvalidArgumentException( 'Submission evaluation score decisions are invalid.' );
		}
		$result = array();
		$seen   = array();
		foreach ( $value as $position => $item ) {
			if ( ! is_array( $item ) || array_is_list( $item ) ) {
				throw new InvalidArgumentException( 'Submission evaluation score decision is invalid.' );
			}
			$this->exact_keys( $item, array( 'binding_key', 'criterion_id', 'response_id', 'scope', 'position', 'source', 'official_points', 'maximum_points', 'reason_code', 'reason_note' ) );
			$key         = $this->hash_argument( $item['binding_key'] ?? null );
			$criterion   = $this->public_id_argument( $item['criterion_id'] ?? null );
			$response    = $this->nullable_public_id_argument( $item['response_id'] ?? null );
			$scope       = $item['scope'] ?? null;
			$source      = $item['source'] ?? null;
			$points      = $this->decimal_argument( $item['official_points'] ?? null );
			$maximum     = $this->decimal_argument( $item['maximum_points'] ?? null );
			$reason_code = $item['reason_code'] ?? null;
			$reason_note = $this->nullable_reason_argument( $item['reason_note'] ?? null );
			if (
				isset( $seen[ $key ] ) || $position !== ( $item['position'] ?? null )
				|| ! in_array( $scope, array( 'response', 'submission' ), true ) || ( 'response' === $scope ) !== ( null !== $response )
				|| ! in_array( $source, array( 'ai_accepted', 'teacher_assigned' ), true )
				|| $this->decimal_units( $points ) > $this->decimal_units( $maximum )
				|| ( 'ai_accepted' === $source && ( null !== $reason_code || null !== $reason_note ) )
				|| ( 'teacher_assigned' === $source && ( ! is_string( $reason_code ) || ! in_array( $reason_code, Review_Submission_Evaluation_Command::REASON_CODES, true ) ) )
				|| ( 'other' === $reason_code && null === $reason_note )
			) {
				throw new InvalidArgumentException( 'Submission evaluation score decision evidence is invalid.' );
			}
			$seen[ $key ] = true;
			$result[]     = array(
				'binding_key'     => $key,
				'criterion_id'    => $criterion,
				'response_id'     => $response,
				'scope'           => $scope,
				'position'        => $position,
				'source'          => $source,
				'official_points' => $points,
				'maximum_points'  => $maximum,
				'reason_code'     => $reason_code,
				'reason_note'     => $reason_note,
			);
		}
		return $result;
	}

	/**
	 * Score total.
	 *
	 * @param array<int,array<string,mixed>> $items Items.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function score_total( array $items ): string {
		$units = 0;
		foreach ( $items as $item ) {
			$units += $this->decimal_units( (string) $item['official_points'] );
		}
		if ( $units > 999999999999 ) {
			throw new InvalidArgumentException( 'Submission evaluation official score is too large.' );
		}
		return intdiv( $units, 10000 ) . '.' . str_pad( (string) ( $units % 10000 ), 4, '0', STR_PAD_LEFT );
	}

	/**
	 * Review content hash.
	 *
	 * @param string      $evaluation_id Evaluation id.
	 * @param string      $result_id Result id.
	 * @param string      $result_hash Result hash.
	 * @param int         $review_no Review no.
	 * @param string      $decision Decision.
	 * @param string|null $score Score.
	 * @param string|null $profile_hash Profile hash.
	 * @param string|null $reason Reason.
	 * @param string|null $previous Previous.
	 * @return string
	 */
	private function review_content_hash( string $evaluation_id, string $result_id, string $result_hash, int $review_no, string $decision, ?string $score, ?string $profile_hash, ?string $reason, ?string $previous ): string {
		return hash(
			'sha256',
			$this->canonical->encode(
				array(
					'decision'             => $decision,
					'evaluation_id'        => $evaluation_id,
					'official_score'       => $score,
					'previous_review_id'   => $previous,
					'reason'               => $reason,
					'result_hash'          => $result_hash,
					'result_id'            => $result_id,
					'review_no'            => $review_no,
					'score_decisions_hash' => $profile_hash,
				)
			)
		);
	}

	/**
	 * Release content hash.
	 *
	 * @param string      $evaluation_id Evaluation id.
	 * @param string      $review_id Review id.
	 * @param string|null $feedback_id Feedback id.
	 * @param int         $release_no Release no.
	 * @param string      $state State.
	 * @param string|null $visible_score Visible score.
	 * @param string      $projection_schema Projection schema.
	 * @param int         $projection_version Projection version.
	 * @param string      $projection_hash Projection hash.
	 * @param string|null $previous_release_id Previous release id.
	 * @param string|null $reason_code Reason code.
	 * @param string|null $reason_note Reason note.
	 * @param string      $released_at Released at.
	 * @return string
	 */
	private function release_content_hash(
		string $evaluation_id,
		string $review_id,
		?string $feedback_id,
		int $release_no,
		string $state,
		?string $visible_score,
		string $projection_schema,
		int $projection_version,
		string $projection_hash,
		?string $previous_release_id,
		?string $reason_code,
		?string $reason_note,
		string $released_at
	): string {
		return hash(
			'sha256',
			$this->canonical->encode(
				array(
					'evaluation_id'              => $evaluation_id,
					'feedback_id'                => $feedback_id,
					'previous_release_id'        => $previous_release_id,
					'release_no'                 => $release_no,
					'release_reason_code'        => $reason_code,
					'release_reason_note'        => $reason_note,
					'released_at_utc'            => $released_at,
					'review_id'                  => $review_id,
					'state'                      => $state,
					'student_projection_hash'    => $projection_hash,
					'student_projection_schema'  => $projection_schema,
					'student_projection_version' => $projection_version,
					'visible_score'              => $visible_score,
				)
			)
		);
	}

	/**
	 * Safe state.
	 *
	 * @param array<string,mixed> $snapshot Snapshot.
	 * @return array<string,int|string>
	 */
	private function safe_state( array $snapshot ): array {
		return array(
			'current_evaluation_lock'      => (int) ( $snapshot['evaluation_lock_version'] ?? 0 ),
			'current_evaluation_state'     => (string) ( $snapshot['evaluation_state'] ?? '' ),
			'current_result_id'            => (string) ( $snapshot['result_id'] ?? '' ),
			'current_result_hash'          => (string) ( $snapshot['result_hash'] ?? '' ),
			'current_review_no'            => (int) ( $snapshot['latest_review_no'] ?? 0 ),
			'current_official_review_id'   => (string) ( $snapshot['official_review_id'] ?? '' ),
			'current_official_review_hash' => (string) ( $snapshot['official_review_hash'] ?? '' ),
			'current_release_id'           => (string) ( $snapshot['current_release_id'] ?? '' ),
			'current_release_hash'         => (string) ( $snapshot['current_release_hash'] ?? '' ),
			'current_release_state'        => (string) ( $snapshot['current_release_state'] ?? '' ),
			'current_submission_lock'      => (int) ( $snapshot['submission_lock_version'] ?? 0 ),
		);
	}

	/**
	 * Pointer value.
	 *
	 * @param mixed $id Id.
	 * @param mixed $hash Hash.
	 * @return array{id:?string,hash:?string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function pointer_value( $id, $hash ): array {
		if ( ( null === $id ) !== ( null === $hash ) ) {
			throw $this->stored_invalid();
		}
		return null === $id ? array(
			'id'   => null,
			'hash' => null,
		) : array(
			'id'   => $this->public_id( $id ),
			'hash' => $this->hash_value( $hash ),
		);
	}

	/**
	 * Pointer argument.
	 *
	 * @param mixed $id Id.
	 * @param mixed $hash Hash.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function pointer_argument( $id, $hash ): void {
		if ( ( null === $id ) !== ( null === $hash ) ) {
			throw new InvalidArgumentException( 'Submission evaluation review pointer is partial.' );
		}
		if ( null !== $id ) {
			$this->public_id_argument( $id );
			$this->hash_argument( $hash );
		}
	}

	/**
	 * Decode canonical.
	 *
	 * @param string $json Json.
	 * @param string $expected_hash Expected hash.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function decode_canonical( string $json, string $expected_hash ): array {
		try {
			return $this->decode_canonical_argument( $json, $expected_hash ); } catch ( InvalidArgumentException ) {
			throw $this->stored_invalid();
			}
	}

	/**
	 * Decode canonical argument.
	 *
	 * @param string $json Json.
	 * @param string $expected_hash Expected hash.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function decode_canonical_argument( string $json, string $expected_hash ): array {
		if ( ! hash_equals( $expected_hash, hash( 'sha256', $json ) ) ) {
			throw new InvalidArgumentException( 'Submission evaluation canonical hash is invalid.' );
		}
		try {
			$value = json_decode( $json, true, 128, JSON_THROW_ON_ERROR ); } catch ( JsonException ) {
			throw new InvalidArgumentException( 'Submission evaluation canonical JSON is invalid.' );
			}
			if ( ! is_array( $value ) || array_is_list( $value ) || ! hash_equals( $json, $this->canonical->encode( $value ) ) ) {
				throw new InvalidArgumentException( 'Submission evaluation canonical JSON drifted.' );
			}
			return $value;
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
			throw $this->stored_invalid();
		} return $value;
	}

	/**
	 * Evaluation state.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function evaluation_state( $value ): string {
		if ( ! is_string( $value ) || ! in_array( $value, array( 'review_required', 'reviewed' ), true ) ) {
			throw $this->stored_invalid();
		}
		return $value;
	}

	/**
	 * Review decision.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function review_decision( $value ): string {
		if ( ! is_string( $value ) || ! in_array( $value, array( 'accepted', 'adjusted', 'rejected', 'withdrawn' ), true ) ) {
			throw $this->stored_invalid();
		}
		return $value;
	}

	/**
	 * Release state.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function release_state( $value ): string {
		if ( ! is_string( $value ) || ! in_array( $value, array( 'released', 'corrected', 'withdrawn', 'stale' ), true ) ) {
			throw $this->stored_invalid();
		}
		return $value;
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
			throw $this->stored_invalid();
		}
		try {
			new Public_Id( $value );
		} catch ( InvalidArgumentException ) {
			throw $this->stored_invalid();
		}
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
	 * Public id argument.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function public_id_argument( $value ): string {
		if ( ! is_string( $value ) ) {
			throw new InvalidArgumentException( 'Submission evaluation review public ID is invalid.' );
		} new Public_Id( $value );
		return $value;
	}

	/**
	 * Nullable public id argument.
	 *
	 * @param mixed $value Value.
	 * @return string|null
	 */
	private function nullable_public_id_argument( $value ): ?string {
		return null === $value ? null : $this->public_id_argument( $value );
	}

	/**
	 * Hash value.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function hash_value( $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $value ) ) {
			throw $this->stored_invalid();
		} return $value;
	}

	/**
	 * Hash argument.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function hash_argument( $value ): string {
		if ( ! is_string( $value ) ) {
			throw new InvalidArgumentException( 'Submission evaluation review hash is invalid.' );
		} $this->assert_hash_argument( $value );
		return $value;
	}

	/**
	 * Reject invalid hash argument.
	 *
	 * @param string $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_hash_argument( string $value ): void {
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $value ) ) {
			throw new InvalidArgumentException( 'Submission evaluation review hash is invalid.' );
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
		if ( is_int( $value ) && $value > 0 ) {
			return $value;
		}
		if ( is_string( $value ) && 1 === preg_match( '/^[1-9][0-9]*$/D', $value ) ) {
			return (int) $value;
		}
		throw $this->stored_invalid();
	}

	/**
	 * Non negative integer.
	 *
	 * @param mixed $value Value.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function non_negative_integer( $value ): int {
		if ( is_int( $value ) && $value >= 0 ) {
			return $value;
		}
		if ( is_string( $value ) && 1 === preg_match( '/^(?:0|[1-9][0-9]*)$/D', $value ) ) {
			return (int) $value;
		}
		throw $this->stored_invalid();
	}

	/**
	 * Decimal.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function decimal( $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^(0|[1-9][0-9]{0,7})(?:\.([0-9]{1,4}))?$/D', $value, $m ) ) {
			throw $this->stored_invalid();
		} return $m[1] . '.' . str_pad( $m[2] ?? '', 4, '0' );
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
	 * Decimal argument.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function decimal_argument( $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^(0|[1-9][0-9]{0,7})(?:\.([0-9]{1,4}))?$/D', $value, $m ) ) {
			throw new InvalidArgumentException( 'Submission evaluation review decimal is invalid.' );
		} return $m[1] . '.' . str_pad( $m[2] ?? '', 4, '0' );
	}

	/**
	 * Decimal units.
	 *
	 * @param string $value Value.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function decimal_units( string $value ): int {
		if ( 1 !== preg_match( '/^(0|[1-9][0-9]{0,7})\.([0-9]{4})$/D', $value, $m ) ) {
			throw new InvalidArgumentException( 'Submission evaluation review decimal units are invalid.' );
		} return (int) $m[1] * 10000 + (int) $m[2];
	}

	/**
	 * Stored levels.
	 *
	 * @param mixed $raw Raw.
	 * @return array<int,array<string,mixed>>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function stored_levels( $raw ): ?array {
		if ( null === $raw ) {
			return null;
		}
		if ( ! is_array( $raw ) || ! array_is_list( $raw ) || count( $raw ) < 2 || count( $raw ) > 10 ) {
			throw $this->stored_invalid( 'stored_levels (shape or count)' );
		}
		$result     = array();
		$seen_keys  = array();
		$seen_units = array();
		foreach ( $raw as $position => $level ) {
			$level = $this->object( $level );
			$this->exact_keys( $level, array( 'label', 'level_key', 'points', 'position' ) );
			$id = $this->public_id( $level['level_key'] ?? null );
			if (
				isset( $seen_keys[ $id ] ) || $position !== ( $level['position'] ?? null )
				|| ! is_string( $level['label'] ?? null ) || '' === trim( $level['label'] )
			) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw $this->stored_invalid( 'stored_levels[' . $position . '] (key, position, or label)' );
			}
			$unit = $this->decimal_units( $this->decimal( $level['points'] ?? null ) );
			if ( isset( $seen_units[ $unit ] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw $this->stored_invalid( 'stored_levels[' . $position . '].points (duplicate)' );
			}
			$seen_keys[ $id ]    = true;
			$seen_units[ $unit ] = true;
			$result[]            = array(
				'label'     => $level['label'],
				'level_key' => $id,
				'points'    => $this->decimal( $level['points'] ),
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
		if ( ! is_string( $value ) || 1 !== preg_match( '/^(?:0(?:\.[0-9]{1,4})?|1(?:\.0{1,4})?)$/D', $value ) ) {
			throw $this->stored_invalid();
		} return number_format( (float) $value, 4, '.', '' );
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
			throw $this->stored_invalid();
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
		if ( 0 === $value || '0' === $value ) {
			return false;
		} if ( 1 === $value || '1' === $value ) {
			return true;
		} throw $this->stored_invalid();
	}

	/**
	 * Flags value.
	 *
	 * @param mixed $value Value.
	 * @return array<int,string>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function flags_value( $value ): array {
		if ( ! is_array( $value ) || ! array_is_list( $value ) || count( $value ) > count( Submission_Evaluation_Output_Normalizer::FLAGS ) ) {
			throw $this->stored_invalid();
		}
		$result = array();
		foreach ( $value as $flag ) {
			if ( ! is_string( $flag ) || ! in_array( $flag, Submission_Evaluation_Output_Normalizer::FLAGS, true ) || in_array( $flag, $result, true ) ) {
				throw $this->stored_invalid();
			} $result[] = $flag;
		}
		sort( $result, SORT_STRING );
		return $result;
	}

	/**
	 * Nullable text value.
	 *
	 * @param mixed $value Value.
	 * @param int   $maximum Maximum.
	 * @return string|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function nullable_text_value( $value, int $maximum ): ?string {
		if ( null === $value ) {
			return null;
		} if ( ! is_string( $value ) || '' === trim( $value ) || strlen( $value ) > $maximum ) {
			throw $this->stored_invalid();
		} return $value;
	}

	/**
	 * Nullable token value.
	 *
	 * @param mixed $value Value.
	 * @param int   $maximum Maximum.
	 * @return string|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function nullable_token_value( $value, int $maximum ): ?string {
		if ( null === $value ) {
			return null;
		} if ( ! is_string( $value ) || strlen( $value ) > $maximum || 1 !== preg_match( '/^[a-z][a-z0-9_.-]*$/D', $value ) ) {
			throw $this->stored_invalid();
		} return $value;
	}

	/**
	 * Nullable reason value.
	 *
	 * @param mixed $value Value.
	 * @return string|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function nullable_reason_value( $value ): ?string {
		if ( null === $value ) {
			return null;
		} if ( ! is_string( $value ) || '' === trim( $value ) || strlen( $value ) > 2000 ) {
			throw $this->stored_invalid();
		} return $value;
	}

	/**
	 * Nullable reason argument.
	 *
	 * @param mixed $value Value.
	 * @return string|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function nullable_reason_argument( $value ): ?string {
		if ( null === $value ) {
			return null;
		} if ( ! is_string( $value ) || '' === trim( $value ) || strlen( $value ) > 2000 ) {
			throw new InvalidArgumentException( 'Submission evaluation review reason is invalid.' );
		} return $value;
	}

	/**
	 * Context token.
	 *
	 * @param mixed $value Value.
	 * @param int   $maximum Maximum.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function context_token( $value, int $maximum ): string {
		if ( ! is_string( $value ) || '' === $value || strlen( $value ) > $maximum ) {
			throw $this->stored_invalid();
		} return $value;
	}

	/**
	 * Context token argument.
	 *
	 * @param mixed $value Value.
	 * @param int   $maximum Maximum.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function context_token_argument( $value, int $maximum ): void {
		if ( ! is_string( $value ) || '' === $value || strlen( $value ) > $maximum ) {
			throw new InvalidArgumentException( 'Submission evaluation review Context is invalid.' );
		}
	}

	/**
	 * Datetime value.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function datetime_value( $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value ) ) {
			throw $this->stored_invalid();
		} return $value;
	}

	/**
	 * Datetime argument.
	 *
	 * @param mixed $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function datetime_argument( $value ): void {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value ) ) {
			throw new InvalidArgumentException( 'Submission evaluation review timestamp is invalid.' );
		}
	}

	/**
	 * Reject invalid storage key.
	 *
	 * @param string $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_storage_key( string $value ): void {
		if ( 1 !== preg_match( '/^command:[a-f0-9]{64}$/D', $value ) ) {
			throw new InvalidArgumentException( 'Submission evaluation review storage key is invalid.' );
		}
	}

	/**
	 * Safe token argument.
	 *
	 * @param mixed $value Value.
	 * @param int   $minimum Minimum.
	 * @param int   $maximum Maximum.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function safe_token_argument( $value, int $minimum, int $maximum ): void {
		if ( ! is_string( $value ) || strlen( $value ) < $minimum || strlen( $value ) > $maximum || 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@-]*$/D', $value ) ) {
			throw new InvalidArgumentException( 'Submission evaluation review token is invalid.' );
		}
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
			throw new InvalidArgumentException( 'Submission evaluation review fields drifted.' );
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
			throw $this->storage_failure();
		} return $prepared;
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
		} if ( ! is_array( $row ) || array_is_list( $row ) ) {
			throw $this->storage_failure();
		} return $row;
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
			throw $this->storage_failure();
		} foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || array_is_list( $row ) ) {
				throw $this->storage_failure();
			}
		} return $rows;
	}

	/**
	 * Scalar.
	 *
	 * @param string $sql Sql.
	 * @return mixed
	 */
	private function scalar( string $sql ) {
		$db = $this->database();
		$this->reset_error( $db );
		$value = $db->get_var( $sql );
		$this->assert_read( $db );
		return $value;
	}

	/**
	 * Query or fail.
	 *
	 * @param string $sql Sql.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function query_or_fail( string $sql ): void {
		$db = $this->database();
		$this->reset_error( $db );
		$result = $db->query( $sql );
		if ( false === $result || '' !== trim( (string) ( $db->last_error ?? '' ) ) ) {
			throw $this->storage_failure();
		}
	}

	/**
	 * Change count.
	 *
	 * @param string $sql Sql.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function change_count( string $sql ): int {
		$db = $this->database();
		$this->reset_error( $db );
		$result = $db->query( $sql );
		if ( ! is_int( $result ) || $result < 0 || '' !== trim( (string) ( $db->last_error ?? '' ) ) ) {
			throw $this->storage_failure();
		}
		return $result;
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
		$db = $this->database();
		$this->reset_error( $db );
		$result = $db->insert( $table, $data );
		if ( false === $result || 1 !== (int) $result || '' !== trim( (string) ( $db->last_error ?? '' ) ) ) {
			throw $this->storage_failure();
		}
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
			throw $this->storage_failure();
		}
	}
	/**
	 * Rollback.
	 *
	 * @return void
	 */
	private function rollback(): void {
		try {
			$this->database()->query( 'ROLLBACK' ); } catch ( Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Preserve the documented recovery or optional-operation outcome; the catch body explains the fallback.
			// Best-effort recovery must not replace the primary failure.
 // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Best-effort recovery must not replace the primary failure.
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
		if ( ! is_object( $db ) || ! method_exists( $db, 'prepare' ) || ! method_exists( $db, 'get_row' ) || ! method_exists( $db, 'get_results' ) || ! method_exists( $db, 'get_var' ) || ! method_exists( $db, 'query' ) || ! method_exists( $db, 'insert' ) ) {
			throw $this->storage_failure();
		}
		return $db;
	}

	/**
	 * Stored invalid.
	 *
	 * @param string $path Path.
	 * @return Submission_Evaluation_Failure
	 */
	private function stored_invalid( string $path = '' ): Submission_Evaluation_Failure {
		return Submission_Evaluation_Failure::because(
			Submission_Evaluation_Failure::STORED_DATA_INVALID,
			'' === $path ? array() : array( 'detail' => $path )
		);
	}

	/**
	 * A short, safe label for a stored internal state/enum value — never
	 * arbitrary content. Only a short token-shaped string is echoed as-is;
	 * anything else is described by type only.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function safe_state_label( $value ): string {
		if ( is_string( $value ) && strlen( $value ) <= 64 && 1 === preg_match( '/^[a-z0-9_]*$/D', $value ) ) {
			return '' === $value ? '(empty)' : $value;
		}
		return null === $value ? 'null' : gettype( $value );
	}
	/**
	 * Storage failure.
	 *
	 * @return Submission_Evaluation_Failure
	 */
	private function storage_failure(): Submission_Evaluation_Failure {
		return Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORAGE_UNAVAILABLE );
	}
}
