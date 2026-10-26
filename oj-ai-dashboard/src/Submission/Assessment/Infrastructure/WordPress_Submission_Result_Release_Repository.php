<?php
/**
 * C43 immutable release repository and current pointer transaction.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Infrastructure;

use InvalidArgumentException;
use JsonException;
use OJAI\Dashboard\Submission\Assessment\Application\Submission_Evaluation_Failure;
use OJAI\Dashboard\Submission\Assessment\Application\Submission_Result_Release_Result;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Result_Projection;
use OJAI\Dashboard\Submission\Assessment\Port\Submission_Result_Release_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * C43 immutable release repository and current pointer transaction.
 */
final class WordPress_Submission_Result_Release_Repository implements Submission_Result_Release_Repository_Port_Interface {
	private const TABLE_EVALUATIONS = 'oj_ai_submission_evaluations';
	private const TABLE_REVISIONS   = 'oj_ai_submission_revisions';
	private const TABLE_SUBMISSIONS = 'oj_ai_submissions';
	private const TABLE_ASSIGNMENTS = 'oj_ai_assignments';
	private const TABLE_TARGETS     = 'oj_ai_assignment_targets';
	private const TABLE_REVIEWS     = 'oj_ai_submission_evaluation_reviews';
	private const TABLE_FEEDBACK    = 'oj_ai_submission_feedback_revisions';
	private const TABLE_RELEASES    = 'oj_ai_submission_release_revisions';
	private const TABLE_AUDIT       = 'oj_ai_audit_events';
	private const TABLE_OUTBOX      = 'oj_ai_outbox_events';

	private const EVENT_RELEASED  = 'submission_result.released';
	private const EVENT_CORRECTED = 'submission_result.corrected';
	private const REASON_CODES    = array(
		'data_correction',
		'publication_error',
		'privacy_request',
		'other',
	);
	private const AUDIT_SCHEMA    = 'oj_ai.submission_result_release_audit';
	private const OUTBOX_SCHEMA   = 'oj_ai.submission_result_release_event';
	private const PROFILE_VERSION = 1;

	/**
	 * Initialize WordPress submission result release repository dependencies and state.
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
	 * @return Submission_Result_Release_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function find_command_receipt( string $command_storage_key, string $request_digest ): ?Submission_Result_Release_Result {
		$this->storage_key( $command_storage_key );
		$this->hash_argument( $request_digest );
		try {
			return $this->command_receipt( $command_storage_key, $request_digest, false );
		} catch ( Submission_Evaluation_Failure $failure ) {
			throw $failure;
		} catch ( InvalidArgumentException ) {
			throw $this->stored_invalid();
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
			throw new InvalidArgumentException( 'Submission result release actor is invalid.' );
		}
		new Public_Id( $evaluation_id );
		try {
			return $this->snapshot( $evaluation_id, false );
		} catch ( Submission_Evaluation_Failure $failure ) {
			throw $failure;
		} catch ( InvalidArgumentException ) {
			throw $this->stored_invalid();
		} catch ( Throwable ) {
			throw $this->storage_failure();
		}
	}

	/**
	 * Append release.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Submission_Result_Release_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function append_release( array $record ): Submission_Result_Release_Result {
		$this->assert_record( $record );
		$receipt = $this->command_receipt( $record['command_storage_key'], $record['request_digest'], false );
		if ( $receipt instanceof Submission_Result_Release_Result ) {
			return $receipt;
		}

		$transaction = false;
		try {
			$this->query_or_fail( 'START TRANSACTION' );
			$transaction = true;
			$receipt     = $this->command_receipt( $record['command_storage_key'], $record['request_digest'], true );
			if ( $receipt instanceof Submission_Result_Release_Result ) {
				$this->query_or_fail( 'COMMIT' );
				$transaction = false;
				return $receipt;
			}

			$snapshot = $this->snapshot( $record['evaluation_public_id'], true );
			if ( null === $snapshot ) {
				throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::NOT_FOUND );
			}
			$this->assert_locked_source( $snapshot, $record );
			$this->insert_release( $snapshot, $record );
			$this->advance_submission_pointer( $snapshot, $record );
			$result = $this->result_from_record( $record, false );
			$this->insert_audit( $record );
			$this->insert_outbox( $record, $result );
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
				$recovered = $this->command_receipt( $record['command_storage_key'], $record['request_digest'], false );
				if ( $recovered instanceof Submission_Result_Release_Result ) {
					return $recovered;
				}
				$current = $this->snapshot( $record['evaluation_public_id'], false );
				if ( is_array( $current ) && ( $record['expected_current_release_id'] !== $current['current_release_id'] || $record['expected_current_release_hash'] !== $current['current_release_hash'] ) ) {
					throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::RELEASE_SOURCE_STALE, $this->safe_state( $current ) );
				}
			} catch ( Submission_Evaluation_Failure $failure ) {
				throw $failure;
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
		$sql = 'SELECT e.id AS evaluation_pk, e.public_id AS evaluation_id, e.lock_version AS evaluation_lock_version, e.state AS evaluation_state, e.submission_revision_public_id AS submission_revision_id, e.submission_revision_hash, revision.content_hash AS joined_revision_hash, revision.state AS revision_state, s.id AS submission_pk, s.public_id AS submission_id, s.lock_version AS submission_lock_version, s.state AS submission_state, s.official_revision_public_id, s.official_review_public_id AS official_review_id, s.official_review_hash, s.current_release_public_id AS current_release_id, s.current_release_hash, a.title_snapshot AS worksheet_title, a.context_type, a.context_id, a.context_revision, a.state AS assignment_state, t.state AS target_state, review.id AS official_review_pk, review.public_id AS joined_review_id, review.content_hash AS joined_review_hash, review.decision AS official_review_decision, review.official_score, (SELECT COUNT(*) FROM ' . self::TABLE_FEEDBACK . ' counted_feedback WHERE counted_feedback.submission_evaluation_id = e.id) AS feedback_count, '
			. 'latest_feedback.id AS latest_feedback_pk, latest_feedback.public_id AS latest_feedback_id, latest_feedback.content_hash AS latest_feedback_hash, '
			. 'latest_feedback.revision_no AS latest_feedback_no, latest_feedback.audience AS latest_feedback_audience, '
			. 'latest_feedback.feedback_schema AS latest_feedback_schema, latest_feedback.feedback_version AS latest_feedback_version, '
			. 'latest_feedback.feedback_json AS latest_feedback_json, latest_feedback.feedback_hash AS latest_feedback_profile_hash, '
			. 'latest_feedback.state AS latest_feedback_state, latest_feedback.source_evaluation_run_public_id AS latest_feedback_source_run_id, '
			. 'feedback_review.public_id AS latest_feedback_review_id, feedback_review.content_hash AS latest_feedback_review_hash, '
			. 'previous_feedback.public_id AS latest_previous_feedback_id, '
			. '(SELECT COUNT(*) FROM ' . self::TABLE_RELEASES . ' counted_release WHERE counted_release.submission_evaluation_id = e.id) AS release_count, '
			. 'current_release.id AS current_release_pk, current_release.public_id AS joined_current_release_id, '
			. 'current_release.submission_evaluation_id AS current_release_evaluation_pk, '
			. 'current_evaluation.submission_revision_public_id AS current_release_revision_id, '
			. 'current_release.content_hash AS joined_current_release_hash, current_release.release_no AS current_release_no, '
			. 'current_release.state AS current_release_state, current_release.student_projection_hash AS current_projection_hash '
			. 'FROM ' . self::TABLE_EVALUATIONS . ' e '
			. 'INNER JOIN ' . self::TABLE_REVISIONS . ' revision ON revision.public_id = e.submission_revision_public_id '
			. 'INNER JOIN ' . self::TABLE_SUBMISSIONS . ' s ON s.id = revision.submission_id '
			. 'INNER JOIN ' . self::TABLE_ASSIGNMENTS . ' a ON a.id = s.assignment_id '
			. 'INNER JOIN ' . self::TABLE_TARGETS . ' t ON t.assignment_id = a.id AND t.learner_user_id = s.learner_user_id '
			. 'INNER JOIN ' . self::TABLE_REVIEWS . ' review ON review.public_id = s.official_review_public_id AND review.submission_evaluation_id = e.id '
			. 'LEFT JOIN ' . self::TABLE_FEEDBACK . ' latest_feedback ON latest_feedback.submission_evaluation_id = e.id AND latest_feedback.revision_no = '
			. '(SELECT MAX(latest_no.revision_no) FROM ' . self::TABLE_FEEDBACK . ' latest_no WHERE latest_no.submission_evaluation_id = e.id) '
			. 'LEFT JOIN ' . self::TABLE_REVIEWS . ' feedback_review ON feedback_review.id = latest_feedback.submission_review_id '
			. 'LEFT JOIN ' . self::TABLE_FEEDBACK . ' previous_feedback ON previous_feedback.id = latest_feedback.previous_revision_id '
			. 'LEFT JOIN ' . self::TABLE_RELEASES . ' current_release ON current_release.public_id = s.current_release_public_id '
			. 'LEFT JOIN ' . self::TABLE_EVALUATIONS . ' current_evaluation ON current_evaluation.id = current_release.submission_evaluation_id '
			. 'WHERE e.public_id = %s AND t.state = %s AND a.state <> %s LIMIT 1';
		if ( $for_update ) {
			$sql .= ' FOR UPDATE';
		}
		$row = $this->row( $this->prepare( $sql, array( $evaluation_id, 'active', 'withdrawn' ) ) );
		if ( null === $row ) {
			return null;
		}

		$evaluation_id = $this->public_id( $row['evaluation_id'] ?? null );
		$review_id     = $this->public_id( $row['official_review_id'] ?? null );
		$review_hash   = $this->hash_value( $row['official_review_hash'] ?? null );
		$revision_id   = $this->public_id( $row['submission_revision_id'] ?? null );
		$revision_hash = $this->hash_value( $row['submission_revision_hash'] ?? null );
		if (
			'reviewed' !== ( $row['evaluation_state'] ?? null )
			|| ! hash_equals( $revision_hash, $this->hash_value( $row['joined_revision_hash'] ?? null ) )
			|| ! hash_equals( $revision_id, $this->public_id( $row['official_revision_public_id'] ?? null ) )
			|| ! in_array( $row['revision_state'] ?? null, array( 'saved', 'reopened_fork' ), true )
			|| ! in_array( $row['submission_state'] ?? null, array( 'submitted', 'auto_submitted', 'proxy_submitted', 'locked' ), true )
			|| 'active' !== ( $row['target_state'] ?? null ) || 'withdrawn' === ( $row['assignment_state'] ?? null )
			|| ! hash_equals( $review_id, $this->public_id( $row['joined_review_id'] ?? null ) )
			|| ! hash_equals( $review_hash, $this->hash_value( $row['joined_review_hash'] ?? null ) )
			|| ! in_array( $row['official_review_decision'] ?? null, array( 'accepted', 'adjusted' ), true )
		) {
			throw $this->stored_invalid();
		}

		$feedback = $this->latest_feedback( $row, $evaluation_id, $this->non_negative_integer( $row['feedback_count'] ?? null ) );
		$current  = $this->current_release( $row, $evaluation_id, $this->non_negative_integer( $row['release_count'] ?? null ) );
		return array(
			'evaluation_pk'                   => $this->positive_integer( $row['evaluation_pk'] ?? null ),
			'evaluation_id'                   => $evaluation_id,
			'evaluation_lock_version'         => $this->non_negative_integer( $row['evaluation_lock_version'] ?? null ),
			'evaluation_state'                => 'reviewed',
			'submission_pk'                   => $this->positive_integer( $row['submission_pk'] ?? null ),
			'submission_id'                   => $this->public_id( $row['submission_id'] ?? null ),
			'submission_lock_version'         => $this->non_negative_integer( $row['submission_lock_version'] ?? null ),
			'context_type'                    => $this->token( $row['context_type'] ?? null, 16 ),
			'context_id'                      => $this->token( $row['context_id'] ?? null, 191, true ),
			'context_revision'                => $this->token( $row['context_revision'] ?? null, 191, true ),
			'worksheet_title'                 => $this->text( $row['worksheet_title'] ?? null, 255 ),
			'official_review_pk'              => $this->positive_integer( $row['official_review_pk'] ?? null ),
			'official_review_id'              => $review_id,
			'official_review_hash'            => $review_hash,
			'official_score'                  => $this->decimal( $row['official_score'] ?? null ),
			'latest_feedback_pk'              => $feedback['pk'],
			'latest_feedback_revision_id'     => $feedback['id'],
			'latest_feedback_revision_hash'   => $feedback['content_hash'],
			'latest_feedback_state'           => $feedback['state'],
			'latest_feedback_review_id'       => $feedback['review_id'],
			'latest_feedback_schema'          => $feedback['schema'],
			'latest_feedback_version'         => $feedback['version'],
			'latest_feedback_json'            => $feedback['json'],
			'latest_feedback_profile_hash'    => $feedback['profile_hash'],
			'current_release_pk'              => $current['pk'],
			'current_release_id'              => $current['id'],
			'current_release_hash'            => $current['hash'],
			'current_release_no'              => $current['no'],
			'current_release_state'           => $current['state'],
			'current_release_same_evaluation' => $current['same_evaluation'] ?? true,
			'current_projection_hash'         => $current['projection_hash'],
			'release_count'                   => $current['count'],
		);
	}

	/**
	 * Latest feedback.
	 *
	 * @param array<string,mixed> $row Row.
	 * @param string              $evaluation_id Evaluation id.
	 * @param int                 $count Count.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function latest_feedback( array $row, string $evaluation_id, int $count ): array {
		if ( 0 === $count ) {
			foreach ( array( 'latest_feedback_pk', 'latest_feedback_id', 'latest_feedback_hash', 'latest_feedback_no', 'latest_feedback_state', 'latest_feedback_review_id', 'latest_feedback_schema', 'latest_feedback_version', 'latest_feedback_json', 'latest_feedback_profile_hash' ) as $key ) {
				if ( null !== ( $row[ $key ] ?? null ) ) {
					throw $this->stored_invalid();
				}
			}
			return array(
				'pk'           => null,
				'id'           => null,
				'content_hash' => null,
				'state'        => null,
				'review_id'    => null,
				'schema'       => null,
				'version'      => null,
				'json'         => null,
				'profile_hash' => null,
			);
		}
		$pk            = $this->positive_integer( $row['latest_feedback_pk'] ?? null );
		$id            = $this->public_id( $row['latest_feedback_id'] ?? null );
		$content_hash  = $this->hash_value( $row['latest_feedback_hash'] ?? null );
		$no            = $this->positive_integer( $row['latest_feedback_no'] ?? null );
		$state         = is_string( $row['latest_feedback_state'] ?? null ) ? $row['latest_feedback_state'] : '';
		$review_id     = $this->public_id( $row['latest_feedback_review_id'] ?? null );
		$review_hash   = $this->hash_value( $row['latest_feedback_review_hash'] ?? null );
		$previous_id   = null === ( $row['latest_previous_feedback_id'] ?? null ) ? null : $this->public_id( $row['latest_previous_feedback_id'] );
		$source_run_id = null === ( $row['latest_feedback_source_run_id'] ?? null ) ? null : $this->public_id( $row['latest_feedback_source_run_id'] );
		$schema        = is_string( $row['latest_feedback_schema'] ?? null ) ? $row['latest_feedback_schema'] : '';
		$version       = $this->positive_integer( $row['latest_feedback_version'] ?? null );
		$json          = is_string( $row['latest_feedback_json'] ?? null ) ? $row['latest_feedback_json'] : '';
		$profile_hash  = $this->hash_value( $row['latest_feedback_profile_hash'] ?? null );
		$this->decode_object( $json, $profile_hash );
		$expected = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'audience'             => 'student',
					'evaluation_id'        => $evaluation_id,
					'feedback_hash'        => $profile_hash,
					'previous_revision_id' => $previous_id,
					'review_id'            => $review_id,
					'revision_no'          => $no,
					'source_run_id'        => $source_run_id,
					'state'                => $state,
				)
			)
		);
		if ( $no !== $count || 'student' !== ( $row['latest_feedback_audience'] ?? null ) || ! in_array( $state, array( 'ai_draft', 'teacher_draft', 'rejected', 'approved', 'stale' ), true ) || ( 1 === $no ) !== ( null === $previous_id ) || ! hash_equals( $content_hash, $expected ) || ! hash_equals( $review_hash, $this->hash_value( $row['latest_feedback_review_hash'] ?? null ) ) ) {
			throw $this->stored_invalid();
		}
		return array(
			'pk'           => $pk,
			'id'           => $id,
			'content_hash' => $content_hash,
			'state'        => $state,
			'review_id'    => $review_id,
			'schema'       => $schema,
			'version'      => $version,
			'json'         => $json,
			'profile_hash' => $profile_hash,
		);
	}

	/**
	 * Current release.
	 *
	 * @param array<string,mixed> $row Row.
	 * @param string              $evaluation_id Evaluation id.
	 * @param int                 $count Count.
	 * @return array{pk:?int,id:?string,hash:?string,no:int,state:?string,projection_hash:?string,count:int,same_evaluation?:bool}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function current_release( array $row, string $evaluation_id, int $count ): array {
		$id   = $row['current_release_id'] ?? null;
		$hash = $row['current_release_hash'] ?? null;
		if ( ( null === $id ) !== ( null === $hash ) ) {
			throw $this->stored_invalid();
		}
		if ( null === $id ) {
			foreach ( array( 'current_release_pk', 'joined_current_release_id', 'joined_current_release_hash', 'current_release_no', 'current_release_state', 'current_projection_hash' ) as $key ) {
				if ( null !== ( $row[ $key ] ?? null ) ) {
					throw $this->stored_invalid();
				}
			}
			return array(
				'pk'              => null,
				'id'              => null,
				'hash'            => null,
				'no'              => 0,
				'state'           => null,
				'projection_hash' => null,
				'count'           => $count,
			);
		}
		$id     = $this->public_id( $id );
		$hash   = $this->hash_value( $hash );
		$same   = $this->positive_integer( $row['current_release_evaluation_pk'] ?? null ) === $this->positive_integer( $row['evaluation_pk'] ?? null );
		$number = $this->positive_integer( $row['current_release_no'] ?? null );
		if ( ! hash_equals( $id, $this->public_id( $row['joined_current_release_id'] ?? null ) ) || ! hash_equals( $hash, $this->hash_value( $row['joined_current_release_hash'] ?? null ) ) || ( $same && ( $count < 1 || $number !== $count ) ) || ! in_array( $row['current_release_state'] ?? null, array( 'released', 'corrected', 'withdrawn', 'stale' ), true ) ) {
			throw $this->stored_invalid();
		}
		if ( ! $same && ( 'stale' !== $row['current_release_state'] || ! hash_equals( $this->public_id( $row['submission_revision_id'] ?? null ), $this->public_id( $row['current_release_revision_id'] ?? null ) ) ) ) {
			throw $this->stored_invalid();
		}
		return array(
			'pk'              => $this->positive_integer( $row['current_release_pk'] ?? null ),
			'id'              => $id,
			'hash'            => $hash,
			'no'              => $number,
			'state'           => (string) $row['current_release_state'],
			'projection_hash' => $this->hash_value( $row['current_projection_hash'] ?? null ),
			'count'           => $count,
			'same_evaluation' => $same,
		);
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
		$feedback_matches   = null === $record['expected_feedback_revision_id'] || ( $record['expected_feedback_revision_id'] === $snapshot['latest_feedback_revision_id'] && $record['expected_feedback_revision_hash'] === $snapshot['latest_feedback_revision_hash'] && 'approved' === $snapshot['latest_feedback_state'] && $record['expected_official_review_id'] === $snapshot['latest_feedback_review_id'] );
		$first_release      = 'released' === $record['state'];
		$transition_matches = $first_release
			? null === $snapshot['current_release_id']
				&& 0 === $snapshot['release_count']
				&& 1 === $record['release_no']
				&& null === $record['previous_release_id']
			: 'corrected' === $record['state']
				&& null !== $snapshot['current_release_id']
				&& in_array( $snapshot['current_release_state'], array( 'released', 'corrected', 'withdrawn', 'stale' ), true )
				&& $record['release_no'] === $snapshot['release_count'] + 1
				&& $record['previous_release_id'] === $snapshot['current_release_id'];
		if (
			$record['expected_evaluation_lock_version'] !== $snapshot['evaluation_lock_version']
			|| $record['expected_evaluation_state'] !== $snapshot['evaluation_state']
			|| $record['expected_submission_lock_version'] !== $snapshot['submission_lock_version']
			|| $record['context_type'] !== $snapshot['context_type'] || $record['context_id'] !== $snapshot['context_id'] || $record['context_revision'] !== $snapshot['context_revision']
			|| $record['expected_official_review_id'] !== $snapshot['official_review_id'] || $record['expected_official_review_hash'] !== $snapshot['official_review_hash']
			|| $record['expected_current_release_id'] !== $snapshot['current_release_id'] || $record['expected_current_release_hash'] !== $snapshot['current_release_hash']
			|| ! $transition_matches || ! $feedback_matches
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::RELEASE_SOURCE_STALE, $this->safe_state( $snapshot ) );
		}
	}

	/**
	 * Insert release.
	 *
	 * @param array<string,mixed> $snapshot Snapshot.
	 * @param array<string,mixed> $record Record.
	 * @return void
	 */
	private function insert_release( array $snapshot, array $record ): void {
		$this->insert_or_fail(
			self::TABLE_RELEASES,
			array(
				'public_id'                  => $record['release_public_id'],
				'content_hash'               => $record['content_hash'],
				'created_by_user_id'         => $record['actor_user_id'],
				'created_at_utc'             => $record['released_at_utc'],
				'submission_evaluation_id'   => $snapshot['evaluation_pk'],
				'submission_review_id'       => $snapshot['official_review_pk'],
				'feedback_revision_id'       => null === $record['expected_feedback_revision_id'] ? null : $snapshot['latest_feedback_pk'],
				'release_no'                 => $record['release_no'],
				'state'                      => $record['state'],
				'visible_score'              => $record['visible_score'],
				'student_projection_schema'  => $record['projection_schema'],
				'student_projection_version' => $record['projection_version'],
				'student_projection_json'    => $record['projection_json'],
				'student_projection_hash'    => $record['projection_hash'],
				'previous_release_id'        => null === $record['previous_release_id'] ? null : $snapshot['current_release_pk'],
				'release_reason_code'        => $record['reason_code'],
				'release_reason_note'        => $record['reason_note'],
				'released_at_utc'            => $record['released_at_utc'],
			)
		);
	}

	/**
	 * Advance submission pointer.
	 *
	 * @param array<string,mixed> $snapshot Snapshot.
	 * @param array<string,mixed> $record Record.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function advance_submission_pointer( array $snapshot, array $record ): void {
		if ( 'released' === $record['state'] ) {
			$sql       = 'UPDATE ' . self::TABLE_SUBMISSIONS . ' SET current_release_public_id = %s, current_release_hash = %s, lock_version = lock_version + 1, updated_by_user_id = %d, updated_at_utc = %s WHERE id = %d AND lock_version = %d AND official_review_public_id = %s AND official_review_hash = %s AND current_release_public_id IS NULL AND current_release_hash IS NULL';
			$arguments = array( $record['release_public_id'], $record['content_hash'], $record['actor_user_id'], $record['released_at_utc'], $snapshot['submission_pk'], $record['expected_submission_lock_version'], $record['expected_official_review_id'], $record['expected_official_review_hash'] );
		} else {
			$sql       = 'UPDATE ' . self::TABLE_SUBMISSIONS . ' SET current_release_public_id = %s, current_release_hash = %s, lock_version = lock_version + 1, updated_by_user_id = %d, updated_at_utc = %s WHERE id = %d AND lock_version = %d AND official_review_public_id = %s AND official_review_hash = %s AND current_release_public_id = %s AND current_release_hash = %s';
			$arguments = array( $record['release_public_id'], $record['content_hash'], $record['actor_user_id'], $record['released_at_utc'], $snapshot['submission_pk'], $record['expected_submission_lock_version'], $record['expected_official_review_id'], $record['expected_official_review_hash'], $record['expected_current_release_id'], $record['expected_current_release_hash'] );
		}
		$changed = $this->query( $this->prepare( $sql, $arguments ) );
		if ( 1 !== $changed ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::RELEASE_SOURCE_STALE, $this->safe_state( $snapshot ) );
		}
	}

	/**
	 * Insert audit.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function insert_audit( array $record ): void {
		$event         = $this->event_type( $record['state'] );
		$metadata      = array(
			'command_id'           => $record['command_id'],
			'feedback_revision_id' => $record['expected_feedback_revision_id'],
			'official_review_id'   => $record['expected_official_review_id'],
			'previous_release_id'  => $record['previous_release_id'],
			'projection_hash'      => $record['projection_hash'],
			'reason_code'          => $record['reason_code'],
			'release_id'           => $record['release_public_id'],
			'request_digest'       => $record['request_digest'],
			'state'                => $record['state'],
		);
		$json          = $this->canonical->encode( $metadata );
		$metadata_hash = hash( 'sha256', $json );
		$previous      = $this->scalar( 'SELECT event_hash FROM ' . self::TABLE_AUDIT . ' ORDER BY id DESC LIMIT 1 FOR UPDATE' );
		if ( null !== $previous && 1 !== preg_match( '/^[a-f0-9]{64}$/D', (string) $previous ) ) {
			throw $this->stored_invalid();
		}
		$previous   = null === $previous ? null : (string) $previous;
		$event_hash = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'action_key'          => $event,
					'actor_user_id'       => $record['actor_user_id'],
					'after_digest'        => $record['content_hash'],
					'before_digest'       => $record['expected_current_release_hash'],
					'correlation_id'      => $record['correlation_id'],
					'metadata_hash'       => $metadata_hash,
					'object_public_id'    => $record['evaluation_public_id'],
					'object_type'         => 'submission_evaluation',
					'occurred_at_utc'     => $record['released_at_utc'],
					'previous_event_hash' => $previous,
				)
			)
		);
		$this->insert_or_fail(
			self::TABLE_AUDIT,
			array(
				'public_id'           => $record['audit_public_id'],
				'occurred_at_utc'     => $record['released_at_utc'],
				'actor_user_id'       => $record['actor_user_id'],
				'action_key'          => $event,
				'object_type'         => 'submission_evaluation',
				'object_public_id'    => $record['evaluation_public_id'],
				'context_type'        => $record['context_type'],
				'context_id'          => $record['context_id'],
				'context_revision'    => $record['context_revision'],
				'correlation_id'      => $record['correlation_id'],
				'before_digest'       => $record['expected_current_release_hash'],
				'after_digest'        => $record['content_hash'],
				'metadata_schema'     => self::AUDIT_SCHEMA,
				'metadata_version'    => self::PROFILE_VERSION,
				'metadata_json'       => $json,
				'metadata_hash'       => $metadata_hash,
				'previous_event_hash' => $previous,
				'event_hash'          => $event_hash,
			)
		);
	}

	/**
	 * Insert outbox.
	 *
	 * @param array<string,mixed>              $record Record.
	 * @param Submission_Result_Release_Result $result Result.
	 * @return void
	 */
	private function insert_outbox( array $record, Submission_Result_Release_Result $result ): void {
		$payload = array(
			'evaluation_id'  => $result->evaluation_id(),
			'release_id'     => $result->release_id(),
			'release_path'   => $result->release_path(),
			'request_digest' => $record['request_digest'],
		);
		$json    = $this->canonical->encode( $payload );
		$event   = $this->event_type( $record['state'] );
		$this->insert_or_fail(
			self::TABLE_OUTBOX,
			array(
				'public_id'            => $record['outbox_public_id'],
				'lock_version'         => 0,
				'created_by_user_id'   => $record['actor_user_id'],
				'created_at_utc'       => $record['released_at_utc'],
				'updated_by_user_id'   => $record['actor_user_id'],
				'updated_at_utc'       => $record['released_at_utc'],
				'aggregate_type'       => 'submission_evaluation',
				'aggregate_public_id'  => $record['evaluation_public_id'],
				'event_type'           => $event,
				'idempotency_key'      => $record['command_storage_key'],
				'payload_schema'       => self::OUTBOX_SCHEMA,
				'payload_version'      => self::PROFILE_VERSION,
				'payload_json'         => $json,
				'payload_hash'         => hash( 'sha256', $json ),
				'available_at_utc'     => $record['released_at_utc'],
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
	 * @return Submission_Result_Release_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function command_receipt( string $key, string $digest, bool $for_update ): ?Submission_Result_Release_Result {
		$sql = 'SELECT aggregate_public_id, event_type, payload_schema, payload_version, payload_json, payload_hash FROM ' . self::TABLE_OUTBOX . ' WHERE idempotency_key = %s AND event_type IN (%s,%s) LIMIT 1';
		if ( $for_update ) {
			$sql .= ' FOR UPDATE';
		}
		$row = $this->row( $this->prepare( $sql, array( $key, self::EVENT_RELEASED, self::EVENT_CORRECTED ) ) );
		if ( null === $row ) {
			return null;
		}
		$event = $row['event_type'] ?? null;
		if ( ! in_array( $event, array( self::EVENT_RELEASED, self::EVENT_CORRECTED ), true ) || self::OUTBOX_SCHEMA !== ( $row['payload_schema'] ?? null ) || self::PROFILE_VERSION !== $this->positive_integer( $row['payload_version'] ?? null ) ) {
			throw $this->stored_invalid();
		}
		$payload = $this->decode_object( $row['payload_json'] ?? null, $this->hash_value( $row['payload_hash'] ?? null ) );
		$this->exact_keys( $payload, array( 'evaluation_id', 'release_id', 'release_path', 'request_digest' ) );
		if ( ! hash_equals( $digest, (string) ( $payload['request_digest'] ?? '' ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::IDEMPOTENCY_CONFLICT );
		}
		$evaluation_id = $this->public_id( $payload['evaluation_id'] ?? null );
		$release_id    = $this->public_id( $payload['release_id'] ?? null );
		if ( ! hash_equals( $evaluation_id, (string) ( $row['aggregate_public_id'] ?? '' ) ) || '/oj-addons/oj-ai-dashboard/results/' . $release_id !== ( $payload['release_path'] ?? null ) ) {
			throw $this->stored_invalid();
		}
		$result = $this->result_for_release( $release_id, true );
		if ( $event !== $this->event_type( $result->state() ) ) {
			throw $this->stored_invalid();
		}
		return $result;
	}

	/**
	 * Result for release.
	 *
	 * @param string $release_id Release id.
	 * @param bool   $replayed Replayed.
	 * @return Submission_Result_Release_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function result_for_release( string $release_id, bool $replayed ): Submission_Result_Release_Result {
		$row = $this->row( $this->prepare( 'SELECT release_row.content_hash AS release_hash, release_row.release_no, release_row.state, release_row.visible_score, release_row.student_projection_schema, release_row.student_projection_version, release_row.student_projection_json, release_row.student_projection_hash, release_row.release_reason_code, release_row.release_reason_note, release_row.released_at_utc, evaluation.public_id AS evaluation_id, review.public_id AS review_id, review.content_hash AS review_hash, feedback.public_id AS feedback_id, previous.public_id AS previous_release_id FROM ' . self::TABLE_RELEASES . ' release_row INNER JOIN ' . self::TABLE_EVALUATIONS . ' evaluation ON evaluation.id = release_row.submission_evaluation_id INNER JOIN ' . self::TABLE_REVIEWS . ' review ON review.id = release_row.submission_review_id LEFT JOIN ' . self::TABLE_FEEDBACK . ' feedback ON feedback.id = release_row.feedback_revision_id LEFT JOIN ' . self::TABLE_RELEASES . ' previous ON previous.id = release_row.previous_release_id WHERE release_row.public_id = %s LIMIT 1', array( $release_id ) ) );
		if ( null === $row ) {
			throw $this->stored_invalid();
		}
		$evaluation_id   = $this->public_id( $row['evaluation_id'] ?? null );
		$review_id       = $this->public_id( $row['review_id'] ?? null );
		$review_hash     = $this->hash_value( $row['review_hash'] ?? null );
		$feedback_id     = null === ( $row['feedback_id'] ?? null ) ? null : $this->public_id( $row['feedback_id'] );
		$previous        = null === ( $row['previous_release_id'] ?? null ) ? null : $this->public_id( $row['previous_release_id'] );
		$no              = $this->positive_integer( $row['release_no'] ?? null );
		$state           = is_string( $row['state'] ?? null ) ? $row['state'] : '';
		$score           = null === ( $row['visible_score'] ?? null ) ? null : $this->decimal( $row['visible_score'] );
		$released        = $this->datetime( $row['released_at_utc'] ?? null );
		$projection_hash = $this->hash_value( $row['student_projection_hash'] ?? null );
		$projection_json = is_string( $row['student_projection_json'] ?? null ) ? $row['student_projection_json'] : '';
		$projection      = $this->decode_object( $projection_json, $projection_hash );
		if ( Submission_Result_Projection::SCHEMA !== ( $row['student_projection_schema'] ?? null ) || Submission_Result_Projection::VERSION !== $this->positive_integer( $row['student_projection_version'] ?? null ) ) {
			throw $this->stored_invalid();
		}
		new Submission_Result_Projection( $projection, $projection_json, $projection_hash, $score );
		$expected     = $this->release_content_hash( $evaluation_id, $review_id, $feedback_id, $no, $state, $score, $projection_hash, $previous, null === ( $row['release_reason_code'] ?? null ) ? null : (string) $row['release_reason_code'], null === ( $row['release_reason_note'] ?? null ) ? null : (string) $row['release_reason_note'], $released );
		$release_hash = $this->hash_value( $row['release_hash'] ?? null );
		if ( ! hash_equals( $release_hash, $expected ) ) {
			throw $this->stored_invalid();
		}
		return new Submission_Result_Release_Result( $evaluation_id, $review_id, $review_hash, $feedback_id, $release_id, $release_hash, $no, $state, $projection_hash, $score, $released, $replayed );
	}

	/**
	 * Result from record.
	 *
	 * @param array<string,mixed> $record Record.
	 * @param bool                $replayed Replayed.
	 * @return Submission_Result_Release_Result
	 */
	private function result_from_record( array $record, bool $replayed ): Submission_Result_Release_Result {
		return new Submission_Result_Release_Result( $record['evaluation_public_id'], $record['expected_official_review_id'], $record['expected_official_review_hash'], $record['expected_feedback_revision_id'], $record['release_public_id'], $record['content_hash'], $record['release_no'], $record['state'], $record['projection_hash'], $record['visible_score'], $record['released_at_utc'], $replayed );
	}

	/**
	 * Reject invalid postcondition.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_postcondition( array $record ): void {
		$result = $this->result_for_release( $record['release_public_id'], false );
		$row    = $this->row( $this->prepare( 'SELECT current_release_public_id, current_release_hash, lock_version FROM ' . self::TABLE_SUBMISSIONS . ' WHERE public_id = (SELECT submission.public_id FROM ' . self::TABLE_SUBMISSIONS . ' submission INNER JOIN ' . self::TABLE_REVISIONS . ' revision ON revision.submission_id = submission.id INNER JOIN ' . self::TABLE_EVALUATIONS . ' evaluation ON evaluation.submission_revision_public_id = revision.public_id WHERE evaluation.public_id = %s LIMIT 1) LIMIT 1', array( $record['evaluation_public_id'] ) ) );
		if ( ! hash_equals( $result->release_hash(), $record['content_hash'] ) || null === $row || $record['release_public_id'] !== ( $row['current_release_public_id'] ?? null ) || $record['content_hash'] !== ( $row['current_release_hash'] ?? null ) || $record['expected_submission_lock_version'] + 1 !== $this->non_negative_integer( $row['lock_version'] ?? null ) ) {
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
		$expected = array( 'actor_user_id', 'audit_public_id', 'command_id', 'command_storage_key', 'content_hash', 'context_id', 'context_revision', 'context_type', 'correlation_id', 'evaluation_public_id', 'expected_current_release_hash', 'expected_current_release_id', 'expected_evaluation_lock_version', 'expected_evaluation_state', 'expected_feedback_revision_hash', 'expected_feedback_revision_id', 'expected_official_review_hash', 'expected_official_review_id', 'expected_submission_lock_version', 'outbox_public_id', 'previous_release_id', 'projection_hash', 'projection_json', 'projection_schema', 'projection_version', 'reason_code', 'reason_note', 'release_no', 'release_public_id', 'released_at_utc', 'request_digest', 'state', 'visible_score' );
		$this->exact_keys_argument( $record, $expected );
		if ( ! is_int( $record['actor_user_id'] ) || $record['actor_user_id'] < 1 || ! is_int( $record['expected_evaluation_lock_version'] ) || $record['expected_evaluation_lock_version'] < 0 || ! is_int( $record['expected_submission_lock_version'] ) || $record['expected_submission_lock_version'] < 0 || ! is_int( $record['release_no'] ) || $record['release_no'] < 1 || ! in_array( $record['state'], array( 'released', 'corrected' ), true ) || 'reviewed' !== $record['expected_evaluation_state'] || Submission_Result_Projection::SCHEMA !== $record['projection_schema'] || Submission_Result_Projection::VERSION !== $record['projection_version'] ) {
			throw new InvalidArgumentException( 'Submission result release record is invalid.' );
		}
		foreach ( array( 'audit_public_id', 'evaluation_public_id', 'expected_official_review_id', 'outbox_public_id', 'release_public_id' ) as $key ) {
			new Public_Id( (string) $record[ $key ] );
		}
		if ( null !== $record['previous_release_id'] ) {
			new Public_Id( (string) $record['previous_release_id'] );
		}
		$this->pointer_argument( $record['expected_feedback_revision_id'], $record['expected_feedback_revision_hash'] );
		$this->pointer_argument( $record['expected_current_release_id'], $record['expected_current_release_hash'] );
		$reason_note   = $this->reason_note_argument( $record['reason_note'] );
		$first_release = 'released' === $record['state'] && 1 === $record['release_no'] && null === $record['expected_current_release_id'] && null === $record['previous_release_id'] && null === $record['reason_code'] && null === $reason_note;
		$correction    = 'corrected' === $record['state'] && is_string( $record['expected_current_release_id'] ) && $record['previous_release_id'] === $record['expected_current_release_id'] && is_string( $record['reason_code'] ) && in_array( $record['reason_code'], self::REASON_CODES, true ) && ( 'other' !== $record['reason_code'] || null !== $reason_note );
		if ( ( ! $first_release && ! $correction ) || $reason_note !== $record['reason_note'] ) {
			throw new InvalidArgumentException( 'Submission result release transition is invalid.' );
		}
		foreach ( array( 'content_hash', 'expected_official_review_hash', 'projection_hash', 'request_digest' ) as $key ) {
			$this->hash_argument( $record[ $key ] );
		}
		$this->storage_key( $record['command_storage_key'] );
		$this->datetime_argument( $record['released_at_utc'] );
		foreach ( array(
			'command_id'       => 128,
			'correlation_id'   => 100,
			'context_type'     => 16,
			'context_id'       => 191,
			'context_revision' => 191,
		) as $key => $maximum ) {
			$this->token_argument( $record[ $key ], $maximum, in_array( $key, array( 'context_id', 'context_revision' ), true ) );
		}
		$score = null === $record['visible_score'] ? null : $this->decimal_argument( $record['visible_score'] );
		if ( ! is_string( $record['projection_json'] ) || ! hash_equals( $record['projection_hash'], hash( 'sha256', $record['projection_json'] ) ) ) {
			throw new InvalidArgumentException( 'Submission result projection record is invalid.' );
		}
		try {
			$projection = json_decode( $record['projection_json'], true, 128, JSON_THROW_ON_ERROR );
		} catch ( JsonException ) {
			throw new InvalidArgumentException( 'Submission result projection record is invalid.' );
		}
		if ( ! is_array( $projection ) || array_is_list( $projection ) || ! hash_equals( $record['projection_json'], $this->canonical->encode( $projection ) ) ) {
			throw new InvalidArgumentException( 'Submission result projection record is invalid.' );
		}
		new Submission_Result_Projection( $projection, $record['projection_json'], $record['projection_hash'], $score );
		$expected_hash = $this->release_content_hash( $record['evaluation_public_id'], $record['expected_official_review_id'], $record['expected_feedback_revision_id'], $record['release_no'], $record['state'], $score, $record['projection_hash'], $record['previous_release_id'], $record['reason_code'], $reason_note, $record['released_at_utc'] );
		if ( ! hash_equals( $record['content_hash'], $expected_hash ) ) {
			throw new InvalidArgumentException( 'Submission result release content hash is invalid.' );
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
	 * @param string|null $previous Previous.
	 * @param string|null $reason_code Reason code.
	 * @param string|null $reason_note Reason note.
	 * @param string      $released_at Released at.
	 * @return string
	 */
	private function release_content_hash( string $evaluation_id, string $review_id, ?string $feedback_id, int $release_no, string $state, ?string $visible_score, string $projection_hash, ?string $previous, ?string $reason_code, ?string $reason_note, string $released_at ): string {
		return hash(
			'sha256',
			$this->canonical->encode(
				array(
					'evaluation_id'              => $evaluation_id,
					'feedback_id'                => $feedback_id,
					'previous_release_id'        => $previous,
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
	 * Event type.
	 *
	 * @param string $state State.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function event_type( string $state ): string {
		return match ( $state ) {
			'released' => self::EVENT_RELEASED, 'corrected' => self::EVENT_CORRECTED, default => throw new InvalidArgumentException( 'Submission result release event state is invalid.' ) };
	}
	/**
	 * Reason note argument.
	 *
	 * @param mixed $value Value.
	 * @return string|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function reason_note_argument( $value ): ?string {
		if ( null === $value ) {
			return null;
		} if ( ! is_string( $value ) ) {
			throw new InvalidArgumentException( 'Submission result release reason note is invalid.' );
		} $normalized = trim( $value );
		if ( '' === $normalized || strlen( $normalized ) > 2000 || 1 === preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $normalized ) ) {
			throw new InvalidArgumentException( 'Submission result release reason note is invalid.' );
		} return $normalized;
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
			'current_official_review_hash'   => (string) ( $snapshot['official_review_hash'] ?? '' ),
			'current_official_review_id'     => (string) ( $snapshot['official_review_id'] ?? '' ),
			'current_release_hash'           => (string) ( $snapshot['current_release_hash'] ?? '' ),
			'current_release_id'             => (string) ( $snapshot['current_release_id'] ?? '' ),
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
		} return trim( $value );
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
	 * Pointer argument.
	 *
	 * @param mixed $id Id.
	 * @param mixed $hash Hash.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function pointer_argument( $id, $hash ): void {
		if ( ( null === $id ) !== ( null === $hash ) ) {
			throw new InvalidArgumentException( 'Submission result release pointer is invalid.' );
		} if ( null !== $id ) {
			new Public_Id( (string) $id );
			$this->hash_argument( $hash );
		}
	}
	/**
	 * Hash argument.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function hash_argument( $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $value ) ) {
			throw new InvalidArgumentException( 'Submission result release hash is invalid.' );
		} return $value;
	}
	/**
	 * Decimal argument.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function decimal_argument( $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^(0|[1-9][0-9]{0,7})(?:\.([0-9]{1,4}))?$/D', $value, $matches ) ) {
			throw new InvalidArgumentException( 'Submission result release decimal is invalid.' );
		} return $matches[1] . '.' . str_pad( $matches[2] ?? '', 4, '0' );
	}
	/**
	 * Datetime argument.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function datetime_argument( $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value ) ) {
			throw new InvalidArgumentException( 'Submission result release UTC time is invalid.' );
		} return $value;
	}
	/**
	 * Token argument.
	 *
	 * @param mixed $value Value.
	 * @param int   $maximum Maximum.
	 * @param bool  $slash Slash.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function token_argument( $value, int $maximum, bool $slash = false ): string {
		if ( ! is_string( $value ) ) {
			throw new InvalidArgumentException( 'Submission result release token is invalid.' );
		} $pattern = $slash ? '/^[A-Za-z0-9][A-Za-z0-9_.:@\/-]*$/D' : '/^[A-Za-z0-9][A-Za-z0-9_.:@-]*$/D';
		if ( '' === $value || strlen( $value ) > $maximum || 1 !== preg_match( $pattern, $value ) ) {
			throw new InvalidArgumentException( 'Submission result release token is invalid.' );
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
	 * Exact keys argument.
	 *
	 * @param array<string,mixed> $value Value.
	 * @param array<int,string>   $expected Expected.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function exact_keys_argument( array $value, array $expected ): void {
		$actual = array_keys( $value );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected ) {
			throw new InvalidArgumentException( 'Submission result release record shape is invalid.' );
		}
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
			throw new InvalidArgumentException( 'Submission result release storage key is invalid.' );
		} return $value;
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
