<?php
/**
 * Literal-table C39/C40 student-note revision repository.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Infrastructure;

use InvalidArgumentException;
use JsonException;
use OJAI\Dashboard\Submission\Assessment\Application\Student_Note_Revision_Result;
use OJAI\Dashboard\Submission\Assessment\Application\Submission_Evaluation_Failure;
use OJAI\Dashboard\Submission\Assessment\Domain\Student_Note_Input_Builder;
use OJAI\Dashboard\Submission\Assessment\Domain\Student_Note_Output_Normalizer;
use OJAI\Dashboard\Submission\Assessment\Port\Student_Note_Approval_Repository_Port_Interface;
use OJAI\Dashboard\Submission\Assessment\Port\Student_Note_Approval_Withdrawal_Repository_Port_Interface;
use OJAI\Dashboard\Submission\Assessment\Port\Student_Note_Report_Inclusion_Repository_Port_Interface;
use OJAI\Dashboard\Submission\Assessment\Port\Student_Note_Review_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Literal-table C39/C40 student-note revision repository.
 */
final class WordPress_Student_Note_Review_Repository implements Student_Note_Review_Repository_Port_Interface, Student_Note_Approval_Repository_Port_Interface, Student_Note_Report_Inclusion_Repository_Port_Interface, Student_Note_Approval_Withdrawal_Repository_Port_Interface {
	private const TABLE_EVALUATIONS = 'oj_ai_submission_evaluations';
	private const TABLE_REVISIONS   = 'oj_ai_submission_revisions';
	private const TABLE_SUBMISSIONS = 'oj_ai_submissions';
	private const TABLE_ASSIGNMENTS = 'oj_ai_assignments';
	private const TABLE_TARGETS     = 'oj_ai_assignment_targets';
	private const TABLE_REVIEWS     = 'oj_ai_submission_evaluation_reviews';
	private const TABLE_NOTES       = 'oj_ai_student_note_revisions';
	private const TABLE_RUNS        = 'oj_ai_evaluation_runs';
	private const TABLE_AUDIT       = 'oj_ai_audit_events';
	private const TABLE_OUTBOX      = 'oj_ai_outbox_events';

	private const REVIEW_EVENT         = 'student_note.reviewed';
	private const APPROVAL_EVENT       = 'student_note.approved';
	private const INCLUDE_EVENT        = 'student_note.included_in_reports';
	private const EXCLUDE_EVENT        = 'student_note.excluded_from_reports';
	private const WITHDRAWAL_EVENT     = 'student_note.approval_withdrawn';
	private const OP_REVIEW            = 'review';
	private const OP_APPROVAL          = 'approval';
	private const OP_REPORT            = 'report_inclusion';
	private const OP_WITHDRAW_APPROVAL = 'withdraw_approval';
	private const AUDIT_SCHEMA         = 'oj_ai.student_note_audit';
	private const OUTBOX_SCHEMA        = 'oj_ai.student_note_revision_event';
	private const PROFILE_VERSION      = 1;
	private const STATES               = array( 'ai_draft', 'teacher_draft', 'approved', 'rejected', 'superseded' );

	/**
	 * Initialize WordPress student note review repository dependencies and state.
	 *
	 * @param Canonical_Json                 $canonical Canonical JSON encoder used for stable digests.
	 * @param Student_Note_Output_Normalizer $normalizer Normalizer.
	 * @param object|null                    $database Database.
	 */
	public function __construct(
		private Canonical_Json $canonical,
		private Student_Note_Output_Normalizer $normalizer,
		private ?object $database = null
	) {
	}

	/**
	 * Find review receipt.
	 *
	 * @param string $command_storage_key Command storage key.
	 * @param string $request_digest Request digest.
	 * @return Student_Note_Revision_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function find_review_receipt( string $command_storage_key, string $request_digest ): ?Student_Note_Revision_Result {
		$this->storage_key( $command_storage_key );
		$this->hash_value( $request_digest );
		try {
			return $this->command_receipt( $command_storage_key, $request_digest, false, self::REVIEW_EVENT ); } catch ( InvalidArgumentException ) {
			throw $this->stored_invalid();
			}
	}

	/**
	 * Find approval receipt.
	 *
	 * @param string $command_storage_key Command storage key.
	 * @param string $request_digest Request digest.
	 * @return Student_Note_Revision_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function find_approval_receipt( string $command_storage_key, string $request_digest ): ?Student_Note_Revision_Result {
		$this->storage_key( $command_storage_key );
		$this->hash_value( $request_digest );
		try {
			return $this->command_receipt( $command_storage_key, $request_digest, false, self::APPROVAL_EVENT ); } catch ( InvalidArgumentException ) {
			throw $this->stored_invalid();
			}
	}

	/**
	 * Find report inclusion receipt.
	 *
	 * @param string $command_storage_key Command storage key.
	 * @param string $request_digest Request digest.
	 * @param bool   $target_include_in_reports Target include in reports.
	 * @return Student_Note_Revision_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function find_report_inclusion_receipt( string $command_storage_key, string $request_digest, bool $target_include_in_reports ): ?Student_Note_Revision_Result {
		$this->storage_key( $command_storage_key );
		$this->hash_value( $request_digest );
		try {
			return $this->command_receipt( $command_storage_key, $request_digest, false, $target_include_in_reports ? self::INCLUDE_EVENT : self::EXCLUDE_EVENT ); } catch ( InvalidArgumentException ) {
			throw $this->stored_invalid();
			}
	}

	/**
	 * Find withdrawal receipt.
	 *
	 * @param string $command_storage_key Command storage key.
	 * @param string $request_digest Request digest.
	 * @return Student_Note_Revision_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function find_withdrawal_receipt( string $command_storage_key, string $request_digest ): ?Student_Note_Revision_Result {
		$this->storage_key( $command_storage_key );
		$this->hash_value( $request_digest );
		try {
			return $this->command_receipt( $command_storage_key, $request_digest, false, self::WITHDRAWAL_EVENT ); } catch ( InvalidArgumentException ) {
			throw $this->stored_invalid();
			}
	}

	/**
	 * Find owned review snapshot.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $note_id Note id.
	 * @return array<string,mixed>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function find_owned_review_snapshot( int $actor_user_id, string $note_id ): ?array {
		if ( $actor_user_id < 1 ) {
			throw new InvalidArgumentException( 'Student note review actor is invalid.' );
		}
		new Public_Id( $note_id );
		try {
			return $this->snapshot( $note_id, false ); } catch ( Submission_Evaluation_Failure $failure ) {
			throw $failure;
			} catch ( InvalidArgumentException ) {
				throw $this->stored_invalid();
			} catch ( Throwable ) {
				throw $this->storage_failure();
			}
	}

	/**
	 * Find owned approval snapshot.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $note_id Note id.
	 * @return array<string,mixed>|null
	 */
	public function find_owned_approval_snapshot( int $actor_user_id, string $note_id ): ?array {
		return $this->find_owned_review_snapshot( $actor_user_id, $note_id );
	}

	/**
	 * Find owned report inclusion snapshot.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $note_id Note id.
	 * @return array<string,mixed>|null
	 */
	public function find_owned_report_inclusion_snapshot( int $actor_user_id, string $note_id ): ?array {
		return $this->find_owned_review_snapshot( $actor_user_id, $note_id );
	}

	/**
	 * Find owned withdrawal snapshot.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $note_id Note id.
	 * @return array<string,mixed>|null
	 */
	public function find_owned_withdrawal_snapshot( int $actor_user_id, string $note_id ): ?array {
		return $this->find_owned_review_snapshot( $actor_user_id, $note_id );
	}

	/**
	 * Append review revision.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Student_Note_Revision_Result
	 */
	public function append_review_revision( array $record ): Student_Note_Revision_Result {
		return $this->append_record( $record, self::REVIEW_EVENT, self::OP_REVIEW );
	}

	/**
	 * Append approved revision.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Student_Note_Revision_Result
	 */
	public function append_approved_revision( array $record ): Student_Note_Revision_Result {
		return $this->append_record( $record, self::APPROVAL_EVENT, self::OP_APPROVAL );
	}

	/**
	 * Append report inclusion revision.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Student_Note_Revision_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function append_report_inclusion_revision( array $record ): Student_Note_Revision_Result {
		$target = $record['include_in_reports'] ?? null;
		if ( ! is_bool( $target ) ) {
			throw new InvalidArgumentException( 'Student note report inclusion target is invalid.' );
		}
		return $this->append_record( $record, $target ? self::INCLUDE_EVENT : self::EXCLUDE_EVENT, self::OP_REPORT );
	}

	/**
	 * Append withdrawn revision.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Student_Note_Revision_Result
	 */
	public function append_withdrawn_revision( array $record ): Student_Note_Revision_Result {
		return $this->append_record( $record, self::WITHDRAWAL_EVENT, self::OP_WITHDRAW_APPROVAL );
	}

	/**
	 * Append record.
	 *
	 * @param array<string,mixed> $record Record.
	 * @param string              $event Event.
	 * @param string              $operation Operation.
	 * @return Student_Note_Revision_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function append_record( array $record, string $event, string $operation ): Student_Note_Revision_Result {
		$this->assert_record( $record, $operation );
		$receipt = $this->command_receipt( $record['command_storage_key'], $record['request_digest'], false, $event );
		if ( $receipt instanceof Student_Note_Revision_Result ) {
			return $receipt;
		}

		$transaction = false;
		try {
			$this->query_or_fail( 'START TRANSACTION' );
			$transaction = true;
			$receipt     = $this->command_receipt( $record['command_storage_key'], $record['request_digest'], true, $event );
			if ( $receipt instanceof Student_Note_Revision_Result ) {
				$this->query_or_fail( 'COMMIT' );
				$transaction = false;
				return $receipt;
			}

			$snapshot = $this->snapshot( $record['note_root_id'], true );
			if ( null === $snapshot ) {
				throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::NOT_FOUND );
			}
			$this->assert_locked_source( $snapshot, $record, $operation );
			$approved_by = null;
			$approved_at = null;
			if ( self::OP_APPROVAL === $operation ) {
				$approved_by = $record['actor_user_id'];
				$approved_at = $record['occurred_at_utc'];
			} elseif ( self::OP_REPORT === $operation ) {
				$approved_by = $snapshot['latest_approved_by'];
				$approved_at = $snapshot['latest_approved_at'];
			}
			$this->insert_or_fail(
				self::TABLE_NOTES,
				array(
					'public_id'                       => $record['revision_public_id'],
					'content_hash'                    => $record['content_hash'],
					'created_by_user_id'              => $record['actor_user_id'],
					'created_at_utc'                  => $record['occurred_at_utc'],
					'student_note_public_id'          => $record['note_root_id'],
					'submission_evaluation_id'        => $snapshot['evaluation_pk'],
					'submission_review_id'            => $snapshot['official_review_pk'],
					'source_evaluation_run_public_id' => $record['source_evaluation_run_public_id'],
					'revision_no'                     => $record['revision_no'],
					'state'                           => $record['state'],
					'include_in_reports'              => $record['include_in_reports'] ? 1 : 0,
					'note_schema'                     => $record['note_schema'],
					'note_version'                    => $record['note_version'],
					'note_json'                       => $record['note_json'],
					'note_hash'                       => $record['note_hash'],
					'previous_revision_id'            => $snapshot['latest_revision_pk'],
					'approved_by_user_id'             => $approved_by,
					'approved_at_utc'                 => $approved_at,
				)
			);
			$result = $this->result_from_record( $record, false );
			$this->insert_audit( $record, $event );
			$this->insert_outbox( $record, $result, $event );
			$this->assert_postcondition( $record, $approved_by, $approved_at );
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
			try {
				$recovered = $this->command_receipt( $record['command_storage_key'], $record['request_digest'], false, $event );
				if ( $recovered instanceof Student_Note_Revision_Result ) {
					return $recovered;
				}
			} catch ( Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Preserve the documented recovery or optional-operation outcome; the catch body explains the fallback.
				// This optional step must not replace the surrounding operation outcome.
 // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- This optional step must not replace the surrounding operation outcome.
			}
			throw $this->storage_failure();
		}
	}

	/**
	 * Snapshot.
	 *
	 * @param string $note_id Note id.
	 * @param bool   $for_update For update.
	 * @return array<string,mixed>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function snapshot( string $note_id, bool $for_update ): ?array {
		$sql = 'SELECT e.id AS evaluation_pk, e.public_id AS evaluation_id, e.lock_version AS evaluation_lock_version, e.state AS evaluation_state, e.submission_revision_public_id AS submission_revision_id, e.submission_revision_hash, source_revision.content_hash AS joined_revision_hash, source_revision.state AS revision_state, submission_row.state AS submission_state, submission_row.official_revision_public_id, submission_row.official_review_public_id AS official_review_id, submission_row.official_review_hash, assignment_row.context_type, assignment_row.context_id, assignment_row.context_revision, assignment_row.state AS assignment_state, target.state AS target_state, review.id AS official_review_pk, review.public_id AS joined_review_id, review.content_hash AS joined_review_hash, review.decision AS official_review_decision, (SELECT COUNT(*) FROM ' . self::TABLE_NOTES . ' counted WHERE counted.submission_evaluation_id = e.id) AS note_count, '
			. 'latest.id AS latest_revision_pk, latest.public_id AS latest_revision_id, latest.content_hash AS latest_revision_hash, '
			. 'latest.student_note_public_id AS latest_note_root_id, latest.revision_no AS latest_revision_no, latest.state AS latest_state, '
			. 'latest.include_in_reports AS latest_include_in_reports, latest.note_schema AS latest_note_schema, latest.note_version AS latest_note_version, '
			. 'latest.note_json AS latest_note_json, latest.note_hash AS latest_note_hash, latest.source_evaluation_run_public_id AS latest_source_run_id, '
			. 'latest.approved_by_user_id AS latest_approved_by, latest.approved_at_utc AS latest_approved_at, '
			. 'latest_review.public_id AS latest_review_id, latest_review.content_hash AS latest_review_hash, '
			. 'previous.public_id AS previous_revision_id, previous.content_hash AS previous_revision_hash, previous.revision_no AS previous_revision_no, '
			. 'previous.student_note_public_id AS previous_note_root_id, source_run.public_id AS joined_source_run_id, '
			. 'source_run.subject_type AS source_run_subject_type, source_run.evaluation_public_id AS source_run_evaluation_id, '
			. 'source_run.operation_key AS source_run_operation, source_run.state AS source_run_state, source_run.result_type AS source_run_result_type, '
			. 'source_run.result_public_id AS source_run_result_id FROM ' . self::TABLE_EVALUATIONS . ' e '
			. 'INNER JOIN ' . self::TABLE_REVISIONS . ' source_revision ON source_revision.public_id = e.submission_revision_public_id '
			. 'INNER JOIN ' . self::TABLE_SUBMISSIONS . ' submission_row ON submission_row.id = source_revision.submission_id '
			. 'INNER JOIN ' . self::TABLE_ASSIGNMENTS . ' assignment_row ON assignment_row.id = submission_row.assignment_id '
			. 'INNER JOIN ' . self::TABLE_TARGETS . ' target ON target.assignment_id = assignment_row.id AND target.learner_user_id = submission_row.learner_user_id '
			. 'INNER JOIN ' . self::TABLE_REVIEWS . ' review ON review.public_id = submission_row.official_review_public_id AND review.submission_evaluation_id = e.id '
			. 'LEFT JOIN ' . self::TABLE_NOTES . ' latest ON latest.submission_evaluation_id = e.id AND latest.revision_no = '
			. '(SELECT MAX(latest_no.revision_no) FROM ' . self::TABLE_NOTES . ' latest_no WHERE latest_no.submission_evaluation_id = e.id) '
			. 'LEFT JOIN ' . self::TABLE_REVIEWS . ' latest_review ON latest_review.id = latest.submission_review_id '
			. 'LEFT JOIN ' . self::TABLE_NOTES . ' previous ON previous.id = latest.previous_revision_id '
			. 'LEFT JOIN ' . self::TABLE_RUNS . ' source_run ON source_run.public_id = latest.source_evaluation_run_public_id '
			. 'WHERE (latest.student_note_public_id = %s OR (e.public_id = %s AND NOT EXISTS '
			. '(SELECT 1 FROM ' . self::TABLE_NOTES . ' existing_note WHERE existing_note.submission_evaluation_id = e.id))) '
			. 'AND target.state = %s AND assignment_row.state <> %s LIMIT 1';
		if ( $for_update ) {
			$sql .= ' FOR UPDATE';
		}
		$row = $this->row( $this->prepare( $sql, array( $note_id, $note_id, 'active', 'withdrawn' ) ) );
		if ( null === $row ) {
			return null;
		}

		$evaluation_id            = $this->public_id( $row['evaluation_id'] ?? null );
		$official_review_id       = $this->public_id( $row['official_review_id'] ?? null );
		$official_review_hash     = $this->hash_value( $row['official_review_hash'] ?? null );
		$submission_revision_id   = $this->public_id( $row['submission_revision_id'] ?? null );
		$submission_revision_hash = $this->hash_value( $row['submission_revision_hash'] ?? null );
		if (
			'reviewed' !== ( $row['evaluation_state'] ?? null )
			|| ! hash_equals( $submission_revision_hash, $this->hash_value( $row['joined_revision_hash'] ?? null ) )
			|| ! hash_equals( $submission_revision_id, $this->public_id( $row['official_revision_public_id'] ?? null ) )
			|| ! in_array( $row['revision_state'] ?? null, array( 'saved', 'reopened_fork' ), true )
			|| ! in_array( $row['submission_state'] ?? null, array( 'submitted', 'auto_submitted', 'proxy_submitted', 'locked' ), true )
			|| 'active' !== ( $row['target_state'] ?? null ) || 'withdrawn' === ( $row['assignment_state'] ?? null )
			|| ! hash_equals( $official_review_id, $this->public_id( $row['joined_review_id'] ?? null ) )
			|| ! hash_equals( $official_review_hash, $this->hash_value( $row['joined_review_hash'] ?? null ) )
			|| ! in_array( $row['official_review_decision'] ?? null, array( 'accepted', 'adjusted' ), true )
		) {
			throw $this->stored_invalid();
		}

		$count   = $this->non_negative_integer( $row['note_count'] ?? null );
		$latest  = $this->latest_note( $row, $evaluation_id, $official_review_id, $official_review_hash, $count );
		$root_id = 0 === $count ? $evaluation_id : $latest['root_id'];
		if ( ! hash_equals( $note_id, $root_id ) ) {
			throw $this->stored_invalid();
		}
		return array(
			'note_root_id'              => $root_id,
			'evaluation_pk'             => $this->positive_integer( $row['evaluation_pk'] ?? null ),
			'evaluation_id'             => $evaluation_id,
			'evaluation_lock_version'   => $this->non_negative_integer( $row['evaluation_lock_version'] ?? null ),
			'evaluation_state'          => 'reviewed',
			'context_type'              => $this->token( $row['context_type'] ?? null, 16 ),
			'context_id'                => $this->token( $row['context_id'] ?? null, 191, true ),
			'context_revision'          => $this->token( $row['context_revision'] ?? null, 191, true ),
			'official_review_pk'        => $this->positive_integer( $row['official_review_pk'] ?? null ),
			'official_review_id'        => $official_review_id,
			'official_review_hash'      => $official_review_hash,
			'latest_revision_pk'        => $latest['pk'],
			'latest_revision_id'        => $latest['id'],
			'latest_revision_hash'      => $latest['hash'],
			'latest_revision_no'        => $count,
			'latest_state'              => $latest['state'],
			'latest_include_in_reports' => $latest['include_in_reports'],
			'latest_source_run_id'      => $latest['source_run_id'],
			'latest_note_json'          => $latest['json'],
			'latest_note_hash'          => $latest['note_hash'],
			'latest_approved_by'        => $latest['approved_by'],
			'latest_approved_at'        => $latest['approved_at'],
		);
	}

	/**
	 * Latest note.
	 *
	 * @param array<string,mixed> $row Row.
	 * @param string              $evaluation_id Evaluation id.
	 * @param string              $review_id Review id.
	 * @param string              $review_hash Review hash.
	 * @param int                 $count Count.
	 * @return array{pk:?int,id:?string,hash:?string,root_id:?string,state:?string,include_in_reports:bool,source_run_id:?string,json:?string,note_hash:?string,approved_by:?int,approved_at:?string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function latest_note( array $row, string $evaluation_id, string $review_id, string $review_hash, int $count ): array {
		$nullable = array( 'latest_revision_pk', 'latest_revision_id', 'latest_revision_hash', 'latest_note_root_id', 'latest_revision_no', 'latest_state', 'latest_include_in_reports', 'latest_note_schema', 'latest_note_version', 'latest_note_json', 'latest_note_hash', 'latest_source_run_id', 'latest_approved_by', 'latest_approved_at', 'latest_review_id', 'latest_review_hash', 'previous_revision_id', 'previous_revision_hash', 'previous_revision_no', 'previous_note_root_id', 'joined_source_run_id', 'source_run_subject_type', 'source_run_evaluation_id', 'source_run_operation', 'source_run_state', 'source_run_result_type', 'source_run_result_id' );
		if ( 0 === $count ) {
			foreach ( $nullable as $key ) {
				if ( null !== ( $row[ $key ] ?? null ) ) {
					throw $this->stored_invalid();
				}
			}
			return array(
				'pk'                 => null,
				'id'                 => null,
				'hash'               => null,
				'root_id'            => null,
				'state'              => null,
				'include_in_reports' => false,
				'source_run_id'      => null,
				'json'               => null,
				'note_hash'          => null,
				'approved_by'        => null,
				'approved_at'        => null,
			);
		}

		$pk                 = $this->positive_integer( $row['latest_revision_pk'] ?? null );
		$id                 = $this->public_id( $row['latest_revision_id'] ?? null );
		$content_hash       = $this->hash_value( $row['latest_revision_hash'] ?? null );
		$root_id            = $this->public_id( $row['latest_note_root_id'] ?? null );
		$revision_no        = $this->positive_integer( $row['latest_revision_no'] ?? null );
		$state              = $row['latest_state'] ?? null;
		$include            = $this->database_boolean( $row['latest_include_in_reports'] ?? null );
		$latest_review_id   = $this->public_id( $row['latest_review_id'] ?? null );
		$latest_review_hash = $this->hash_value( $row['latest_review_hash'] ?? null );
		$previous_id        = null === ( $row['previous_revision_id'] ?? null ) ? null : $this->public_id( $row['previous_revision_id'] );
		$previous_hash      = null === ( $row['previous_revision_hash'] ?? null ) ? null : $this->hash_value( $row['previous_revision_hash'] );
		$previous_no        = null === ( $row['previous_revision_no'] ?? null ) ? null : $this->positive_integer( $row['previous_revision_no'] );
		$previous_root      = null === ( $row['previous_note_root_id'] ?? null ) ? null : $this->public_id( $row['previous_note_root_id'] );
		if (
			$count !== $revision_no || ! in_array( $state, self::STATES, true ) || ( $include && 'approved' !== $state )
			|| ! hash_equals( $review_id, $latest_review_id ) || ! hash_equals( $review_hash, $latest_review_hash )
			|| ( 1 === $count ) !== ( null === $previous_id ) || ( 1 === $count ) !== ( null === $previous_hash )
			|| ( 1 === $count ) !== ( null === $previous_no ) || ( 1 === $count ) !== ( null === $previous_root )
			|| ( $count > 1 && ( $count - 1 !== $previous_no || ! hash_equals( $root_id, (string) $previous_root ) ) )
		) {
			throw $this->stored_invalid();
		}

		$approved_by = null === ( $row['latest_approved_by'] ?? null ) ? null : $this->positive_integer( $row['latest_approved_by'] );
		$approved_at = null === ( $row['latest_approved_at'] ?? null ) ? null : $this->datetime( $row['latest_approved_at'] );
		if ( ( null === $approved_by ) !== ( null === $approved_at ) || ( 'approved' === $state && null === $approved_at ) || ( in_array( $state, array( 'ai_draft', 'teacher_draft', 'rejected' ), true ) && null !== $approved_at ) ) {
			throw $this->stored_invalid();
		}
		if ( Student_Note_Input_Builder::RESULT_SCHEMA !== ( $row['latest_note_schema'] ?? null ) || 1 !== $this->positive_integer( $row['latest_note_version'] ?? null ) ) {
			throw $this->stored_invalid();
		}
		$note_hash = $this->hash_value( $row['latest_note_hash'] ?? null );
		$json      = is_string( $row['latest_note_json'] ?? null ) ? $row['latest_note_json'] : '';
		$profile   = $this->decode_object( $json, $note_hash );
		try {
			$profile = $this->normalizer->validate_stored_profile( $profile ); } catch ( InvalidArgumentException ) {
			throw $this->stored_invalid();
			}
			if ( ! hash_equals( $json, $this->canonical->encode( $profile ) ) || ! hash_equals( $review_hash, $profile['source_hash'] ) ) {
				throw $this->stored_invalid();
			}

			$source_run_id = null === ( $row['latest_source_run_id'] ?? null ) ? null : $this->public_id( $row['latest_source_run_id'] );
			if ( null === $source_run_id ) {
				foreach ( array( 'joined_source_run_id', 'source_run_subject_type', 'source_run_evaluation_id', 'source_run_operation', 'source_run_state', 'source_run_result_type', 'source_run_result_id' ) as $key ) {
					if ( null !== ( $row[ $key ] ?? null ) ) {
								throw $this->stored_invalid();
					}
				}
			} else {
				if (
				! hash_equals( $source_run_id, $this->public_id( $row['joined_source_run_id'] ?? null ) )
				|| 'submission_evaluation' !== ( $row['source_run_subject_type'] ?? null )
				|| ! hash_equals( $evaluation_id, $this->public_id( $row['source_run_evaluation_id'] ?? null ) )
				|| Student_Note_Input_Builder::OPERATION !== ( $row['source_run_operation'] ?? null )
				|| 'succeeded' !== ( $row['source_run_state'] ?? null ) || 'student_note_revision' !== ( $row['source_run_result_type'] ?? null )
				) {
					throw $this->stored_invalid();
				}
				$result_id = $this->public_id( $row['source_run_result_id'] ?? null );
				if ( 'ai_draft' === $state && ! hash_equals( $id, $result_id ) ) {
					throw $this->stored_invalid();
				}
			}
			if ( 'ai_draft' === $state && null === $source_run_id ) {
				throw $this->stored_invalid();
			}

			$expected_hash = hash(
				'sha256',
				$this->canonical->encode(
					array(
						'evaluation_id'        => $evaluation_id,
						'include_in_reports'   => $include,
						'note_hash'            => $note_hash,
						'note_root_id'         => $root_id,
						'previous_revision_id' => $previous_id,
						'review_id'            => $review_id,
						'revision_no'          => $revision_no,
						'source_run_id'        => $source_run_id,
						'state'                => $state,
					)
				)
			);
		if ( ! hash_equals( $content_hash, $expected_hash ) ) {
			throw $this->stored_invalid();
		}
		return array(
			'pk'                 => $pk,
			'id'                 => $id,
			'hash'               => $content_hash,
			'root_id'            => $root_id,
			'state'              => $state,
			'include_in_reports' => $include,
			'source_run_id'      => $source_run_id,
			'json'               => $json,
			'note_hash'          => $note_hash,
			'approved_by'        => $approved_by,
			'approved_at'        => $approved_at,
		);
	}

	/**
	 * Reject invalid locked source.
	 *
	 * @param array<string,mixed> $snapshot Snapshot.
	 * @param array<string,mixed> $record Record.
	 * @param string              $operation Operation.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_locked_source( array $snapshot, array $record, string $operation ): void {
		if (
			! hash_equals( $record['note_root_id'], $snapshot['note_root_id'] )
			|| ! hash_equals( $record['evaluation_public_id'], $snapshot['evaluation_id'] )
			|| $record['expected_evaluation_lock_version'] !== $snapshot['evaluation_lock_version']
			|| $record['expected_evaluation_state'] !== $snapshot['evaluation_state']
			|| ! hash_equals( $record['context_type'], $snapshot['context_type'] ) || ! hash_equals( $record['context_id'], $snapshot['context_id'] ) || ! hash_equals( $record['context_revision'], $snapshot['context_revision'] )
			|| ! hash_equals( $record['expected_official_review_id'], $snapshot['official_review_id'] ) || ! hash_equals( $record['expected_official_review_hash'], $snapshot['official_review_hash'] )
			|| $record['expected_revision_id'] !== $snapshot['latest_revision_id'] || $record['expected_revision_hash'] !== $snapshot['latest_revision_hash']
			|| $record['expected_revision_no'] !== $snapshot['latest_revision_no'] || $record['expected_note_state'] !== $snapshot['latest_state']
			|| $record['expected_include_in_reports'] !== $snapshot['latest_include_in_reports'] || $record['expected_source_run_id'] !== $snapshot['latest_source_run_id']
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STUDENT_NOTE_SOURCE_STALE, $this->safe_state( $snapshot ) );
		}
		if ( self::OP_REPORT === $operation ) {
			if (
				'approved' !== $snapshot['latest_state']
				|| $snapshot['latest_include_in_reports'] === $record['include_in_reports']
				|| ! hash_equals( $record['note_json'], (string) $snapshot['latest_note_json'] )
				|| ! hash_equals( $record['note_hash'], (string) $snapshot['latest_note_hash'] )
			) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STUDENT_NOTE_SOURCE_STALE, $this->safe_state( $snapshot ) );
			}
			return;
		}
		if ( $snapshot['latest_include_in_reports'] ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STUDENT_NOTE_SOURCE_STALE, $this->safe_state( $snapshot ) );
		}
		if ( 'edit' === $record['source_mode'] && ! in_array( $snapshot['latest_state'], array( 'ai_draft', 'teacher_draft' ), true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STUDENT_NOTE_SOURCE_STALE, $this->safe_state( $snapshot ) );
		}
		if ( 'direct' === $record['source_mode'] && null !== $snapshot['latest_revision_id'] && ! in_array( $snapshot['latest_state'], array( 'ai_draft', 'teacher_draft', 'rejected', 'superseded' ), true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STUDENT_NOTE_SOURCE_STALE, $this->safe_state( $snapshot ) );
		}
		if ( self::OP_APPROVAL === $operation && ( 'teacher_draft' !== $snapshot['latest_state'] || ! hash_equals( $record['note_json'], (string) $snapshot['latest_note_json'] ) || ! hash_equals( $record['note_hash'], (string) $snapshot['latest_note_hash'] ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STUDENT_NOTE_SOURCE_STALE, $this->safe_state( $snapshot ) );
		}
		if ( self::OP_WITHDRAW_APPROVAL === $operation && ( 'approved' !== $snapshot['latest_state'] || ! hash_equals( $record['note_json'], (string) $snapshot['latest_note_json'] ) || ! hash_equals( $record['note_hash'], (string) $snapshot['latest_note_hash'] ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STUDENT_NOTE_SOURCE_STALE, $this->safe_state( $snapshot ) );
		}
	}

	/**
	 * Insert audit.
	 *
	 * @param array<string,mixed> $record Record.
	 * @param string              $event Event.
	 * @return void
	 */
	private function insert_audit( array $record, string $event ): void {
		$metadata            = array(
			'command_id'                 => $record['command_id'],
			'include_in_reports'         => $record['include_in_reports'],
			'official_review_hash'       => $record['expected_official_review_hash'],
			'official_review_id'         => $record['expected_official_review_id'],
			'previous_revision_id'       => $record['previous_revision_id'],
			'reason_digest'              => $record['reason_digest'],
			'request_digest'             => $record['request_digest'],
			'source_mode'                => $record['source_mode'],
			'source_run_id'              => $record['source_evaluation_run_public_id'],
			'state'                      => $record['state'],
			'student_note_revision_hash' => $record['content_hash'],
			'student_note_revision_id'   => $record['revision_public_id'],
			'student_note_revision_no'   => $record['revision_no'],
		);
		$json                = $this->canonical->encode( $metadata );
		$metadata_hash       = hash( 'sha256', $json );
		$previous_event_hash = $this->scalar( 'SELECT event_hash FROM ' . self::TABLE_AUDIT . ' ORDER BY id DESC LIMIT 1 FOR UPDATE' );
		if ( null !== $previous_event_hash ) {
			$previous_event_hash = $this->hash_value( $previous_event_hash );
		}
		$event_hash = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'action_key'          => $event,
					'actor_user_id'       => $record['actor_user_id'],
					'after_digest'        => $record['content_hash'],
					'before_digest'       => $record['expected_revision_hash'],
					'correlation_id'      => $record['correlation_id'],
					'metadata_hash'       => $metadata_hash,
					'object_public_id'    => $record['evaluation_public_id'],
					'object_type'         => 'submission_evaluation',
					'occurred_at_utc'     => $record['occurred_at_utc'],
					'previous_event_hash' => $previous_event_hash,
				)
			)
		);
		$this->insert_or_fail(
			self::TABLE_AUDIT,
			array(
				'public_id'           => $record['audit_public_id'],
				'occurred_at_utc'     => $record['occurred_at_utc'],
				'actor_user_id'       => $record['actor_user_id'],
				'action_key'          => $event,
				'object_type'         => 'submission_evaluation',
				'object_public_id'    => $record['evaluation_public_id'],
				'context_type'        => $record['context_type'],
				'context_id'          => $record['context_id'],
				'context_revision'    => $record['context_revision'],
				'correlation_id'      => $record['correlation_id'],
				'before_digest'       => $record['expected_revision_hash'],
				'after_digest'        => $record['content_hash'],
				'metadata_schema'     => self::AUDIT_SCHEMA,
				'metadata_version'    => self::PROFILE_VERSION,
				'metadata_json'       => $json,
				'metadata_hash'       => $metadata_hash,
				'previous_event_hash' => $previous_event_hash,
				'event_hash'          => $event_hash,
			)
		);
	}

	/**
	 * Insert outbox.
	 *
	 * @param array<string,mixed>          $record Record.
	 * @param Student_Note_Revision_Result $result Result.
	 * @param string                       $event Event.
	 * @return void
	 */
	private function insert_outbox( array $record, Student_Note_Revision_Result $result, string $event ): void {
		$payload = $this->receipt_payload( $result, $record['request_digest'] );
		$json    = $this->canonical->encode( $payload );
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
				'event_type'           => $event,
				'idempotency_key'      => $record['command_storage_key'],
				'payload_schema'       => self::OUTBOX_SCHEMA,
				'payload_version'      => self::PROFILE_VERSION,
				'payload_json'         => $json,
				'payload_hash'         => hash( 'sha256', $json ),
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
	 * @param string $key Key.
	 * @param string $digest Digest.
	 * @param bool   $for_update For update.
	 * @param string $event Event.
	 * @return Student_Note_Revision_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function command_receipt( string $key, string $digest, bool $for_update, string $event ): ?Student_Note_Revision_Result {
		$sql = 'SELECT payload_json, payload_hash FROM ' . self::TABLE_OUTBOX . ' WHERE idempotency_key = %s AND event_type = %s LIMIT 1';
		if ( $for_update ) {
			$sql .= ' FOR UPDATE';
		}
		$row = $this->row( $this->prepare( $sql, array( $key, $event ) ) );
		if ( null === $row ) {
			return null;
		}
		$payload = $this->decode_object( $row['payload_json'] ?? null, $this->hash_value( $row['payload_hash'] ?? null ) );
		if ( ! hash_equals( $digest, (string) ( $payload['request_digest'] ?? '' ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::IDEMPOTENCY_CONFLICT );
		}
		return $this->result_from_payload( $payload, true );
	}

	/**
	 * Result from record.
	 *
	 * @param array<string,mixed> $record Record.
	 * @param bool                $replayed Replayed.
	 * @return Student_Note_Revision_Result
	 */
	private function result_from_record( array $record, bool $replayed ): Student_Note_Revision_Result {
		return new Student_Note_Revision_Result( $record['note_root_id'], $record['evaluation_public_id'], $record['expected_official_review_id'], $record['expected_official_review_hash'], $record['revision_public_id'], $record['content_hash'], $record['revision_no'], $record['state'], $record['include_in_reports'], $record['previous_revision_id'], $record['source_evaluation_run_public_id'], $record['occurred_at_utc'], $replayed );
	}

	/**
	 * Result from payload.
	 *
	 * @param array<string,mixed> $payload Input payload at this boundary; see the declared shape.
	 * @param bool                $replayed Replayed.
	 * @return Student_Note_Revision_Result
	 */
	private function result_from_payload( array $payload, bool $replayed ): Student_Note_Revision_Result {
		$this->exact_keys( $payload, array( 'evaluation_id', 'include_in_reports', 'note_id', 'occurred_at_utc', 'official_review_hash', 'official_review_id', 'previous_revision_id', 'request_digest', 'revision_hash', 'revision_id', 'revision_no', 'source_evaluation_run_id', 'state' ) );
		return new Student_Note_Revision_Result( $this->public_id( $payload['note_id'] ?? null ), $this->public_id( $payload['evaluation_id'] ?? null ), $this->public_id( $payload['official_review_id'] ?? null ), $this->hash_value( $payload['official_review_hash'] ?? null ), $this->public_id( $payload['revision_id'] ?? null ), $this->hash_value( $payload['revision_hash'] ?? null ), $this->positive_integer( $payload['revision_no'] ?? null ), (string) ( $payload['state'] ?? '' ), $this->boolean( $payload['include_in_reports'] ?? null ), null === ( $payload['previous_revision_id'] ?? null ) ? null : $this->public_id( $payload['previous_revision_id'] ), null === ( $payload['source_evaluation_run_id'] ?? null ) ? null : $this->public_id( $payload['source_evaluation_run_id'] ), $this->datetime( $payload['occurred_at_utc'] ?? null ), $replayed );
	}

	/**
	 * Receipt payload.
	 *
	 * @param Student_Note_Revision_Result $result Result.
	 * @param string                       $request_digest Request digest.
	 * @return array<string,mixed>
	 */
	private function receipt_payload( Student_Note_Revision_Result $result, string $request_digest ): array {
		return array(
			'evaluation_id'            => $result->evaluation_id(),
			'include_in_reports'       => $result->include_in_reports(),
			'note_id'                  => $result->note_id(),
			'occurred_at_utc'          => $result->occurred_at_utc(),
			'official_review_hash'     => $result->official_review_hash(),
			'official_review_id'       => $result->official_review_id(),
			'previous_revision_id'     => $result->previous_revision_id(),
			'request_digest'           => $request_digest,
			'revision_hash'            => $result->revision_hash(),
			'revision_id'              => $result->revision_id(),
			'revision_no'              => $result->revision_no(),
			'source_evaluation_run_id' => $result->source_evaluation_run_id(),
			'state'                    => $result->state(),
		);
	}

	/**
	 * Reject invalid postcondition.
	 *
	 * @param array<string,mixed> $record Record.
	 * @param int|null            $approved_by Approved by.
	 * @param string|null         $approved_at Approved at.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_postcondition( array $record, ?int $approved_by, ?string $approved_at ): void {
		$row                = $this->row( $this->prepare( 'SELECT note.revision_no, note.state, note.content_hash, note.student_note_public_id, note.include_in_reports, note.note_schema, note.note_version, note.note_hash, note.source_evaluation_run_public_id, note.approved_by_user_id, note.approved_at_utc, review.public_id AS review_id, previous.public_id AS previous_id, (SELECT COUNT(*) FROM ' . self::TABLE_NOTES . ' counted WHERE counted.submission_evaluation_id = note.submission_evaluation_id) AS note_count FROM ' . self::TABLE_NOTES . ' note INNER JOIN ' . self::TABLE_REVIEWS . ' review ON review.id = note.submission_review_id LEFT JOIN ' . self::TABLE_NOTES . ' previous ON previous.id = note.previous_revision_id WHERE note.public_id = %s LIMIT 1', array( $record['revision_public_id'] ) ) );
		$stored_approved_by = null === ( $row['approved_by_user_id'] ?? null ) ? null : $this->positive_integer( $row['approved_by_user_id'] );
		$stored_approved_at = null === ( $row['approved_at_utc'] ?? null ) ? null : $this->datetime( $row['approved_at_utc'] );
		if ( null === $row || $record['revision_no'] !== $this->positive_integer( $row['revision_no'] ?? null ) || $record['revision_no'] !== $this->positive_integer( $row['note_count'] ?? null ) || $record['state'] !== ( $row['state'] ?? null ) || ! hash_equals( $record['content_hash'], (string) ( $row['content_hash'] ?? '' ) ) || ! hash_equals( $record['note_root_id'], (string) ( $row['student_note_public_id'] ?? '' ) ) || (int) $record['include_in_reports'] !== $this->non_negative_integer( $row['include_in_reports'] ?? null ) || $record['note_schema'] !== ( $row['note_schema'] ?? null ) || $record['note_version'] !== $this->positive_integer( $row['note_version'] ?? null ) || ! hash_equals( $record['note_hash'], (string) ( $row['note_hash'] ?? '' ) ) || ! hash_equals( $record['expected_official_review_id'], (string) ( $row['review_id'] ?? '' ) ) || $record['previous_revision_id'] !== ( $row['previous_id'] ?? null ) || $record['source_evaluation_run_public_id'] !== ( $row['source_evaluation_run_public_id'] ?? null ) || $approved_by !== $stored_approved_by || $approved_at !== $stored_approved_at ) {
			throw $this->storage_failure();
		}
	}

	/**
	 * Reject invalid record.
	 *
	 * @param array<string,mixed> $record Record.
	 * @param string              $operation Operation.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_record( array $record, string $operation ): void {
		$expected = array( 'actor_user_id', 'audit_public_id', 'command_id', 'command_storage_key', 'content_hash', 'context_id', 'context_revision', 'context_type', 'correlation_id', 'evaluation_public_id', 'expected_evaluation_lock_version', 'expected_evaluation_state', 'expected_include_in_reports', 'expected_note_state', 'expected_official_review_hash', 'expected_official_review_id', 'expected_revision_hash', 'expected_revision_id', 'expected_revision_no', 'expected_source_run_id', 'include_in_reports', 'note_hash', 'note_json', 'note_root_id', 'note_schema', 'note_version', 'occurred_at_utc', 'outbox_public_id', 'previous_revision_id', 'reason_digest', 'request_digest', 'revision_no', 'revision_public_id', 'source_evaluation_run_public_id', 'source_mode', 'state' );
		$this->exact_keys( $record, $expected );
		if ( ! is_int( $record['actor_user_id'] ) || $record['actor_user_id'] < 1 || ! is_int( $record['expected_evaluation_lock_version'] ) || $record['expected_evaluation_lock_version'] < 0 || 'reviewed' !== $record['expected_evaluation_state'] || ! is_int( $record['expected_revision_no'] ) || ! is_int( $record['revision_no'] ) || $record['revision_no'] !== $record['expected_revision_no'] + 1 || ! is_bool( $record['include_in_reports'] ) || ! is_bool( $record['expected_include_in_reports'] ) || Student_Note_Input_Builder::RESULT_SCHEMA !== $record['note_schema'] || 1 !== $record['note_version'] ) {
			throw new InvalidArgumentException( 'Student note revision record is invalid.' );
		}
		if ( self::OP_APPROVAL === $operation ) {
			if ( 'approved' !== $record['state'] || 'approve' !== $record['source_mode'] || 'teacher_draft' !== $record['expected_note_state'] || $record['include_in_reports'] || $record['expected_include_in_reports'] ) {
				throw new InvalidArgumentException( 'Student note approval record is invalid.' );
			}
		} elseif ( self::OP_REPORT === $operation ) {
			$expected_mode = $record['include_in_reports'] ? 'include_in_reports' : 'exclude_from_reports';
			if ( 'approved' !== $record['state'] || 'approved' !== $record['expected_note_state'] || $expected_mode !== $record['source_mode'] || $record['include_in_reports'] === $record['expected_include_in_reports'] || null !== $record['reason_digest'] ) {
				throw new InvalidArgumentException( 'Student note report inclusion record is invalid.' );
			}
		} elseif ( self::OP_WITHDRAW_APPROVAL === $operation ) {
			if ( 'teacher_draft' !== $record['state'] || 'withdraw_approval' !== $record['source_mode'] || 'approved' !== $record['expected_note_state'] || $record['include_in_reports'] || $record['expected_include_in_reports'] || null !== $record['reason_digest'] ) {
				throw new InvalidArgumentException( 'Student note approval withdrawal record is invalid.' );
			}
		} elseif ( self::OP_REVIEW !== $operation || $record['include_in_reports'] || $record['expected_include_in_reports'] || ! in_array( $record['state'], array( 'teacher_draft', 'rejected' ), true ) || ! in_array( $record['source_mode'], array( 'direct', 'edit' ), true ) ) {
			throw new InvalidArgumentException( 'Student note review record is invalid.' );
		}
		foreach ( array( 'audit_public_id', 'evaluation_public_id', 'expected_official_review_id', 'note_root_id', 'outbox_public_id', 'revision_public_id' ) as $key ) {
			new Public_Id( (string) $record[ $key ] );
		}
		foreach ( array( 'expected_revision_id', 'expected_source_run_id', 'previous_revision_id', 'source_evaluation_run_public_id' ) as $key ) {
			if ( null !== $record[ $key ] ) {
				new Public_Id( (string) $record[ $key ] );
			}
		}
		foreach ( array( 'content_hash', 'expected_official_review_hash', 'note_hash', 'request_digest' ) as $key ) {
			$this->hash_value( $record[ $key ] );
		}
		foreach ( array( 'expected_revision_hash', 'reason_digest' ) as $key ) {
			if ( null !== $record[ $key ] ) {
				$this->hash_value( $record[ $key ] );
			}
		}
		if ( ( null === $record['expected_revision_id'] ) !== ( null === $record['expected_revision_hash'] ) || ( null === $record['expected_revision_id'] ) !== ( 0 === $record['expected_revision_no'] ) || ( null === $record['expected_revision_id'] ) !== ( null === $record['expected_note_state'] ) || $record['previous_revision_id'] !== $record['expected_revision_id'] || ( 'edit' === $record['source_mode'] && null === $record['expected_revision_id'] ) || ( 'direct' === $record['source_mode'] && null !== $record['source_evaluation_run_public_id'] ) || ( in_array( $record['source_mode'], array( 'edit', 'approve', 'include_in_reports', 'exclude_from_reports', 'withdraw_approval' ), true ) && $record['source_evaluation_run_public_id'] !== $record['expected_source_run_id'] ) || ( 'rejected' === $record['state'] ) !== ( null !== $record['reason_digest'] ) || ( 'rejected' === $record['state'] && 'edit' !== $record['source_mode'] ) ) {
			throw new InvalidArgumentException( 'Student note revision source record is invalid.' );
		}
		if ( null !== $record['expected_note_state'] && ! in_array( $record['expected_note_state'], self::STATES, true ) ) {
			throw new InvalidArgumentException( 'Student note expected state is invalid.' );
		}
		$this->storage_key( $record['command_storage_key'] );
		$this->datetime( $record['occurred_at_utc'] );
		foreach ( array(
			'command_id'       => 128,
			'correlation_id'   => 100,
			'context_type'     => 16,
			'context_id'       => 191,
			'context_revision' => 191,
		) as $key => $maximum ) {
			$this->token( $record[ $key ], $maximum, in_array( $key, array( 'context_id', 'context_revision' ), true ) );
		}
		$profile = $this->decode_object( $record['note_json'], $record['note_hash'] );
		try {
			$profile = $this->normalizer->validate_stored_profile( $profile ); } catch ( InvalidArgumentException ) {
			throw new InvalidArgumentException( 'Student note revision profile is invalid.' );
			}
			if ( ! hash_equals( $record['note_json'], $this->canonical->encode( $profile ) ) || ! hash_equals( $record['expected_official_review_hash'], $profile['source_hash'] ) ) {
				throw new InvalidArgumentException( 'Student note revision profile source is invalid.' );
			}
			$expected_hash = hash(
				'sha256',
				$this->canonical->encode(
					array(
						'evaluation_id'        => $record['evaluation_public_id'],
						'include_in_reports'   => $record['include_in_reports'],
						'note_hash'            => $record['note_hash'],
						'note_root_id'         => $record['note_root_id'],
						'previous_revision_id' => $record['previous_revision_id'],
						'review_id'            => $record['expected_official_review_id'],
						'revision_no'          => $record['revision_no'],
						'source_run_id'        => $record['source_evaluation_run_public_id'],
						'state'                => $record['state'],
					)
				)
			);
		if ( ! hash_equals( $record['content_hash'], $expected_hash ) ) {
			throw new InvalidArgumentException( 'Student note revision content hash is invalid.' );
		}
	}

	/**
	 * Safe state.
	 *
	 * @param array<string,mixed> $snapshot Snapshot.
	 * @return array<string,int|string>
	 */
	private function safe_state( array $snapshot ): array {
		return array(
			'current_include_in_reports'   => (int) (bool) ( $snapshot['latest_include_in_reports'] ?? false ),
			'current_official_review_hash' => (string) ( $snapshot['official_review_hash'] ?? '' ),
			'current_official_review_id'   => (string) ( $snapshot['official_review_id'] ?? '' ),
			'current_revision_hash'        => (string) ( $snapshot['latest_revision_hash'] ?? '' ),
			'current_revision_id'          => (string) ( $snapshot['latest_revision_id'] ?? '' ),
			'current_revision_no'          => (int) ( $snapshot['latest_revision_no'] ?? 0 ),
			'current_state'                => (string) ( $snapshot['latest_state'] ?? '' ),
		);
	}

	/**
	 * Decode object.
	 *
	 * @param mixed  $value Value.
	 * @param string $hash Hash.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function decode_object( $value, string $hash ): array {
		if ( ! is_string( $value ) || ! hash_equals( $hash, hash( 'sha256', $value ) ) ) {
			throw $this->stored_invalid();
		} try {
			$decoded = json_decode( $value, true, 128, JSON_THROW_ON_ERROR );
		} catch ( JsonException ) {
			throw $this->stored_invalid();
		} if ( ! is_array( $decoded ) || array_is_list( $decoded ) || ! hash_equals( $value, $this->canonical->encode( $decoded ) ) ) {
			throw $this->stored_invalid();
		} return $decoded;
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
		if ( is_int( $value ) && $value > 0 ) {
			return $value;
		} if ( is_string( $value ) && 1 === preg_match( '/^[1-9][0-9]*$/D', $value ) ) {
			return (int) $value;
		} throw $this->stored_invalid();
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
		} if ( is_string( $value ) && 1 === preg_match( '/^(?:0|[1-9][0-9]*)$/D', $value ) ) {
			return (int) $value;
		} throw $this->stored_invalid();
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
	 * Token.
	 *
	 * @param mixed $value Value.
	 * @param int   $maximum Maximum.
	 * @param bool  $slash Slash.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function token( $value, int $maximum, bool $slash = false ): string {
		if ( ! is_string( $value ) ) {
			throw $this->stored_invalid();
		} $pattern = $slash ? '/^[A-Za-z0-9][A-Za-z0-9_.:@\/-]*$/D' : '/^[A-Za-z0-9][A-Za-z0-9_.:@-]*$/D';
		if ( '' === $value || strlen( $value ) > $maximum || 1 !== preg_match( $pattern, $value ) ) {
			throw $this->stored_invalid();
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
			throw new InvalidArgumentException( 'Student note revision UTC time is invalid.' );
		} return $value;
	}
	/**
	 * Storage key.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function storage_key( $value ): string {
		if ( ! is_string( $value ) || strlen( $value ) > 191 || 1 !== preg_match( '/^[a-z]+:[a-f0-9]{64}$/D', $value ) ) {
			throw new InvalidArgumentException( 'Student note revision storage key is invalid.' );
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
	 * Scalar.
	 *
	 * @param string $sql Sql.
	 * @return mixed
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function scalar( string $sql ) {
		$database = $this->database();
		$value    = $database->get_var( $sql );
		if ( '' !== (string) ( $database->last_error ?? '' ) ) {
			throw $this->storage_failure();
		} return $value;
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
			throw $this->storage_failure();
		} return (int) $result;
	}
	/**
	 * Query or fail.
	 *
	 * @param string $sql Sql.
	 * @return void
	 * @throws \Throwable Propagates failures from the database query boundary.
	 */
	private function query_or_fail( string $sql ): void {
		$this->query( $sql );
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
		$database = $this->database ?? ( $GLOBALS['wpdb'] ?? null );
		if ( ! is_object( $database ) ) {
			throw $this->storage_failure();
		} foreach ( array( 'prepare', 'get_row', 'get_var', 'query', 'insert' ) as $method ) {
			if ( ! method_exists( $database, $method ) ) {
				throw $this->storage_failure();
			}
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
