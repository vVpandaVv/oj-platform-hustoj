<?php
/**
 * Q33 current student result projection query.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Infrastructure;

use InvalidArgumentException;
use JsonException;
use OJAI\Dashboard\Submission\Assessment\Application\Submission_Evaluation_Failure;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Feedback_Input_Builder;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Result_Projection;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Result_Projection_Builder;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Score_Input_Builder;
use OJAI\Dashboard\Submission\Assessment\Port\Submission_Result_Query_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads a body only after actor ownership and the current release pointer match.
 */
final class WordPress_Submission_Result_Query implements Submission_Result_Query_Port_Interface {
	private const TABLE_RELEASES    = 'oj_ai_submission_release_revisions';
	private const TABLE_EVALUATIONS = 'oj_ai_submission_evaluations';
	private const TABLE_REVISIONS   = 'oj_ai_submission_revisions';
	private const TABLE_SUBMISSIONS = 'oj_ai_submissions';
	private const TABLE_ASSIGNMENTS = 'oj_ai_assignments';
	private const TABLE_TARGETS     = 'oj_ai_assignment_targets';
	private const TABLE_REVIEWS     = 'oj_ai_submission_evaluation_reviews';
	private const TABLE_FEEDBACK    = 'oj_ai_submission_feedback_revisions';

	/**
	 * Initialize WordPress submission result query dependencies and state.
	 *
	 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
	 * @param object|null    $database Database.
	 */
	public function __construct( private Canonical_Json $canonical, private ?object $database = null ) {
	}

	/**
	 * Authorization snapshot.
	 *
	 * @param string $release_id Release id.
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @return array{release_id:string,release_hash:string,release_state:string,submission_id:string,context_type:string,context_id:string,context_revision:string,assignment_state:string,target_state:string}|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function authorization_snapshot( string $release_id, int $actor_user_id ): ?array {
		new Public_Id( $release_id );
		$this->actor_argument( $actor_user_id );
		try {
			$sql = 'SELECT release_row.public_id AS release_id, release_row.content_hash AS release_hash, release_row.state AS release_state, '
				. 'submission_row.public_id AS submission_id, submission_row.current_release_public_id, submission_row.current_release_hash, '
				. 'assignment_row.context_type, assignment_row.context_id, assignment_row.context_revision, assignment_row.state AS assignment_state, '
				. 'target.state AS target_state FROM ' . self::TABLE_RELEASES . ' release_row '
				. 'INNER JOIN ' . self::TABLE_EVALUATIONS . ' evaluation ON evaluation.id = release_row.submission_evaluation_id '
				. 'INNER JOIN ' . self::TABLE_REVISIONS . ' revision_row ON revision_row.public_id = evaluation.submission_revision_public_id '
				. 'INNER JOIN ' . self::TABLE_SUBMISSIONS . ' submission_row ON submission_row.id = revision_row.submission_id '
				. 'INNER JOIN ' . self::TABLE_ASSIGNMENTS . ' assignment_row ON assignment_row.id = submission_row.assignment_id '
				. 'INNER JOIN ' . self::TABLE_TARGETS . ' target ON target.assignment_id = assignment_row.id AND target.learner_user_id = submission_row.learner_user_id '
				. 'WHERE release_row.public_id = %s AND submission_row.learner_user_id = %d AND target.learner_user_id = %d '
				. 'AND revision_row.public_id = submission_row.official_revision_public_id AND revision_row.content_hash = evaluation.submission_revision_hash '
				. "AND (release_row.state NOT IN ('released','corrected') OR (evaluation.state = 'reviewed' AND EXISTS (SELECT 1 FROM " . self::TABLE_REVIEWS . ' current_review WHERE current_review.id = release_row.submission_review_id AND current_review.submission_evaluation_id = evaluation.id AND current_review.public_id = submission_row.official_review_public_id AND current_review.content_hash = submission_row.official_review_hash))) '
				. 'AND submission_row.current_release_public_id = release_row.public_id AND submission_row.current_release_hash = release_row.content_hash LIMIT 1';
			$row = $this->row( $this->prepare( $sql, array( $release_id, $actor_user_id, $actor_user_id ) ) );
			return null === $row ? null : $this->snapshot_from_row( $row, $release_id );
		} catch ( Submission_Evaluation_Failure $failure ) {
			throw $failure;
		} catch ( InvalidArgumentException ) {
			throw $this->stored_invalid();
		} catch ( Throwable ) {
			throw $this->storage_failure();
		}
	}

	/**
	 * Find current for actor.
	 *
	 * @param string                                                                                                                               $release_id Release id.
	 * @param int                                                                                                                                  $actor_user_id Authenticated actor from the access context.
	 * @param array{type:string,id:string,label:string,lifecycle_state:string,revision:string,attributes:array<string,bool|int|float|string|null>} $context Access or execution context for this operation.
	 * @return array<string,mixed>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function find_current_for_actor( string $release_id, int $actor_user_id, array $context ): ?array {
		new Public_Id( $release_id );
		$this->actor_argument( $actor_user_id );
		$this->assert_context_argument( $context );
		try {
			$sql = 'SELECT release_row.public_id AS release_id, release_row.content_hash AS release_hash, release_row.release_no, release_row.state AS release_state, '
				. 'release_row.visible_score, release_row.student_projection_schema, release_row.student_projection_version, release_row.student_projection_json, '
				. 'release_row.student_projection_hash, release_row.previous_release_id AS previous_release_pk, release_row.feedback_revision_id AS release_feedback_pk, '
				. 'release_row.release_reason_code, release_row.release_reason_note, release_row.released_at_utc, '
				. 'evaluation.id AS evaluation_pk, evaluation.public_id AS evaluation_id, review.id AS review_pk, review.public_id AS review_id, '
				. 'feedback.id AS joined_feedback_pk, feedback.public_id AS feedback_id, previous.id AS joined_previous_pk, previous.public_id AS previous_release_id, '
				. 'previous.submission_evaluation_id AS previous_evaluation_pk, submission_row.public_id AS submission_id, '
				. 'previous_evaluation.submission_revision_public_id AS previous_revision_id, evaluation.submission_revision_public_id AS source_revision_id, '
				. 'submission_row.current_release_public_id, submission_row.current_release_hash, assignment_row.context_type, assignment_row.context_id, '
				. 'assignment_row.context_revision, assignment_row.state AS assignment_state, target.state AS target_state, '
				. '(SELECT COUNT(*) FROM ' . self::TABLE_RELEASES . ' counted WHERE counted.submission_evaluation_id = evaluation.id) AS release_count '
				. 'FROM ' . self::TABLE_RELEASES . ' release_row '
				. 'INNER JOIN ' . self::TABLE_EVALUATIONS . ' evaluation ON evaluation.id = release_row.submission_evaluation_id '
				. 'INNER JOIN ' . self::TABLE_REVISIONS . ' revision_row ON revision_row.public_id = evaluation.submission_revision_public_id '
				. 'INNER JOIN ' . self::TABLE_SUBMISSIONS . ' submission_row ON submission_row.id = revision_row.submission_id '
				. 'INNER JOIN ' . self::TABLE_ASSIGNMENTS . ' assignment_row ON assignment_row.id = submission_row.assignment_id '
				. 'INNER JOIN ' . self::TABLE_TARGETS . ' target ON target.assignment_id = assignment_row.id AND target.learner_user_id = submission_row.learner_user_id '
				. 'INNER JOIN ' . self::TABLE_REVIEWS . ' review ON review.id = release_row.submission_review_id AND review.submission_evaluation_id = evaluation.id '
				. 'LEFT JOIN ' . self::TABLE_FEEDBACK . ' feedback ON feedback.id = release_row.feedback_revision_id AND feedback.submission_evaluation_id = evaluation.id AND feedback.submission_review_id = review.id '
				. 'LEFT JOIN ' . self::TABLE_RELEASES . ' previous ON previous.id = release_row.previous_release_id '
				. 'LEFT JOIN ' . self::TABLE_EVALUATIONS . ' previous_evaluation ON previous_evaluation.id = previous.submission_evaluation_id '
				. 'WHERE release_row.public_id = %s AND submission_row.learner_user_id = %d AND target.learner_user_id = %d '
				. 'AND target.state = %s AND assignment_row.state <> %s AND assignment_row.context_type = %s '
				. 'AND assignment_row.context_id = %s AND assignment_row.context_revision = %s '
				. 'AND revision_row.public_id = submission_row.official_revision_public_id AND revision_row.content_hash = evaluation.submission_revision_hash '
				. "AND (release_row.state NOT IN ('released','corrected') OR (evaluation.state = 'reviewed' AND EXISTS (SELECT 1 FROM " . self::TABLE_REVIEWS . ' current_review WHERE current_review.id = release_row.submission_review_id AND current_review.submission_evaluation_id = evaluation.id AND current_review.public_id = submission_row.official_review_public_id AND current_review.content_hash = submission_row.official_review_hash))) '
				. 'AND submission_row.current_release_public_id = release_row.public_id AND submission_row.current_release_hash = release_row.content_hash LIMIT 1';
			$row = $this->row(
				$this->prepare(
					$sql,
					array( $release_id, $actor_user_id, $actor_user_id, 'active', 'withdrawn', $context['type'], $context['id'], $context['revision'] )
				)
			);
			return null === $row ? null : $this->result_from_row( $row, $release_id );
		} catch ( Submission_Evaluation_Failure $failure ) {
			throw $failure;
		} catch ( InvalidArgumentException ) {
			throw $this->stored_invalid();
		} catch ( Throwable ) {
			throw $this->storage_failure();
		}
	}

	/**
	 * Snapshot from row.
	 *
	 * @param array<string,mixed> $row Row.
	 * @param string              $requested_id Requested id.
	 * @return array{release_id:string,release_hash:string,release_state:string,submission_id:string,context_type:string,context_id:string,context_revision:string,assignment_state:string,target_state:string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function snapshot_from_row( array $row, string $requested_id ): array {
		$release_id   = $this->public_id( $row['release_id'] ?? null );
		$release_hash = $this->hash_value( $row['release_hash'] ?? null );
		$state        = $this->release_state( $row['release_state'] ?? null );
		$assignment   = $this->assignment_state( $row['assignment_state'] ?? null );
		$target       = $this->target_state( $row['target_state'] ?? null );
		if (
			! hash_equals( $requested_id, $release_id )
			|| ! hash_equals( $release_id, $this->public_id( $row['current_release_public_id'] ?? null ) )
			|| ! hash_equals( $release_hash, $this->hash_value( $row['current_release_hash'] ?? null ) )
		) {
			throw $this->stored_invalid();
		}
		return array(
			'release_id'       => $release_id,
			'release_hash'     => $release_hash,
			'release_state'    => $state,
			'submission_id'    => $this->public_id( $row['submission_id'] ?? null ),
			'context_type'     => $this->context_type( $row['context_type'] ?? null ),
			'context_id'       => $this->token( $row['context_id'] ?? null, 191 ),
			'context_revision' => $this->token( $row['context_revision'] ?? null, 191 ),
			'assignment_state' => $assignment,
			'target_state'     => $target,
		);
	}

	/**
	 * Result from row.
	 *
	 * @param array<string,mixed> $row Row.
	 * @param string              $requested_id Requested id.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function result_from_row( array $row, string $requested_id ): array {
		$snapshot = $this->snapshot_from_row( $row, $requested_id );
		if ( 'active' !== $snapshot['target_state'] || 'withdrawn' === $snapshot['assignment_state'] ) {
			throw $this->stored_invalid();
		}

		$release_no    = $this->positive_integer( $row['release_no'] ?? null );
		$release_count = $this->positive_integer( $row['release_count'] ?? null );
		$evaluation_pk = $this->positive_integer( $row['evaluation_pk'] ?? null );
		$evaluation_id = $this->public_id( $row['evaluation_id'] ?? null );
		$review_id     = $this->public_id( $row['review_id'] ?? null );
		$this->positive_integer( $row['review_pk'] ?? null );
		$feedback_pk            = $this->optional_positive_integer( $row['release_feedback_pk'] ?? null );
		$joined_feedback_pk     = $this->optional_positive_integer( $row['joined_feedback_pk'] ?? null );
		$feedback_id            = null === $feedback_pk ? null : $this->public_id( $row['feedback_id'] ?? null );
		$previous_pk            = $this->optional_positive_integer( $row['previous_release_pk'] ?? null );
		$joined_previous_pk     = $this->optional_positive_integer( $row['joined_previous_pk'] ?? null );
		$previous_id            = null === $previous_pk ? null : $this->public_id( $row['previous_release_id'] ?? null );
		$previous_evaluation_pk = null === $previous_pk ? null : $this->positive_integer( $row['previous_evaluation_pk'] ?? null );
		if (
			$release_no !== $release_count
			|| ( 'released' === $snapshot['release_state'] ) !== ( null === $previous_pk )
			|| $feedback_pk !== $joined_feedback_pk
			|| $previous_pk !== $joined_previous_pk
			|| ( null !== $previous_evaluation_pk && $evaluation_pk !== $previous_evaluation_pk && $this->public_id( $row['previous_revision_id'] ?? null ) !== $this->public_id( $row['source_revision_id'] ?? null ) )
		) {
			throw $this->stored_invalid();
		}

		$state           = $snapshot['release_state'];
		$visible_score   = null === ( $row['visible_score'] ?? null ) ? null : $this->decimal( $row['visible_score'] );
		$projection_hash = $this->hash_value( $row['student_projection_hash'] ?? null );
		$projection_json = is_string( $row['student_projection_json'] ?? null ) ? $row['student_projection_json'] : '';
		$projection      = $this->decode_object( $projection_json, $projection_hash );
		if (
			Submission_Result_Projection::SCHEMA !== ( $row['student_projection_schema'] ?? null )
			|| Submission_Result_Projection::VERSION !== $this->positive_integer( $row['student_projection_version'] ?? null )
		) {
			throw $this->stored_invalid();
		}
		new Submission_Result_Projection( $projection, $projection_json, $projection_hash, $visible_score );
		$this->assert_projection( $projection, $visible_score );

		$reason_code   = $this->optional_token( $row['release_reason_code'] ?? null, 64 );
		$reason_note   = $this->optional_text( $row['release_reason_note'] ?? null, 4000 );
		$released_at   = $this->datetime( $row['released_at_utc'] ?? null );
		$expected_hash = $this->release_content_hash(
			$evaluation_id,
			$review_id,
			$feedback_id,
			$release_no,
			$state,
			$visible_score,
			$projection_hash,
			$previous_id,
			$reason_code,
			$reason_note,
			$released_at
		);
		if ( ! hash_equals( $snapshot['release_hash'], $expected_hash ) ) {
			throw $this->stored_invalid();
		}

		$projected_release = $projection['release'];
		if (
			in_array( $state, array( 'released', 'corrected' ), true )
			&& ( $state !== $projected_release['state'] || $released_at !== $projected_release['released_at_utc'] )
		) {
			throw $this->stored_invalid();
		}

		return array(
			'hash'            => $snapshot['release_hash'],
			'id'              => $snapshot['release_id'],
			'projection'      => $projection,
			'released_at_utc' => $released_at,
			'state'           => $state,
		);
	}

	/**
	 * Reject invalid projection.
	 *
	 * @param array<string,mixed> $projection Projection.
	 * @param string|null         $visible_score Visible score.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_projection( array $projection, ?string $visible_score ): void {
		$this->exact_keys( $projection, array( 'feedback', 'release', 'schema_id', 'schema_version', 'score', 'worksheet' ) );
		if ( Submission_Result_Projection::SCHEMA !== $projection['schema_id'] || Submission_Result_Projection::SCHEMA_VERSION !== $projection['schema_version'] ) {
			throw $this->stored_invalid();
		}

		$release = $this->object_value( $projection['release'] ?? null );
		$this->exact_keys( $release, array( 'released_at_utc', 'state' ) );
		if ( ! in_array( $release['state'] ?? null, array( 'released', 'corrected' ), true ) ) {
			throw $this->stored_invalid();
		}
		$this->datetime( $release['released_at_utc'] ?? null );

		$worksheet = $this->object_value( $projection['worksheet'] ?? null );
		$this->exact_keys( $worksheet, array( 'title' ) );
		$this->text( $worksheet['title'] ?? null, 255 );

		$score = $this->object_value( $projection['score'] ?? null );
		$this->exact_keys( $score, array( 'criteria', 'total', 'visibility' ) );
		$score_visibility = $score['visibility'] ?? null;
		if ( ! in_array( $score_visibility, Submission_Result_Projection_Builder::SCORE_VISIBILITIES, true ) ) {
			throw $this->stored_invalid();
		}
		$criteria = $this->list_value( $score['criteria'] ?? null, Submission_Score_Input_Builder::MAXIMUM_BINDINGS );
		$total    = null === ( $score['total'] ?? null ) ? null : $this->decimal( $score['total'] );
		if (
			( 'hidden' === $score_visibility && ( null !== $total || array() !== $criteria || null !== $visible_score ) )
			|| ( 'total' === $score_visibility && ( null === $total || array() !== $criteria || $total !== $visible_score ) )
			|| ( 'total_and_criteria' === $score_visibility && ( null === $total || array() === $criteria || $total !== $visible_score ) )
		) {
			throw $this->stored_invalid();
		}
		$total_units = 0;
		foreach ( $criteria as $criterion ) {
			$criterion = $this->object_value( $criterion );
			$this->exact_keys( $criterion, array( 'label', 'maximum_points', 'points' ) );
			$this->text( $criterion['label'] ?? null, 255 );
			$maximum = $this->decimal( $criterion['maximum_points'] ?? null );
			$points  = $this->decimal( $criterion['points'] ?? null );
			if ( $this->decimal_units( $points ) > $this->decimal_units( $maximum ) ) {
				throw $this->stored_invalid();
			}
			$total_units += $this->decimal_units( $points );
		}
		if ( array() !== $criteria && null !== $total && $total_units !== $this->decimal_units( $total ) ) {
			throw $this->stored_invalid();
		}

		$feedback = $this->object_value( $projection['feedback'] ?? null );
		$this->exact_keys( $feedback, array( 'criteria', 'next_steps', 'overall_feedback', 'visibility' ) );
		$feedback_visibility = $feedback['visibility'] ?? null;
		if ( ! in_array( $feedback_visibility, Submission_Result_Projection_Builder::FEEDBACK_VISIBILITIES, true ) ) {
			throw $this->stored_invalid();
		}
		$feedback_items = $this->list_value( $feedback['criteria'] ?? null, Submission_Score_Input_Builder::MAXIMUM_BINDINGS );
		$next_steps     = $this->list_value( $feedback['next_steps'] ?? null, Submission_Feedback_Input_Builder::MAXIMUM_NEXT_STEPS );
		$overall        = $feedback['overall_feedback'] ?? null;
		if ( 'hidden' === $feedback_visibility ) {
			if ( array() !== $feedback_items || array() !== $next_steps || null !== $overall ) {
				throw $this->stored_invalid();
			}
		} else {
			if ( array() === $feedback_items ) {
				throw $this->stored_invalid();
			}
			$this->text( $overall, Submission_Feedback_Input_Builder::MAXIMUM_OVERALL_FEEDBACK_CHARACTERS );
			foreach ( $feedback_items as $item ) {
				$item = $this->object_value( $item );
				$this->exact_keys( $item, array( 'label', 'next_step', 'strength' ) );
				$this->text( $item['label'] ?? null, 255 );
				$next     = $this->text( $item['next_step'] ?? null, Submission_Feedback_Input_Builder::MAXIMUM_CRITERION_FEEDBACK_CHARACTERS );
				$strength = $this->text( $item['strength'] ?? null, Submission_Feedback_Input_Builder::MAXIMUM_CRITERION_FEEDBACK_CHARACTERS );
				if ( $this->length( $next ) + $this->length( $strength ) > Submission_Feedback_Input_Builder::MAXIMUM_CRITERION_FEEDBACK_CHARACTERS ) {
					throw $this->stored_invalid();
				}
			}
			$seen = array();
			foreach ( $next_steps as $step ) {
				$step = $this->text( $step, Submission_Feedback_Input_Builder::MAXIMUM_NEXT_STEP_CHARACTERS );
				if ( isset( $seen[ $step ] ) ) {
					throw $this->stored_invalid();
				}
				$seen[ $step ] = true;
			}
		}
		if ( 'hidden' === $score_visibility && 'hidden' === $feedback_visibility ) {
			throw $this->stored_invalid();
		}
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
	 * @param string      $projection_hash Projection hash.
	 * @param string|null $previous_id Previous id.
	 * @param string|null $reason_code Reason code.
	 * @param string|null $reason_note Reason note.
	 * @param string      $released_at Released at.
	 * @return string
	 */
	private function release_content_hash( string $evaluation_id, string $review_id, ?string $feedback_id, int $release_no, string $state, ?string $visible_score, string $projection_hash, ?string $previous_id, ?string $reason_code, ?string $reason_note, string $released_at ): string {
		return hash(
			'sha256',
			$this->canonical->encode(
				array(
					'evaluation_id'              => $evaluation_id,
					'feedback_id'                => $feedback_id,
					'previous_release_id'        => $previous_id,
					'release_no'                 => $release_no,
					'release_reason_code'        => $reason_code,
					'release_reason_note'        => $reason_note,
					'released_at_utc'            => $released_at,
					'review_id'                  => $review_id,
					'state'                      => $state,
					'student_projection_hash'    => $projection_hash,
					'student_projection_schema'  => Submission_Result_Projection::SCHEMA,
					'student_projection_version' => Submission_Result_Projection::VERSION,
					'visible_score'              => $visible_score,
				)
			)
		);
	}

	/**
	 * Reject invalid context argument.
	 *
	 * @param array<string,mixed> $context Access or execution context for this operation.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_context_argument( array $context ): void {
		$expected = array( 'attributes', 'id', 'label', 'lifecycle_state', 'revision', 'type' );
		$actual   = array_keys( $context );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		$attributes = $context['attributes'] ?? null;
		if ( $actual !== $expected || 'active' !== ( $context['lifecycle_state'] ?? null ) || ! is_array( $attributes ) || ( array() !== $attributes && array_is_list( $attributes ) ) ) {
			throw new InvalidArgumentException( 'Student result Context is invalid.' );
		}
		if ( ! in_array( $context['type'] ?? null, array( 'class', 'group' ), true ) ) {
			throw new InvalidArgumentException( 'Student result Context type is invalid.' );
		}
		foreach ( array( 'id', 'revision' ) as $key ) {
			if ( ! is_string( $context[ $key ] ?? null ) || '' === $context[ $key ] || strlen( $context[ $key ] ) > 191 ) {
				throw new InvalidArgumentException( 'Student result Context value is invalid.' );
			}
		}
	}

	/**
	 * Decode object.
	 *
	 * @param string $json Json.
	 * @param string $hash Hash.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function decode_object( string $json, string $hash ): array {
		if ( ! hash_equals( $hash, hash( 'sha256', $json ) ) || strlen( $json ) > Submission_Result_Projection::MAXIMUM_BYTES ) {
			throw $this->stored_invalid();
		}
		try {
			$decoded = json_decode( $json, true, 128, JSON_THROW_ON_ERROR ); } catch ( JsonException ) {
			throw $this->stored_invalid();
			}
			if ( ! is_array( $decoded ) || array_is_list( $decoded ) || ! hash_equals( $json, $this->canonical->encode( $decoded ) ) ) {
				throw $this->stored_invalid();
			}
			return $decoded;
	}

	/**
	 * Object value.
	 *
	 * @param mixed $value Value.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function object_value( $value ): array {
		if ( ! is_array( $value ) || array_is_list( $value ) ) {
			throw $this->stored_invalid();
		} return $value;
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
			throw $this->stored_invalid();
		} return $value;
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
			throw $this->stored_invalid();
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
			throw $this->stored_invalid();
		} try {
			new Public_Id( $value );
		} catch ( InvalidArgumentException ) {
			throw $this->stored_invalid();
		} return $value;
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
			throw $this->stored_invalid();
		} return $value;
	}
	/**
	 * Optional positive integer.
	 *
	 * @param mixed $value Value.
	 * @return int|null
	 */
	private function optional_positive_integer( $value ): ?int {
		return null === $value ? null : $this->positive_integer( $value );
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
		} return $value;
	}
	/**
	 * Assignment state.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assignment_state( $value ): string {
		if ( ! is_string( $value ) || ! in_array( $value, array( 'scheduled', 'open', 'closed', 'withdrawn' ), true ) ) {
			throw $this->stored_invalid();
		} return $value;
	}
	/**
	 * Target state.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function target_state( $value ): string {
		if ( ! is_string( $value ) || ! in_array( $value, array( 'active', 'revoked' ), true ) ) {
			throw $this->stored_invalid();
		} return $value;
	}
	/**
	 * Context type.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function context_type( $value ): string {
		if ( ! is_string( $value ) || ! in_array( $value, array( 'class', 'group' ), true ) ) {
			throw $this->stored_invalid();
		} return $value;
	}
	/**
	 * Token.
	 *
	 * @param mixed $value Value.
	 * @param int   $maximum Maximum.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function token( $value, int $maximum ): string {
		if ( ! is_string( $value ) || '' === $value || strlen( $value ) > $maximum || 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@\/-]*$/D', $value ) ) {
			throw $this->stored_invalid();
		} return $value;
	}
	/**
	 * Optional token.
	 *
	 * @param mixed $value Value.
	 * @param int   $maximum Maximum.
	 * @return string|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function optional_token( $value, int $maximum ): ?string {
		if ( null === $value ) {
			return null;
		} if ( ! is_string( $value ) || '' === $value || strlen( $value ) > $maximum || 1 !== preg_match( '/^[a-z][a-z0-9_.-]*$/D', $value ) ) {
			throw $this->stored_invalid();
		} return $value;
	}
	/**
	 * Text.
	 *
	 * @param mixed $value Value.
	 * @param int   $maximum Maximum.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function text( $value, int $maximum ): string {
		if ( ! is_string( $value ) ) {
			throw $this->stored_invalid();
		} $value = trim( $value );
		if ( '' === $value || $this->length( $value ) > $maximum || 1 === preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value ) ) {
			throw $this->stored_invalid();
		} return $value;
	}
	/**
	 * Optional text.
	 *
	 * @param mixed $value Value.
	 * @param int   $maximum Maximum.
	 * @return string|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function optional_text( $value, int $maximum ): ?string {
		if ( null === $value ) {
			return null;
		} if ( ! is_string( $value ) || $this->length( $value ) > $maximum || 1 === preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value ) ) {
			throw $this->stored_invalid();
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
		if ( ! is_string( $value ) || 1 !== preg_match( '/^(0|[1-9][0-9]{0,7})(?:\.([0-9]{1,4}))?$/D', $value, $matches ) ) {
			throw $this->stored_invalid();
		} return $matches[1] . '.' . str_pad( $matches[2] ?? '', 4, '0' );
	}
	/**
	 * Decimal units.
	 *
	 * @param string $value Value.
	 * @return int
	 */
	private function decimal_units( string $value ): int {
		[ $whole, $fraction ] = explode( '.', $value );
		return ( (int) $whole * 10000 ) + (int) $fraction;
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
			throw $this->stored_invalid();
		} return $value;
	}
	/**
	 * Length.
	 *
	 * @param string $value Value.
	 * @return int
	 */
	private function length( string $value ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : strlen( $value );
	}
	/**
	 * Actor argument.
	 *
	 * @param int $actor_user_id Authenticated actor from the access context.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function actor_argument( int $actor_user_id ): void {
		if ( $actor_user_id < 1 ) {
			throw new InvalidArgumentException( 'Student result actor is invalid.' );
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
		$database = $this->database();
		$row      = $database->get_row( $sql, defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A' );
		if ( '' !== (string) ( $database->last_error ?? '' ) ) {
			throw $this->storage_failure();
		} if ( null === $row ) {
			return null;
		} if ( ! is_array( $row ) || array_is_list( $row ) ) {
			throw $this->storage_failure();
		} return $row;
	}
	/**
	 * Database.
	 *
	 * @return object
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function database(): object {
		$database = $this->database ?? ( $GLOBALS['wpdb'] ?? null );
		if ( ! is_object( $database ) || ! method_exists( $database, 'prepare' ) || ! method_exists( $database, 'get_row' ) ) {
			throw $this->storage_failure();
		} return $database;
	}
	/**
	 * Storage failure.
	 *
	 * @return Submission_Evaluation_Failure
	 */
	private function storage_failure(): Submission_Evaluation_Failure {
		return Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORAGE_UNAVAILABLE );
	}
	/**
	 * Stored invalid.
	 *
	 * @return Submission_Evaluation_Failure
	 */
	private function stored_invalid(): Submission_Evaluation_Failure {
		return Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORED_DATA_INVALID );
	}
}
