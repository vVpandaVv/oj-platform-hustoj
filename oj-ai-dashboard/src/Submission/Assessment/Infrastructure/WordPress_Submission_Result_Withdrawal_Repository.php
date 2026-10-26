<?php
/**
 * C44 immutable withdrawal revision and current pointer transaction.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Infrastructure;

use InvalidArgumentException;
use JsonException;
use OJAI\Dashboard\Submission\Assessment\Application\Submission_Evaluation_Failure;
use OJAI\Dashboard\Submission\Assessment\Application\Submission_Result_Withdrawal_Result;
use OJAI\Dashboard\Submission\Assessment\Application\Withdraw_Submission_Result_Command;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Result_Projection;
use OJAI\Dashboard\Submission\Assessment\Port\Submission_Result_Withdrawal_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * C44 immutable withdrawal revision and current pointer transaction.
 */
final class WordPress_Submission_Result_Withdrawal_Repository implements Submission_Result_Withdrawal_Repository_Port_Interface {
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

	private const EVENT           = 'submission_result.withdrawn';
	private const AUDIT_SCHEMA    = 'oj_ai.submission_result_withdrawal_audit';
	private const OUTBOX_SCHEMA   = 'oj_ai.submission_result_withdrawal_event';
	private const PROFILE_VERSION = 1;

	/**
	 * Initialize WordPress submission result withdrawal repository dependencies and state.
	 *
	 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
	 * @param object|null    $database Database.
	 */
	public function __construct( private Canonical_Json $canonical, private ?object $database = null ) {
	}

	/**
	 * Find withdrawal receipt.
	 *
	 * @param string $command_storage_key Command storage key.
	 * @param string $request_digest Request digest.
	 * @return Submission_Result_Withdrawal_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function find_withdrawal_receipt( string $command_storage_key, string $request_digest ): ?Submission_Result_Withdrawal_Result {
		$this->storage_key( $command_storage_key );
		$this->hash_argument( $request_digest );
		try {
			return $this->withdrawal_receipt( $command_storage_key, $request_digest, false ); } catch ( Submission_Evaluation_Failure $failure ) {
			throw $failure;
			} catch ( InvalidArgumentException ) {
				throw $this->stored_invalid();
			} catch ( Throwable ) {
				throw $this->storage_failure();
			}
	}

	/**
	 * Find owned withdrawal snapshot.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $evaluation_id Evaluation id.
	 * @return array<string,mixed>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function find_owned_withdrawal_snapshot( int $actor_user_id, string $evaluation_id ): ?array {
		if ( $actor_user_id < 1 ) {
			throw new InvalidArgumentException( 'Submission result withdrawal actor is invalid.' );
		}
		new Public_Id( $evaluation_id );
		try {
			return $this->snapshot( $evaluation_id, false ); } catch ( Submission_Evaluation_Failure $failure ) {
			throw $failure;
			} catch ( InvalidArgumentException ) {
				throw $this->stored_invalid();
			} catch ( Throwable ) {
				throw $this->storage_failure();
			}
	}

	/**
	 * Append withdrawal.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Submission_Result_Withdrawal_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function append_withdrawal( array $record ): Submission_Result_Withdrawal_Result {
		$this->assert_record( $record );
		$receipt = $this->withdrawal_receipt( $record['command_storage_key'], $record['request_digest'], false );
		if ( $receipt instanceof Submission_Result_Withdrawal_Result ) {
			return $receipt;
		}

		$transaction = false;
		try {
			$this->query_or_fail( 'START TRANSACTION' );
			$transaction = true;
			$receipt     = $this->withdrawal_receipt( $record['command_storage_key'], $record['request_digest'], true );
			if ( $receipt instanceof Submission_Result_Withdrawal_Result ) {
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
				$recovered = $this->withdrawal_receipt( $record['command_storage_key'], $record['request_digest'], false );
				if ( $recovered instanceof Submission_Result_Withdrawal_Result ) {
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
		$sql = 'SELECT e.id AS evaluation_pk, e.public_id AS evaluation_id, e.lock_version AS evaluation_lock_version, e.state AS evaluation_state, e.submission_revision_public_id AS submission_revision_id, e.submission_revision_hash, revision.content_hash AS joined_revision_hash, revision.state AS revision_state, s.id AS submission_pk, s.public_id AS submission_id, s.lock_version AS submission_lock_version, s.state AS submission_state, s.official_revision_public_id, s.official_review_public_id AS official_review_id, s.official_review_hash, s.current_release_public_id AS current_release_id, s.current_release_hash, a.context_type, a.context_id, a.context_revision, a.state AS assignment_state, t.state AS target_state, official_review.public_id AS joined_official_review_id, official_review.content_hash AS joined_official_review_hash, official_review.decision AS official_review_decision, (SELECT COUNT(*) FROM ' . self::TABLE_RELEASES . ' counted_release WHERE counted_release.submission_evaluation_id = e.id) AS release_count, '
			. 'current_release.id AS current_release_pk, current_release.public_id AS joined_current_release_id, '
			. 'current_release.content_hash AS joined_current_release_hash, current_release.submission_evaluation_id AS current_release_evaluation_pk, '
			. 'current_release.submission_review_id AS current_release_review_pk, current_release.feedback_revision_id AS current_release_feedback_pk, '
			. 'current_release.previous_release_id AS current_release_previous_pk, current_release.release_no AS current_release_no, '
			. 'current_release.state AS current_release_state, current_release.visible_score AS current_release_visible_score, '
			. 'current_release.student_projection_schema AS current_release_projection_schema, current_release.student_projection_version AS current_release_projection_version, '
			. 'current_release.student_projection_json AS current_release_projection_json, current_release.student_projection_hash AS current_release_projection_hash, '
			. 'current_release.release_reason_code AS current_release_reason_code, current_release.release_reason_note AS current_release_reason_note, '
			. 'current_release.released_at_utc AS current_release_released_at_utc, release_review.public_id AS current_release_review_id, '
			. 'release_review.content_hash AS current_release_review_hash, release_feedback.public_id AS current_release_feedback_id, '
			. 'previous_release.public_id AS current_release_previous_id, previous_release.submission_evaluation_id AS previous_release_evaluation_pk, previous_evaluation.submission_revision_public_id AS previous_revision_id '
			. 'FROM ' . self::TABLE_EVALUATIONS . ' e '
			. 'INNER JOIN ' . self::TABLE_REVISIONS . ' revision ON revision.public_id = e.submission_revision_public_id '
			. 'INNER JOIN ' . self::TABLE_SUBMISSIONS . ' s ON s.id = revision.submission_id '
			. 'INNER JOIN ' . self::TABLE_ASSIGNMENTS . ' a ON a.id = s.assignment_id '
			. 'INNER JOIN ' . self::TABLE_TARGETS . ' t ON t.assignment_id = a.id AND t.learner_user_id = s.learner_user_id '
			. 'INNER JOIN ' . self::TABLE_REVIEWS . ' official_review ON official_review.public_id = s.official_review_public_id AND official_review.submission_evaluation_id = e.id '
			. 'LEFT JOIN ' . self::TABLE_RELEASES . ' current_release ON current_release.public_id = s.current_release_public_id '
			. 'LEFT JOIN ' . self::TABLE_REVIEWS . ' release_review ON release_review.id = current_release.submission_review_id '
			. 'LEFT JOIN ' . self::TABLE_FEEDBACK . ' release_feedback ON release_feedback.id = current_release.feedback_revision_id '
			. 'LEFT JOIN ' . self::TABLE_RELEASES . ' previous_release ON previous_release.id = current_release.previous_release_id '
			. 'LEFT JOIN ' . self::TABLE_EVALUATIONS . ' previous_evaluation ON previous_evaluation.id = previous_release.submission_evaluation_id '
			. 'WHERE e.public_id = %s AND t.state = %s AND a.state <> %s LIMIT 1';
		if ( $for_update ) {
			$sql .= ' FOR UPDATE';
		}
		$row = $this->row( $this->prepare( $sql, array( $evaluation_id, 'active', 'withdrawn' ) ) );
		if ( null === $row ) {
			return null;
		}

		$evaluation_pk = $this->positive_integer( $row['evaluation_pk'] ?? null );
		$evaluation_id = $this->public_id( $row['evaluation_id'] ?? null );
		$official_id   = $this->public_id( $row['official_review_id'] ?? null );
		$official_hash = $this->hash_value( $row['official_review_hash'] ?? null );
		$revision_id   = $this->public_id( $row['submission_revision_id'] ?? null );
		$revision_hash = $this->hash_value( $row['submission_revision_hash'] ?? null );
		if (
			'reviewed' !== ( $row['evaluation_state'] ?? null )
			|| ! hash_equals( $revision_hash, $this->hash_value( $row['joined_revision_hash'] ?? null ) )
			|| ! hash_equals( $revision_id, $this->public_id( $row['official_revision_public_id'] ?? null ) )
			|| ! in_array( $row['revision_state'] ?? null, array( 'saved', 'reopened_fork' ), true )
			|| ! in_array( $row['submission_state'] ?? null, array( 'submitted', 'auto_submitted', 'proxy_submitted', 'locked' ), true )
			|| 'active' !== ( $row['target_state'] ?? null ) || 'withdrawn' === ( $row['assignment_state'] ?? null )
			|| ! hash_equals( $official_id, $this->public_id( $row['joined_official_review_id'] ?? null ) )
			|| ! hash_equals( $official_hash, $this->hash_value( $row['joined_official_review_hash'] ?? null ) )
			|| ! in_array( $row['official_review_decision'] ?? null, array( 'accepted', 'adjusted' ), true )
		) {
			throw $this->stored_invalid();
		}

		$base         = array(
			'evaluation_pk'           => $evaluation_pk,
			'evaluation_id'           => $evaluation_id,
			'evaluation_lock_version' => $this->non_negative_integer( $row['evaluation_lock_version'] ?? null ),
			'evaluation_state'        => 'reviewed',
			'submission_pk'           => $this->positive_integer( $row['submission_pk'] ?? null ),
			'submission_id'           => $this->public_id( $row['submission_id'] ?? null ),
			'submission_lock_version' => $this->non_negative_integer( $row['submission_lock_version'] ?? null ),
			'context_type'            => $this->token( $row['context_type'] ?? null, 16 ),
			'context_id'              => $this->token( $row['context_id'] ?? null, 191, true ),
			'context_revision'        => $this->token( $row['context_revision'] ?? null, 191, true ),
			'official_review_id'      => $official_id,
			'official_review_hash'    => $official_hash,
		);
		$count        = $this->non_negative_integer( $row['release_count'] ?? null );
		$current_id   = $row['current_release_id'] ?? null;
		$current_hash = $row['current_release_hash'] ?? null;
		if ( ( null === $current_id ) !== ( null === $current_hash ) ) {
			throw $this->stored_invalid();
		}
		if ( null === $current_id ) {
			if ( 0 !== $count ) {
				throw $this->stored_invalid();
			}
			foreach ( array( 'current_release_pk', 'joined_current_release_id', 'joined_current_release_hash', 'current_release_no', 'current_release_state', 'current_release_projection_hash' ) as $key ) {
				if ( null !== ( $row[ $key ] ?? null ) ) {
					throw $this->stored_invalid();
				}
			}
			return $base + array(
				'current_release_pk'                 => null,
				'current_release_id'                 => null,
				'current_release_hash'               => null,
				'current_release_no'                 => 0,
				'current_release_state'              => null,
				'current_release_visible_score'      => null,
				'current_release_projection_schema'  => null,
				'current_release_projection_version' => null,
				'current_release_projection_json'    => null,
				'current_release_projection_hash'    => null,
				'current_release_review_pk'          => null,
				'current_release_review_id'          => null,
				'current_release_review_hash'        => null,
				'current_release_feedback_pk'        => null,
				'current_release_feedback_id'        => null,
				'current_release_previous_pk'        => null,
				'current_release_previous_id'        => null,
				'current_release_reason_code'        => null,
				'current_release_reason_note'        => null,
				'current_release_released_at_utc'    => null,
				'release_count'                      => 0,
			);
		}

		$current_id             = $this->public_id( $current_id );
		$current_hash           = $this->hash_value( $current_hash );
		$release_pk             = $this->positive_integer( $row['current_release_pk'] ?? null );
		$release_no             = $this->positive_integer( $row['current_release_no'] ?? null );
		$state                  = $this->release_state( $row['current_release_state'] ?? null );
		$review_pk              = $this->positive_integer( $row['current_release_review_pk'] ?? null );
		$review_id              = $this->public_id( $row['current_release_review_id'] ?? null );
		$review_hash            = $this->hash_value( $row['current_release_review_hash'] ?? null );
		$feedback_pk            = $this->optional_positive_integer( $row['current_release_feedback_pk'] ?? null );
		$feedback_id            = null === $feedback_pk ? null : $this->public_id( $row['current_release_feedback_id'] ?? null );
		$previous_pk            = $this->optional_positive_integer( $row['current_release_previous_pk'] ?? null );
		$previous_id            = null === $previous_pk ? null : $this->public_id( $row['current_release_previous_id'] ?? null );
		$previous_evaluation_pk = null === $previous_pk ? null : $this->positive_integer( $row['previous_release_evaluation_pk'] ?? null );
		$score                  = null === ( $row['current_release_visible_score'] ?? null ) ? null : $this->decimal( $row['current_release_visible_score'] );
		$schema                 = is_string( $row['current_release_projection_schema'] ?? null ) ? $row['current_release_projection_schema'] : '';
		$version                = $this->positive_integer( $row['current_release_projection_version'] ?? null );
		$json                   = is_string( $row['current_release_projection_json'] ?? null ) ? $row['current_release_projection_json'] : '';
		$projection_hash        = $this->hash_value( $row['current_release_projection_hash'] ?? null );
		$projection             = $this->decode_object( $json, $projection_hash );
		try {
			new Submission_Result_Projection( $projection, $json, $projection_hash, $score ); } catch ( InvalidArgumentException ) {
			throw $this->stored_invalid();
			}
			$reason_code = $this->optional_reason_code( $row['current_release_reason_code'] ?? null );
			$reason_note = $this->optional_text( $row['current_release_reason_note'] ?? null, 2000 );
			$released_at = $this->datetime( $row['current_release_released_at_utc'] ?? null );
			if (
			$this->positive_integer( $row['current_release_evaluation_pk'] ?? null ) !== $evaluation_pk
			|| ! hash_equals( $current_id, $this->public_id( $row['joined_current_release_id'] ?? null ) )
			|| ! hash_equals( $current_hash, $this->hash_value( $row['joined_current_release_hash'] ?? null ) )
			|| $release_no !== $count
			|| ( 'released' === $state ) !== ( null === $previous_pk )
			|| ( null !== $previous_evaluation_pk && $evaluation_pk !== $previous_evaluation_pk && $this->public_id( $row['previous_revision_id'] ?? null ) !== $this->public_id( $row['submission_revision_id'] ?? null ) )
			|| Submission_Result_Projection::SCHEMA !== $schema
			|| Submission_Result_Projection::VERSION !== $version
			|| ( 'released' === $state && ( 1 !== $release_no || null !== $reason_code || null !== $reason_note ) )
			|| ( 'released' !== $state && null === $reason_code )
			|| ( 'other' === $reason_code && null === $reason_note )
			) {
				throw $this->stored_invalid();
			}
			$expected_hash = $this->release_content_hash( $evaluation_id, $review_id, $feedback_id, $release_no, $state, $score, $projection_hash, $previous_id, $reason_code, $reason_note, $released_at );
			if ( ! hash_equals( $current_hash, $expected_hash ) ) {
				throw $this->stored_invalid();
			}
			if ( in_array( $state, array( 'released', 'corrected' ), true ) ) {
				$projected_release = $projection['release'] ?? null;
				if ( ! is_array( $projected_release ) || array_is_list( $projected_release ) || $state !== ( $projected_release['state'] ?? null ) || $released_at !== ( $projected_release['released_at_utc'] ?? null ) ) {
					throw $this->stored_invalid();
				}
			}

			return $base + array(
				'current_release_pk'                 => $release_pk,
				'current_release_id'                 => $current_id,
				'current_release_hash'               => $current_hash,
				'current_release_no'                 => $release_no,
				'current_release_state'              => $state,
				'current_release_visible_score'      => $score,
				'current_release_projection_schema'  => $schema,
				'current_release_projection_version' => $version,
				'current_release_projection_json'    => $json,
				'current_release_projection_hash'    => $projection_hash,
				'current_release_review_pk'          => $review_pk,
				'current_release_review_id'          => $review_id,
				'current_release_review_hash'        => $review_hash,
				'current_release_feedback_pk'        => $feedback_pk,
				'current_release_feedback_id'        => $feedback_id,
				'current_release_previous_pk'        => $previous_pk,
				'current_release_previous_id'        => $previous_id,
				'current_release_reason_code'        => $reason_code,
				'current_release_reason_note'        => $reason_note,
				'current_release_released_at_utc'    => $released_at,
				'release_count'                      => $count,
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
		if (
			$record['expected_evaluation_lock_version'] !== $snapshot['evaluation_lock_version']
			|| $record['expected_evaluation_state'] !== $snapshot['evaluation_state']
			|| $record['expected_submission_lock_version'] !== $snapshot['submission_lock_version']
			|| $record['context_type'] !== $snapshot['context_type'] || $record['context_id'] !== $snapshot['context_id'] || $record['context_revision'] !== $snapshot['context_revision']
			|| $record['expected_official_review_id'] !== $snapshot['official_review_id'] || $record['expected_official_review_hash'] !== $snapshot['official_review_hash']
			|| $record['expected_current_release_id'] !== $snapshot['current_release_id'] || $record['expected_current_release_hash'] !== $snapshot['current_release_hash']
			|| $record['expected_current_release_no'] !== $snapshot['current_release_no'] || $record['expected_current_release_state'] !== $snapshot['current_release_state']
			|| ! in_array( $snapshot['current_release_state'], array( 'released', 'corrected' ), true )
			|| $record['review_id'] !== $snapshot['current_release_review_id'] || $record['review_hash'] !== $snapshot['current_release_review_hash']
			|| $record['feedback_revision_id'] !== $snapshot['current_release_feedback_id']
			|| $record['projection_schema'] !== $snapshot['current_release_projection_schema'] || $record['projection_version'] !== $snapshot['current_release_projection_version']
			|| $record['projection_json'] !== $snapshot['current_release_projection_json'] || $record['projection_hash'] !== $snapshot['current_release_projection_hash']
			|| $record['visible_score'] !== $snapshot['current_release_visible_score']
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
				'submission_review_id'       => $snapshot['current_release_review_pk'],
				'feedback_revision_id'       => $snapshot['current_release_feedback_pk'],
				'release_no'                 => $record['release_no'],
				'state'                      => 'withdrawn',
				'visible_score'              => $record['visible_score'],
				'student_projection_schema'  => $record['projection_schema'],
				'student_projection_version' => $record['projection_version'],
				'student_projection_json'    => $record['projection_json'],
				'student_projection_hash'    => $record['projection_hash'],
				'previous_release_id'        => $snapshot['current_release_pk'],
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
		$sql     = 'UPDATE ' . self::TABLE_SUBMISSIONS . ' SET current_release_public_id = %s, current_release_hash = %s, lock_version = lock_version + 1, updated_by_user_id = %d, updated_at_utc = %s WHERE id = %d AND lock_version = %d AND official_review_public_id = %s AND official_review_hash = %s AND current_release_public_id = %s AND current_release_hash = %s';
		$changed = $this->query( $this->prepare( $sql, array( $record['release_public_id'], $record['content_hash'], $record['actor_user_id'], $record['released_at_utc'], $snapshot['submission_pk'], $record['expected_submission_lock_version'], $record['expected_official_review_id'], $record['expected_official_review_hash'], $record['expected_current_release_id'], $record['expected_current_release_hash'] ) ) );
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
		$metadata      = array(
			'command_id'          => $record['command_id'],
			'previous_release_id' => $record['previous_release_id'],
			'reason_code'         => $record['reason_code'],
			'release_id'          => $record['release_public_id'],
			'request_digest'      => $record['request_digest'],
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
					'action_key'          => self::EVENT,
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
				'action_key'          => self::EVENT,
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
	 * @param array<string,mixed>                 $record Record.
	 * @param Submission_Result_Withdrawal_Result $result Result.
	 * @return void
	 */
	private function insert_outbox( array $record, Submission_Result_Withdrawal_Result $result ): void {
		$payload = array(
			'evaluation_id'  => $result->evaluation_id(),
			'release_id'     => $result->release_id(),
			'release_path'   => $result->release_path(),
			'request_digest' => $record['request_digest'],
		);
		$json    = $this->canonical->encode( $payload );
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
				'event_type'           => self::EVENT,
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
	 * Withdrawal receipt.
	 *
	 * @param string $key Key.
	 * @param string $digest Digest.
	 * @param bool   $for_update For update.
	 * @return Submission_Result_Withdrawal_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function withdrawal_receipt( string $key, string $digest, bool $for_update ): ?Submission_Result_Withdrawal_Result {
		$sql = 'SELECT aggregate_public_id, payload_schema, payload_version, payload_json, payload_hash FROM ' . self::TABLE_OUTBOX . ' WHERE idempotency_key = %s AND event_type = %s LIMIT 1';
		if ( $for_update ) {
			$sql .= ' FOR UPDATE';
		}
		$row = $this->row( $this->prepare( $sql, array( $key, self::EVENT ) ) );
		if ( null === $row ) {
			return null;
		}
		if ( self::OUTBOX_SCHEMA !== ( $row['payload_schema'] ?? null ) || self::PROFILE_VERSION !== $this->positive_integer( $row['payload_version'] ?? null ) ) {
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
		return $this->result_for_release( $release_id, true );
	}

	/**
	 * Result for release.
	 *
	 * @param string $release_id Release id.
	 * @param bool   $replayed Replayed.
	 * @return Submission_Result_Withdrawal_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function result_for_release( string $release_id, bool $replayed ): Submission_Result_Withdrawal_Result {
		$row = $this->row( $this->prepare( 'SELECT release_row.content_hash AS release_hash, release_row.release_no, release_row.state, release_row.visible_score, release_row.student_projection_schema, release_row.student_projection_version, release_row.student_projection_json, release_row.student_projection_hash, release_row.release_reason_code, release_row.release_reason_note, release_row.released_at_utc, evaluation.public_id AS evaluation_id, review.public_id AS review_id, review.content_hash AS review_hash, feedback.public_id AS feedback_id, previous.public_id AS previous_release_id FROM ' . self::TABLE_RELEASES . ' release_row INNER JOIN ' . self::TABLE_EVALUATIONS . ' evaluation ON evaluation.id = release_row.submission_evaluation_id INNER JOIN ' . self::TABLE_REVIEWS . ' review ON review.id = release_row.submission_review_id LEFT JOIN ' . self::TABLE_FEEDBACK . ' feedback ON feedback.id = release_row.feedback_revision_id INNER JOIN ' . self::TABLE_RELEASES . ' previous ON previous.id = release_row.previous_release_id WHERE release_row.public_id = %s LIMIT 1', array( $release_id ) ) );
		if ( null === $row ) {
			throw $this->stored_invalid();
		}
		$evaluation_id   = $this->public_id( $row['evaluation_id'] ?? null );
		$review_id       = $this->public_id( $row['review_id'] ?? null );
		$review_hash     = $this->hash_value( $row['review_hash'] ?? null );
		$feedback_id     = null === ( $row['feedback_id'] ?? null ) ? null : $this->public_id( $row['feedback_id'] );
		$previous_id     = $this->public_id( $row['previous_release_id'] ?? null );
		$no              = $this->positive_integer( $row['release_no'] ?? null );
		$state           = $this->release_state( $row['state'] ?? null );
		$score           = null === ( $row['visible_score'] ?? null ) ? null : $this->decimal( $row['visible_score'] );
		$released        = $this->datetime( $row['released_at_utc'] ?? null );
		$projection_hash = $this->hash_value( $row['student_projection_hash'] ?? null );
		$projection_json = is_string( $row['student_projection_json'] ?? null ) ? $row['student_projection_json'] : '';
		$projection      = $this->decode_object( $projection_json, $projection_hash );
		if ( Submission_Result_Projection::SCHEMA !== ( $row['student_projection_schema'] ?? null ) || Submission_Result_Projection::VERSION !== $this->positive_integer( $row['student_projection_version'] ?? null ) ) {
			throw $this->stored_invalid();
		}
		try {
			new Submission_Result_Projection( $projection, $projection_json, $projection_hash, $score );
		} catch ( InvalidArgumentException ) {
			throw $this->stored_invalid();
		}
		$reason_code = $this->reason_code( $row['release_reason_code'] ?? null );
		$reason_note = $this->optional_text( $row['release_reason_note'] ?? null, 2000 );
		if ( 'withdrawn' !== $state || $no < 2 || ( 'other' === $reason_code && null === $reason_note ) ) {
			throw $this->stored_invalid();
		}
		$expected     = $this->release_content_hash( $evaluation_id, $review_id, $feedback_id, $no, $state, $score, $projection_hash, $previous_id, $reason_code, $reason_note, $released );
		$release_hash = $this->hash_value( $row['release_hash'] ?? null );
		if ( ! hash_equals( $release_hash, $expected ) ) {
			throw $this->stored_invalid();
		}
		return new Submission_Result_Withdrawal_Result( $evaluation_id, $review_id, $review_hash, $feedback_id, $previous_id, $release_id, $release_hash, $no, $projection_hash, $score, $reason_code, $released, $replayed );
	}

	/**
	 * Result from record.
	 *
	 * @param array<string,mixed> $record Record.
	 * @param bool                $replayed Replayed.
	 * @return Submission_Result_Withdrawal_Result
	 */
	private function result_from_record( array $record, bool $replayed ): Submission_Result_Withdrawal_Result {
		return new Submission_Result_Withdrawal_Result( $record['evaluation_public_id'], $record['review_id'], $record['review_hash'], $record['feedback_revision_id'], $record['previous_release_id'], $record['release_public_id'], $record['content_hash'], $record['release_no'], $record['projection_hash'], $record['visible_score'], $record['reason_code'], $record['released_at_utc'], $replayed );
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
		$expected = array( 'actor_user_id', 'audit_public_id', 'command_id', 'command_storage_key', 'content_hash', 'context_id', 'context_revision', 'context_type', 'correlation_id', 'evaluation_public_id', 'expected_current_release_hash', 'expected_current_release_id', 'expected_current_release_no', 'expected_current_release_state', 'expected_evaluation_lock_version', 'expected_evaluation_state', 'expected_official_review_hash', 'expected_official_review_id', 'expected_submission_lock_version', 'feedback_revision_id', 'outbox_public_id', 'previous_release_id', 'projection_hash', 'projection_json', 'projection_schema', 'projection_version', 'reason_code', 'reason_note', 'release_no', 'release_public_id', 'released_at_utc', 'request_digest', 'review_hash', 'review_id', 'state', 'visible_score' );
		$this->exact_keys_argument( $record, $expected );
		if ( ! is_int( $record['actor_user_id'] ) || $record['actor_user_id'] < 1 || ! is_int( $record['expected_evaluation_lock_version'] ) || $record['expected_evaluation_lock_version'] < 0 || ! is_int( $record['expected_submission_lock_version'] ) || $record['expected_submission_lock_version'] < 0 || ! is_int( $record['expected_current_release_no'] ) || $record['expected_current_release_no'] < 1 || $record['release_no'] !== $record['expected_current_release_no'] + 1 || 'withdrawn' !== $record['state'] || 'reviewed' !== $record['expected_evaluation_state'] || ! in_array( $record['expected_current_release_state'], array( 'released', 'corrected' ), true ) || $record['previous_release_id'] !== $record['expected_current_release_id'] || $record['review_id'] !== $record['expected_official_review_id'] || $record['review_hash'] !== $record['expected_official_review_hash'] || Submission_Result_Projection::SCHEMA !== $record['projection_schema'] || Submission_Result_Projection::VERSION !== $record['projection_version'] ) {
			throw new InvalidArgumentException( 'Submission result withdrawal record is invalid.' );
		}
		foreach ( array( 'audit_public_id', 'evaluation_public_id', 'expected_current_release_id', 'expected_official_review_id', 'outbox_public_id', 'previous_release_id', 'release_public_id', 'review_id' ) as $key ) {
			new Public_Id( (string) $record[ $key ] );
		}
		if ( null !== $record['feedback_revision_id'] ) {
			new Public_Id( (string) $record['feedback_revision_id'] );
		}
		foreach ( array( 'content_hash', 'expected_current_release_hash', 'expected_official_review_hash', 'projection_hash', 'request_digest', 'review_hash' ) as $key ) {
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
		$reason_code = $this->reason_code_argument( $record['reason_code'] );
		$reason_note = $this->optional_text_argument( $record['reason_note'], 2000 );
		if ( 'other' === $reason_code && null === $reason_note ) {
			throw new InvalidArgumentException( 'Submission result withdrawal reason is incomplete.' );
		}
		$score = null === $record['visible_score'] ? null : $this->decimal_argument( $record['visible_score'] );
		if ( ! is_string( $record['projection_json'] ) || ! hash_equals( $record['projection_hash'], hash( 'sha256', $record['projection_json'] ) ) ) {
			throw new InvalidArgumentException( 'Submission result withdrawal projection is invalid.' );
		}
		try {
			$projection = json_decode( $record['projection_json'], true, 128, JSON_THROW_ON_ERROR );
		} catch ( JsonException ) {
			throw new InvalidArgumentException( 'Submission result withdrawal projection is invalid.' );
		}
		if ( ! is_array( $projection ) || array_is_list( $projection ) || ! hash_equals( $record['projection_json'], $this->canonical->encode( $projection ) ) ) {
			throw new InvalidArgumentException( 'Submission result withdrawal projection is invalid.' );
		}
		new Submission_Result_Projection( $projection, $record['projection_json'], $record['projection_hash'], $score );
		$expected_hash = $this->release_content_hash( $record['evaluation_public_id'], $record['review_id'], $record['feedback_revision_id'], $record['release_no'], 'withdrawn', $score, $record['projection_hash'], $record['previous_release_id'], $reason_code, $reason_note, $record['released_at_utc'] );
		if ( ! hash_equals( $record['content_hash'], $expected_hash ) ) {
			throw new InvalidArgumentException( 'Submission result withdrawal content hash is invalid.' );
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
	 * Safe state.
	 *
	 * @param array<string,mixed> $snapshot Snapshot.
	 * @return array<string,int|string>
	 */
	private function safe_state( array $snapshot ): array {
		return array(
			'current_official_review_hash' => (string) ( $snapshot['official_review_hash'] ?? '' ),
			'current_official_review_id'   => (string) ( $snapshot['official_review_id'] ?? '' ),
			'current_release_hash'         => (string) ( $snapshot['current_release_hash'] ?? '' ),
			'current_release_id'           => (string) ( $snapshot['current_release_id'] ?? '' ),
			'current_release_state'        => (string) ( $snapshot['current_release_state'] ?? '' ),
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
	 * Optional positive integer.
	 *
	 * @param mixed $value Value.
	 * @return int|null
	 */
	private function optional_positive_integer( $value ): ?int {
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
		} if ( ! is_string( $value ) || '' === trim( $value ) || strlen( trim( $value ) ) > $maximum || 1 === preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value ) ) {
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
	 * Optional reason code.
	 *
	 * @param mixed $value Value.
	 * @return string|null
	 */
	private function optional_reason_code( $value ): ?string {
		return null === $value ? null : $this->reason_code( $value );
	}
	/**
	 * Reason code.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function reason_code( $value ): string {
		if ( ! is_string( $value ) || ! in_array( $value, Withdraw_Submission_Result_Command::REASON_CODES, true ) ) {
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
		if ( ! is_string( $value ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $value ) ) {
			throw new InvalidArgumentException( 'Submission result withdrawal hash is invalid.' );
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
			throw new InvalidArgumentException( 'Submission result withdrawal decimal is invalid.' );
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
			throw new InvalidArgumentException( 'Submission result withdrawal UTC time is invalid.' );
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
			throw new InvalidArgumentException( 'Submission result withdrawal token is invalid.' );
		} $pattern = $slash ? '/^[A-Za-z0-9][A-Za-z0-9_.:@\/-]*$/D' : '/^[A-Za-z0-9][A-Za-z0-9_.:@-]*$/D';
		if ( '' === $value || strlen( $value ) > $maximum || 1 !== preg_match( $pattern, $value ) ) {
			throw new InvalidArgumentException( 'Submission result withdrawal token is invalid.' );
		} return $value;
	}
	/**
	 * Optional text argument.
	 *
	 * @param mixed $value Value.
	 * @param int   $maximum Maximum.
	 * @return string|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function optional_text_argument( $value, int $maximum ): ?string {
		if ( null === $value ) {
			return null;
		} if ( ! is_string( $value ) || '' === trim( $value ) || strlen( trim( $value ) ) > $maximum || 1 === preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value ) ) {
			throw new InvalidArgumentException( 'Submission result withdrawal note is invalid.' );
		} return trim( $value );
	}
	/**
	 * Reason code argument.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function reason_code_argument( $value ): string {
		if ( ! is_string( $value ) || ! in_array( $value, Withdraw_Submission_Result_Command::REASON_CODES, true ) ) {
			throw new InvalidArgumentException( 'Submission result withdrawal reason code is invalid.' );
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
			throw new InvalidArgumentException( 'Submission result withdrawal record shape is invalid.' );
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
			throw new InvalidArgumentException( 'Submission result withdrawal storage key is invalid.' );
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
