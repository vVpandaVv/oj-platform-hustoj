<?php
/**
 * WordPress official student submission commit repository.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Student\Infrastructure;

use InvalidArgumentException;
use JsonException;
use OJAI\Dashboard\Submission\Assignment\Application\Assignment_Failure;
use OJAI\Dashboard\Submission\Student\Application\Submit_Own_Submission_Result;
use OJAI\Dashboard\Submission\Student\Domain\Submission_Attempt_Allowance;
use OJAI\Dashboard\Submission\Student\Domain\Submission_Response_Validator;
use OJAI\Dashboard\Submission\Student\Domain\Submission_Revision_Integrity;
use OJAI\Dashboard\Submission\Student\Domain\Submission_Save_Token;
use OJAI\Dashboard\Submission\Student\Port\Auto_Submission_Commit_Repository_Port_Interface;
use OJAI\Dashboard\Submission\Student\Port\Student_Submission_Commit_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Makes one exact C26 revision official and appends its immutable receipt.
 */
final class WordPress_Student_Submission_Commit_Repository implements Student_Submission_Commit_Repository_Port_Interface, Auto_Submission_Commit_Repository_Port_Interface {
	private const TABLE_ASSIGNMENTS         = 'oj_ai_assignments';
	private const TABLE_TARGETS             = 'oj_ai_assignment_targets';
	private const TABLE_PUBLICATIONS        = 'oj_ai_worksheet_publications';
	private const TABLE_WORKSHEET_REVISIONS = 'oj_ai_worksheet_revisions';
	private const TABLE_SUBMISSIONS         = 'oj_ai_submissions';
	private const TABLE_REVISIONS           = 'oj_ai_submission_revisions';
	private const TABLE_RECEIPTS            = 'oj_ai_submission_receipts';
	private const TABLE_ATTACHMENTS         = 'oj_ai_submission_attachments';
	private const TABLE_PRIVATE_FILES       = 'oj_ai_private_files';
	private const TABLE_AUDIT               = 'oj_ai_audit_events';
	private const TABLE_OUTBOX              = 'oj_ai_outbox_events';

	private const EVENT_TYPE          = 'submission.submitted';
	private const PAYLOAD_SCHEMA      = 'oj_ai.submission_submitted_event';
	private const AUTO_EVENT_TYPE     = 'submission.auto_submitted';
	private const AUTO_PAYLOAD_SCHEMA = 'oj_ai.submission_auto_submitted_event';
	private const AUDIT_SCHEMA        = 'oj_ai.submission_audit';
	private const POLICY_SCHEMA       = 'oj_ai.assignment_resubmission_policy';
	private const VERSION             = 1;
	private const MAX_ATTACHMENTS     = 100;
	// Preserve immutable result history, but never present it as the new answer's result.
	private const EMPTY_RESULT_POINTERS = array(
		'official_review_public_id' => null,
		'official_review_hash'      => null,
		'current_release_public_id' => null,
		'current_release_hash'      => null,
	);

	/**
	 * Canonical JSON encoder used for stable digests.
	 *
	 * @var Canonical_Json
	 */
	private Canonical_Json $canonical;
	/**
	 * Responses.
	 *
	 * @var Submission_Response_Validator
	 */
	private Submission_Response_Validator $responses;
	/**
	 * Integrity.
	 *
	 * @var Submission_Revision_Integrity
	 */
	private Submission_Revision_Integrity $integrity;
	/**
	 * Save tokens.
	 *
	 * @var Submission_Save_Token
	 */
	private Submission_Save_Token $save_tokens;

	/**
	 * Initialize WordPress student submission commit repository dependencies and state.
	 *
	 * @param object|null         $database Database.
	 * @param Canonical_Json|null $canonical Canonical JSON encoder used for stable digests.
	 */
	public function __construct( private ?object $database = null, ?Canonical_Json $canonical = null ) {
		$this->canonical   = $canonical ?? new Canonical_Json();
		$this->responses   = new Submission_Response_Validator( $this->canonical );
		$this->integrity   = new Submission_Revision_Integrity( $this->canonical );
		$this->save_tokens = new Submission_Save_Token( $this->canonical );
	}

	/**
	 * Find submit receipt.
	 *
	 * @param string $idempotency_storage_key Idempotency storage key.
	 * @param string $request_digest Request digest.
	 * @return Submit_Own_Submission_Result|null
	 */
	public function find_submit_receipt( string $idempotency_storage_key, string $request_digest ): ?Submit_Own_Submission_Result {
		$this->assert_storage_key( $idempotency_storage_key );
		$this->assert_hash_argument( $request_digest );
		return $this->receipt( $idempotency_storage_key, $request_digest, false );
	}

	/**
	 * Submit.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Submit_Own_Submission_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function submit( array $record ): Submit_Own_Submission_Result {
		$this->assert_record( $record );
		$replay = $this->receipt( $record['idempotency_storage_key'], $record['request_digest'], false );
		if ( $replay instanceof Submit_Own_Submission_Result ) {
			return $replay;
		}

		$transaction = false;
		try {
			$this->query_or_fail( 'START TRANSACTION' );
			$transaction = true;
			$replay      = $this->receipt( $record['idempotency_storage_key'], $record['request_digest'], true );
			if ( $replay instanceof Submit_Own_Submission_Result ) {
				$this->query_or_fail( 'COMMIT' );
				$transaction = false;
				return $replay;
			}

			$current = $this->lock_submission_context( $record['submission_public_id'], $record['actor_user_id'] );
			if ( null === $current || 'active' !== ( $current['target_state'] ?? null ) ) {
				throw Assignment_Failure::because( Assignment_Failure::NOT_FOUND );
			}
			$this->assert_expected( $current, $record );
			$policy         = $this->resubmission_policy( $current, $record );
			$revision       = $this->lock_and_validate_current_revision( $current, $record );
			$latest_receipt = $this->latest_receipt( $this->positive_integer( $current['submission_pk'] ?? null ) );
			$receipt_no     = null === $latest_receipt ? 1 : $latest_receipt + 1;
			$attempt_no     = $this->attempt_no( $current, $policy, $record, $latest_receipt );
			$timeliness     = $record['occurred_at_utc'] <= (string) $current['due_at_utc'] ? 'on_time' : 'late';

			$receipt_content      = array(
				'action'                      => 'submit',
				'actor_type'                  => 'learner',
				'actor_user_id'               => $record['actor_user_id'],
				'assignment_schedule_hash'    => (string) $current['schedule_hash'],
				'assignment_schedule_version' => $this->positive_integer( $current['schedule_version'] ?? null ),
				'attachment_set_hash'         => $revision['attachment_set_hash'],
				'context_revision'            => $record['authorized_context_revision'],
				'idempotency_key'             => $record['idempotency_storage_key'],
				'attempt_no'                  => $attempt_no,
				'publication_hash'            => (string) $current['publication_hash'],
				'reason'                      => null,
				'receipt_no'                  => $receipt_no,
				'received_at_utc'             => $record['occurred_at_utc'],
				'resubmission_policy_hash'    => $policy['hash'],
				'submission_id'               => (string) $current['submission_id'],
				'submission_revision_hash'    => $revision['content_hash'],
				'submission_revision_id'      => $revision['public_id'],
				'timeliness'                  => $timeliness,
			);
			$receipt_content_hash = hash( 'sha256', $this->canonical->encode( $receipt_content ) );
			$this->insert_or_fail(
				self::TABLE_RECEIPTS,
				array(
					'public_id'                   => $record['receipt_public_id'],
					'created_at_utc'              => $record['occurred_at_utc'],
					'content_hash'                => $receipt_content_hash,
					'submission_id'               => $this->positive_integer( $current['submission_pk'] ?? null ),
					'submission_revision_id'      => $revision['revision_pk'],
					'receipt_no'                  => $receipt_no,
					'action'                      => 'submit',
					'idempotency_key'             => $record['idempotency_storage_key'],
					'context_revision'            => $record['authorized_context_revision'],
					'actor_type'                  => 'learner',
					'actor_user_id'               => $record['actor_user_id'],
					'reason'                      => null,
					'attempt_no'                  => $attempt_no,
					'submission_revision_hash'    => $revision['content_hash'],
					'publication_hash'            => (string) $current['publication_hash'],
					'attachment_set_hash'         => $revision['attachment_set_hash'],
					'assignment_schedule_version' => $this->positive_integer( $current['schedule_version'] ?? null ),
					'assignment_schedule_hash'    => (string) $current['schedule_hash'],
					'resubmission_policy_hash'    => $policy['hash'],
					'timeliness'                  => $timeliness,
					'received_at_utc'             => $record['occurred_at_utc'],
				)
			);
			$receipt_pk       = $this->insert_id();
			$new_lock_version = $record['expected_lock_version'] + 1;
			$this->update_or_conflict(
				self::TABLE_SUBMISSIONS,
				array_merge(
					array(
						'lock_version'                => $new_lock_version,
						'updated_by_user_id'          => $record['actor_user_id'],
						'updated_at_utc'              => $record['occurred_at_utc'],
						'state'                       => 'submitted',
						'official_revision_public_id' => $revision['public_id'],
						'official_attempt_no'         => $attempt_no,
						'submitted_at_utc'            => $record['occurred_at_utc'],
					),
					self::EMPTY_RESULT_POINTERS
				),
				array(
					'id'                         => $this->positive_integer( $current['submission_pk'] ?? null ),
					'lock_version'               => $record['expected_lock_version'],
					'state'                      => $record['expected_state'],
					'current_revision_public_id' => $record['expected_current_revision_id'],
				)
			);

			$result = $this->result(
				$current,
				$record,
				$revision,
				$receipt_no,
				$attempt_no,
				$new_lock_version,
				$timeliness,
				$policy['hash'],
				$receipt_content_hash,
				false
			);
			$this->insert_audit( $current, $record, $result );
			$this->insert_outbox( $record, $result );
			$this->assert_postcondition( $current, $record, $revision, $receipt_pk, $receipt_content_hash, $new_lock_version, $attempt_no );
			$this->query_or_fail( 'COMMIT' );
			$transaction = false;
			return $result;
		} catch ( Assignment_Failure $failure ) {
			if ( $transaction ) {
				$this->rollback();
			}
			throw $failure;
		} catch ( Throwable ) {
			if ( $transaction ) {
				$this->rollback();
			}
			$replay = $this->receipt( $record['idempotency_storage_key'], $record['request_digest'], false );
			if ( $replay instanceof Submit_Own_Submission_Result ) {
				return $replay;
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Auto submit.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function auto_submit( array $record ): string {
		$this->assert_auto_record( $record );
		$transaction = false;
		try {
			$this->query_or_fail( 'START TRANSACTION' );
			$transaction = true;
			$current     = $this->lock_submission_context( $record['submission_public_id'], $record['learner_user_id'] );
			if ( null === $current || 'active' !== ( $current['target_state'] ?? null ) ) {
				$this->query_or_fail( 'COMMIT' );
				return Auto_Submission_Commit_Repository_Port_Interface::STALE;
			}
			$state = $this->submission_state( $current['submission_state'] ?? null );
			if ( in_array( $state, array( 'submitted', 'auto_submitted', 'proxy_submitted', 'locked' ), true ) ) {
				$this->query_or_fail( 'COMMIT' );
				return Auto_Submission_Commit_Repository_Port_Interface::STALE;
			}

			$this->assert_auto_expected( $current, $record );
			$policy                             = $this->auto_resubmission_policy( $current );
			$validation_record                  = $record;
			$validation_record['actor_user_id'] = $record['learner_user_id'];
			$revision                           = $this->lock_and_validate_current_revision( $current, $validation_record );
			$latest_receipt                     = $this->latest_receipt( $this->positive_integer( $current['submission_pk'] ?? null ) );
			$receipt_no                         = null === $latest_receipt ? 1 : $latest_receipt + 1;
			$attempt_no                         = $this->auto_attempt_no( $current, $policy, $record, $latest_receipt );
			$received_at                        = $record['effective_received_at_utc'];
			$timeliness                         = $received_at <= (string) $current['due_at_utc'] ? 'on_time' : 'late';

			$receipt_content      = array(
				'action'                      => 'auto_submit',
				'actor_type'                  => 'system',
				'actor_user_id'               => null,
				'assignment_schedule_hash'    => (string) $current['schedule_hash'],
				'assignment_schedule_version' => $this->positive_integer( $current['schedule_version'] ?? null ),
				'attachment_set_hash'         => $revision['attachment_set_hash'],
				'context_revision'            => $record['authorized_context_revision'],
				'idempotency_key'             => $record['idempotency_storage_key'],
				'attempt_no'                  => $attempt_no,
				'publication_hash'            => (string) $current['publication_hash'],
				'reason'                      => null,
				'receipt_no'                  => $receipt_no,
				'received_at_utc'             => $received_at,
				'resubmission_policy_hash'    => $policy['hash'],
				'submission_id'               => (string) $current['submission_id'],
				'submission_revision_hash'    => $revision['content_hash'],
				'submission_revision_id'      => $revision['public_id'],
				'timeliness'                  => $timeliness,
			);
			$receipt_content_hash = hash( 'sha256', $this->canonical->encode( $receipt_content ) );
			$this->insert_or_fail(
				self::TABLE_RECEIPTS,
				array(
					'public_id'                   => $record['receipt_public_id'],
					'created_at_utc'              => $record['occurred_at_utc'],
					'content_hash'                => $receipt_content_hash,
					'submission_id'               => $this->positive_integer( $current['submission_pk'] ?? null ),
					'submission_revision_id'      => $revision['revision_pk'],
					'receipt_no'                  => $receipt_no,
					'action'                      => 'auto_submit',
					'idempotency_key'             => $record['idempotency_storage_key'],
					'context_revision'            => $record['authorized_context_revision'],
					'actor_type'                  => 'system',
					'actor_user_id'               => null,
					'reason'                      => null,
					'attempt_no'                  => $attempt_no,
					'submission_revision_hash'    => $revision['content_hash'],
					'publication_hash'            => (string) $current['publication_hash'],
					'attachment_set_hash'         => $revision['attachment_set_hash'],
					'assignment_schedule_version' => $this->positive_integer( $current['schedule_version'] ?? null ),
					'assignment_schedule_hash'    => (string) $current['schedule_hash'],
					'resubmission_policy_hash'    => $policy['hash'],
					'timeliness'                  => $timeliness,
					'received_at_utc'             => $received_at,
				)
			);
			$receipt_pk       = $this->insert_id();
			$new_lock_version = $record['expected_lock_version'] + 1;
			$this->update_or_conflict(
				self::TABLE_SUBMISSIONS,
				array_merge(
					array(
						'lock_version'                => $new_lock_version,
						'updated_by_user_id'          => $record['learner_user_id'],
						'updated_at_utc'              => $record['occurred_at_utc'],
						'state'                       => 'auto_submitted',
						'official_revision_public_id' => $revision['public_id'],
						'official_attempt_no'         => $attempt_no,
						'submitted_at_utc'            => $received_at,
						'closed_at_utc'               => $received_at,
					),
					self::EMPTY_RESULT_POINTERS
				),
				array(
					'id'                         => $this->positive_integer( $current['submission_pk'] ?? null ),
					'lock_version'               => $record['expected_lock_version'],
					'state'                      => $record['expected_state'],
					'current_revision_public_id' => $record['expected_current_revision_id'],
				)
			);

			$submission = array(
				'id'                   => (string) $current['submission_id'],
				'assignment_id'        => (string) $current['assignment_id'],
				'state'                => 'auto_submitted',
				'lock_version'         => $new_lock_version,
				'current_revision_id'  => $revision['public_id'],
				'official_revision_id' => $revision['public_id'],
				'official_attempt_no'  => $attempt_no,
				'submitted_at_utc'     => $received_at,
				'actions'              => array(),
			);
			$receipt    = array(
				'id'                          => $record['receipt_public_id'],
				'content_hash'                => $receipt_content_hash,
				'receipt_no'                  => $receipt_no,
				'action'                      => 'auto_submit',
				'actor_type'                  => 'system',
				'submission_id'               => (string) $current['submission_id'],
				'submission_revision_id'      => $revision['public_id'],
				'attempt_no'                  => $attempt_no,
				'submission_revision_hash'    => $revision['content_hash'],
				'publication_id'              => (string) $current['worksheet_publication_public_id'],
				'publication_hash'            => (string) $current['publication_hash'],
				'attachment_set_hash'         => $revision['attachment_set_hash'],
				'assignment_schedule_version' => $this->positive_integer( $current['schedule_version'] ?? null ),
				'assignment_schedule_hash'    => (string) $current['schedule_hash'],
				'resubmission_policy_hash'    => $policy['hash'],
				'timeliness'                  => $timeliness,
				'received_at_utc'             => $received_at,
				'context_revision'            => $record['authorized_context_revision'],
			);
			$this->insert_auto_audit( $current, $record, $submission, $receipt );
			$this->insert_auto_outbox( $record, $submission, $receipt );
			$this->assert_auto_postcondition(
				$current,
				$record,
				$revision,
				$receipt_pk,
				$receipt_content_hash,
				$new_lock_version,
				$attempt_no
			);
			$this->query_or_fail( 'COMMIT' );
			$transaction = false;
			return Auto_Submission_Commit_Repository_Port_Interface::AUTO_SUBMITTED;
		} catch ( Assignment_Failure $failure ) {
			if ( $transaction ) {
				$this->rollback();
			}
			throw $failure;
		} catch ( Throwable ) {
			if ( $transaction ) {
				$this->rollback();
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORAGE_UNAVAILABLE );
		}
	}
	/**
	 * Reject invalid result pointer reset.
	 *
	 * @param array<string,mixed> $root Root.
	 * @return void
	 */
	private function assert_result_pointer_reset( array $root ): void {
		foreach ( self::EMPTY_RESULT_POINTERS as $column => $expected ) {
			if ( ! array_key_exists( $column, $root ) || $expected !== $root[ $column ] ) {
				$this->stored_invalid( Assignment_Failure::SUBMIT_POSTCONDITION_MISMATCH );
			}
		}
	}

	/**
	 * Lock submission context.
	 *
	 * @param string $submission_id Submission public identifier.
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @return array<string,mixed>|null
	 */
	private function lock_submission_context( string $submission_id, int $actor_user_id ): ?array {
		$sql = 'SELECT s.id AS submission_pk, s.public_id AS submission_id, s.lock_version AS submission_lock_version, s.state AS submission_state, s.current_revision_public_id, s.official_revision_public_id, s.official_attempt_no, a.id AS assignment_pk, a.public_id AS assignment_id, a.context_type, a.context_id, a.context_revision, a.state AS assignment_state, a.worksheet_publication_public_id, a.publication_hash, a.opens_at_utc, a.due_at_utc, a.closes_at_utc, a.schedule_version, a.schedule_hash, a.auto_submit_enabled, a.resubmission_policy_schema, a.resubmission_policy_version, a.resubmission_policy_json, a.resubmission_policy_hash, t.state AS target_state, p.content_hash AS publication_content_hash, p.publication_hash AS stored_publication_hash, p.response_schema_schema, p.response_schema_version, p.response_schema_json, p.response_schema_hash, wr.document_hash AS publication_source_hash FROM ' . self::TABLE_SUBMISSIONS . ' s INNER JOIN ' . self::TABLE_ASSIGNMENTS . ' a ON a.id = s.assignment_id '
			. 'INNER JOIN ' . self::TABLE_TARGETS . ' t ON t.assignment_id = a.id '
			. 'INNER JOIN ' . self::TABLE_PUBLICATIONS . ' p ON p.public_id = a.worksheet_publication_public_id '
			. 'INNER JOIN ' . self::TABLE_WORKSHEET_REVISIONS . ' wr ON wr.id = p.worksheet_revision_id '
			. 'WHERE s.public_id = %s AND s.learner_user_id = %d AND t.learner_user_id = %d LIMIT 1 FOR UPDATE';
		return $this->get_row( $this->prepare( $sql, array( $submission_id, $actor_user_id, $actor_user_id ) ) );
	}

	/**
	 * Reject invalid expected.
	 *
	 * @param array<string,mixed> $current Current.
	 * @param array<string,mixed> $record Record.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_expected( array $current, array $record ): void {
		$submission_id = $this->stored_public_id( $current['submission_id'] ?? null );
		$assignment_id = $this->stored_public_id( $current['assignment_id'] ?? null );
		$this->stored_public_id( $current['worksheet_publication_public_id'] ?? null );
		if ( ! hash_equals( $record['submission_public_id'], $submission_id ) ) {
			$this->stored_invalid( Assignment_Failure::SUBMIT_SUBMISSION_ID_MISMATCH );
		}
		$state = $this->submission_state( $current['submission_state'] ?? null );
		if ( ! in_array( $state, array( 'draft', 'reopened' ), true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::SUBMISSION_ALREADY_OFFICIAL );
		}
		if ( 'open' !== $this->assignment_state( $current['assignment_state'] ?? null ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STATE_CONFLICT );
		}
		$this->token( $current['context_type'] ?? null, 32 );
		$this->token( $current['context_id'] ?? null, 191 );
		$this->token( $current['context_revision'] ?? null, 191 );

		$opens  = $this->optional_utc( $current['opens_at_utc'] ?? null );
		$due    = $this->required_utc( $current['due_at_utc'] ?? null );
		$closes = $this->required_utc( $current['closes_at_utc'] ?? null );
		if ( ( null !== $opens && $opens > $due ) || $due > $closes ) {
			$this->stored_invalid( Assignment_Failure::SUBMIT_SCHEDULE_WINDOW_INVALID );
		}
		if ( ( null !== $opens && $record['occurred_at_utc'] < $opens ) || $record['occurred_at_utc'] >= $closes ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STATE_CONFLICT );
		}
		$schedule_version     = $this->positive_integer( $current['schedule_version'] ?? null );
		$schedule_hash        = $this->stored_hash( $current['schedule_hash'] ?? null );
		$actual_schedule_hash = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'auto_submit_enabled' => 1 === $this->zero_or_one( $current['auto_submit_enabled'] ?? null ),
					'closes_at_utc'       => $closes,
					'due_at_utc'          => $due,
					'opens_at_utc'        => $opens,
					'schedule_version'    => $schedule_version,
				)
			)
		);
		if ( ! hash_equals( $schedule_hash, $actual_schedule_hash ) ) {
			$this->stored_invalid( Assignment_Failure::SUBMIT_SCHEDULE_HASH_MISMATCH );
		}

		$publication_hash = $this->stored_hash( $current['publication_hash'] ?? null );
		if (
			! hash_equals( $publication_hash, $this->stored_hash( $current['publication_content_hash'] ?? null ) )
			|| ! hash_equals( $publication_hash, $this->stored_hash( $current['stored_publication_hash'] ?? null ) )
		) {
			$this->stored_invalid( Assignment_Failure::SUBMIT_PUBLICATION_HASH_MISMATCH );
		}
		$current_revision_id = $this->optional_stored_public_id( $current['current_revision_public_id'] ?? null );
		if ( null === $current_revision_id ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::SUBMISSION_VALIDATION_FAILED );
		}
		$lock_version = $this->non_negative_integer( $current['submission_lock_version'] ?? null );
		$save_token   = $this->save_tokens->create( $submission_id, $assignment_id, $lock_version, $current_revision_id, $publication_hash, $schedule_hash );
		if (
			$record['expected_state'] !== $state
			|| $record['expected_lock_version'] !== $lock_version
			|| $record['expected_current_revision_id'] !== $current_revision_id
			|| $record['expected_schedule_version'] !== $schedule_version
			|| ! hash_equals( $record['expected_schedule_hash'], $schedule_hash )
			|| ! hash_equals( $record['expected_publication_hash'], $publication_hash )
			|| ! hash_equals( $record['expected_save_token'], $save_token )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::SUBMISSION_REVISION_CONFLICT );
		}
	}

	/**
	 * Resubmission policy.
	 *
	 * @param array<string,mixed> $current Current.
	 * @param array<string,mixed> $record Record.
	 * @return array{max_attempts:int,reopen_until_utc:?string,copy_previous_answers:bool,hash:string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function resubmission_policy( array $current, array $record ): array {
		if (
			self::POLICY_SCHEMA !== ( $current['resubmission_policy_schema'] ?? null )
			|| self::VERSION !== $this->positive_integer( $current['resubmission_policy_version'] ?? null )
		) {
			$this->stored_invalid( Assignment_Failure::SUBMIT_RESUBMISSION_POLICY_SCHEMA_INVALID );
		}
		$hash   = $this->stored_hash( $current['resubmission_policy_hash'] ?? null );
		$policy = $this->decode_json( (string) ( $current['resubmission_policy_json'] ?? '' ), $hash, Assignment_Failure::SUBMIT_RESUBMISSION_POLICY_SCHEMA_INVALID );
		$this->stored_exact_keys( $policy, array( 'copy_previous_answers', 'max_attempts', 'reopen_until_utc' ), Assignment_Failure::SUBMIT_RESUBMISSION_POLICY_SCHEMA_INVALID );
		$maximum      = $this->positive_integer( $policy['max_attempts'] ?? null );
		$reopen_until = $this->optional_utc( $policy['reopen_until_utc'] ?? null );
		if ( $maximum > 10 || ! is_bool( $policy['copy_previous_answers'] ?? null ) ) {
			$this->stored_invalid( Assignment_Failure::SUBMIT_RESUBMISSION_POLICY_VALUES_INVALID );
		}
		if ( null !== $reopen_until && $reopen_until > $this->required_utc( $current['closes_at_utc'] ?? null ) ) {
			$this->stored_invalid( Assignment_Failure::SUBMIT_RESUBMISSION_POLICY_WINDOW_INVALID );
		}
		if ( 'reopened' === $record['expected_state'] && null !== $reopen_until && $record['occurred_at_utc'] > $reopen_until ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STATE_CONFLICT );
		}
		return array(
			'max_attempts'          => $maximum,
			'reopen_until_utc'      => $reopen_until,
			'copy_previous_answers' => (bool) $policy['copy_previous_answers'],
			'hash'                  => $hash,
		);
	}

	/**
	 * Lock and validate current revision.
	 *
	 * @param array<string,mixed> $current Current.
	 * @param array<string,mixed> $record Record.
	 * @return array{revision_pk:int,public_id:string,content_hash:string,attachment_set_hash:string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function lock_and_validate_current_revision( array $current, array $record ): array {
		$sql = 'SELECT id AS revision_pk, public_id, content_hash, revision_no, base_revision_no, state, response_schema_version, response_schema, response_version, response_json, response_hash, attachment_set_hash FROM ' . self::TABLE_REVISIONS
			. ' WHERE submission_id = %d AND public_id = %s LIMIT 1 FOR UPDATE';
		$row = $this->get_row(
			$this->prepare(
				$sql,
				array(
					$this->positive_integer( $current['submission_pk'] ?? null ),
					$record['expected_current_revision_id'],
				)
			)
		);
		if ( null === $row || $record['expected_current_revision_id'] !== $this->stored_public_id( $row['public_id'] ?? null ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::SUBMISSION_REVISION_CONFLICT );
		}
		$revision_pk      = $this->positive_integer( $row['revision_pk'] ?? null );
		$revision_no      = $this->positive_integer( $row['revision_no'] ?? null );
		$base_revision_no = null === ( $row['base_revision_no'] ?? null ) ? null : $this->positive_integer( $row['base_revision_no'] );
		$state            = (string) ( $row['state'] ?? '' );
		if (
			! in_array( $state, array( 'saved', 'reopened_fork' ), true )
			|| Submission_Revision_Integrity::RESPONSE_SCHEMA_VERSION !== ( $row['response_schema_version'] ?? null )
			|| Submission_Revision_Integrity::RESPONSE_SCHEMA !== ( $row['response_schema'] ?? null )
			|| Submission_Revision_Integrity::RESPONSE_VERSION !== $this->positive_integer( $row['response_version'] ?? null )
		) {
			$this->stored_invalid( Assignment_Failure::SUBMIT_REVISION_INTEGRITY_INVALID );
		}
		$response_hash = $this->stored_hash( $row['response_hash'] ?? null );
		$response      = $this->decode_json( (string) ( $row['response_json'] ?? '' ), $response_hash, Assignment_Failure::SUBMIT_REVISION_INTEGRITY_INVALID );
		$this->stored_exact_keys( $response, array( 'answers', 'publication_hash', 'publication_id', 'response_schema_version' ), Assignment_Failure::SUBMIT_REVISION_INTEGRITY_INVALID );
		if (
			( $current['worksheet_publication_public_id'] ?? null ) !== ( $response['publication_id'] ?? null )
			|| ( $current['publication_hash'] ?? null ) !== ( $response['publication_hash'] ?? null )
			|| Submission_Revision_Integrity::RESPONSE_SCHEMA_VERSION !== ( $response['response_schema_version'] ?? null )
			|| ! is_array( $response['answers'] ?? null )
		) {
			$this->stored_invalid( Assignment_Failure::SUBMIT_REVISION_PUBLICATION_MISMATCH );
		}
		$profile   = $this->response_profile( $current );
		$validated = $this->responses->validate_complete( $profile, $response['answers'] );
		// A strict !== comparison here false-positives whenever the two arrays
		// were built with the same content in a different key order: $response
		// came back from decode_json() in the canonical (alphabetically
		// sorted-by-key) order it was stored in, while validate_complete()
		// rebuilds $normalized by iterating the profile's response items in
		// their authored position order, which is essentially never
		// alphabetical for real response IDs. Compare the canonical encoding
		// of both sides instead, so only an actual content difference fails.
		if ( $this->canonical->encode( $validated['answers'] ) !== $this->canonical->encode( $response['answers'] ) ) {
			$this->stored_invalid( Assignment_Failure::SUBMIT_REVISION_ANSWERS_MISMATCH );
		}
		$attachment_set_hash = $this->stored_hash( $row['attachment_set_hash'] ?? null );
		$this->lock_and_validate_attachments(
			$this->positive_integer( $current['submission_pk'] ?? null ),
			$record['submission_public_id'],
			$record['actor_user_id'],
			$attachment_set_hash,
			$validated['attachments']
		);
		$content_hash = $this->integrity->content_hash(
			$record['submission_public_id'],
			$revision_no,
			$base_revision_no,
			$state,
			Submission_Revision_Integrity::RESPONSE_SCHEMA_VERSION,
			$response_hash,
			$attachment_set_hash
		);
		if ( ! hash_equals( $this->stored_hash( $row['content_hash'] ?? null ), $content_hash ) ) {
			$this->stored_invalid( Assignment_Failure::SUBMIT_REVISION_CONTENT_HASH_MISMATCH );
		}

		return array(
			'revision_pk'         => $revision_pk,
			'public_id'           => $record['expected_current_revision_id'],
			'content_hash'        => $content_hash,
			'attachment_set_hash' => $attachment_set_hash,
		);
	}

	/**
	 * Response profile.
	 *
	 * @param array<string,mixed> $current Current.
	 * @return array<string,mixed>
	 */
	private function response_profile( array $current ): array {
		if (
			'oj_ai.student_response_schema' !== ( $current['response_schema_schema'] ?? null )
			|| 1 !== $this->positive_integer( $current['response_schema_version'] ?? null )
		) {
			$this->stored_invalid( Assignment_Failure::SUBMIT_RESPONSE_SCHEMA_INVALID );
		}
		$profile = $this->decode_json( (string) ( $current['response_schema_json'] ?? '' ), $this->stored_hash( $current['response_schema_hash'] ?? null ), Assignment_Failure::SUBMIT_RESPONSE_SCHEMA_INVALID );
		if ( ! hash_equals( $this->stored_hash( $current['publication_source_hash'] ?? null ), (string) ( $profile['source_hash'] ?? '' ) ) ) {
			$this->stored_invalid( Assignment_Failure::SUBMIT_RESPONSE_SOURCE_HASH_MISMATCH );
		}
		return $profile;
	}

	/**
	 * Lock and validate attachments.
	 *
	 * @param int                                                                                                        $submission_pk Submission pk.
	 * @param string                                                                                                     $submission_id Submission public identifier.
	 * @param int                                                                                                        $actor_user_id Authenticated actor from the access context.
	 * @param string                                                                                                     $expected_set_hash Expected set hash.
	 * @param array<string,array{response_id:string,kind:string,role:string,mime_types:array<int,string>,max_bytes:int}> $requirements Requirements.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function lock_and_validate_attachments(
		int $submission_pk,
		string $submission_id,
		int $actor_user_id,
		string $expected_set_hash,
		array $requirements
	): void {
		if ( count( $requirements ) > 20 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::SUBMISSION_VALIDATION_FAILED );
		}
		$sql  = 'SELECT id, public_id, created_by_user_id, submission_revision_id, response_key, file_public_id, state, original_name, mime_type, byte_size, sha256, bound_at_utc FROM ' . self::TABLE_ATTACHMENTS
			. ' WHERE submission_id = %d AND state IN (%s,%s,%s,%s,%s) ORDER BY id ASC LIMIT %d FOR UPDATE';
		$rows = $this->get_results(
			$this->prepare(
				$sql,
				array( $submission_pk, 'staged', 'scanning', 'ready', 'rejected', 'bound', self::MAX_ATTACHMENTS + 1 )
			)
		);
		if ( count( $rows ) > self::MAX_ATTACHMENTS ) {
			$this->stored_invalid( Assignment_Failure::SUBMIT_ATTACHMENT_ROWS_INVALID );
		}
		$by_id = array();
		foreach ( $rows as $row ) {
			$id = $this->stored_public_id( $row['public_id'] ?? null );
			if ( isset( $by_id[ $id ] ) ) {
				$this->stored_invalid( Assignment_Failure::SUBMIT_ATTACHMENT_ROWS_INVALID );
			}
			if ( null === ( $row['submission_revision_id'] ?? null ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Assignment_Failure::because( Assignment_Failure::ATTACHMENT_NOT_READY );
			}
			$by_id[ $id ] = $row;
		}

		$attachment_ids = array_keys( $requirements );
		sort( $attachment_ids, SORT_STRING );
		if ( ! hash_equals( $expected_set_hash, hash( 'sha256', $this->canonical->encode( $attachment_ids ) ) ) ) {
			$this->stored_invalid( Assignment_Failure::SUBMIT_ATTACHMENT_SET_HASH_MISMATCH );
		}
		$file_ids = array();
		foreach ( $requirements as $id => $requirement ) {
			$row = $by_id[ $id ] ?? null;
			if ( ! is_array( $row ) || 'bound' !== ( $row['state'] ?? null ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Assignment_Failure::because( Assignment_Failure::ATTACHMENT_NOT_READY );
			}
			$this->positive_integer( $row['submission_revision_id'] );
			$this->required_utc( $row['bound_at_utc'] ?? null );
			$file_id = $this->stored_public_id( $row['file_public_id'] ?? null );
			$mime    = $this->stored_text( $row['mime_type'] ?? null, 191 );
			$bytes   = $this->positive_integer( $row['byte_size'] ?? null );
			if (
				$actor_user_id !== $this->positive_integer( $row['created_by_user_id'] ?? null )
				|| $requirement['response_id'] !== ( $row['response_key'] ?? null )
				|| ! in_array( $mime, $requirement['mime_types'], true )
				|| $bytes > $requirement['max_bytes']
			) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Assignment_Failure::because( Assignment_Failure::SUBMISSION_VALIDATION_FAILED );
			}
			$this->stored_hash( $row['sha256'] ?? null );
			$file_ids[ $file_id ] = $id;
		}
		if ( array() === $file_ids ) {
			return;
		}

		$placeholders  = implode( ',', array_fill( 0, count( $file_ids ), '%s' ) );
		$files         = $this->get_results(
			$this->prepare(
				'SELECT public_id, created_by_user_id, owner_type, owner_public_id, purpose, original_name, mime_type, byte_size, sha256, state, scan_state, retention_class, purged_at_utc FROM ' . self::TABLE_PRIVATE_FILES
				. ' WHERE public_id IN (' . $placeholders . ') ORDER BY id ASC FOR UPDATE',
				array_keys( $file_ids )
			)
		);
		$private_by_id = array();
		foreach ( $files as $file ) {
			$file_id = $this->stored_public_id( $file['public_id'] ?? null );
			if ( isset( $private_by_id[ $file_id ] ) ) {
				$this->stored_invalid( Assignment_Failure::SUBMIT_ATTACHMENT_ROWS_INVALID );
			}
			$private_by_id[ $file_id ] = $file;
		}
		if ( count( $private_by_id ) !== count( $file_ids ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::ATTACHMENT_NOT_READY );
		}
		foreach ( $file_ids as $file_id => $attachment_id ) {
			$file       = $private_by_id[ $file_id ];
			$attachment = $by_id[ $attachment_id ];
			if (
				$actor_user_id !== $this->positive_integer( $file['created_by_user_id'] ?? null )
				|| 'submission' !== ( $file['owner_type'] ?? null )
				|| $submission_id !== ( $file['owner_public_id'] ?? null )
				|| 'submission_attachment' !== ( $file['purpose'] ?? null )
				|| 'submission_staging' !== ( $file['retention_class'] ?? null )
				|| 'ready' !== ( $file['state'] ?? null )
				|| 'clean' !== ( $file['scan_state'] ?? null )
				|| null !== ( $file['purged_at_utc'] ?? null )
				|| ( $attachment['original_name'] ?? null ) !== ( $file['original_name'] ?? null )
				|| ( $attachment['mime_type'] ?? null ) !== ( $file['mime_type'] ?? null )
				|| $this->positive_integer( $attachment['byte_size'] ?? null ) !== $this->positive_integer( $file['byte_size'] ?? null )
				|| ! hash_equals( $this->stored_hash( $attachment['sha256'] ?? null ), $this->stored_hash( $file['sha256'] ?? null ) )
			) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Assignment_Failure::because( Assignment_Failure::ATTACHMENT_NOT_READY );
			}
		}
	}

	/**
	 * Latest receipt.
	 *
	 * @param int $submission_pk Submission pk.
	 * @return int|null
	 */
	private function latest_receipt( int $submission_pk ): ?int {
		$row = $this->get_row(
			$this->prepare(
				'SELECT receipt_no FROM ' . self::TABLE_RECEIPTS . ' WHERE submission_id = %d ORDER BY receipt_no DESC LIMIT 1 FOR UPDATE',
				array( $submission_pk )
			)
		);
		return null === $row ? null : $this->positive_integer( $row['receipt_no'] ?? null );
	}

	/**
	 * Attempt no.
	 *
	 * @param array<string,mixed>                                                                     $current Current.
	 * @param array{max_attempts:int,reopen_until_utc:?string,copy_previous_answers:bool,hash:string} $policy Policy.
	 * @param array<string,mixed>                                                                     $record Record.
	 * @param int|null                                                                                $latest_receipt Latest receipt.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function attempt_no( array $current, array $policy, array $record, ?int $latest_receipt ): int {
		$official_id = $this->optional_stored_public_id( $current['official_revision_public_id'] ?? null );
		$attempt     = $this->non_negative_integer( $current['official_attempt_no'] ?? null );
		if ( 'draft' === $record['expected_state'] ) {
			if ( null !== $official_id || 0 !== $attempt || null !== $latest_receipt ) {
				$this->stored_invalid( Assignment_Failure::SUBMIT_ATTEMPT_LEDGER_INCONSISTENT );
			}
			return 1;
		}
		if (
			null === $official_id || $attempt < 1 || null === $latest_receipt
			|| $official_id === $record['expected_current_revision_id']
			|| Submission_Attempt_Allowance::remaining( $policy['max_attempts'], $attempt, (string) $current['submission_state'] ) < 1
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STATE_CONFLICT );
		}
		return $attempt + 1;
	}

	/**
	 * Result.
	 *
	 * @param array<string,mixed>                                                                    $current Current.
	 * @param array<string,mixed>                                                                    $record Record.
	 * @param array{revision_pk:int,public_id:string,content_hash:string,attachment_set_hash:string} $revision Revision.
	 * @param int                                                                                    $receipt_no Receipt no.
	 * @param int                                                                                    $attempt_no Attempt no.
	 * @param int                                                                                    $lock_version Lock version.
	 * @param string                                                                                 $timeliness Timeliness.
	 * @param string                                                                                 $policy_hash Policy hash.
	 * @param string                                                                                 $receipt_content_hash Receipt content hash.
	 * @param bool                                                                                   $replayed Replayed.
	 * @return Submit_Own_Submission_Result
	 */
	private function result(
		array $current,
		array $record,
		array $revision,
		int $receipt_no,
		int $attempt_no,
		int $lock_version,
		string $timeliness,
		string $policy_hash,
		string $receipt_content_hash,
		bool $replayed
	): Submit_Own_Submission_Result {
		return new Submit_Own_Submission_Result(
			array(
				'id'                   => (string) $current['submission_id'],
				'assignment_id'        => (string) $current['assignment_id'],
				'state'                => 'submitted',
				'lock_version'         => $lock_version,
				'current_revision_id'  => $revision['public_id'],
				'official_revision_id' => $revision['public_id'],
				'official_attempt_no'  => $attempt_no,
				'submitted_at_utc'     => $record['occurred_at_utc'],
				'actions'              => array(),
			),
			array(
				'id'                          => $record['receipt_public_id'],
				'content_hash'                => $receipt_content_hash,
				'receipt_no'                  => $receipt_no,
				'action'                      => 'submit',
				'actor_type'                  => 'learner',
				'submission_id'               => (string) $current['submission_id'],
				'submission_revision_id'      => $revision['public_id'],
				'attempt_no'                  => $attempt_no,
				'submission_revision_hash'    => $revision['content_hash'],
				'publication_id'              => (string) $current['worksheet_publication_public_id'],
				'publication_hash'            => (string) $current['publication_hash'],
				'attachment_set_hash'         => $revision['attachment_set_hash'],
				'assignment_schedule_version' => $this->positive_integer( $current['schedule_version'] ?? null ),
				'assignment_schedule_hash'    => (string) $current['schedule_hash'],
				'resubmission_policy_hash'    => $policy_hash,
				'timeliness'                  => $timeliness,
				'received_at_utc'             => $record['occurred_at_utc'],
				'context_revision'            => $record['authorized_context_revision'],
			),
			true,
			$replayed
		);
	}

	/**
	 * Insert audit.
	 *
	 * @param array<string,mixed>          $current Current.
	 * @param array<string,mixed>          $record Record.
	 * @param Submit_Own_Submission_Result $result Result.
	 * @return void
	 */
	private function insert_audit( array $current, array $record, Submit_Own_Submission_Result $result ): void {
		$previous = $this->get_var( 'SELECT event_hash FROM ' . self::TABLE_AUDIT . ' ORDER BY id DESC LIMIT 1 FOR UPDATE' );
		if ( null !== $previous ) {
			$this->stored_hash( $previous );
		}
		$before        = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'id'                   => $current['submission_id'],
					'state'                => $current['submission_state'],
					'lock_version'         => $current['submission_lock_version'],
					'current_revision_id'  => $current['current_revision_public_id'],
					'official_revision_id' => $current['official_revision_public_id'],
					'official_attempt_no'  => $current['official_attempt_no'],
				)
			)
		);
		$after         = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'receipt'    => $result->receipt(),
					'submission' => $result->submission(),
				)
			)
		);
		$metadata_json = $this->canonical->encode(
			array(
				'command_id'                   => $record['command_id'],
				'request_digest'               => $record['request_digest'],
				'expected_current_revision_id' => $record['expected_current_revision_id'],
				'expected_lock_version'        => $record['expected_lock_version'],
				'receipt_id'                   => $result->receipt()['id'],
			)
		);
		$metadata_hash = hash( 'sha256', $metadata_json );
		$event_hash    = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'action_key'          => self::EVENT_TYPE,
					'actor_user_id'       => $record['actor_user_id'],
					'after_digest'        => $after,
					'before_digest'       => $before,
					'correlation_id'      => $record['correlation_id'],
					'metadata_hash'       => $metadata_hash,
					'object_public_id'    => $current['submission_id'],
					'object_type'         => 'submission',
					'occurred_at_utc'     => $record['occurred_at_utc'],
					'previous_event_hash' => null === $previous ? null : (string) $previous,
				)
			)
		);
		$this->insert_or_fail(
			self::TABLE_AUDIT,
			array(
				'public_id'           => $record['audit_public_id'],
				'occurred_at_utc'     => $record['occurred_at_utc'],
				'actor_user_id'       => $record['actor_user_id'],
				'action_key'          => self::EVENT_TYPE,
				'object_type'         => 'submission',
				'object_public_id'    => $current['submission_id'],
				'context_type'        => $current['context_type'],
				'context_id'          => $current['context_id'],
				'context_revision'    => $record['authorized_context_revision'],
				'correlation_id'      => $record['correlation_id'],
				'before_digest'       => $before,
				'after_digest'        => $after,
				'metadata_schema'     => self::AUDIT_SCHEMA,
				'metadata_version'    => self::VERSION,
				'metadata_json'       => $metadata_json,
				'metadata_hash'       => $metadata_hash,
				'previous_event_hash' => null === $previous ? null : (string) $previous,
				'event_hash'          => $event_hash,
			)
		);
	}

	/**
	 * Insert outbox.
	 *
	 * @param array<string,mixed>          $record Record.
	 * @param Submit_Own_Submission_Result $result Result.
	 * @return void
	 */
	private function insert_outbox( array $record, Submit_Own_Submission_Result $result ): void {
		$payload_json = $this->canonical->encode(
			array(
				'request_digest' => $record['request_digest'],
				'result'         => array(
					'receipt'    => $result->receipt(),
					'submission' => $result->submission(),
				),
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
				'aggregate_type'       => 'submission',
				'aggregate_public_id'  => $record['submission_public_id'],
				'event_type'           => self::EVENT_TYPE,
				'idempotency_key'      => $record['idempotency_storage_key'],
				'payload_schema'       => self::PAYLOAD_SCHEMA,
				'payload_version'      => self::VERSION,
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
	 * Receipt.
	 *
	 * @param string $storage_key Storage key.
	 * @param string $request_digest Request digest.
	 * @param bool   $for_update For update.
	 * @return Submit_Own_Submission_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function receipt( string $storage_key, string $request_digest, bool $for_update ): ?Submit_Own_Submission_Result {
		$sql = 'SELECT aggregate_type, aggregate_public_id, event_type, payload_schema, payload_version, payload_json, payload_hash FROM ' . self::TABLE_OUTBOX . ' WHERE idempotency_key = %s LIMIT 1';
		if ( $for_update ) {
			$sql .= ' FOR UPDATE';
		}
		$row = $this->get_row( $this->prepare( $sql, array( $storage_key ) ) );
		if ( null === $row ) {
			return null;
		}
		if (
			'submission' !== ( $row['aggregate_type'] ?? null ) || self::EVENT_TYPE !== ( $row['event_type'] ?? null )
			|| self::PAYLOAD_SCHEMA !== ( $row['payload_schema'] ?? null ) || self::VERSION !== $this->positive_integer( $row['payload_version'] ?? null )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::IDEMPOTENCY_CONFLICT );
		}
		$payload = $this->decode_json( (string) ( $row['payload_json'] ?? '' ), $this->stored_hash( $row['payload_hash'] ?? null ) );
		$this->stored_exact_keys( $payload, array( 'request_digest', 'result' ) );
		if ( ! is_string( $payload['request_digest'] ?? null ) || ! hash_equals( $request_digest, $payload['request_digest'] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::IDEMPOTENCY_CONFLICT );
		}
		$result = $payload['result'] ?? null;
		if ( ! is_array( $result ) || array_is_list( $result ) ) {
			$this->stored_invalid();
		}
		$this->stored_exact_keys( $result, array( 'receipt', 'submission' ) );
		if ( ! is_array( $result['submission'] ) || ! is_array( $result['receipt'] ) || ( $row['aggregate_public_id'] ?? null ) !== ( $result['submission']['id'] ?? null ) ) {
			$this->stored_invalid();
		}
		try {
			return new Submit_Own_Submission_Result( $result['submission'], $result['receipt'], true, true ); } catch ( Throwable ) {
			$this->stored_invalid();
			}
	}

	/**
	 * Reject invalid postcondition.
	 *
	 * @param array<string,mixed>                                                                    $current Current.
	 * @param array<string,mixed>                                                                    $record Record.
	 * @param array{revision_pk:int,public_id:string,content_hash:string,attachment_set_hash:string} $revision Revision.
	 * @param int                                                                                    $receipt_pk Receipt pk.
	 * @param string                                                                                 $receipt_content_hash Receipt content hash.
	 * @param int                                                                                    $lock_version Lock version.
	 * @param int                                                                                    $attempt_no Attempt no.
	 * @return void
	 */
	private function assert_postcondition(
		array $current,
		array $record,
		array $revision,
		int $receipt_pk,
		string $receipt_content_hash,
		int $lock_version,
		int $attempt_no
	): void {
		$root    = $this->get_row(
			$this->prepare(
				'SELECT public_id, lock_version, state, current_revision_public_id, official_revision_public_id, official_attempt_no, submitted_at_utc , official_review_public_id, official_review_hash, current_release_public_id, current_release_hash FROM ' . self::TABLE_SUBMISSIONS . ' WHERE id = %d LIMIT 1 FOR UPDATE',
				array( $this->positive_integer( $current['submission_pk'] ?? null ) )
			)
		);
		$receipt = $this->get_row(
			$this->prepare(
				'SELECT public_id, content_hash, submission_revision_id FROM ' . self::TABLE_RECEIPTS . ' WHERE id = %d LIMIT 1 FOR UPDATE',
				array( $receipt_pk )
			)
		);
		if (
			null === $root || null === $receipt || $record['submission_public_id'] !== ( $root['public_id'] ?? null )
			|| $lock_version !== $this->non_negative_integer( $root['lock_version'] ?? null ) || 'submitted' !== ( $root['state'] ?? null )
			|| $revision['public_id'] !== ( $root['current_revision_public_id'] ?? null )
			|| $revision['public_id'] !== ( $root['official_revision_public_id'] ?? null )
			|| $attempt_no !== $this->positive_integer( $root['official_attempt_no'] ?? null )
			|| $record['occurred_at_utc'] !== ( $root['submitted_at_utc'] ?? null )
			|| $record['receipt_public_id'] !== ( $receipt['public_id'] ?? null )
			|| ! hash_equals( $receipt_content_hash, (string) ( $receipt['content_hash'] ?? '' ) )
			|| $revision['revision_pk'] !== $this->positive_integer( $receipt['submission_revision_id'] ?? null )
		) {
			$this->stored_invalid( Assignment_Failure::SUBMIT_POSTCONDITION_MISMATCH );
		}
		$this->assert_result_pointer_reset( $root );
	}

	/**
	 * Reject invalid auto expected.
	 *
	 * @param array<string,mixed> $current Current.
	 * @param array<string,mixed> $record Record.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_auto_expected( array $current, array $record ): void {
		$submission_id = $this->stored_public_id( $current['submission_id'] ?? null );
		$assignment_id = $this->stored_public_id( $current['assignment_id'] ?? null );
		if (
			! hash_equals( $record['submission_public_id'], $submission_id )
			|| ! hash_equals( $record['assignment_public_id'], $assignment_id )
			|| $record['learner_user_id'] !== $this->positive_integer( $current['learner_user_id'] ?? $record['learner_user_id'] )
		) {
			$this->stored_invalid();
		}
		$state = $this->submission_state( $current['submission_state'] ?? null );
		if ( ! in_array( $state, array( 'draft', 'reopened', 'conflict' ), true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::SUBMISSION_ALREADY_OFFICIAL );
		}
		if ( ! in_array( $this->assignment_state( $current['assignment_state'] ?? null ), array( 'scheduled', 'open', 'closed' ), true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STATE_CONFLICT );
		}
		$this->token( $current['context_type'] ?? null, 32 );
		$this->token( $current['context_id'] ?? null, 191 );
		$context_revision = $this->token( $current['context_revision'] ?? null, 191 );
		if ( ! hash_equals( $record['authorized_context_revision'], $context_revision ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::SUBMISSION_REVISION_CONFLICT );
		}

		$opens                = $this->optional_utc( $current['opens_at_utc'] ?? null );
		$due                  = $this->required_utc( $current['due_at_utc'] ?? null );
		$closes               = $this->required_utc( $current['closes_at_utc'] ?? null );
		$schedule_version     = $this->positive_integer( $current['schedule_version'] ?? null );
		$schedule_hash        = $this->stored_hash( $current['schedule_hash'] ?? null );
		$actual_schedule_hash = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'auto_submit_enabled' => 1 === $this->zero_or_one( $current['auto_submit_enabled'] ?? null ),
					'closes_at_utc'       => $closes,
					'due_at_utc'          => $due,
					'opens_at_utc'        => $opens,
					'schedule_version'    => $schedule_version,
				)
			)
		);
		if (
			( null !== $opens && $opens > $due ) || $due > $closes
			|| 1 !== $this->zero_or_one( $current['auto_submit_enabled'] ?? null )
			|| ! hash_equals( $schedule_hash, $actual_schedule_hash )
			|| $record['effective_received_at_utc'] !== $closes || $record['occurred_at_utc'] < $closes
			|| $record['expected_schedule_version'] !== $schedule_version
			|| ! hash_equals( $record['expected_schedule_hash'], $schedule_hash )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::SUBMISSION_REVISION_CONFLICT );
		}

		$publication_hash = $this->stored_hash( $current['publication_hash'] ?? null );
		if (
			! hash_equals( $publication_hash, $this->stored_hash( $current['publication_content_hash'] ?? null ) )
			|| ! hash_equals( $publication_hash, $this->stored_hash( $current['stored_publication_hash'] ?? null ) )
		) {
			$this->stored_invalid();
		}
		$current_revision_id = $this->optional_stored_public_id( $current['current_revision_public_id'] ?? null );
		if ( null === $current_revision_id ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::SUBMISSION_VALIDATION_FAILED );
		}
		if (
			$record['expected_state'] !== $state
			|| $record['expected_lock_version'] !== $this->non_negative_integer( $current['submission_lock_version'] ?? null )
			|| $record['expected_current_revision_id'] !== $current_revision_id
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::SUBMISSION_REVISION_CONFLICT );
		}
	}

	/**
	 * Auto resubmission policy.
	 *
	 * @param array<string,mixed> $current Current.
	 * @return array{max_attempts:int,hash:string}
	 */
	private function auto_resubmission_policy( array $current ): array {
		if (
			self::POLICY_SCHEMA !== ( $current['resubmission_policy_schema'] ?? null )
			|| self::VERSION !== $this->positive_integer( $current['resubmission_policy_version'] ?? null )
		) {
			$this->stored_invalid();
		}
		$hash   = $this->stored_hash( $current['resubmission_policy_hash'] ?? null );
		$policy = $this->decode_json( (string) ( $current['resubmission_policy_json'] ?? '' ), $hash );
		$this->stored_exact_keys( $policy, array( 'copy_previous_answers', 'max_attempts', 'reopen_until_utc' ) );
		$maximum = $this->positive_integer( $policy['max_attempts'] ?? null );
		if (
			$maximum > 10 || ! is_bool( $policy['copy_previous_answers'] ?? null )
			|| ( null !== ( $policy['reopen_until_utc'] ?? null ) && $this->required_utc( $policy['reopen_until_utc'] ) > $this->required_utc( $current['closes_at_utc'] ?? null ) )
		) {
			$this->stored_invalid();
		}
		return array(
			'max_attempts' => $maximum,
			'hash'         => $hash,
		);
	}

	/**
	 * Auto attempt no.
	 *
	 * @param array<string,mixed>                 $current Current.
	 * @param array{max_attempts:int,hash:string} $policy Policy.
	 * @param array<string,mixed>                 $record Record.
	 * @param int|null                            $latest_receipt Latest receipt.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function auto_attempt_no( array $current, array $policy, array $record, ?int $latest_receipt ): int {
		$official_id = $this->optional_stored_public_id( $current['official_revision_public_id'] ?? null );
		$attempt     = $this->non_negative_integer( $current['official_attempt_no'] ?? null );
		if ( null === $official_id && 0 === $attempt && null === $latest_receipt ) {
			return 1;
		}
		if (
			null === $official_id || $attempt < 1 || null === $latest_receipt
			|| $official_id === $record['expected_current_revision_id']
			|| Submission_Attempt_Allowance::remaining( $policy['max_attempts'], $attempt, (string) $current['submission_state'] ) < 1
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STATE_CONFLICT );
		}
		return $attempt + 1;
	}

	/**
	 * Insert auto audit.
	 *
	 * @param array<string,mixed> $current Current.
	 * @param array<string,mixed> $record Record.
	 * @param array<string,mixed> $submission Submission.
	 * @param array<string,mixed> $receipt Receipt.
	 * @return void
	 */
	private function insert_auto_audit( array $current, array $record, array $submission, array $receipt ): void {
		$previous = $this->get_var( 'SELECT event_hash FROM ' . self::TABLE_AUDIT . ' ORDER BY id DESC LIMIT 1 FOR UPDATE' );
		if ( null !== $previous ) {
			$this->stored_hash( $previous );
		}
		$before        = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'id'                   => $current['submission_id'],
					'state'                => $current['submission_state'],
					'lock_version'         => $current['submission_lock_version'],
					'current_revision_id'  => $current['current_revision_public_id'],
					'official_revision_id' => $current['official_revision_public_id'],
					'official_attempt_no'  => $current['official_attempt_no'],
				)
			)
		);
		$after         = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'receipt'    => $receipt,
					'submission' => $submission,
				)
			)
		);
		$metadata_json = $this->canonical->encode(
			array(
				'command_id'                   => $record['command_id'],
				'effective_received_at_utc'    => $record['effective_received_at_utc'],
				'expected_current_revision_id' => $record['expected_current_revision_id'],
				'expected_lock_version'        => $record['expected_lock_version'],
				'receipt_id'                   => $receipt['id'],
			)
		);
		$metadata_hash = hash( 'sha256', $metadata_json );
		$event_hash    = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'action_key'          => self::AUTO_EVENT_TYPE,
					'actor_user_id'       => null,
					'after_digest'        => $after,
					'before_digest'       => $before,
					'correlation_id'      => $record['correlation_id'],
					'metadata_hash'       => $metadata_hash,
					'object_public_id'    => $current['submission_id'],
					'object_type'         => 'submission',
					'occurred_at_utc'     => $record['occurred_at_utc'],
					'previous_event_hash' => null === $previous ? null : (string) $previous,
				)
			)
		);
		$this->insert_or_fail(
			self::TABLE_AUDIT,
			array(
				'public_id'           => $record['audit_public_id'],
				'occurred_at_utc'     => $record['occurred_at_utc'],
				'actor_user_id'       => null,
				'action_key'          => self::AUTO_EVENT_TYPE,
				'object_type'         => 'submission',
				'object_public_id'    => $current['submission_id'],
				'context_type'        => $current['context_type'],
				'context_id'          => $current['context_id'],
				'context_revision'    => $record['authorized_context_revision'],
				'correlation_id'      => $record['correlation_id'],
				'before_digest'       => $before,
				'after_digest'        => $after,
				'metadata_schema'     => self::AUDIT_SCHEMA,
				'metadata_version'    => self::VERSION,
				'metadata_json'       => $metadata_json,
				'metadata_hash'       => $metadata_hash,
				'previous_event_hash' => null === $previous ? null : (string) $previous,
				'event_hash'          => $event_hash,
			)
		);
	}

	/**
	 * Insert auto outbox.
	 *
	 * @param array<string,mixed> $record Record.
	 * @param array<string,mixed> $submission Submission.
	 * @param array<string,mixed> $receipt Receipt.
	 * @return void
	 */
	private function insert_auto_outbox( array $record, array $submission, array $receipt ): void {
		$payload_json = $this->canonical->encode(
			array(
				'result' => array(
					'receipt'    => $receipt,
					'submission' => $submission,
				),
			)
		);
		$this->insert_or_fail(
			self::TABLE_OUTBOX,
			array(
				'public_id'            => $record['outbox_public_id'],
				'lock_version'         => 0,
				'created_by_user_id'   => $record['learner_user_id'],
				'created_at_utc'       => $record['occurred_at_utc'],
				'updated_by_user_id'   => $record['learner_user_id'],
				'updated_at_utc'       => $record['occurred_at_utc'],
				'aggregate_type'       => 'submission',
				'aggregate_public_id'  => $record['submission_public_id'],
				'event_type'           => self::AUTO_EVENT_TYPE,
				'idempotency_key'      => $record['idempotency_storage_key'],
				'payload_schema'       => self::AUTO_PAYLOAD_SCHEMA,
				'payload_version'      => self::VERSION,
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
	 * Reject invalid auto postcondition.
	 *
	 * @param array<string,mixed>                                                                    $current Current.
	 * @param array<string,mixed>                                                                    $record Record.
	 * @param array{revision_pk:int,public_id:string,content_hash:string,attachment_set_hash:string} $revision Revision.
	 * @param int                                                                                    $receipt_pk Receipt pk.
	 * @param string                                                                                 $receipt_content_hash Receipt content hash.
	 * @param int                                                                                    $lock_version Lock version.
	 * @param int                                                                                    $attempt_no Attempt no.
	 * @return void
	 */
	private function assert_auto_postcondition(
		array $current,
		array $record,
		array $revision,
		int $receipt_pk,
		string $receipt_content_hash,
		int $lock_version,
		int $attempt_no
	): void {
		$root    = $this->get_row(
			$this->prepare(
				'SELECT public_id, lock_version, state, current_revision_public_id, official_revision_public_id, official_attempt_no, submitted_at_utc, closed_at_utc , official_review_public_id, official_review_hash, current_release_public_id, current_release_hash FROM ' . self::TABLE_SUBMISSIONS . ' WHERE id = %d LIMIT 1 FOR UPDATE',
				array( $this->positive_integer( $current['submission_pk'] ?? null ) )
			)
		);
		$receipt = $this->get_row(
			$this->prepare(
				'SELECT public_id, content_hash, submission_revision_id, action, actor_type, actor_user_id FROM ' . self::TABLE_RECEIPTS
				. ' WHERE id = %d LIMIT 1 FOR UPDATE',
				array( $receipt_pk )
			)
		);
		if (
			null === $root || null === $receipt || $record['submission_public_id'] !== ( $root['public_id'] ?? null )
			|| $lock_version !== $this->non_negative_integer( $root['lock_version'] ?? null )
			|| 'auto_submitted' !== ( $root['state'] ?? null )
			|| $revision['public_id'] !== ( $root['current_revision_public_id'] ?? null )
			|| $revision['public_id'] !== ( $root['official_revision_public_id'] ?? null )
			|| $attempt_no !== $this->positive_integer( $root['official_attempt_no'] ?? null )
			|| $record['effective_received_at_utc'] !== ( $root['submitted_at_utc'] ?? null )
			|| $record['effective_received_at_utc'] !== ( $root['closed_at_utc'] ?? null )
			|| $record['receipt_public_id'] !== ( $receipt['public_id'] ?? null )
			|| ! hash_equals( $receipt_content_hash, (string) ( $receipt['content_hash'] ?? '' ) )
			|| $revision['revision_pk'] !== $this->positive_integer( $receipt['submission_revision_id'] ?? null )
			|| 'auto_submit' !== ( $receipt['action'] ?? null ) || 'system' !== ( $receipt['actor_type'] ?? null )
			|| null !== ( $receipt['actor_user_id'] ?? null )
		) {
			$this->stored_invalid();
		}
		$this->assert_result_pointer_reset( $root );
	}

	/**
	 * Reject invalid auto record.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_auto_record( array $record ): void {
		$expected = array(
			'assignment_public_id',
			'learner_user_id',
			'submission_public_id',
			'receipt_public_id',
			'audit_public_id',
			'outbox_public_id',
			'command_id',
			'correlation_id',
			'idempotency_storage_key',
			'occurred_at_utc',
			'effective_received_at_utc',
			'expected_state',
			'expected_lock_version',
			'expected_current_revision_id',
			'expected_schedule_version',
			'expected_schedule_hash',
			'authorized_context_revision',
		);
		$actual   = array_keys( $record );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected || ! is_int( $record['learner_user_id'] ) || $record['learner_user_id'] < 1 ) {
			throw new InvalidArgumentException( 'Auto-submit record is invalid.' );
		}
		foreach ( array( 'assignment_public_id', 'submission_public_id', 'receipt_public_id', 'audit_public_id', 'outbox_public_id' ) as $field ) {
			new Public_Id( (string) $record[ $field ] );
		}
		if ( null !== $record['expected_current_revision_id'] ) {
			new Public_Id( (string) $record['expected_current_revision_id'] );
		}
		$this->assert_hash_argument( (string) $record['expected_schedule_hash'] );
		$this->assert_storage_key( (string) $record['idempotency_storage_key'] );
		$this->assert_utc_argument( (string) $record['occurred_at_utc'] );
		$this->assert_utc_argument( (string) $record['effective_received_at_utc'] );
		if (
			! in_array( $record['expected_state'], array( 'draft', 'reopened', 'conflict' ), true )
			|| ! is_int( $record['expected_lock_version'] ) || $record['expected_lock_version'] < 0
			|| ! is_int( $record['expected_schedule_version'] ) || $record['expected_schedule_version'] < 1
			|| ! is_string( $record['authorized_context_revision'] ) || '' === $record['authorized_context_revision']
			|| strlen( $record['authorized_context_revision'] ) > 191
		) {
			throw new InvalidArgumentException( 'Auto-submit record values are invalid.' );
		}
		$this->token_argument( (string) $record['command_id'], 128 );
		$this->token_argument( (string) $record['correlation_id'], 128 );
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
			'submission_public_id',
			'receipt_public_id',
			'audit_public_id',
			'outbox_public_id',
			'command_id',
			'correlation_id',
			'idempotency_storage_key',
			'request_digest',
			'occurred_at_utc',
			'expected_state',
			'expected_lock_version',
			'expected_current_revision_id',
			'expected_schedule_version',
			'expected_schedule_hash',
			'expected_publication_hash',
			'expected_save_token',
			'authorized_context_revision',
		);
		$actual   = array_keys( $record );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected || ! is_int( $record['actor_user_id'] ) || $record['actor_user_id'] < 1 ) {
			throw new InvalidArgumentException( 'Submit record is invalid.' );
		}
		foreach ( array( 'submission_public_id', 'receipt_public_id', 'audit_public_id', 'outbox_public_id', 'expected_current_revision_id' ) as $field ) {
			new Public_Id( (string) $record[ $field ] );
		}
		foreach ( array( 'request_digest', 'expected_schedule_hash', 'expected_publication_hash', 'expected_save_token' ) as $field ) {
			$this->assert_hash_argument( (string) $record[ $field ] );
		}
		$this->assert_storage_key( (string) $record['idempotency_storage_key'] );
		$this->assert_utc_argument( (string) $record['occurred_at_utc'] );
		if (
			! in_array( $record['expected_state'], array( 'draft', 'reopened' ), true )
			|| ! is_int( $record['expected_lock_version'] ) || $record['expected_lock_version'] < 0
			|| ! is_int( $record['expected_schedule_version'] ) || $record['expected_schedule_version'] < 1
			|| ! is_string( $record['authorized_context_revision'] ) || '' === $record['authorized_context_revision']
			|| strlen( $record['authorized_context_revision'] ) > 191
		) {
			throw new InvalidArgumentException( 'Submit record values are invalid.' );
		}
		$this->token_argument( (string) $record['command_id'], 128 );
		$this->token_argument( (string) $record['correlation_id'], 128 );
	}

	/**
	 * Decode json.
	 *
	 * @param string $json Json.
	 * @param string $hash Hash.
	 * @param string $reason Reason.
	 * @return array<string,mixed>
	 */
	private function decode_json( string $json, string $hash, string $reason = Assignment_Failure::STORED_DATA_INVALID ): array {
		if ( ! hash_equals( $hash, hash( 'sha256', $json ) ) ) {
			$this->stored_invalid( $reason );
		}
		try {
			$value = json_decode( $json, true, 64, JSON_THROW_ON_ERROR ); } catch ( JsonException ) {
			$this->stored_invalid( $reason );
			}
			if ( ! is_array( $value ) || array_is_list( $value ) || ! hash_equals( $json, $this->canonical->encode( $value ) ) ) {
				$this->stored_invalid( $reason );
			}
			return $value;
	}

	/**
	 * Stored exact keys.
	 *
	 * @param array<string,mixed> $value Value.
	 * @param array<int,string>   $expected Expected.
	 * @param string              $reason Reason.
	 * @return void
	 */
	private function stored_exact_keys( array $value, array $expected, string $reason = Assignment_Failure::STORED_DATA_INVALID ): void {
		$actual = array_keys( $value );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected ) {
			$this->stored_invalid( $reason );
		}
	}

	/**
	 * Submission state.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function submission_state( mixed $value ): string {
		if ( ! is_string( $value ) || ! in_array( $value, array( 'draft', 'conflict', 'submitted', 'auto_submitted', 'proxy_submitted', 'reopened', 'locked' ), true ) ) {
			$this->stored_invalid();
		} return $value;
	}
	/**
	 * Assignment state.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function assignment_state( mixed $value ): string {
		if ( ! is_string( $value ) || ! in_array( $value, array( 'draft', 'scheduled', 'open', 'closed', 'withdrawn' ), true ) ) {
			$this->stored_invalid();
		} return $value;
	}
	/**
	 * Stored public id.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function stored_public_id( mixed $value ): string {
		if ( ! is_string( $value ) ) {
			$this->stored_invalid();
		} try {
			new Public_Id( $value );
		} catch ( InvalidArgumentException ) {
			$this->stored_invalid();
		} return $value;
	}
	/**
	 * Optional stored public id.
	 *
	 * @param mixed $value Value.
	 * @return string|null
	 */
	private function optional_stored_public_id( mixed $value ): ?string {
		return null === $value ? null : $this->stored_public_id( $value );
	}
	/**
	 * Stored hash.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function stored_hash( mixed $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $value ) ) {
			$this->stored_invalid();
		} return $value;
	}
	/**
	 * Token.
	 *
	 * @param mixed $value Value.
	 * @param int   $maximum Maximum.
	 * @return string
	 */
	private function token( mixed $value, int $maximum ): string {
		if ( ! is_string( $value ) || '' === $value || strlen( $value ) > $maximum || 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@\/-]*$/D', $value ) ) {
			$this->stored_invalid();
		} return $value;
	}
	/**
	 * Stored text.
	 *
	 * @param mixed $value Value.
	 * @param int   $maximum Maximum.
	 * @return string
	 */
	private function stored_text( mixed $value, int $maximum ): string {
		if ( ! is_string( $value ) || '' === $value || strlen( $value ) > $maximum || 1 !== preg_match( '//u', $value ) ) {
			$this->stored_invalid();
		} return $value;
	}
	/**
	 * Positive integer.
	 *
	 * @param mixed $value Value.
	 * @return int
	 */
	private function positive_integer( mixed $value ): int {
		if ( is_string( $value ) && 1 === preg_match( '/^[1-9][0-9]*$/D', $value ) ) {
			$value = (int) $value;
		} if ( ! is_int( $value ) || $value < 1 ) {
			$this->stored_invalid();
		} return $value;
	}
	/**
	 * Non negative integer.
	 *
	 * @param mixed $value Value.
	 * @return int
	 */
	private function non_negative_integer( mixed $value ): int {
		if ( is_string( $value ) && 1 === preg_match( '/^(?:0|[1-9][0-9]*)$/D', $value ) ) {
			$value = (int) $value;
		} if ( ! is_int( $value ) || $value < 0 ) {
			$this->stored_invalid();
		} return $value;
	}
	/**
	 * Zero or one.
	 *
	 * @param mixed $value Value.
	 * @return int
	 */
	private function zero_or_one( mixed $value ): int {
		$value = $this->non_negative_integer( $value );
		if ( $value > 1 ) {
			$this->stored_invalid();
		} return $value;
	}
	/**
	 * Required utc.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function required_utc( mixed $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value ) ) {
			$this->stored_invalid();
		} return $value;
	}
	/**
	 * Optional utc.
	 *
	 * @param mixed $value Value.
	 * @return string|null
	 */
	private function optional_utc( mixed $value ): ?string {
		return null === $value ? null : $this->required_utc( $value );
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
			throw new InvalidArgumentException( 'Submit hash is invalid.' );
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
		if ( 1 !== preg_match( '/^submission:[a-f0-9]{64}$/D', $value ) ) {
			throw new InvalidArgumentException( 'Submit storage key is invalid.' );
		}
	}
	/**
	 * Reject invalid utc argument.
	 *
	 * @param string $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_utc_argument( string $value ): void {
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value ) ) {
			throw new InvalidArgumentException( 'Submit UTC value is invalid.' );
		}
	}
	/**
	 * Token argument.
	 *
	 * @param string $value Value.
	 * @param int    $maximum Maximum.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function token_argument( string $value, int $maximum ): void {
		if ( strlen( $value ) < 8 || strlen( $value ) > $maximum || 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@-]*$/D', $value ) ) {
			throw new InvalidArgumentException( 'Submit token is invalid.' );
		}
	}
	/**
	 * Stored invalid.
	 *
	 * @param string $reason Reason.
	 * @return never
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function stored_invalid( string $reason = Assignment_Failure::STORED_DATA_INVALID ): never {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
		throw Assignment_Failure::because( $reason );
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
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORAGE_UNAVAILABLE );
		} return $prepared;
	}
	/**
	 * Get row.
	 *
	 * @param string $sql Sql.
	 * @return array<string,mixed>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function get_row( string $sql ): ?array {
		$db = $this->database();
		$this->reset_error( $db );
		$row = $db->get_row( $sql, defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A' );
		$this->assert_read( $db );
		if ( null === $row ) {
			return null;
		} if ( ! is_array( $row ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORAGE_UNAVAILABLE );
		} return $row;
	}
	/**
	 * Get results.
	 *
	 * @param string $sql Sql.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function get_results( string $sql ): array {
		$db = $this->database();
		$this->reset_error( $db );
		$rows = $db->get_results( $sql, defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A' );
		$this->assert_read( $db );
		if ( ! is_array( $rows ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORAGE_UNAVAILABLE );
		} foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Assignment_Failure::because( Assignment_Failure::STORAGE_UNAVAILABLE );
			}
		} return array_values( $rows );
	}
	/**
	 * Get var.
	 *
	 * @param string $sql Sql.
	 * @return mixed
	 */
	private function get_var( string $sql ): mixed {
		$db = $this->database();
		$this->reset_error( $db );
		$value = $db->get_var( $sql );
		$this->assert_read( $db );
		return $value;
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
		if ( false === $db->insert( $table, $data ) || '' !== trim( (string) ( $db->last_error ?? '' ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORAGE_UNAVAILABLE );
		}
	}
	/**
	 * Update or conflict.
	 *
	 * @param string              $table Table.
	 * @param array<string,mixed> $data Data.
	 * @param array<string,mixed> $where Where.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function update_or_conflict( string $table, array $data, array $where ): void {
		$db = $this->database();
		$this->reset_error( $db );
		$changed = $db->update( $table, $data, $where );
		if ( 1 !== $changed || '' !== trim( (string) ( $db->last_error ?? '' ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::SUBMISSION_REVISION_CONFLICT );
		}
	}
	/**
	 * Insert id.
	 *
	 * @return int
	 */
	private function insert_id(): int {
		return $this->positive_integer( $this->database()->insert_id ?? null );
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
		if ( false === $db->query( $sql ) || '' !== trim( (string) ( $db->last_error ?? '' ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORAGE_UNAVAILABLE );
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
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORAGE_UNAVAILABLE );
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
		if ( ! is_object( $db ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORAGE_UNAVAILABLE );
		}
		foreach ( array( 'prepare', 'get_row', 'get_results', 'get_var', 'insert', 'update', 'query' ) as $method ) {
			if ( ! method_exists( $db, $method ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Assignment_Failure::because( Assignment_Failure::STORAGE_UNAVAILABLE );
			}
		}
		return $db;
	}
}
