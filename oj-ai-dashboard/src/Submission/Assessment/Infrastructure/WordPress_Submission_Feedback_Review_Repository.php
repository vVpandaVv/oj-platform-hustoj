<?php
/**
 * C36 immutable teacher feedback revision repository.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Infrastructure;

use InvalidArgumentException;
use JsonException;
use OJAI\Dashboard\Submission\Assessment\Application\Review_Submission_Feedback;
use OJAI\Dashboard\Submission\Assessment\Application\Submission_Evaluation_Failure;
use OJAI\Dashboard\Submission\Assessment\Application\Submission_Feedback_Review_Result;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Feedback_Input_Builder;
use OJAI\Dashboard\Submission\Assessment\Port\Submission_Feedback_Approval_Repository_Port_Interface;
use OJAI\Dashboard\Submission\Assessment\Port\Submission_Feedback_Approval_Withdrawal_Repository_Port_Interface;
use OJAI\Dashboard\Submission\Assessment\Port\Submission_Feedback_Review_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * C36 immutable teacher feedback revision repository.
 */
final class WordPress_Submission_Feedback_Review_Repository implements Submission_Feedback_Review_Repository_Port_Interface, Submission_Feedback_Approval_Repository_Port_Interface, Submission_Feedback_Approval_Withdrawal_Repository_Port_Interface {
	private const TABLE_EVALUATIONS = 'oj_ai_submission_evaluations';
	private const TABLE_REVISIONS   = 'oj_ai_submission_revisions';
	private const TABLE_SUBMISSIONS = 'oj_ai_submissions';
	private const TABLE_ASSIGNMENTS = 'oj_ai_assignments';
	private const TABLE_TARGETS     = 'oj_ai_assignment_targets';
	private const TABLE_REVIEWS     = 'oj_ai_submission_evaluation_reviews';
	private const TABLE_FEEDBACK    = 'oj_ai_submission_feedback_revisions';
	private const TABLE_AUDIT       = 'oj_ai_audit_events';
	private const TABLE_OUTBOX      = 'oj_ai_outbox_events';

	private const REVIEW_EVENT             = 'submission_feedback.reviewed';
	private const REVIEW_AUDIT_SCHEMA      = 'oj_ai.submission_feedback_review_audit';
	private const REVIEW_OUTBOX_SCHEMA     = 'oj_ai.submission_feedback_review_event';
	private const APPROVAL_EVENT           = 'submission_feedback.approved';
	private const APPROVAL_AUDIT_SCHEMA    = 'oj_ai.submission_feedback_approval_audit';
	private const APPROVAL_OUTBOX_SCHEMA   = 'oj_ai.submission_feedback_approval_event';
	private const WITHDRAWAL_EVENT         = 'submission_feedback.approval_withdrawn';
	private const WITHDRAWAL_AUDIT_SCHEMA  = 'oj_ai.submission_feedback_approval_withdrawal_audit';
	private const WITHDRAWAL_OUTBOX_SCHEMA = 'oj_ai.submission_feedback_approval_withdrawal_event';
	private const PROFILE_VERSION          = 1;
	private const STATES                   = array( 'ai_draft', 'teacher_draft', 'rejected', 'approved', 'stale' );

	/**
	 * Initialize WordPress submission feedback review repository dependencies and state.
	 *
	 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
	 * @param object|null    $database Database.
	 */
	public function __construct( private Canonical_Json $canonical, private ?object $database = null ) {
	}

	/**
	 * Find command receipt.
	 *
	 * @param string $command_storage_key Command storage key.
	 * @param string $request_digest Request digest.
	 * @return Submission_Feedback_Review_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function find_command_receipt(
		string $command_storage_key,
		string $request_digest
	): ?Submission_Feedback_Review_Result {
		$this->storage_key( $command_storage_key );
		$this->hash_value( $request_digest );
		try {
			return $this->command_receipt( $command_storage_key, $request_digest, false, self::REVIEW_EVENT );
		} catch ( InvalidArgumentException ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORED_DATA_INVALID );
		}
	}

	/**
	 * Find approval command receipt.
	 *
	 * @param string $command_storage_key Command storage key.
	 * @param string $request_digest Request digest.
	 * @return Submission_Feedback_Review_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function find_approval_command_receipt(
		string $command_storage_key,
		string $request_digest
	): ?Submission_Feedback_Review_Result {
		$this->storage_key( $command_storage_key );
		$this->hash_value( $request_digest );
		try {
			return $this->command_receipt( $command_storage_key, $request_digest, false, self::APPROVAL_EVENT );
		} catch ( InvalidArgumentException ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORED_DATA_INVALID );
		}
	}

	/**
	 * Find withdrawal receipt.
	 *
	 * @param string $command_storage_key Command storage key.
	 * @param string $request_digest Request digest.
	 * @return Submission_Feedback_Review_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function find_withdrawal_receipt(
		string $command_storage_key,
		string $request_digest
	): ?Submission_Feedback_Review_Result {
		$this->storage_key( $command_storage_key );
		$this->hash_value( $request_digest );
		try {
			return $this->command_receipt( $command_storage_key, $request_digest, false, self::WITHDRAWAL_EVENT );
		} catch ( InvalidArgumentException ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORED_DATA_INVALID );
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
			throw new InvalidArgumentException( 'Submission feedback review actor is invalid.' );
		}
		new Public_Id( $evaluation_id );
		try {
			return $this->snapshot( $evaluation_id, false );
		} catch ( Submission_Evaluation_Failure $failure ) {
			throw $failure;
		} catch ( InvalidArgumentException ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORED_DATA_INVALID );
		} catch ( Throwable ) {
			throw $this->storage_failure();
		}
	}

	/**
	 * Find owned approval snapshot.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $evaluation_id Evaluation id.
	 * @return array<string,mixed>|null
	 */
	public function find_owned_approval_snapshot( int $actor_user_id, string $evaluation_id ): ?array {
		return $this->find_owned_snapshot( $actor_user_id, $evaluation_id );
	}

	/**
	 * Find owned withdrawal snapshot.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $evaluation_id Evaluation id.
	 * @return array<string,mixed>|null
	 */
	public function find_owned_withdrawal_snapshot( int $actor_user_id, string $evaluation_id ): ?array {
		return $this->find_owned_snapshot( $actor_user_id, $evaluation_id );
	}

	/**
	 * Append revision.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Submission_Feedback_Review_Result
	 */
	public function append_revision( array $record ): Submission_Feedback_Review_Result {
		return $this->append_record( $record, self::REVIEW_EVENT, self::REVIEW_AUDIT_SCHEMA, self::REVIEW_OUTBOX_SCHEMA, 'review' );
	}

	/**
	 * Append approved revision.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Submission_Feedback_Review_Result
	 */
	public function append_approved_revision( array $record ): Submission_Feedback_Review_Result {
		return $this->append_record( $record, self::APPROVAL_EVENT, self::APPROVAL_AUDIT_SCHEMA, self::APPROVAL_OUTBOX_SCHEMA, 'approval' );
	}

	/**
	 * Append withdrawn revision.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Submission_Feedback_Review_Result
	 */
	public function append_withdrawn_revision( array $record ): Submission_Feedback_Review_Result {
		return $this->append_record( $record, self::WITHDRAWAL_EVENT, self::WITHDRAWAL_AUDIT_SCHEMA, self::WITHDRAWAL_OUTBOX_SCHEMA, 'withdrawal' );
	}

	/**
	 * Append record.
	 *
	 * @param array<string,mixed> $record Record.
	 * @param string              $event Event.
	 * @param string              $audit_schema Audit schema.
	 * @param string              $outbox_schema Outbox schema.
	 * @param string              $mode Mode.
	 * @return Submission_Feedback_Review_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function append_record( array $record, string $event, string $audit_schema, string $outbox_schema, string $mode ): Submission_Feedback_Review_Result {
		$this->assert_record( $record, $mode );
		$receipt = $this->command_receipt( $record['command_storage_key'], $record['request_digest'], false, $event );
		if ( $receipt instanceof Submission_Feedback_Review_Result ) {
			return $receipt;
		}

		$transaction = false;
		try {
			$this->query_or_fail( 'START TRANSACTION' );
			$transaction = true;
			$receipt     = $this->command_receipt( $record['command_storage_key'], $record['request_digest'], true, $event );
			if ( $receipt instanceof Submission_Feedback_Review_Result ) {
				$this->query_or_fail( 'COMMIT' );
				$transaction = false;
				return $receipt;
			}

			$snapshot = $this->snapshot( $record['evaluation_public_id'], true );
			if ( null === $snapshot ) {
				throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::NOT_FOUND );
			}
			$this->assert_locked_source( $snapshot, $record );

			$this->insert_or_fail(
				self::TABLE_FEEDBACK,
				array(
					'public_id'                       => $record['revision_public_id'],
					'content_hash'                    => $record['content_hash'],
					'created_by_user_id'              => $record['actor_user_id'],
					'created_at_utc'                  => $record['occurred_at_utc'],
					'submission_evaluation_id'        => $snapshot['evaluation_pk'],
					'submission_review_id'            => $snapshot['official_review_pk'],
					'revision_no'                     => $record['revision_no'],
					'audience'                        => 'student',
					'feedback_schema'                 => $record['feedback_schema'],
					'feedback_version'                => $record['feedback_version'],
					'feedback_json'                   => $record['feedback_json'],
					'feedback_hash'                   => $record['feedback_hash'],
					'state'                           => $record['state'],
					'previous_revision_id'            => $snapshot['latest_feedback_pk'],
					'source_evaluation_run_public_id' => $record['source_evaluation_run_public_id'],
				)
			);

			$result = $this->result_from_record( $record, false );
			$this->insert_audit( $record, $event, $audit_schema );
			$this->insert_outbox( $record, $result, $event, $outbox_schema );
			$this->assert_postcondition( $record );
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
				if ( $recovered instanceof Submission_Feedback_Review_Result ) {
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
	 * @param string $evaluation_id Evaluation id.
	 * @param bool   $for_update For update.
	 * @return array<string,mixed>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function snapshot( string $evaluation_id, bool $for_update ): ?array {
		$sql = 'SELECT e.id AS evaluation_pk, e.public_id AS evaluation_id, e.lock_version AS evaluation_lock_version, e.state AS evaluation_state, e.submission_revision_public_id AS submission_revision_id, e.submission_revision_hash, revision.content_hash AS joined_revision_hash, revision.state AS revision_state, s.state AS submission_state, s.official_revision_public_id, s.official_review_public_id AS official_review_id, s.official_review_hash, a.context_type, a.context_id, a.context_revision, a.state AS assignment_state, t.state AS target_state, review.id AS official_review_pk, review.public_id AS joined_review_id, review.content_hash AS joined_review_hash, review.decision AS official_review_decision, (SELECT COUNT(*) FROM ' . self::TABLE_FEEDBACK . ' counted WHERE counted.submission_evaluation_id = e.id) AS feedback_count, '
			. 'latest.id AS latest_feedback_pk, latest.public_id AS latest_feedback_id, latest.content_hash AS latest_feedback_hash, '
			. 'latest.revision_no AS latest_feedback_no, latest.audience AS latest_feedback_audience, latest.feedback_schema AS latest_feedback_schema, '
			. 'latest.feedback_version AS latest_feedback_version, latest.feedback_json AS latest_feedback_json, latest.feedback_hash AS latest_profile_hash, '
			. 'latest.state AS latest_feedback_state, latest.source_evaluation_run_public_id AS latest_feedback_source_run_id, '
			. 'latest_review.public_id AS latest_feedback_review_id, latest_review.content_hash AS latest_feedback_review_hash, '
			. 'previous.public_id AS latest_previous_feedback_id, previous.content_hash AS latest_previous_feedback_hash, '
			. 'previous.revision_no AS latest_previous_feedback_no '
			. 'FROM ' . self::TABLE_EVALUATIONS . ' e '
			. 'INNER JOIN ' . self::TABLE_REVISIONS . ' revision ON revision.public_id = e.submission_revision_public_id '
			. 'INNER JOIN ' . self::TABLE_SUBMISSIONS . ' s ON s.id = revision.submission_id '
			. 'INNER JOIN ' . self::TABLE_ASSIGNMENTS . ' a ON a.id = s.assignment_id '
			. 'INNER JOIN ' . self::TABLE_TARGETS . ' t ON t.assignment_id = a.id AND t.learner_user_id = s.learner_user_id '
			. 'INNER JOIN ' . self::TABLE_REVIEWS . ' review ON review.public_id = s.official_review_public_id AND review.submission_evaluation_id = e.id '
			. 'LEFT JOIN ' . self::TABLE_FEEDBACK . ' latest ON latest.submission_evaluation_id = e.id AND latest.revision_no = '
			. '(SELECT MAX(latest_no.revision_no) FROM ' . self::TABLE_FEEDBACK . ' latest_no WHERE latest_no.submission_evaluation_id = e.id) '
			. 'LEFT JOIN ' . self::TABLE_REVIEWS . ' latest_review ON latest_review.id = latest.submission_review_id '
			. 'LEFT JOIN ' . self::TABLE_FEEDBACK . ' previous ON previous.id = latest.previous_revision_id '
			. 'WHERE e.public_id = %s AND t.state = %s AND a.state <> %s LIMIT 1';
		if ( $for_update ) {
			$sql .= ' FOR UPDATE';
		}
		$row = $this->row( $this->prepare( $sql, array( $evaluation_id, 'active', 'withdrawn' ) ) );
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
			|| 'active' !== ( $row['target_state'] ?? null )
			|| 'withdrawn' === ( $row['assignment_state'] ?? null )
			|| ! hash_equals( $official_review_id, $this->public_id( $row['joined_review_id'] ?? null ) )
			|| ! hash_equals( $official_review_hash, $this->hash_value( $row['joined_review_hash'] ?? null ) )
			|| ! in_array( $row['official_review_decision'] ?? null, array( 'accepted', 'adjusted' ), true )
		) {
			throw $this->stored_invalid();
		}

		$count  = $this->non_negative_integer( $row['feedback_count'] ?? null );
		$latest = $this->latest_feedback( $row, $evaluation_id, $count );
		return array(
			'evaluation_pk'                 => $this->positive_integer( $row['evaluation_pk'] ?? null ),
			'evaluation_id'                 => $evaluation_id,
			'evaluation_lock_version'       => $this->non_negative_integer( $row['evaluation_lock_version'] ?? null ),
			'evaluation_state'              => 'reviewed',
			'context_type'                  => $this->token( $row['context_type'] ?? null, 16 ),
			'context_id'                    => $this->token( $row['context_id'] ?? null, 191, true ),
			'context_revision'              => $this->token( $row['context_revision'] ?? null, 191, true ),
			'official_review_pk'            => $this->positive_integer( $row['official_review_pk'] ?? null ),
			'official_review_id'            => $official_review_id,
			'official_review_hash'          => $official_review_hash,
			'latest_feedback_pk'            => $latest['pk'],
			'latest_feedback_revision_id'   => $latest['id'],
			'latest_feedback_revision_hash' => $latest['hash'],
			'latest_feedback_revision_no'   => $count,
			'latest_feedback_state'         => $latest['state'],
			'latest_feedback_review_id'     => $latest['review_id'],
			'latest_feedback_source_run_id' => $latest['source_run_id'],
			'latest_feedback_schema'        => $latest['schema'],
			'latest_feedback_version'       => $latest['version'],
			'latest_feedback_json'          => $latest['json'],
			'latest_feedback_profile_hash'  => $latest['profile_hash'],
		);
	}

	/**
	 * Latest feedback.
	 *
	 * @param array<string,mixed> $row Row.
	 * @param string              $evaluation_id Evaluation id.
	 * @param int                 $count Count.
	 * @return array{pk:?int,id:?string,hash:?string,state:?string,review_id:?string,source_run_id:?string,schema:?string,version:?int,json:?string,profile_hash:?string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function latest_feedback( array $row, string $evaluation_id, int $count ): array {
		if ( 0 === $count ) {
			foreach ( array( 'latest_feedback_pk', 'latest_feedback_id', 'latest_feedback_hash', 'latest_feedback_no', 'latest_feedback_state', 'latest_feedback_review_id', 'latest_feedback_source_run_id', 'latest_feedback_schema', 'latest_feedback_version', 'latest_feedback_json', 'latest_profile_hash', 'latest_previous_feedback_id', 'latest_previous_feedback_hash', 'latest_previous_feedback_no' ) as $key ) {
				if ( null !== ( $row[ $key ] ?? null ) ) {
					throw $this->stored_invalid();
				}
			}
			return array(
				'pk'            => null,
				'id'            => null,
				'hash'          => null,
				'state'         => null,
				'review_id'     => null,
				'source_run_id' => null,
				'schema'        => null,
				'version'       => null,
				'json'          => null,
				'profile_hash'  => null,
			);
		}

		$pk            = $this->positive_integer( $row['latest_feedback_pk'] ?? null );
		$id            = $this->public_id( $row['latest_feedback_id'] ?? null );
		$content_hash  = $this->hash_value( $row['latest_feedback_hash'] ?? null );
		$revision_no   = $this->positive_integer( $row['latest_feedback_no'] ?? null );
		$state         = $row['latest_feedback_state'] ?? null;
		$review_id     = $this->public_id( $row['latest_feedback_review_id'] ?? null );
		$review_hash   = $this->hash_value( $row['latest_feedback_review_hash'] ?? null );
		$source_run_id = null === ( $row['latest_feedback_source_run_id'] ?? null ) ? null : $this->public_id( $row['latest_feedback_source_run_id'] );
		$previous_id   = null === ( $row['latest_previous_feedback_id'] ?? null ) ? null : $this->public_id( $row['latest_previous_feedback_id'] );
		$previous_hash = null === ( $row['latest_previous_feedback_hash'] ?? null ) ? null : $this->hash_value( $row['latest_previous_feedback_hash'] );
		$previous_no   = null === ( $row['latest_previous_feedback_no'] ?? null ) ? null : $this->positive_integer( $row['latest_previous_feedback_no'] );
		if (
			$count !== $revision_no
			|| 'student' !== ( $row['latest_feedback_audience'] ?? null )
			|| ! in_array( $state, self::STATES, true )
			|| ( 1 === $count ) !== ( null === $previous_id )
			|| ( 1 === $count ) !== ( null === $previous_hash )
			|| ( $count > 1 && $count - 1 !== $previous_no )
		) {
			throw $this->stored_invalid();
		}
		$schema       = is_string( $row['latest_feedback_schema'] ?? null ) ? $row['latest_feedback_schema'] : '';
		$version      = $this->positive_integer( $row['latest_feedback_version'] ?? null );
		$json         = is_string( $row['latest_feedback_json'] ?? null ) ? $row['latest_feedback_json'] : '';
		$profile_hash = $this->hash_value( $row['latest_profile_hash'] ?? null );
		$profile      = $this->decode_object( $json, $profile_hash );
		$this->assert_profile( $profile, $schema, $version, $state, $review_hash, $previous_id, $previous_hash );
		$expected_content_hash = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'audience'             => 'student',
					'evaluation_id'        => $evaluation_id,
					'feedback_hash'        => $profile_hash,
					'previous_revision_id' => $previous_id,
					'review_id'            => $review_id,
					'revision_no'          => $revision_no,
					'source_run_id'        => $source_run_id,
					'state'                => $state,
				)
			)
		);
		if ( ! hash_equals( $content_hash, $expected_content_hash ) ) {
			throw $this->stored_invalid();
		}
		return array(
			'pk'            => $pk,
			'id'            => $id,
			'hash'          => $content_hash,
			'state'         => $state,
			'review_id'     => $review_id,
			'source_run_id' => $source_run_id,
			'schema'        => $schema,
			'version'       => $version,
			'json'          => $json,
			'profile_hash'  => $profile_hash,
		);
	}

	/**
	 * Reject invalid profile.
	 *
	 * @param array<string,mixed> $profile Profile.
	 * @param mixed               $schema Schema.
	 * @param mixed               $version Version.
	 * @param string              $state State.
	 * @param string              $review_hash Review hash.
	 * @param string|null         $previous_id Previous id.
	 * @param string|null         $previous_hash Previous hash.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_profile( array $profile, $schema, $version, string $state, string $review_hash, ?string $previous_id, ?string $previous_hash ): void {
		if ( Review_Submission_Feedback::PROFILE_VERSION !== $this->positive_integer( $version ) || ! is_string( $schema ) || ( $profile['schema_id'] ?? null ) !== $schema || '1.0.0' !== ( $profile['schema_version'] ?? null ) ) {
			throw $this->stored_invalid();
		}
		if ( Review_Submission_Feedback::REJECTION_SCHEMA === $schema ) {
			$this->exact_keys( $profile, array( 'reason', 'schema_id', 'schema_version', 'source_feedback_hash', 'source_feedback_ref', 'source_hash' ) );
			if ( 'rejected' !== $state || null === $previous_id || null === $previous_hash || ! hash_equals( $previous_id, $this->public_id( $profile['source_feedback_ref'] ?? null ) ) || ! hash_equals( $previous_hash, $this->hash_value( $profile['source_feedback_hash'] ?? null ) ) || ! hash_equals( $review_hash, $this->hash_value( $profile['source_hash'] ?? null ) ) ) {
				throw $this->stored_invalid();
			}
			$this->text( $profile['reason'] ?? null, 2000 );
			return;
		}
		if ( Submission_Feedback_Input_Builder::RESULT_SCHEMA !== $schema || 'rejected' === $state ) {
			throw $this->stored_invalid();
		}
		$this->exact_keys( $profile, array( 'criterion_feedback', 'feedback_ref', 'next_steps', 'overall_feedback', 'safety_flags', 'schema_id', 'schema_version', 'source_hash' ) );
		if ( ! hash_equals( $review_hash, $this->hash_value( $profile['source_hash'] ?? null ) ) ) {
			throw $this->stored_invalid();
		}
	}

	/**
	 * Reject invalid locked source.
	 *
	 * @param array<string,mixed> $snapshot Snapshot.
	 * @param array<string,mixed> $record Record.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_locked_source( array $snapshot, array $record ): void {
		if (
			! hash_equals( $record['evaluation_public_id'], $snapshot['evaluation_id'] )
			|| $record['expected_evaluation_lock_version'] !== $snapshot['evaluation_lock_version']
			|| $record['expected_evaluation_state'] !== $snapshot['evaluation_state']
			|| ! hash_equals( $record['context_type'], $snapshot['context_type'] )
			|| ! hash_equals( $record['context_id'], $snapshot['context_id'] )
			|| ! hash_equals( $record['context_revision'], $snapshot['context_revision'] )
			|| ! hash_equals( $record['expected_official_review_id'], $snapshot['official_review_id'] )
			|| ! hash_equals( $record['expected_official_review_hash'], $snapshot['official_review_hash'] )
			|| $record['expected_feedback_revision_id'] !== $snapshot['latest_feedback_revision_id']
			|| $record['expected_feedback_revision_hash'] !== $snapshot['latest_feedback_revision_hash']
			|| $record['expected_feedback_revision_no'] !== $snapshot['latest_feedback_revision_no']
			|| $record['expected_feedback_state'] !== $snapshot['latest_feedback_state']
			|| $record['expected_feedback_review_id'] !== $snapshot['latest_feedback_review_id']
			|| $record['expected_feedback_source_run_id'] !== $snapshot['latest_feedback_source_run_id']
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::FEEDBACK_SOURCE_STALE, $this->safe_state( $snapshot ) );
		}
		if (
			'edit' === $record['source_mode']
			&& ( ! in_array( $snapshot['latest_feedback_state'], array( 'ai_draft', 'teacher_draft' ), true )
				|| ! hash_equals( $snapshot['official_review_id'], (string) $snapshot['latest_feedback_review_id'] ) )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::FEEDBACK_SOURCE_STALE, $this->safe_state( $snapshot ) );
		}
		if (
			'approve' === $record['source_mode']
			&& ( 'teacher_draft' !== $snapshot['latest_feedback_state']
				|| ! hash_equals( $snapshot['official_review_id'], (string) $snapshot['latest_feedback_review_id'] )
				|| ! hash_equals( $record['feedback_schema'], (string) $snapshot['latest_feedback_schema'] )
				|| $record['feedback_version'] !== $snapshot['latest_feedback_version']
				|| ! hash_equals( $record['feedback_json'], (string) $snapshot['latest_feedback_json'] )
				|| ! hash_equals( $record['feedback_hash'], (string) $snapshot['latest_feedback_profile_hash'] ) )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::FEEDBACK_SOURCE_STALE, $this->safe_state( $snapshot ) );
		}
		if (
			'withdraw_approval' === $record['source_mode']
			&& ( 'approved' !== $snapshot['latest_feedback_state']
				|| ! hash_equals( $snapshot['official_review_id'], (string) $snapshot['latest_feedback_review_id'] )
				|| ! hash_equals( $record['feedback_schema'], (string) $snapshot['latest_feedback_schema'] )
				|| $record['feedback_version'] !== $snapshot['latest_feedback_version']
				|| ! hash_equals( $record['feedback_json'], (string) $snapshot['latest_feedback_json'] )
				|| ! hash_equals( $record['feedback_hash'], (string) $snapshot['latest_feedback_profile_hash'] ) )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::FEEDBACK_SOURCE_STALE, $this->safe_state( $snapshot ) );
		}
	}

	/**
	 * Insert audit.
	 *
	 * @param array<string,mixed> $record Record.
	 * @param string              $event Event.
	 * @param string              $audit_schema Audit schema.
	 * @return void
	 */
	private function insert_audit( array $record, string $event, string $audit_schema ): void {
		$metadata            = array(
			'command_id'             => $record['command_id'],
			'feedback_revision_hash' => $record['content_hash'],
			'feedback_revision_id'   => $record['revision_public_id'],
			'feedback_revision_no'   => $record['revision_no'],
			'official_review_hash'   => $record['expected_official_review_hash'],
			'official_review_id'     => $record['expected_official_review_id'],
			'previous_revision_id'   => $record['previous_feedback_revision_id'],
			'reason_digest'          => $record['reason_digest'],
			'request_digest'         => $record['request_digest'],
			'source_mode'            => $record['source_mode'],
			'source_run_id'          => $record['source_evaluation_run_public_id'],
			'state'                  => $record['state'],
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
					'before_digest'       => $record['expected_feedback_revision_hash'],
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
				'before_digest'       => $record['expected_feedback_revision_hash'],
				'after_digest'        => $record['content_hash'],
				'metadata_schema'     => $audit_schema,
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
	 * @param array<string,mixed>               $record Record.
	 * @param Submission_Feedback_Review_Result $result Result.
	 * @param string                            $event Event.
	 * @param string                            $outbox_schema Outbox schema.
	 * @return void
	 */
	private function insert_outbox( array $record, Submission_Feedback_Review_Result $result, string $event, string $outbox_schema ): void {
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
				'payload_schema'       => $outbox_schema,
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
	 * @return Submission_Feedback_Review_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function command_receipt( string $key, string $digest, bool $for_update, string $event ): ?Submission_Feedback_Review_Result {
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
	 * @return Submission_Feedback_Review_Result
	 */
	private function result_from_record( array $record, bool $replayed ): Submission_Feedback_Review_Result {
		return new Submission_Feedback_Review_Result( $record['evaluation_public_id'], $record['expected_official_review_id'], $record['expected_official_review_hash'], $record['revision_public_id'], $record['content_hash'], $record['revision_no'], $record['state'], $record['previous_feedback_revision_id'], $record['source_evaluation_run_public_id'], $record['occurred_at_utc'], $replayed );
	}

	/**
	 * Result from payload.
	 *
	 * @param array<string,mixed> $payload Input payload at this boundary; see the declared shape.
	 * @param bool                $replayed Replayed.
	 * @return Submission_Feedback_Review_Result
	 */
	private function result_from_payload( array $payload, bool $replayed ): Submission_Feedback_Review_Result {
		$this->exact_keys( $payload, array( 'evaluation_id', 'feedback_revision_hash', 'feedback_revision_id', 'feedback_revision_no', 'occurred_at_utc', 'official_review_hash', 'official_review_id', 'previous_revision_id', 'request_digest', 'source_evaluation_run_id', 'state' ) );
		return new Submission_Feedback_Review_Result( $this->public_id( $payload['evaluation_id'] ?? null ), $this->public_id( $payload['official_review_id'] ?? null ), $this->hash_value( $payload['official_review_hash'] ?? null ), $this->public_id( $payload['feedback_revision_id'] ?? null ), $this->hash_value( $payload['feedback_revision_hash'] ?? null ), $this->positive_integer( $payload['feedback_revision_no'] ?? null ), (string) ( $payload['state'] ?? '' ), null === ( $payload['previous_revision_id'] ?? null ) ? null : $this->public_id( $payload['previous_revision_id'] ), null === ( $payload['source_evaluation_run_id'] ?? null ) ? null : $this->public_id( $payload['source_evaluation_run_id'] ), $this->datetime( $payload['occurred_at_utc'] ?? null ), $replayed );
	}

	/**
	 * Receipt payload.
	 *
	 * @param Submission_Feedback_Review_Result $result Result.
	 * @param string                            $request_digest Request digest.
	 * @return array<string,mixed>
	 */
	private function receipt_payload( Submission_Feedback_Review_Result $result, string $request_digest ): array {
		return array(
			'evaluation_id'            => $result->evaluation_id(),
			'feedback_revision_hash'   => $result->feedback_revision_hash(),
			'feedback_revision_id'     => $result->feedback_revision_id(),
			'feedback_revision_no'     => $result->feedback_revision_no(),
			'occurred_at_utc'          => $result->occurred_at_utc(),
			'official_review_hash'     => $result->official_review_hash(),
			'official_review_id'       => $result->official_review_id(),
			'previous_revision_id'     => $result->previous_revision_id(),
			'request_digest'           => $request_digest,
			'source_evaluation_run_id' => $result->source_evaluation_run_id(),
			'state'                    => $result->state(),
		);
	}

	/**
	 * Reject invalid postcondition.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_postcondition( array $record ): void {
		$row = $this->row( $this->prepare( 'SELECT feedback.revision_no, feedback.state, feedback.content_hash, feedback.feedback_schema, feedback.feedback_version, feedback.feedback_hash, feedback.source_evaluation_run_public_id, review.public_id AS review_id, previous.public_id AS previous_id, (SELECT COUNT(*) FROM ' . self::TABLE_FEEDBACK . ' counted WHERE counted.submission_evaluation_id = feedback.submission_evaluation_id) AS feedback_count FROM ' . self::TABLE_FEEDBACK . ' feedback INNER JOIN ' . self::TABLE_REVIEWS . ' review ON review.id = feedback.submission_review_id LEFT JOIN ' . self::TABLE_FEEDBACK . ' previous ON previous.id = feedback.previous_revision_id WHERE feedback.public_id = %s LIMIT 1', array( $record['revision_public_id'] ) ) );
		if ( null === $row || $record['revision_no'] !== $this->positive_integer( $row['revision_no'] ?? null ) || $record['revision_no'] !== $this->positive_integer( $row['feedback_count'] ?? null ) || $record['state'] !== ( $row['state'] ?? null ) || ! hash_equals( $record['content_hash'], (string) ( $row['content_hash'] ?? '' ) ) || $record['feedback_schema'] !== ( $row['feedback_schema'] ?? null ) || $record['feedback_version'] !== $this->positive_integer( $row['feedback_version'] ?? null ) || ! hash_equals( $record['feedback_hash'], (string) ( $row['feedback_hash'] ?? '' ) ) || ! hash_equals( $record['expected_official_review_id'], (string) ( $row['review_id'] ?? '' ) ) || $record['previous_feedback_revision_id'] !== ( $row['previous_id'] ?? null ) || $record['source_evaluation_run_public_id'] !== ( $row['source_evaluation_run_public_id'] ?? null ) ) {
			throw $this->storage_failure();
		}
	}

	/**
	 * Reject invalid record.
	 *
	 * @param array<string,mixed> $record Record.
	 * @param string              $mode Mode.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_record( array $record, string $mode ): void {
		$expected = array( 'actor_user_id', 'audit_public_id', 'command_id', 'command_storage_key', 'content_hash', 'context_id', 'context_revision', 'context_type', 'correlation_id', 'evaluation_public_id', 'expected_evaluation_lock_version', 'expected_evaluation_state', 'expected_feedback_revision_hash', 'expected_feedback_revision_id', 'expected_feedback_revision_no', 'expected_feedback_review_id', 'expected_feedback_source_run_id', 'expected_feedback_state', 'expected_official_review_hash', 'expected_official_review_id', 'feedback_json', 'feedback_hash', 'feedback_schema', 'feedback_version', 'occurred_at_utc', 'outbox_public_id', 'previous_feedback_revision_id', 'reason_digest', 'request_digest', 'revision_no', 'revision_public_id', 'source_evaluation_run_public_id', 'source_mode', 'state' );
		$this->exact_keys( $record, $expected );
		if ( ! is_int( $record['actor_user_id'] ) || $record['actor_user_id'] < 1 || ! is_int( $record['expected_evaluation_lock_version'] ) || $record['expected_evaluation_lock_version'] < 0 || 'reviewed' !== $record['expected_evaluation_state'] || ! is_int( $record['expected_feedback_revision_no'] ) || ! is_int( $record['revision_no'] ) || $record['revision_no'] !== $record['expected_feedback_revision_no'] + 1 || Review_Submission_Feedback::PROFILE_VERSION !== $record['feedback_version'] ) {
			throw new InvalidArgumentException( 'Submission feedback review record is invalid.' );
		}
		if ( 'approval' === $mode ) {
			if ( 'approved' !== $record['state'] || 'approve' !== $record['source_mode'] ) {
				throw new InvalidArgumentException( 'Submission feedback approval record is invalid.' );
			}
		} elseif ( 'withdrawal' === $mode ) {
			if ( 'teacher_draft' !== $record['state'] || 'withdraw_approval' !== $record['source_mode'] || 'approved' !== $record['expected_feedback_state'] ) {
				throw new InvalidArgumentException( 'Submission feedback approval withdrawal record is invalid.' );
			}
		} elseif ( ! in_array( $record['state'], array( 'teacher_draft', 'rejected' ), true ) || ! in_array( $record['source_mode'], array( 'direct', 'edit' ), true ) ) {
			throw new InvalidArgumentException( 'Submission feedback review record is invalid.' );
		}
		foreach ( array( 'audit_public_id', 'evaluation_public_id', 'expected_official_review_id', 'outbox_public_id', 'revision_public_id' ) as $key ) {
			new Public_Id( (string) $record[ $key ] );
		}
		foreach ( array( 'expected_feedback_revision_id', 'expected_feedback_review_id', 'expected_feedback_source_run_id', 'previous_feedback_revision_id', 'source_evaluation_run_public_id' ) as $key ) {
			if ( null !== $record[ $key ] ) {
				new Public_Id( (string) $record[ $key ] );
			}
		}
		foreach ( array( 'content_hash', 'expected_official_review_hash', 'feedback_hash', 'request_digest' ) as $key ) {
			$this->hash_value( $record[ $key ] );
		}
		foreach ( array( 'expected_feedback_revision_hash', 'reason_digest' ) as $key ) {
			if ( null !== $record[ $key ] ) {
				$this->hash_value( $record[ $key ] );
			}
		}
		if ( ( null === $record['expected_feedback_revision_id'] ) !== ( null === $record['expected_feedback_revision_hash'] ) || ( null === $record['expected_feedback_revision_id'] ) !== ( 0 === $record['expected_feedback_revision_no'] ) || $record['previous_feedback_revision_id'] !== $record['expected_feedback_revision_id'] || ( null === $record['expected_feedback_revision_id'] ) !== ( null === $record['expected_feedback_state'] ) || ( null === $record['expected_feedback_revision_id'] ) !== ( null === $record['expected_feedback_review_id'] ) || ( 'edit' === $record['source_mode'] && null === $record['expected_feedback_revision_id'] ) || ( 'direct' === $record['source_mode'] && null !== $record['source_evaluation_run_public_id'] ) || ( in_array( $record['source_mode'], array( 'edit', 'withdraw_approval' ), true ) && $record['source_evaluation_run_public_id'] !== $record['expected_feedback_source_run_id'] ) || ( 'rejected' === $record['state'] ) !== ( null !== $record['reason_digest'] ) || ( 'rejected' === $record['state'] ) !== ( Review_Submission_Feedback::REJECTION_SCHEMA === $record['feedback_schema'] ) || ( 'rejected' === $record['state'] && 'edit' !== $record['source_mode'] ) ) {
			throw new InvalidArgumentException( 'Submission feedback review source record is invalid.' );
		}
		if ( 'approval' === $mode && ( null === $record['expected_feedback_revision_id'] || 'teacher_draft' !== $record['expected_feedback_state'] || $record['expected_feedback_review_id'] !== $record['expected_official_review_id'] || $record['source_evaluation_run_public_id'] !== $record['expected_feedback_source_run_id'] || null !== $record['reason_digest'] || Submission_Feedback_Input_Builder::RESULT_SCHEMA !== $record['feedback_schema'] ) ) {
			throw new InvalidArgumentException( 'Submission feedback approval source record is invalid.' );
		}
		if ( 'withdrawal' === $mode && ( null === $record['expected_feedback_revision_id'] || 'approved' !== $record['expected_feedback_state'] || $record['expected_feedback_review_id'] !== $record['expected_official_review_id'] || $record['source_evaluation_run_public_id'] !== $record['expected_feedback_source_run_id'] || null !== $record['reason_digest'] || Submission_Feedback_Input_Builder::RESULT_SCHEMA !== $record['feedback_schema'] ) ) {
			throw new InvalidArgumentException( 'Submission feedback approval withdrawal source record is invalid.' );
		}
		if ( null !== $record['expected_feedback_state'] && ! in_array( $record['expected_feedback_state'], self::STATES, true ) ) {
			throw new InvalidArgumentException( 'Submission feedback review state is invalid.' );
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
		$profile = $this->decode_object( $record['feedback_json'], $record['feedback_hash'] );
		$this->assert_profile( $profile, $record['feedback_schema'], $record['feedback_version'], $record['state'], $record['expected_official_review_hash'], $record['previous_feedback_revision_id'], $record['expected_feedback_revision_hash'] );
		$expected_content_hash = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'audience'             => 'student',
					'evaluation_id'        => $record['evaluation_public_id'],
					'feedback_hash'        => $record['feedback_hash'],
					'previous_revision_id' => $record['previous_feedback_revision_id'],
					'review_id'            => $record['expected_official_review_id'],
					'revision_no'          => $record['revision_no'],
					'source_run_id'        => $record['source_evaluation_run_public_id'],
					'state'                => $record['state'],
				)
			)
		);
		if ( ! hash_equals( $record['content_hash'], $expected_content_hash ) ) {
			throw new InvalidArgumentException( 'Submission feedback review content hash is invalid.' );
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
			'current_feedback_revision_hash' => (string) ( $snapshot['latest_feedback_revision_hash'] ?? '' ),
			'current_feedback_revision_id'   => (string) ( $snapshot['latest_feedback_revision_id'] ?? '' ),
			'current_feedback_revision_no'   => (int) ( $snapshot['latest_feedback_revision_no'] ?? 0 ),
			'current_feedback_state'         => (string) ( $snapshot['latest_feedback_state'] ?? '' ),
			'current_official_review_hash'   => (string) ( $snapshot['official_review_hash'] ?? '' ),
			'current_official_review_id'     => (string) ( $snapshot['official_review_id'] ?? '' ),
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
	 * Text.
	 *
	 * @param mixed $value Value.
	 * @param int   $maximum Maximum.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function text( $value, int $maximum ): string {
		if ( ! is_string( $value ) || '' === trim( $value ) || strlen( $value ) > $maximum ) {
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
			throw new InvalidArgumentException( 'Submission feedback review UTC time is invalid.' );
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
			throw new InvalidArgumentException( 'Submission feedback review storage key is invalid.' );
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
