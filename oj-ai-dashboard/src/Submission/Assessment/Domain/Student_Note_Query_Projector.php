<?php
/**
 * Safe Q18 and Q19 teacher-only student-note projections.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Domain;

use InvalidArgumentException;
use JsonException;
use OJAI\Dashboard\Submission\Assessment\Application\Submission_Evaluation_Failure;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Safe Q18 and Q19 teacher-only student-note projections.
 */
final class Student_Note_Query_Projector {
	private const NOTE_STATES          = array( 'ai_draft', 'teacher_draft', 'approved', 'rejected', 'superseded' );
	private const EVALUATION_STATES    = array( 'requested', 'queued', 'running', 'review_required', 'reviewed', 'failed', 'cancelled' );
	private const SUBMISSION_STATES    = array( 'draft', 'conflict', 'submitted', 'auto_submitted', 'proxy_submitted', 'reopened', 'locked' );
	private const MAXIMUM_HISTORY      = 500;
	private const FORBIDDEN_AUDIT_KEYS = array(
		'actor_user_id',
		'created_by_user_id',
		'updated_by_user_id',
		'correlation_id',
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
	);

	/**
	 * Initialize student note query projector dependencies and state.
	 *
	 * @param Canonical_Json                 $canonical Canonical JSON encoder used for stable digests.
	 * @param Student_Note_Output_Normalizer $normalizer Normalizer.
	 */
	public function __construct(
		private Canonical_Json $canonical,
		private Student_Note_Output_Normalizer $normalizer
	) {
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
			$base     = $this->base( $row );
			$revision = $this->revision( $row, $row );
			$count    = $this->positive_integer( $row['revision_count'] ?? null );
			if ( $count !== $revision['revision_no'] ) {
				throw new InvalidArgumentException( 'Student-note latest revision count drifted.' );
			}

			return array(
				'id'              => $revision['note_id'],
				'assignment'      => $base['assignment'],
				'context'         => $base['context'],
				'learner'         => $base['learner'],
				'submission'      => $base['submission'],
				'evaluation'      => $base['evaluation'],
				'latest_revision' => array(
					'id'                 => $revision['id'],
					'revision_no'        => $revision['revision_no'],
					'state'              => $revision['state'],
					'include_in_reports' => $revision['include_in_reports'],
					'summary'            => $revision['content']['summary']['text'],
					'created_at_utc'     => $revision['created_at_utc'],
				),
				'status'          => array(
					'revision_count'         => $count,
					'review_required'        => in_array( $revision['state'], array( 'ai_draft', 'teacher_draft' ), true ),
					'report_status'          => $revision['include_in_reports'] ? 'included' : 'excluded',
					'can_include_in_reports' => 'approved' === $revision['state'] && ! $revision['include_in_reports'],
				),
				'updated_at_utc'  => $revision['created_at_utc'],
			);
		} catch ( Submission_Evaluation_Failure $failure ) {
			throw $failure;
		} catch ( Throwable ) {
			throw $this->stored_invalid();
		}
	}

	/**
	 * Detail.
	 *
	 * @param array<string,mixed>            $root Root.
	 * @param array<int,array<string,mixed>> $revision_rows Revision rows.
	 * @param array<int,array<string,mixed>> $audit_rows Audit rows.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function detail( array $root, array $revision_rows, array $audit_rows ): array {
		try {
			$note = $this->list_item( $root );
			if ( array() === $revision_rows || count( $revision_rows ) > self::MAXIMUM_HISTORY ) {
				throw new InvalidArgumentException( 'Student-note revision history is invalid.' );
			}
			$revisions   = array();
			$known_ids   = array();
			$previous_id = null;
			foreach ( $revision_rows as $index => $row ) {
				$revision = $this->revision( $row, $root );
				if (
					$revision['note_id'] !== $note['id']
					|| $revision['revision_no'] !== $index + 1
					|| $revision['previous_revision_id'] !== $previous_id
				) {
					throw new InvalidArgumentException( 'Student-note revision chain drifted.' );
				}
				$known_ids[] = $revision['id'];
				$previous_id = $revision['id'];
				unset( $revision['note_id'] );
				$revisions[] = $revision;
			}
			$latest = $revisions[ count( $revisions ) - 1 ];
			if (
				$latest['id'] !== $note['latest_revision']['id']
				|| $latest['revision_no'] !== $note['status']['revision_count']
				|| $latest['state'] !== $note['latest_revision']['state']
				|| $latest['include_in_reports'] !== $note['latest_revision']['include_in_reports']
			) {
				throw new InvalidArgumentException( 'Student-note latest revision drifted.' );
			}

			return array(
				'note'         => $note,
				'revisions'    => $revisions,
				'audit'        => $this->audit( $audit_rows, $root, $known_ids ),
				'precondition' => array(
					'latest_revision_id'   => $latest['id'],
					'latest_revision_hash' => $latest['content_hash'],
					'latest_revision_no'   => $latest['revision_no'],
					'latest_state'         => $latest['state'],
					'include_in_reports'   => $latest['include_in_reports'],
				),
			);
		} catch ( Submission_Evaluation_Failure $failure ) {
			throw $failure;
		} catch ( Throwable ) {
			throw $this->stored_invalid();
		}
	}

	/**
	 * Base.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function base( array $row ): array {
		$assignment_state = $this->one_of( $row['assignment_state'] ?? null, array( 'draft', 'scheduled', 'open', 'closed', 'withdrawn' ) );
		if ( 'withdrawn' === $assignment_state || 'active' !== $this->one_of( $row['target_state'] ?? null, array( 'active', 'revoked' ) ) ) {
			throw new InvalidArgumentException( 'Student-note access snapshot is inactive.' );
		}
		$source_hash = $this->sha256( $row['evaluation_submission_revision_hash'] ?? null );
		if ( ! hash_equals( $source_hash, $this->sha256( $row['source_revision_content_hash'] ?? null ) ) ) {
			throw new InvalidArgumentException( 'Student-note submission source drifted.' );
		}
		if (
			! hash_equals( $this->public_id( $row['evaluation_publication_id'] ?? null ), $this->public_id( $row['assignment_publication_id'] ?? null ) )
			|| ! hash_equals( $this->sha256( $row['evaluation_publication_hash'] ?? null ), $this->sha256( $row['assignment_publication_hash'] ?? null ) )
		) {
			throw new InvalidArgumentException( 'Student-note publication source drifted.' );
		}

		return array(
			'assignment' => array(
				'id'    => $this->public_id( $row['assignment_id'] ?? null ),
				'title' => $this->text( $row['assignment_title'] ?? null, 255, false ),
				'state' => $assignment_state,
			),
			'context'    => array(
				'type'     => $this->one_of( $row['context_type'] ?? null, array( 'class', 'group' ) ),
				'id'       => $this->token( $row['context_id'] ?? null, 191, true ),
				'revision' => $this->token( $row['context_revision'] ?? null, 191, true ),
			),
			'learner'    => $this->learner( $row ),
			'submission' => array(
				'id'    => $this->public_id( $row['submission_id'] ?? null ),
				'state' => $this->one_of( $row['submission_state'] ?? null, self::SUBMISSION_STATES ),
			),
			'evaluation' => array(
				'id'                 => $this->public_id( $row['evaluation_id'] ?? null ),
				'state'              => $this->one_of( $row['evaluation_state'] ?? null, self::EVALUATION_STATES ),
				'official_review_id' => $this->public_id( $row['official_review_id'] ?? null ),
			),
		);
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
			throw new InvalidArgumentException( 'Student-note learner display snapshot drifted.' );
		}
		$display = $this->decode_canonical( $row['display_snapshot_json'] ?? null, $this->sha256( $row['display_snapshot_hash'] ?? null ) );
		$this->exact_keys( $display, array( 'display_name' ) );
		return array(
			'user_id'             => $this->positive_integer( $row['learner_user_id'] ?? null ),
			'display_name'        => $this->text( $display['display_name'] ?? null, 255, false ),
			'membership_revision' => $this->token( $row['membership_revision'] ?? null, 191, true ),
		);
	}

	/**
	 * Revision.
	 *
	 * @param array<string,mixed> $row Row.
	 * @param array<string,mixed> $root Root.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function revision( array $row, array $root ): array {
		$note_id     = $this->public_id( $row['note_root_id'] ?? null );
		$revision_id = $this->public_id( $row['note_revision_id'] ?? null );
		$revision_no = $this->positive_integer( $row['note_revision_no'] ?? null );
		$state       = $this->one_of( $row['note_state'] ?? null, self::NOTE_STATES );
		$include     = $this->database_boolean( $row['include_in_reports'] ?? null );
		if ( $include && 'approved' !== $state ) {
			throw new InvalidArgumentException( 'Only approved student notes may be included in reports.' );
		}
		$approved_by = $this->nullable_positive_integer( $row['approved_by_user_id'] ?? null );
		$approved_at = $this->nullable_datetime( $row['approved_at_utc'] ?? null );
		if ( ( null === $approved_by ) !== ( null === $approved_at ) || ( 'approved' === $state && null === $approved_at ) ) {
			throw new InvalidArgumentException( 'Student-note approval evidence is inconsistent.' );
		}
		if ( in_array( $state, array( 'ai_draft', 'teacher_draft', 'rejected' ), true ) && null !== $approved_at ) {
			throw new InvalidArgumentException( 'Unapproved student note carries approval evidence.' );
		}

		$evaluation_pk = $this->positive_integer( $root['evaluation_pk'] ?? null );
		if ( $evaluation_pk !== $this->positive_integer( $row['note_evaluation_pk'] ?? null ) ) {
			throw new InvalidArgumentException( 'Student-note evaluation link drifted.' );
		}
		$official_review_id   = $this->public_id( $row['note_review_id'] ?? null );
		$official_review_hash = $this->sha256( $row['note_review_hash'] ?? null );
		if (
			$evaluation_pk !== $this->positive_integer( $row['note_review_evaluation_pk'] ?? null )
		) {
			throw new InvalidArgumentException( 'Student-note official review provenance drifted.' );
		}

		if (
			Student_Note_Input_Builder::RESULT_SCHEMA !== ( $row['note_schema'] ?? null )
			|| 1 !== $this->positive_integer( $row['note_version'] ?? null )
		) {
			throw new InvalidArgumentException( 'Student-note stored profile schema drifted.' );
		}
		$profile = $this->normalizer->validate_stored_profile(
			$this->decode_canonical( $row['note_json'] ?? null, $this->sha256( $row['note_hash'] ?? null ) )
		);
		if ( ! hash_equals( $official_review_hash, $profile['source_hash'] ) ) {
			throw new InvalidArgumentException( 'Student-note profile source drifted.' );
		}

		$source_run_id = $this->nullable_public_id( $row['note_source_run_id'] ?? null );
		$source_run    = null;
		if ( null === $source_run_id ) {
			foreach ( array( 'joined_source_run_id', 'source_run_subject_type', 'source_run_evaluation_id', 'source_run_operation_key', 'source_run_state', 'source_run_result_type', 'source_run_result_id' ) as $key ) {
				if ( null !== ( $row[ $key ] ?? null ) ) {
					throw new InvalidArgumentException( 'Student-note source run is partial.' );
				}
			}
		} else {
			$joined_id     = $this->public_id( $row['joined_source_run_id'] ?? null );
			$run_result_id = $this->public_id( $row['source_run_result_id'] ?? null );
			if (
				! hash_equals( $source_run_id, $joined_id )
				|| 'submission_evaluation' !== ( $row['source_run_subject_type'] ?? null )
				|| ! hash_equals( $this->public_id( $root['evaluation_id'] ?? null ), $this->public_id( $row['source_run_evaluation_id'] ?? null ) )
				|| Student_Note_Input_Builder::OPERATION !== ( $row['source_run_operation_key'] ?? null )
				|| 'succeeded' !== ( $row['source_run_state'] ?? null )
				|| 'student_note_revision' !== ( $row['source_run_result_type'] ?? null )
				|| ( 'ai_draft' === $state && ! hash_equals( $revision_id, $run_result_id ) )
			) {
				throw new InvalidArgumentException( 'Student-note source run drifted.' );
			}
			$source_run = $source_run_id;
		}
		if ( 'ai_draft' === $state && null === $source_run ) {
			throw new InvalidArgumentException( 'AI student-note draft lacks a source run.' );
		}

		return array(
			'note_id'              => $note_id,
			'id'                   => $revision_id,
			'content_hash'         => $this->sha256( $row['note_content_hash'] ?? null ),
			'note_hash'            => $this->sha256( $row['note_hash'] ?? null ),
			'revision_no'          => $revision_no,
			'state'                => $state,
			'include_in_reports'   => $include,
			'content'              => array(
				'summary'             => $profile['summary'],
				'strengths'           => $profile['strengths'],
				'support_suggestions' => $profile['support_suggestions'],
				'evidence'            => $profile['evidence'],
				'safety_flags'        => $profile['safety_flags'],
			),
			'source'               => array(
				'note_ref'             => $profile['note_ref'],
				'official_review_id'   => $official_review_id,
				'official_review_hash' => $official_review_hash,
				'evaluation_run_id'    => $source_run,
				'source_hash'          => $profile['source_hash'],
			),
			'previous_revision_id' => $this->nullable_public_id( $row['previous_note_revision_id'] ?? null ),
			'approval'             => array(
				'is_approved'     => 'approved' === $state,
				'approved_at_utc' => $approved_at,
			),
			'created_at_utc'       => $this->datetime( $row['note_created_at_utc'] ?? null ),
		);
	}

	/**
	 * Audit.
	 *
	 * @param array<int,array<string,mixed>> $rows Rows.
	 * @param array<string,mixed>            $root Root.
	 * @param array<int,string>              $known_revision_ids Known revision ids.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function audit( array $rows, array $root, array $known_revision_ids ): array {
		if ( count( $rows ) > self::MAXIMUM_HISTORY ) {
			throw new InvalidArgumentException( 'Student-note audit history is too large.' );
		}
		$result = array();
		foreach ( $rows as $row ) {
			$action = $row['audit_action'] ?? null;
			if ( ! is_string( $action ) || 1 !== preg_match( '/^student_note\.[a-z][a-z0-9_]*$/D', $action ) ) {
				throw new InvalidArgumentException( 'Student-note audit action is invalid.' );
			}
			if (
				'submission_evaluation' !== ( $row['audit_object_type'] ?? null )
				|| ! hash_equals( $this->public_id( $root['evaluation_id'] ?? null ), $this->public_id( $row['audit_object_id'] ?? null ) )
			) {
				throw new InvalidArgumentException( 'Student-note audit object drifted.' );
			}
			foreach ( array( 'context_type', 'context_id', 'context_revision' ) as $key ) {
				if ( ! is_string( $row[ 'audit_' . $key ] ?? null ) || ! hash_equals( (string) $root[ $key ], (string) $row[ 'audit_' . $key ] ) ) {
					throw new InvalidArgumentException( 'Student-note audit Context drifted.' );
				}
			}
			if ( 'oj_ai.student_note_audit' !== ( $row['audit_metadata_schema'] ?? null ) || 1 !== $this->positive_integer( $row['audit_metadata_version'] ?? null ) ) {
				throw new InvalidArgumentException( 'Student-note audit schema drifted.' );
			}
			$metadata = $this->decode_canonical( $row['audit_metadata_json'] ?? null, $this->sha256( $row['audit_metadata_hash'] ?? null ) );
			$this->assert_no_forbidden_keys( $metadata );
			$this->nullable_sha256( $row['audit_before_digest'] ?? null );
			$this->sha256( $row['audit_after_digest'] ?? null );

			$revision_id = $this->metadata_public_id( $metadata, 'student_note_revision_id' );
			if ( null !== $revision_id && ! in_array( $revision_id, $known_revision_ids, true ) ) {
				throw new InvalidArgumentException( 'Student-note audit revision is unknown.' );
			}
			$result[] = array(
				'id'              => $this->public_id( $row['audit_id'] ?? null ),
				'action'          => $action,
				'revision_id'     => $revision_id,
				'run_id'          => $this->metadata_public_id( $metadata, 'run_id' ),
				'error_code'      => $this->metadata_error_code( $metadata ),
				'occurred_at_utc' => $this->datetime( $row['audit_occurred_at_utc'] ?? null ),
			);
		}
		return $result;
	}

	/**
	 * Metadata public id.
	 *
	 * @param array<string,mixed> $metadata Metadata.
	 * @param string              $key Key.
	 * @return string|null
	 */
	private function metadata_public_id( array $metadata, string $key ): ?string {
		if ( ! array_key_exists( $key, $metadata ) || null === $metadata[ $key ] ) {
			return null;
		}
		return $this->public_id( $metadata[ $key ] );
	}

	/**
	 * Metadata error code.
	 *
	 * @param array<string,mixed> $metadata Metadata.
	 * @return string|null
	 */
	private function metadata_error_code( array $metadata ): ?string {
		if ( ! array_key_exists( 'error_code', $metadata ) || null === $metadata['error_code'] ) {
			return null;
		}
		return $this->token( $metadata['error_code'], 100 );
	}

	/**
	 * Reject invalid no forbidden keys.
	 *
	 * @param mixed $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_no_forbidden_keys( $value ): void {
		if ( ! is_array( $value ) ) {
			return;
		}
		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) ) {
				$snake      = preg_replace( '/([a-z0-9])([A-Z])/', '$1_$2', $key );
				$normalized = strtolower( str_replace( '-', '_', is_string( $snake ) ? $snake : $key ) );
				if ( in_array( $normalized, self::FORBIDDEN_AUDIT_KEYS, true ) ) {
					throw new InvalidArgumentException( 'Student-note audit contains a forbidden field.' );
				}
			}
			$this->assert_no_forbidden_keys( $item );
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
			throw new InvalidArgumentException( 'Stored student-note JSON hash is invalid.' );
		}
		try {
			$decoded = json_decode( $value, true, 128, JSON_THROW_ON_ERROR );
		} catch ( JsonException $error ) {
			throw new InvalidArgumentException( 'Stored student-note JSON is invalid.', 0, $error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The diagnostic text is literal; a chained Throwable is not HTML output.
		}
		if ( ! is_array( $decoded ) || array_is_list( $decoded ) || ! hash_equals( $value, $this->canonical->encode( $decoded ) ) ) {
			throw new InvalidArgumentException( 'Stored student-note JSON representation drifted.' );
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
			throw new InvalidArgumentException( 'Stored student-note object shape is invalid.' );
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
			throw new InvalidArgumentException( 'Stored student-note public ID is invalid.' );
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
			throw new InvalidArgumentException( 'Stored student-note SHA-256 is invalid.' );
		}
		return $value;
	}

	/**
	 * Nullable sha256.
	 *
	 * @param mixed $value Value.
	 * @return string|null
	 */
	private function nullable_sha256( $value ): ?string {
		return null === $value ? null : $this->sha256( $value );
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
			throw new InvalidArgumentException( 'Stored student-note positive integer is invalid.' );
		}
		return $value;
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
	 * Database boolean.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function database_boolean( $value ): bool {
		if ( is_string( $value ) && ( '0' === $value || '1' === $value ) ) {
			$value = (int) $value;
		}
		if ( ! is_int( $value ) || $value < 0 || $value > 1 ) {
			throw new InvalidArgumentException( 'Stored student-note boolean is invalid.' );
		}
		return 1 === $value;
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
			throw new InvalidArgumentException( 'Stored student-note enum is invalid.' );
		}
		return $value;
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
			throw new InvalidArgumentException( 'Stored student-note UTC datetime is invalid.' );
		}
		return $value;
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
			throw new InvalidArgumentException( 'Stored student-note token is invalid.' );
		}
		return $value;
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
		if (
			! is_string( $value )
			|| '' === trim( $value )
			|| strlen( $value ) > $maximum * 4
			|| ( ! $allow_newline && str_contains( $value, "\n" ) )
			|| 1 === preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value )
		) {
			throw new InvalidArgumentException( 'Stored student-note text is invalid.' );
		}
		return $value;
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
