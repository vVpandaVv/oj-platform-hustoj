<?php
/**
 * Literal-table Q18 and Q19 student-note query adapter.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Infrastructure;

use InvalidArgumentException;
use OJAI\Dashboard\Submission\Assessment\Application\Submission_Evaluation_Failure;
use OJAI\Dashboard\Submission\Assessment\Domain\Student_Note_Output_Normalizer;
use OJAI\Dashboard\Submission\Assessment\Domain\Student_Note_Query_Projector;
use OJAI\Dashboard\Submission\Assessment\Port\Student_Note_Query_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Infrastructure\Signed_Cursor_Codec;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Literal-table Q18 and Q19 student-note query adapter.
 */
final class WordPress_Student_Note_Query implements Student_Note_Query_Port_Interface {
	private const TABLE_NOTES          = 'oj_ai_student_note_revisions';
	private const TABLE_EVALUATIONS    = 'oj_ai_submission_evaluations';
	private const TABLE_REVIEWS        = 'oj_ai_submission_evaluation_reviews';
	private const TABLE_RUNS           = 'oj_ai_evaluation_runs';
	private const TABLE_AUDIT          = 'oj_ai_audit_events';
	private const TABLE_REVISIONS      = 'oj_ai_submission_revisions';
	private const TABLE_SUBMISSIONS    = 'oj_ai_submissions';
	private const TABLE_ASSIGNMENTS    = 'oj_ai_assignments';
	private const TABLE_TARGETS        = 'oj_ai_assignment_targets';
	private const MAXIMUM_HISTORY_ROWS = 501;
	private const CURSOR_KIND          = 'q18';

	/**
	 * Projector.
	 *
	 * @var Student_Note_Query_Projector
	 */
	private Student_Note_Query_Projector $projector;
	/**
	 * Cursors.
	 *
	 * @var Signed_Cursor_Codec
	 */
	private Signed_Cursor_Codec $cursors;

	/**
	 * Initialize WordPress student note query dependencies and state.
	 *
	 * @param Canonical_Json                 $canonical Canonical JSON encoder used for stable digests.
	 * @param Student_Note_Output_Normalizer $normalizer Normalizer.
	 * @param Signed_Cursor_Codec|null       $cursors Cursors.
	 * @param object|null                    $database Database.
	 */
	public function __construct(
		Canonical_Json $canonical,
		Student_Note_Output_Normalizer $normalizer,
		?Signed_Cursor_Codec $cursors = null,
		private ?object $database = null
	) {
		$this->projector = new Student_Note_Query_Projector( $canonical, $normalizer );
		$this->cursors   = $cursors ?? new Signed_Cursor_Codec( $canonical );
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
	 * @param string $report_status Report status.
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
		string $report_status,
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
				$state,
				$report_status
			);
			$cursor_value = $this->cursors->decode( $cursor, self::CURSOR_KIND );
			$sql          = $this->list_select()
				. ' WHERE a.context_type = %s AND a.context_id = %s AND a.context_revision = %s '
				. 'AND target.state = %s AND a.state <> %s '
				. 'AND e.submission_revision_hash = source_revision.content_hash '
				. 'AND e.worksheet_publication_public_id = a.worksheet_publication_public_id '
				. 'AND e.publication_hash = a.publication_hash '
				. 'AND note.revision_no = (SELECT MAX(latest_note.revision_no) FROM ' . self::TABLE_NOTES
				. ' latest_note WHERE latest_note.student_note_public_id = note.student_note_public_id)';
			$values       = array( $context_type, $context_id, $context_revision, 'active', 'withdrawn' );

			if ( '' !== $state ) {
				$sql     .= ' AND note.state = %s';
				$values[] = $state;
			}
			if ( '' !== $assignment_id ) {
				$sql     .= ' AND a.public_id = %s';
				$values[] = $assignment_id;
			}
			if ( 'included' === $report_status ) {
				$sql     .= ' AND note.include_in_reports = %d';
				$values[] = 1;
			} elseif ( 'excluded' === $report_status ) {
				$sql     .= ' AND note.include_in_reports = %d';
				$values[] = 0;
			}
			if ( array() !== $cursor_value ) {
				$this->exact_keys( $cursor_value, array( 'f', 'i', 't' ) );
				if ( ! hash_equals( $scope_hash, (string) ( $cursor_value['f'] ?? '' ) ) ) {
					throw new InvalidArgumentException( 'Student-note cursor scope does not match.' );
				}
				$cursor_id   = (string) ( $cursor_value['i'] ?? '' );
				$cursor_time = (string) ( $cursor_value['t'] ?? '' );
				new Public_Id( $cursor_id );
				$this->assert_utc( $cursor_time );
				$sql     .= ' AND (note.created_at_utc < %s OR (note.created_at_utc = %s AND note.student_note_public_id > %s))';
				$values[] = $cursor_time;
				$values[] = $cursor_time;
				$values[] = $cursor_id;
			}
			$sql     .= ' ORDER BY note.created_at_utc DESC, note.student_note_public_id ASC LIMIT %d';
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
	 * @param string $note_id Note id.
	 * @param string $context_type Context type.
	 * @param string $context_id Context id.
	 * @param string $context_revision Context revision.
	 * @return array|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function find_context(
		string $note_id,
		string $context_type,
		string $context_id,
		string $context_revision
	): ?array {
		try {
			$root = $this->row(
				$this->prepare(
					$this->list_select()
						. ' WHERE note.student_note_public_id = %s AND a.context_type = %s AND a.context_id = %s '
						. 'AND a.context_revision = %s AND target.state = %s AND a.state <> %s '
						. 'AND e.submission_revision_hash = source_revision.content_hash '
						. 'AND e.worksheet_publication_public_id = a.worksheet_publication_public_id '
						. 'AND e.publication_hash = a.publication_hash '
						. 'AND note.revision_no = (SELECT MAX(latest_note.revision_no) FROM ' . self::TABLE_NOTES
						. ' latest_note WHERE latest_note.student_note_public_id = note.student_note_public_id) LIMIT 1',
					array( $note_id, $context_type, $context_id, $context_revision, 'active', 'withdrawn' )
				)
			);
			if ( null === $root ) {
				return null;
			}
			$evaluation_pk = $this->positive_integer( $root['evaluation_pk'] ?? null );
			$evaluation_id = $this->public_id( $root['evaluation_id'] ?? null );

			$revisions = $this->rows(
				$this->prepare(
					'SELECT ' . $this->note_columns() . ' ' . $this->note_joins()
						. ' WHERE note.student_note_public_id = %s AND note.submission_evaluation_id = %d '
						. 'ORDER BY note.revision_no ASC LIMIT %d',
					array( $note_id, $evaluation_pk, self::MAXIMUM_HISTORY_ROWS )
				)
			);
			$audit     = $this->rows(
				$this->prepare(
					'SELECT audit.public_id AS audit_id, audit.occurred_at_utc AS audit_occurred_at_utc, '
						. 'audit.action_key AS audit_action, audit.object_type AS audit_object_type, '
						. 'audit.object_public_id AS audit_object_id, audit.context_type AS audit_context_type, '
						. 'audit.context_id AS audit_context_id, audit.context_revision AS audit_context_revision, '
						. 'audit.before_digest AS audit_before_digest, audit.after_digest AS audit_after_digest, '
						. 'audit.metadata_schema AS audit_metadata_schema, audit.metadata_version AS audit_metadata_version, '
						. 'audit.metadata_json AS audit_metadata_json, audit.metadata_hash AS audit_metadata_hash FROM '
						. self::TABLE_AUDIT . ' audit WHERE audit.object_type = %s AND audit.object_public_id = %s '
						. 'AND audit.context_type = %s AND audit.context_id = %s AND audit.context_revision = %s '
						. 'AND audit.action_key LIKE %s ORDER BY audit.occurred_at_utc ASC, audit.id ASC LIMIT %d',
					array(
						'submission_evaluation',
						$evaluation_id,
						$context_type,
						$context_id,
						$context_revision,
						'student_note.%',
						self::MAXIMUM_HISTORY_ROWS,
					)
				)
			);

			return $this->projector->detail( $root, $revisions, $audit );
		} catch ( InvalidArgumentException | Submission_Evaluation_Failure $failure ) {
			throw $failure;
		} catch ( Throwable ) {
			throw $this->storage_unavailable();
		}
	}

	/**
	 * Authorization snapshot.
	 *
	 * @param string $note_id Note id.
	 * @param string $context_type Context type.
	 * @param string $context_id Context id.
	 * @param string $context_revision Context revision.
	 * @return array|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function authorization_snapshot(
		string $note_id,
		string $context_type,
		string $context_id,
		string $context_revision
	): ?array {
		try {
			$row = $this->row(
				$this->prepare(
					'SELECT note.content_hash, note.state, a.context_type, a.context_id, a.context_revision FROM '
						. self::TABLE_NOTES . ' note INNER JOIN ' . self::TABLE_EVALUATIONS
						. ' e ON e.id = note.submission_evaluation_id INNER JOIN ' . self::TABLE_REVISIONS
						. ' source_revision ON source_revision.public_id = e.submission_revision_public_id INNER JOIN '
						. self::TABLE_SUBMISSIONS . ' submission_row ON submission_row.id = source_revision.submission_id INNER JOIN '
						. self::TABLE_ASSIGNMENTS . ' a ON a.id = submission_row.assignment_id INNER JOIN '
						. self::TABLE_TARGETS . ' target ON target.assignment_id = a.id AND target.learner_user_id = submission_row.learner_user_id '
						. 'WHERE note.student_note_public_id = %s AND a.context_type = %s AND a.context_id = %s '
						. 'AND a.context_revision = %s AND target.state = %s AND a.state <> %s '
						. 'AND e.submission_revision_hash = source_revision.content_hash '
						. 'AND e.worksheet_publication_public_id = a.worksheet_publication_public_id '
						. 'AND e.publication_hash = a.publication_hash '
						. 'AND note.revision_no = (SELECT MAX(latest_note.revision_no) FROM ' . self::TABLE_NOTES
						. ' latest_note WHERE latest_note.student_note_public_id = note.student_note_public_id) LIMIT 1',
					array( $note_id, $context_type, $context_id, $context_revision, 'active', 'withdrawn' )
				)
			);
			if ( null === $row ) {
				$row = $this->row(
					$this->prepare(
						'SELECT e.public_id AS evaluation_id, e.state AS evaluation_state, e.submission_revision_public_id, '
							. 'e.submission_revision_hash, source_revision.content_hash AS source_revision_hash, '
							. 'submission_row.official_revision_public_id, submission_row.official_review_public_id AS official_review_id, '
							. 'submission_row.official_review_hash, review.public_id AS joined_review_id, review.content_hash AS joined_review_hash, '
							. 'review.decision AS review_decision, a.context_type, a.context_id, a.context_revision FROM ' . self::TABLE_EVALUATIONS . ' e '
							. 'INNER JOIN ' . self::TABLE_REVISIONS . ' source_revision ON source_revision.public_id = e.submission_revision_public_id '
							. 'INNER JOIN ' . self::TABLE_SUBMISSIONS . ' submission_row ON submission_row.id = source_revision.submission_id '
							. 'INNER JOIN ' . self::TABLE_ASSIGNMENTS . ' a ON a.id = submission_row.assignment_id '
							. 'INNER JOIN ' . self::TABLE_TARGETS . ' target ON target.assignment_id = a.id AND target.learner_user_id = submission_row.learner_user_id '
							. 'INNER JOIN ' . self::TABLE_REVIEWS . ' review ON review.public_id = submission_row.official_review_public_id '
							. 'AND review.submission_evaluation_id = e.id WHERE e.public_id = %s AND a.context_type = %s AND a.context_id = %s '
							. 'AND a.context_revision = %s AND target.state = %s AND a.state <> %s AND NOT EXISTS '
							. '(SELECT 1 FROM ' . self::TABLE_NOTES . ' existing_note WHERE existing_note.submission_evaluation_id = e.id) LIMIT 1',
						array( $note_id, $context_type, $context_id, $context_revision, 'active', 'withdrawn' )
					)
				);
				if ( null === $row ) {
					return null;
				}
				$evaluation_id          = $this->public_id( $row['evaluation_id'] ?? null );
				$submission_revision_id = $this->public_id( $row['submission_revision_public_id'] ?? null );
				$official_review_id     = $this->public_id( $row['official_review_id'] ?? null );
				$official_review_hash   = $this->sha256( $row['official_review_hash'] ?? null );
				if (
					! hash_equals( $note_id, $evaluation_id )
					|| 'reviewed' !== ( $row['evaluation_state'] ?? null )
					|| ! hash_equals( $this->sha256( $row['submission_revision_hash'] ?? null ), $this->sha256( $row['source_revision_hash'] ?? null ) )
					|| ! hash_equals( $submission_revision_id, $this->public_id( $row['official_revision_public_id'] ?? null ) )
					|| ! hash_equals( $official_review_id, $this->public_id( $row['joined_review_id'] ?? null ) )
					|| ! hash_equals( $official_review_hash, $this->sha256( $row['joined_review_hash'] ?? null ) )
					|| ! in_array( $row['review_decision'] ?? null, array( 'accepted', 'adjusted' ), true )
				) {
					throw $this->stored_invalid();
				}
				return array(
					'context_type'     => $context_type,
					'context_id'       => $context_id,
					'context_revision' => $context_revision,
					'current_revision' => $official_review_hash,
					'state'            => 'reviewed',
					'relation'         => 'context_staff',
				);
			}
			$state = (string) ( $row['state'] ?? '' );
			if ( ! in_array( $state, array( 'ai_draft', 'teacher_draft', 'approved', 'rejected', 'superseded' ), true ) ) {
				throw $this->stored_invalid();
			}
			$current_revision = $this->sha256( $row['content_hash'] ?? null );
			foreach ( array(
				'context_type'     => $context_type,
				'context_id'       => $context_id,
				'context_revision' => $context_revision,
			) as $key => $expected ) {
				if ( ! is_string( $row[ $key ] ?? null ) || ! hash_equals( $expected, (string) $row[ $key ] ) ) {
					throw $this->stored_invalid();
				}
			}
			return array(
				'context_type'     => $context_type,
				'context_id'       => $context_id,
				'context_revision' => $context_revision,
				'current_revision' => $current_revision,
				'state'            => $state,
				'relation'         => 'context_staff',
			);
		} catch ( Submission_Evaluation_Failure $failure ) {
			throw $failure;
		} catch ( InvalidArgumentException ) {
			throw $this->stored_invalid();
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
		return 'SELECT ' . $this->note_columns() . ', e.id AS evaluation_pk, e.public_id AS evaluation_id, '
			. 'e.state AS evaluation_state, e.submission_revision_hash AS evaluation_submission_revision_hash, '
			. 'e.worksheet_publication_public_id AS evaluation_publication_id, e.publication_hash AS evaluation_publication_hash, '
			. 'source_revision.content_hash AS source_revision_content_hash, submission_row.public_id AS submission_id, '
			. 'submission_row.state AS submission_state, submission_row.official_review_public_id AS official_review_id, '
			. 'submission_row.official_review_hash, a.public_id AS assignment_id, a.title_snapshot AS assignment_title, '
			. 'a.state AS assignment_state, a.context_type, a.context_id, a.context_revision, '
			. 'a.worksheet_publication_public_id AS assignment_publication_id, a.publication_hash AS assignment_publication_hash, '
			. 'target.content_hash AS target_content_hash, target.learner_user_id, target.membership_revision, '
			. 'target.display_snapshot_schema, target.display_snapshot_version, target.display_snapshot_json, '
			. 'target.display_snapshot_hash, target.snapshot_hash AS target_snapshot_hash, target.state AS target_state, '
			. 'COALESCE((SELECT COUNT(*) FROM ' . self::TABLE_NOTES . ' counted_note WHERE '
			. 'counted_note.student_note_public_id = note.student_note_public_id), 0) AS revision_count '
			. $this->note_joins() . ' INNER JOIN ' . self::TABLE_EVALUATIONS . ' e ON e.id = note.submission_evaluation_id '
			. 'INNER JOIN ' . self::TABLE_REVISIONS . ' source_revision ON source_revision.public_id = e.submission_revision_public_id '
			. 'INNER JOIN ' . self::TABLE_SUBMISSIONS . ' submission_row ON submission_row.id = source_revision.submission_id '
			. 'INNER JOIN ' . self::TABLE_ASSIGNMENTS . ' a ON a.id = submission_row.assignment_id '
			. 'INNER JOIN ' . self::TABLE_TARGETS . ' target ON target.assignment_id = a.id '
			. 'AND target.learner_user_id = submission_row.learner_user_id';
	}

	/**
	 * Note columns.
	 *
	 * @return string
	 */
	private function note_columns(): string {
		return 'note.id AS note_pk, note.public_id AS note_revision_id, note.content_hash AS note_content_hash, '
			. 'note.created_at_utc AS note_created_at_utc, note.student_note_public_id AS note_root_id, '
			. 'note.submission_evaluation_id AS note_evaluation_pk, note.source_evaluation_run_public_id AS note_source_run_id, '
			. 'note.revision_no AS note_revision_no, note.state AS note_state, note.include_in_reports, '
			. 'note.note_schema, note.note_version, note.note_json, note.note_hash, note.approved_by_user_id, note.approved_at_utc, '
			. 'previous_note.public_id AS previous_note_revision_id, note_review.public_id AS note_review_id, '
			. 'note_review.content_hash AS note_review_hash, note_review.submission_evaluation_id AS note_review_evaluation_pk, '
			. 'source_run.public_id AS joined_source_run_id, source_run.subject_type AS source_run_subject_type, '
			. 'source_run.evaluation_public_id AS source_run_evaluation_id, source_run.operation_key AS source_run_operation_key, '
			. 'source_run.state AS source_run_state, source_run.result_type AS source_run_result_type, '
			. 'source_run.result_public_id AS source_run_result_id';
	}

	/**
	 * Note joins.
	 *
	 * @return string
	 */
	private function note_joins(): string {
		return 'FROM ' . self::TABLE_NOTES . ' note LEFT JOIN ' . self::TABLE_NOTES
			. ' previous_note ON previous_note.id = note.previous_revision_id LEFT JOIN ' . self::TABLE_REVIEWS
			. ' note_review ON note_review.id = note.submission_review_id LEFT JOIN ' . self::TABLE_RUNS
			. ' source_run ON source_run.public_id = note.source_evaluation_run_public_id';
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
	 * @param string $report_status Report status.
	 * @return string
	 */
	private function scope_hash( int $actor, string $type, string $id, string $revision, string $assignment, string $state, string $report_status ): string {
		return substr( hash( 'sha256', implode( "\0", array( (string) $actor, $type, $id, $revision, $assignment, $state, $report_status ) ) ), 0, 32 );
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
			throw new InvalidArgumentException( 'Student-note cursor contains unknown fields.' );
		}
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
			throw new InvalidArgumentException( 'Student-note cursor time is invalid.' );
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
		}
		try {
			new Public_Id( $value );
		} catch ( InvalidArgumentException ) {
			throw $this->stored_invalid();
		}
		return $value;
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
			throw $this->stored_invalid();
		}
		return $value;
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
			throw $this->stored_invalid();
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
	 * Stored invalid.
	 *
	 * @return Submission_Evaluation_Failure
	 */
	private function stored_invalid(): Submission_Evaluation_Failure {
		return Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORED_DATA_INVALID );
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
