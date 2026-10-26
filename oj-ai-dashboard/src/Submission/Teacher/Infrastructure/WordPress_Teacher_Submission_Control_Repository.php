<?php
/**
 * WordPress teacher submission control repository.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Teacher\Infrastructure;

use InvalidArgumentException;
use JsonException;
use OJAI\Dashboard\Submission\Assignment\Application\Assignment_Failure;
use OJAI\Dashboard\Submission\Student\Domain\Submission_Response_Validator;
use OJAI\Dashboard\Submission\Student\Domain\Submission_Revision_Integrity;
use OJAI\Dashboard\Submission\Teacher\Application\Control_Teacher_Submission_Command;
use OJAI\Dashboard\Submission\Teacher\Application\Control_Teacher_Submission_Result;
use OJAI\Dashboard\Submission\Teacher\Port\Teacher_Submission_Control_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Commits C27-C28 without changing any earlier revision or receipt.
 */
final class WordPress_Teacher_Submission_Control_Repository implements Teacher_Submission_Control_Repository_Port_Interface {
	private const TABLE_ASSIGNMENTS         = 'oj_ai_assignments';
	private const TABLE_TARGETS             = 'oj_ai_assignment_targets';
	private const TABLE_PUBLICATIONS        = 'oj_ai_worksheet_publications';
	private const TABLE_WORKSHEET_REVISIONS = 'oj_ai_worksheet_revisions';
	private const TABLE_SUBMISSIONS         = 'oj_ai_submissions';
	private const TABLE_REVISIONS           = 'oj_ai_submission_revisions';
	private const TABLE_RECEIPTS            = 'oj_ai_submission_receipts';
	private const TABLE_ATTACHMENTS         = 'oj_ai_submission_attachments';
	private const TABLE_AUDIT               = 'oj_ai_audit_events';
	private const TABLE_OUTBOX              = 'oj_ai_outbox_events';

	private const PAYLOAD_SCHEMA  = 'oj_ai.submission_controlled_event';
	private const AUDIT_SCHEMA    = 'oj_ai.submission_audit';
	private const POLICY_SCHEMA   = 'oj_ai.assignment_resubmission_policy';
	private const VERSION         = 1;
	private const MAX_ATTACHMENTS = 100;

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
	 * Initialize WordPress teacher submission control repository dependencies and state.
	 *
	 * @param object|null         $database Database.
	 * @param Canonical_Json|null $canonical Canonical JSON encoder used for stable digests.
	 */
	public function __construct( private ?object $database = null, ?Canonical_Json $canonical = null ) {
		$this->canonical = $canonical ?? new Canonical_Json();
		$this->responses = new Submission_Response_Validator( $this->canonical );
		$this->integrity = new Submission_Revision_Integrity( $this->canonical );
	}

	/**
	 * Find receipt.
	 *
	 * @param string $storage_key Storage key.
	 * @param string $request_digest Request digest.
	 * @param string $action Action.
	 * @return Control_Teacher_Submission_Result|null
	 */
	public function find_receipt( string $storage_key, string $request_digest, string $action ): ?Control_Teacher_Submission_Result {
		$this->assert_storage_key( $storage_key );
		$this->assert_hash_argument( $request_digest );
		$this->assert_action_argument( $action );
		return $this->receipt( $storage_key, $request_digest, $action, false );
	}

	/**
	 * Control.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Control_Teacher_Submission_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function control( array $record ): Control_Teacher_Submission_Result {
		$this->assert_record( $record );
		$replay = $this->receipt( $record['idempotency_storage_key'], $record['request_digest'], $record['action'], false );
		if ( $replay instanceof Control_Teacher_Submission_Result ) {
			return $replay;
		}

		$transaction = false;
		try {
			$this->query_or_fail( 'START TRANSACTION' );
			$transaction = true;
			$replay      = $this->receipt( $record['idempotency_storage_key'], $record['request_digest'], $record['action'], true );
			if ( $replay instanceof Control_Teacher_Submission_Result ) {
				$this->query_or_fail( 'COMMIT' );
				$transaction = false;
				return $replay;
			}

			$current = $this->lock_submission_context( $record );
			if ( null === $current || 'active' !== ( $current['target_state'] ?? null ) ) {
				throw Assignment_Failure::because( Assignment_Failure::NOT_FOUND );
			}
			$validated      = $this->assert_expected( $current, $record );
			$official       = $this->lock_official_revision( $current, $record );
			$latest_receipt = $this->latest_receipt_no( $this->positive_integer( $current['submission_pk'] ?? null ) );
			if ( null === $latest_receipt ) {
				$this->stored_invalid();
			}
			$receipt_no       = $latest_receipt + 1;
			$editable         = null;
			$receipt_revision = $official;
			if ( Control_Teacher_Submission_Command::REOPEN === $record['action'] ) {
				$receipt_revision = $this->create_reopened_fork( $current, $record, $official, $validated['policy'] );
				$editable         = $receipt_revision['projection'];
			}

			$receipt_record   = $this->insert_receipt(
				$current,
				$record,
				$receipt_revision,
				$receipt_no,
				$validated['policy']['hash'],
				$validated['schedule']['due_at_utc']
			);
			$new_lock_version = $record['expected_lock_version'] + 1;
			$this->update_root( $current, $record, $receipt_revision, $new_lock_version );
			$result = $this->result(
				$current,
				$record,
				$receipt_revision,
				$receipt_no,
				$new_lock_version,
				$validated['policy']['hash'],
				$validated['schedule']['due_at_utc'],
				$receipt_record['content_hash'],
				$editable,
				false
			);
			$this->insert_audit( $current, $record, $result );
			$this->insert_outbox( $record, $result );
			$this->assert_postcondition(
				$current,
				$record,
				$receipt_revision,
				$receipt_record['receipt_pk'],
				$receipt_record['content_hash'],
				$new_lock_version,
				$editable
			);
			$this->query_or_fail( 'COMMIT' );
			$transaction = false;
			return $result;
		} catch ( Assignment_Failure $failure ) {
			$this->rollback();
			throw $failure;
		} catch ( Throwable ) {
			if ( $transaction ) {
				$this->rollback();
			}
			$replay = $this->receipt( $record['idempotency_storage_key'], $record['request_digest'], $record['action'], false );
			if ( $replay instanceof Control_Teacher_Submission_Result ) {
				return $replay;
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Lock submission context.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return array<string,mixed>|null
	 */
	private function lock_submission_context( array $record ): ?array {
		$sql = 'SELECT s.id AS submission_pk, s.public_id AS submission_id, s.lock_version AS submission_lock_version, s.state AS submission_state, s.current_revision_public_id, s.official_revision_public_id, s.official_attempt_no, s.submitted_at_utc, s.closed_at_utc, a.id AS assignment_pk, a.public_id AS assignment_id, a.context_type, a.context_id, a.context_revision, a.state AS assignment_state, a.worksheet_publication_public_id, a.publication_hash, a.opens_at_utc, a.due_at_utc, a.closes_at_utc, a.schedule_version, a.schedule_hash, a.auto_submit_enabled, a.resubmission_policy_schema, a.resubmission_policy_version, a.resubmission_policy_json, a.resubmission_policy_hash, t.state AS target_state, p.content_hash AS publication_content_hash, p.publication_hash AS stored_publication_hash, p.response_schema_schema, p.response_schema_version, p.response_schema_json, p.response_schema_hash, wr.document_hash AS publication_source_hash FROM ' . self::TABLE_SUBMISSIONS . ' s INNER JOIN ' . self::TABLE_ASSIGNMENTS . ' a ON a.id = s.assignment_id '
			. 'INNER JOIN ' . self::TABLE_TARGETS . ' t ON t.assignment_id = a.id AND t.learner_user_id = s.learner_user_id '
			. 'INNER JOIN ' . self::TABLE_PUBLICATIONS . ' p ON p.public_id = a.worksheet_publication_public_id '
			. 'INNER JOIN ' . self::TABLE_WORKSHEET_REVISIONS . ' wr ON wr.id = p.worksheet_revision_id '
			. 'WHERE s.public_id = %s AND a.context_type = %s AND a.context_id = %s AND a.context_revision = %s LIMIT 1 FOR UPDATE';
		return $this->get_row(
			$this->prepare(
				$sql,
				array(
					$record['submission_public_id'],
					$record['authorized_context_type'],
					$record['authorized_context_id'],
					$record['authorized_context_revision'],
				)
			)
		);
	}

	/**
	 * Reject invalid expected.
	 *
	 * @param array<string,mixed> $current Current.
	 * @param array<string,mixed> $record Record.
	 * @return array{schedule:array{version:int,hash:string,opens_at_utc:?string,due_at_utc:string,closes_at_utc:string},policy:array{max_attempts:int,reopen_until_utc:?string,copy_previous_answers:bool,hash:string}}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_expected( array $current, array $record ): array {
		$submission_id = $this->stored_public_id( $current['submission_id'] ?? null );
		$this->stored_public_id( $current['assignment_id'] ?? null );
		$this->stored_public_id( $current['worksheet_publication_public_id'] ?? null );
		if ( ! hash_equals( $record['submission_public_id'], $submission_id ) ) {
			$this->stored_invalid();
		}
		$state            = $this->submission_state( $current['submission_state'] ?? null );
		$assignment_state = $this->assignment_state( $current['assignment_state'] ?? null );
		$allowed          = array(
			Control_Teacher_Submission_Command::REOPEN => array( 'submitted', 'auto_submitted', 'proxy_submitted', 'locked' ),
			Control_Teacher_Submission_Command::LOCK   => array( 'submitted', 'auto_submitted', 'proxy_submitted', 'reopened' ),
		);
		if ( ! in_array( $state, $allowed[ $record['action'] ], true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STATE_CONFLICT );
		}
		if (
			( Control_Teacher_Submission_Command::REOPEN === $record['action'] && 'open' !== $assignment_state )
			|| ( Control_Teacher_Submission_Command::LOCK === $record['action'] && ! in_array( $assignment_state, array( 'open', 'closed' ), true ) )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STATE_CONFLICT );
		}
		if ( 'active' !== $this->target_state( $current['target_state'] ?? null ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::NOT_FOUND );
		}
		foreach ( array(
			'context_type'     => 32,
			'context_id'       => 191,
			'context_revision' => 191,
		) as $field => $maximum ) {
			$value = $this->token( $current[ $field ] ?? null, $maximum );
			if ( ! hash_equals( $record[ 'authorized_' . $field ], $value ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Assignment_Failure::because( Assignment_Failure::NOT_FOUND );
			}
		}

		$schedule = $this->schedule( $current );
		if (
			$record['expected_schedule_version'] !== $schedule['version']
			|| ! hash_equals( $record['expected_schedule_hash'], $schedule['hash'] )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::SUBMISSION_REVISION_CONFLICT );
		}
		if ( Control_Teacher_Submission_Command::REOPEN === $record['action'] ) {
			if (
				( null !== $schedule['opens_at_utc'] && $record['occurred_at_utc'] < $schedule['opens_at_utc'] )
				|| $record['occurred_at_utc'] >= $schedule['closes_at_utc']
			) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Assignment_Failure::because( Assignment_Failure::STATE_CONFLICT );
			}
		}

		$publication_hash = $this->stored_hash( $current['publication_hash'] ?? null );
		if (
			! hash_equals( $publication_hash, $record['expected_publication_hash'] )
			|| ! hash_equals( $publication_hash, $this->stored_hash( $current['publication_content_hash'] ?? null ) )
			|| ! hash_equals( $publication_hash, $this->stored_hash( $current['stored_publication_hash'] ?? null ) )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::SUBMISSION_REVISION_CONFLICT );
		}

		$lock_version = $this->non_negative_integer( $current['submission_lock_version'] ?? null );
		$official_id  = $this->stored_public_id( $current['official_revision_public_id'] ?? null );
		$current_id   = $this->stored_public_id( $current['current_revision_public_id'] ?? null );
		$attempt      = $this->positive_integer( $current['official_attempt_no'] ?? null );
		$this->required_utc( $current['submitted_at_utc'] ?? null );
		if (
			$record['expected_state'] !== $state
			|| $record['expected_lock_version'] !== $lock_version
			|| $record['expected_official_revision_id'] !== $official_id
			|| $record['expected_official_attempt_no'] !== $attempt
			|| ( 'reopened' === $state ? $current_id === $official_id : $current_id !== $official_id )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::SUBMISSION_REVISION_CONFLICT );
		}

		$policy = $this->resubmission_policy( $current );
		if ( Control_Teacher_Submission_Command::REOPEN === $record['action'] ) {
			// The teacher's audited reopen authorizes one next submission, even at the limit.
			if ( null !== $policy['reopen_until_utc'] && $record['occurred_at_utc'] > $policy['reopen_until_utc'] ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Assignment_Failure::because( Assignment_Failure::STATE_CONFLICT );
			}
		}

		return array(
			'schedule' => $schedule,
			'policy'   => $policy,
		);
	}

	/**
	 * Schedule.
	 *
	 * @param array<string,mixed> $current Current.
	 * @return array{version:int,hash:string,opens_at_utc:?string,due_at_utc:string,closes_at_utc:string}
	 */
	private function schedule( array $current ): array {
		$version = $this->positive_integer( $current['schedule_version'] ?? null );
		$opens   = $this->optional_utc( $current['opens_at_utc'] ?? null );
		$due     = $this->required_utc( $current['due_at_utc'] ?? null );
		$closes  = $this->required_utc( $current['closes_at_utc'] ?? null );
		if ( ( null !== $opens && $opens > $due ) || $due > $closes ) {
			$this->stored_invalid();
		}
		$hash     = $this->stored_hash( $current['schedule_hash'] ?? null );
		$expected = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'auto_submit_enabled' => 1 === $this->zero_or_one( $current['auto_submit_enabled'] ?? null ),
					'closes_at_utc'       => $closes,
					'due_at_utc'          => $due,
					'opens_at_utc'        => $opens,
					'schedule_version'    => $version,
				)
			)
		);
		if ( ! hash_equals( $hash, $expected ) ) {
			$this->stored_invalid();
		}
		return array(
			'version'       => $version,
			'hash'          => $hash,
			'opens_at_utc'  => $opens,
			'due_at_utc'    => $due,
			'closes_at_utc' => $closes,
		);
	}

	/**
	 * Resubmission policy.
	 *
	 * @param array<string,mixed> $current Current.
	 * @return array{max_attempts:int,reopen_until_utc:?string,copy_previous_answers:bool,hash:string}
	 */
	private function resubmission_policy( array $current ): array {
		if (
			self::POLICY_SCHEMA !== ( $current['resubmission_policy_schema'] ?? null )
			|| self::VERSION !== $this->positive_integer( $current['resubmission_policy_version'] ?? null )
		) {
			$this->stored_invalid();
		}
		$hash   = $this->stored_hash( $current['resubmission_policy_hash'] ?? null );
		$policy = $this->decode_json( (string) ( $current['resubmission_policy_json'] ?? '' ), $hash );
		$this->stored_exact_keys( $policy, array( 'copy_previous_answers', 'max_attempts', 'reopen_until_utc' ) );
		$maximum      = $this->positive_integer( $policy['max_attempts'] ?? null );
		$reopen_until = $this->optional_utc( $policy['reopen_until_utc'] ?? null );
		if ( $maximum > 10 || ! is_bool( $policy['copy_previous_answers'] ?? null ) ) {
			$this->stored_invalid();
		}
		if ( null !== $reopen_until && $reopen_until > $this->required_utc( $current['closes_at_utc'] ?? null ) ) {
			$this->stored_invalid();
		}
		return array(
			'max_attempts'          => $maximum,
			'reopen_until_utc'      => $reopen_until,
			'copy_previous_answers' => (bool) $policy['copy_previous_answers'],
			'hash'                  => $hash,
		);
	}

	/**
	 * Lock official revision.
	 *
	 * @param array<string,mixed> $current Current.
	 * @param array<string,mixed> $record Record.
	 * @return array{revision_pk:int,public_id:string,revision_no:int,content_hash:string,response_json:string,response_hash:string,response:array<string,mixed>,attachment_set_hash:string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function lock_official_revision( array $current, array $record ): array {
		$sql = 'SELECT id AS revision_pk, public_id, content_hash, revision_no, base_revision_no, state, response_schema_version, response_schema, response_version, response_json, response_hash, attachment_set_hash FROM ' . self::TABLE_REVISIONS
			. ' WHERE submission_id = %d AND public_id = %s LIMIT 1 FOR UPDATE';
		$row = $this->get_row(
			$this->prepare(
				$sql,
				array( $this->positive_integer( $current['submission_pk'] ?? null ), $record['expected_official_revision_id'] )
			)
		);
		if ( null === $row || $record['expected_official_revision_id'] !== $this->stored_public_id( $row['public_id'] ?? null ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::SUBMISSION_REVISION_CONFLICT );
		}
		$revision_no      = $this->positive_integer( $row['revision_no'] ?? null );
		$base_revision_no = null === ( $row['base_revision_no'] ?? null ) ? null : $this->positive_integer( $row['base_revision_no'] );
		$state            = (string) ( $row['state'] ?? '' );
		if (
			! in_array( $state, array( 'saved', 'reopened_fork' ), true )
			|| Submission_Revision_Integrity::RESPONSE_SCHEMA_VERSION !== ( $row['response_schema_version'] ?? null )
			|| Submission_Revision_Integrity::RESPONSE_SCHEMA !== ( $row['response_schema'] ?? null )
			|| Submission_Revision_Integrity::RESPONSE_VERSION !== $this->positive_integer( $row['response_version'] ?? null )
		) {
			$this->stored_invalid();
		}
		$response_hash = $this->stored_hash( $row['response_hash'] ?? null );
		$response_json = (string) ( $row['response_json'] ?? '' );
		$response      = $this->decode_json( $response_json, $response_hash );
		$this->stored_exact_keys( $response, array( 'answers', 'publication_hash', 'publication_id', 'response_schema_version' ) );
		if (
			( $current['worksheet_publication_public_id'] ?? null ) !== ( $response['publication_id'] ?? null )
			|| ( $current['publication_hash'] ?? null ) !== ( $response['publication_hash'] ?? null )
			|| Submission_Revision_Integrity::RESPONSE_SCHEMA_VERSION !== ( $response['response_schema_version'] ?? null )
			|| ! is_array( $response['answers'] ?? null )
		) {
			$this->stored_invalid();
		}
		$validated = $this->responses->validate_complete( $this->response_profile( $current ), $response['answers'] );
		if ( $validated['answers'] !== $response['answers'] ) {
			$this->stored_invalid();
		}
		$attachment_hash = $this->stored_hash( $row['attachment_set_hash'] ?? null );
		$this->lock_and_validate_bound_attachments(
			$this->positive_integer( $current['submission_pk'] ?? null ),
			$attachment_hash,
			$validated['attachments']
		);
		$content_hash = $this->integrity->content_hash(
			$record['submission_public_id'],
			$revision_no,
			$base_revision_no,
			$state,
			Submission_Revision_Integrity::RESPONSE_SCHEMA_VERSION,
			$response_hash,
			$attachment_hash
		);
		if ( ! hash_equals( $this->stored_hash( $row['content_hash'] ?? null ), $content_hash ) ) {
			$this->stored_invalid();
		}
		return array(
			'revision_pk'         => $this->positive_integer( $row['revision_pk'] ?? null ),
			'public_id'           => $record['expected_official_revision_id'],
			'revision_no'         => $revision_no,
			'content_hash'        => $content_hash,
			'response_json'       => $response_json,
			'response_hash'       => $response_hash,
			'response'            => $response,
			'attachment_set_hash' => $attachment_hash,
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
			$this->stored_invalid();
		}
		$profile = $this->decode_json( (string) ( $current['response_schema_json'] ?? '' ), $this->stored_hash( $current['response_schema_hash'] ?? null ) );
		if ( ! hash_equals( $this->stored_hash( $current['publication_source_hash'] ?? null ), (string) ( $profile['source_hash'] ?? '' ) ) ) {
			$this->stored_invalid();
		}
		return $profile;
	}

	/**
	 * Lock and validate bound attachments.
	 *
	 * @param int                                                                                                        $submission_pk Submission pk.
	 * @param string                                                                                                     $expected_hash Expected hash.
	 * @param array<string,array{response_id:string,kind:string,role:string,mime_types:array<int,string>,max_bytes:int}> $requirements Requirements.
	 * @return void
	 */
	private function lock_and_validate_bound_attachments( int $submission_pk, string $expected_hash, array $requirements ): void {
		$ids = array_keys( $requirements );
		sort( $ids, SORT_STRING );
		if ( count( $ids ) > self::MAX_ATTACHMENTS || ! hash_equals( $expected_hash, hash( 'sha256', $this->canonical->encode( $ids ) ) ) ) {
			$this->stored_invalid();
		}
		if ( array() === $ids ) {
			return;
		}
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%s' ) );
		$sql          = 'SELECT id, public_id, submission_revision_id, response_key, state, mime_type, byte_size FROM ' . self::TABLE_ATTACHMENTS
			. ' WHERE submission_id = %d AND public_id IN (' . $placeholders . ') FOR UPDATE';
		$rows         = $this->get_results( $this->prepare( $sql, array_merge( array( $submission_pk ), $ids ) ) );
		$by_id        = array();
		foreach ( $rows as $row ) {
			$id = $this->stored_public_id( $row['public_id'] ?? null );
			if ( isset( $by_id[ $id ] ) ) {
				$this->stored_invalid();
			}
			$by_id[ $id ] = $row;
		}
		if ( count( $by_id ) !== count( $requirements ) ) {
			$this->stored_invalid();
		}
		foreach ( $requirements as $id => $requirement ) {
			$row = $by_id[ $id ] ?? null;
			if (
				! is_array( $row )
				|| 'bound' !== ( $row['state'] ?? null )
				|| $requirement['response_id'] !== ( $row['response_key'] ?? null )
				|| ! in_array( (string) ( $row['mime_type'] ?? '' ), $requirement['mime_types'], true )
				|| $this->positive_integer( $row['byte_size'] ?? null ) > $requirement['max_bytes']
			) {
				$this->stored_invalid();
			}
			$this->positive_integer( $row['submission_revision_id'] ?? null );
		}
	}

	/**
	 * Create reopened fork.
	 *
	 * @param array<string,mixed>                                                                     $current Current.
	 * @param array<string,mixed>                                                                     $record Record.
	 * @param array<string,mixed>                                                                     $official Official.
	 * @param array{max_attempts:int,reopen_until_utc:?string,copy_previous_answers:bool,hash:string} $policy Policy.
	 * @return array{revision_pk:int,public_id:string,revision_no:int,content_hash:string,response_hash:string,attachment_set_hash:string,projection:array<string,mixed>}
	 */
	private function create_reopened_fork( array $current, array $record, array $official, array $policy ): array {
		$latest = $this->latest_revision_no( $this->positive_integer( $current['submission_pk'] ?? null ) );
		if ( $latest < $official['revision_no'] ) {
			$this->stored_invalid();
		}
		$revision_no      = $latest + 1;
		$base_revision_no = $official['revision_no'];
		if ( $policy['copy_previous_answers'] ) {
			$response        = $official['response'];
			$response_json   = $official['response_json'];
			$response_hash   = $official['response_hash'];
			$attachment_hash = $official['attachment_set_hash'];
		} else {
			$validated = $this->responses->validate( $this->response_profile( $current ), array() );
			if ( array() !== $validated['attachments'] ) {
				$this->stored_invalid();
			}
			$response        = array(
				'answers'                 => $validated['answers'],
				'publication_hash'        => (string) $current['publication_hash'],
				'publication_id'          => (string) $current['worksheet_publication_public_id'],
				'response_schema_version' => Submission_Revision_Integrity::RESPONSE_SCHEMA_VERSION,
			);
			$response_json   = $this->canonical->encode( $response );
			$response_hash   = hash( 'sha256', $response_json );
			$attachment_hash = hash( 'sha256', $this->canonical->encode( array() ) );
		}
		if ( ! hash_equals( $response_json, $this->canonical->encode( $response ) ) ) {
			$this->stored_invalid();
		}
		$content_hash = $this->integrity->content_hash(
			$record['submission_public_id'],
			$revision_no,
			$base_revision_no,
			'reopened_fork',
			Submission_Revision_Integrity::RESPONSE_SCHEMA_VERSION,
			$response_hash,
			$attachment_hash
		);
		$this->insert_or_fail(
			self::TABLE_REVISIONS,
			array(
				'public_id'               => $record['revision_public_id'],
				'content_hash'            => $content_hash,
				'created_by_user_id'      => $record['actor_user_id'],
				'created_at_utc'          => $record['occurred_at_utc'],
				'submission_id'           => $this->positive_integer( $current['submission_pk'] ?? null ),
				'revision_no'             => $revision_no,
				'base_revision_no'        => $base_revision_no,
				'state'                   => 'reopened_fork',
				'response_schema_version' => Submission_Revision_Integrity::RESPONSE_SCHEMA_VERSION,
				'response_schema'         => Submission_Revision_Integrity::RESPONSE_SCHEMA,
				'response_version'        => Submission_Revision_Integrity::RESPONSE_VERSION,
				'response_json'           => $response_json,
				'response_hash'           => $response_hash,
				'attachment_set_hash'     => $attachment_hash,
			)
		);
		$revision_pk = $this->insert_id();
		$projection  = array(
			'id'                  => $record['revision_public_id'],
			'revision_no'         => $revision_no,
			'base_revision_no'    => $base_revision_no,
			'state'               => 'reopened_fork',
			'response_hash'       => $response_hash,
			'content_hash'        => $content_hash,
			'attachment_set_hash' => $attachment_hash,
			'created_at_utc'      => $record['occurred_at_utc'],
		);
		return array(
			'revision_pk'         => $revision_pk,
			'public_id'           => $record['revision_public_id'],
			'revision_no'         => $revision_no,
			'content_hash'        => $content_hash,
			'response_hash'       => $response_hash,
			'attachment_set_hash' => $attachment_hash,
			'projection'          => $projection,
		);
	}

	/**
	 * Latest revision no.
	 *
	 * @param int $submission_pk Submission pk.
	 * @return int
	 */
	private function latest_revision_no( int $submission_pk ): int {
		$row = $this->get_row(
			$this->prepare(
				'SELECT revision_no FROM ' . self::TABLE_REVISIONS . ' WHERE submission_id = %d ORDER BY revision_no DESC LIMIT 1 FOR UPDATE',
				array( $submission_pk )
			)
		);
		if ( null === $row ) {
			$this->stored_invalid();
		}
		return $this->positive_integer( $row['revision_no'] ?? null );
	}

	/**
	 * Latest receipt no.
	 *
	 * @param int $submission_pk Submission pk.
	 * @return int|null
	 */
	private function latest_receipt_no( int $submission_pk ): ?int {
		$row = $this->get_row(
			$this->prepare(
				'SELECT receipt_no FROM ' . self::TABLE_RECEIPTS . ' WHERE submission_id = %d ORDER BY receipt_no DESC LIMIT 1 FOR UPDATE',
				array( $submission_pk )
			)
		);
		return null === $row ? null : $this->positive_integer( $row['receipt_no'] ?? null );
	}

	/**
	 * Insert receipt.
	 *
	 * @param array<string,mixed> $current Current.
	 * @param array<string,mixed> $record Record.
	 * @param array<string,mixed> $revision Revision.
	 * @param int                 $receipt_no Receipt no.
	 * @param string              $policy_hash Policy hash.
	 * @param string              $due_at Due at.
	 * @return array{receipt_pk:int,content_hash:string}
	 */
	private function insert_receipt( array $current, array $record, array $revision, int $receipt_no, string $policy_hash, string $due_at ): array {
		$timeliness   = $record['occurred_at_utc'] <= $due_at ? 'on_time' : 'late';
		$content      = array(
			'action'                      => $record['action'],
			'actor_type'                  => 'teacher',
			'actor_user_id'               => $record['actor_user_id'],
			'assignment_schedule_hash'    => (string) $current['schedule_hash'],
			'assignment_schedule_version' => $this->positive_integer( $current['schedule_version'] ?? null ),
			'attachment_set_hash'         => $revision['attachment_set_hash'],
			'context_revision'            => $record['authorized_context_revision'],
			'idempotency_key'             => $record['idempotency_storage_key'],
			'attempt_no'                  => $record['expected_official_attempt_no'],
			'publication_hash'            => (string) $current['publication_hash'],
			'reason'                      => $record['reason'],
			'receipt_no'                  => $receipt_no,
			'received_at_utc'             => $record['occurred_at_utc'],
			'resubmission_policy_hash'    => $policy_hash,
			'submission_id'               => (string) $current['submission_id'],
			'submission_revision_hash'    => $revision['content_hash'],
			'submission_revision_id'      => $revision['public_id'],
			'timeliness'                  => $timeliness,
		);
		$content_hash = hash( 'sha256', $this->canonical->encode( $content ) );
		$this->insert_or_fail(
			self::TABLE_RECEIPTS,
			array(
				'public_id'                   => $record['receipt_public_id'],
				'created_at_utc'              => $record['occurred_at_utc'],
				'content_hash'                => $content_hash,
				'submission_id'               => $this->positive_integer( $current['submission_pk'] ?? null ),
				'submission_revision_id'      => $revision['revision_pk'],
				'receipt_no'                  => $receipt_no,
				'action'                      => $record['action'],
				'idempotency_key'             => $record['idempotency_storage_key'],
				'context_revision'            => $record['authorized_context_revision'],
				'actor_type'                  => 'teacher',
				'actor_user_id'               => $record['actor_user_id'],
				'reason'                      => $record['reason'],
				'attempt_no'                  => $record['expected_official_attempt_no'],
				'submission_revision_hash'    => $revision['content_hash'],
				'publication_hash'            => (string) $current['publication_hash'],
				'attachment_set_hash'         => $revision['attachment_set_hash'],
				'assignment_schedule_version' => $this->positive_integer( $current['schedule_version'] ?? null ),
				'assignment_schedule_hash'    => (string) $current['schedule_hash'],
				'resubmission_policy_hash'    => $policy_hash,
				'timeliness'                  => $timeliness,
				'received_at_utc'             => $record['occurred_at_utc'],
			)
		);
		return array(
			'receipt_pk'   => $this->insert_id(),
			'content_hash' => $content_hash,
		);
	}

	/**
	 * Update root.
	 *
	 * @param array<string,mixed> $current Current.
	 * @param array<string,mixed> $record Record.
	 * @param array<string,mixed> $revision Revision.
	 * @param int                 $lock_version Lock version.
	 * @return void
	 */
	private function update_root( array $current, array $record, array $revision, int $lock_version ): void {
		$reopen = Control_Teacher_Submission_Command::REOPEN === $record['action'];
		$this->update_or_conflict(
			self::TABLE_SUBMISSIONS,
			array(
				'lock_version'               => $lock_version,
				'updated_by_user_id'         => $record['actor_user_id'],
				'updated_at_utc'             => $record['occurred_at_utc'],
				'state'                      => $reopen ? 'reopened' : 'locked',
				'current_revision_public_id' => $revision['public_id'],
				'closed_at_utc'              => $reopen ? null : $record['occurred_at_utc'],
			),
			array(
				'id'                          => $this->positive_integer( $current['submission_pk'] ?? null ),
				'lock_version'                => $record['expected_lock_version'],
				'state'                       => $record['expected_state'],
				'current_revision_public_id'  => $current['current_revision_public_id'],
				'official_revision_public_id' => $record['expected_official_revision_id'],
				'official_attempt_no'         => $record['expected_official_attempt_no'],
			)
		);
	}

	/**
	 * Result.
	 *
	 * @param array<string,mixed>      $current Current.
	 * @param array<string,mixed>      $record Record.
	 * @param array<string,mixed>      $revision Revision.
	 * @param int                      $receipt_no Receipt no.
	 * @param int                      $lock_version Lock version.
	 * @param string                   $policy_hash Policy hash.
	 * @param string                   $due_at Due at.
	 * @param string                   $receipt_content_hash Receipt content hash.
	 * @param array<string,mixed>|null $editable Editable.
	 * @param bool                     $replayed Replayed.
	 * @return Control_Teacher_Submission_Result
	 */
	private function result(
		array $current,
		array $record,
		array $revision,
		int $receipt_no,
		int $lock_version,
		string $policy_hash,
		string $due_at,
		string $receipt_content_hash,
		?array $editable,
		bool $replayed
	): Control_Teacher_Submission_Result {
		$reopen     = Control_Teacher_Submission_Command::REOPEN === $record['action'];
		$timeliness = $record['occurred_at_utc'] <= $due_at ? 'on_time' : 'late';
		return new Control_Teacher_Submission_Result(
			array(
				'id'                   => (string) $current['submission_id'],
				'assignment_id'        => (string) $current['assignment_id'],
				'state'                => $reopen ? 'reopened' : 'locked',
				'lock_version'         => $lock_version,
				'current_revision_id'  => $revision['public_id'],
				'official_revision_id' => $record['expected_official_revision_id'],
				'official_attempt_no'  => $record['expected_official_attempt_no'],
				'submitted_at_utc'     => $this->required_utc( $current['submitted_at_utc'] ?? null ),
				'closed_at_utc'        => $reopen ? null : $record['occurred_at_utc'],
				'actions'              => array(),
			),
			array(
				'id'                          => $record['receipt_public_id'],
				'content_hash'                => $receipt_content_hash,
				'receipt_no'                  => $receipt_no,
				'action'                      => $record['action'],
				'actor_type'                  => 'teacher',
				'reason'                      => $record['reason'],
				'submission_id'               => (string) $current['submission_id'],
				'submission_revision_id'      => $revision['public_id'],
				'attempt_no'                  => $record['expected_official_attempt_no'],
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
			$editable,
			true,
			$replayed
		);
	}

	/**
	 * Insert audit.
	 *
	 * @param array<string,mixed>               $current Current.
	 * @param array<string,mixed>               $record Record.
	 * @param Control_Teacher_Submission_Result $result Result.
	 * @return void
	 */
	private function insert_audit( array $current, array $record, Control_Teacher_Submission_Result $result ): void {
		$event_type = $this->event_type( $record['action'] );
		$previous   = $this->get_var( 'SELECT event_hash FROM ' . self::TABLE_AUDIT . ' ORDER BY id DESC LIMIT 1 FOR UPDATE' );
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
					'editable_revision' => $result->editable_revision(),
					'receipt'           => $result->receipt(),
					'submission'        => $result->submission(),
				)
			)
		);
		$metadata_json = $this->canonical->encode(
			array(
				'command_id'                    => $record['command_id'],
				'request_digest'                => $record['request_digest'],
				'expected_lock_version'         => $record['expected_lock_version'],
				'expected_official_attempt_no'  => $record['expected_official_attempt_no'],
				'expected_official_revision_id' => $record['expected_official_revision_id'],
				'receipt_id'                    => $result->receipt()['id'],
				'reason'                        => $record['reason'],
			)
		);
		$metadata_hash = hash( 'sha256', $metadata_json );
		$event_hash    = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'action_key'          => $event_type,
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
				'action_key'          => $event_type,
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
	 * @param array<string,mixed>               $record Record.
	 * @param Control_Teacher_Submission_Result $result Result.
	 * @return void
	 */
	private function insert_outbox( array $record, Control_Teacher_Submission_Result $result ): void {
		$payload_json = $this->canonical->encode(
			array(
				'request_digest' => $record['request_digest'],
				'result'         => array(
					'editable_revision' => $result->editable_revision(),
					'receipt'           => $result->receipt(),
					'submission'        => $result->submission(),
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
				'event_type'           => $this->event_type( $record['action'] ),
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
	 * @param string $action Action.
	 * @param bool   $for_update For update.
	 * @return Control_Teacher_Submission_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function receipt( string $storage_key, string $request_digest, string $action, bool $for_update ): ?Control_Teacher_Submission_Result {
		$sql = 'SELECT aggregate_type, aggregate_public_id, event_type, payload_schema, payload_version, payload_json, payload_hash FROM ' . self::TABLE_OUTBOX . ' WHERE idempotency_key = %s LIMIT 1';
		if ( $for_update ) {
			$sql .= ' FOR UPDATE';
		}
		$row = $this->get_row( $this->prepare( $sql, array( $storage_key ) ) );
		if ( null === $row ) {
			return null;
		}
		if (
			'submission' !== ( $row['aggregate_type'] ?? null )
			|| $this->event_type( $action ) !== ( $row['event_type'] ?? null )
			|| self::PAYLOAD_SCHEMA !== ( $row['payload_schema'] ?? null )
			|| self::VERSION !== $this->positive_integer( $row['payload_version'] ?? null )
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
		$this->stored_exact_keys( $result, array( 'editable_revision', 'receipt', 'submission' ) );
		if (
			! is_array( $result['submission'] ?? null )
			|| ! is_array( $result['receipt'] ?? null )
			|| ( null !== $result['editable_revision'] && ! is_array( $result['editable_revision'] ) )
			|| ( $row['aggregate_public_id'] ?? null ) !== ( $result['submission']['id'] ?? null )
		) {
			$this->stored_invalid();
		}
		try {
			return new Control_Teacher_Submission_Result(
				$result['submission'],
				$result['receipt'],
				$result['editable_revision'],
				true,
				true
			);
		} catch ( Throwable ) {
			$this->stored_invalid();
		}
	}

	/**
	 * Reject invalid postcondition.
	 *
	 * @param array<string,mixed>      $current Current.
	 * @param array<string,mixed>      $record Record.
	 * @param array<string,mixed>      $revision Revision.
	 * @param int                      $receipt_pk Receipt pk.
	 * @param string                   $receipt_hash Receipt hash.
	 * @param int                      $lock_version Lock version.
	 * @param array<string,mixed>|null $editable Editable.
	 * @return void
	 */
	private function assert_postcondition(
		array $current,
		array $record,
		array $revision,
		int $receipt_pk,
		string $receipt_hash,
		int $lock_version,
		?array $editable
	): void {
		$root    = $this->get_row(
			$this->prepare(
				'SELECT public_id, lock_version, state, current_revision_public_id, official_revision_public_id, official_attempt_no, closed_at_utc FROM ' . self::TABLE_SUBMISSIONS . ' WHERE id = %d LIMIT 1 FOR UPDATE',
				array( $this->positive_integer( $current['submission_pk'] ?? null ) )
			)
		);
		$receipt = $this->get_row(
			$this->prepare(
				'SELECT public_id, content_hash, submission_revision_id FROM ' . self::TABLE_RECEIPTS . ' WHERE id = %d LIMIT 1 FOR UPDATE',
				array( $receipt_pk )
			)
		);
		$reopen  = Control_Teacher_Submission_Command::REOPEN === $record['action'];
		if (
			null === $root || null === $receipt
			|| $record['submission_public_id'] !== ( $root['public_id'] ?? null )
			|| $lock_version !== $this->non_negative_integer( $root['lock_version'] ?? null )
			|| ( $reopen ? 'reopened' : 'locked' ) !== ( $root['state'] ?? null )
			|| $revision['public_id'] !== ( $root['current_revision_public_id'] ?? null )
			|| $record['expected_official_revision_id'] !== ( $root['official_revision_public_id'] ?? null )
			|| $record['expected_official_attempt_no'] !== $this->positive_integer( $root['official_attempt_no'] ?? null )
			|| ( $reopen ? null : $record['occurred_at_utc'] ) !== ( $root['closed_at_utc'] ?? null )
			|| $record['receipt_public_id'] !== ( $receipt['public_id'] ?? null )
			|| ! hash_equals( $receipt_hash, (string) ( $receipt['content_hash'] ?? '' ) )
			|| $revision['revision_pk'] !== $this->positive_integer( $receipt['submission_revision_id'] ?? null )
		) {
			$this->stored_invalid();
		}
		if ( null !== $editable ) {
			$fork = $this->get_row(
				$this->prepare(
					'SELECT public_id, content_hash FROM ' . self::TABLE_REVISIONS . ' WHERE id = %d LIMIT 1 FOR UPDATE',
					array( $revision['revision_pk'] )
				)
			);
			if (
				null === $fork
				|| $editable['id'] !== ( $fork['public_id'] ?? null )
				|| ! hash_equals( $editable['content_hash'], (string) ( $fork['content_hash'] ?? '' ) )
			) {
				$this->stored_invalid();
			}
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
			'action',
			'actor_user_id',
			'submission_public_id',
			'revision_public_id',
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
			'expected_official_revision_id',
			'expected_official_attempt_no',
			'expected_schedule_version',
			'expected_schedule_hash',
			'expected_publication_hash',
			'reason',
			'authorized_context_type',
			'authorized_context_id',
			'authorized_context_revision',
		);
		$actual   = array_keys( $record );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected || ! is_int( $record['actor_user_id'] ) || $record['actor_user_id'] < 1 ) {
			throw new InvalidArgumentException( 'Teacher submission control record is invalid.' );
		}
		$this->assert_action_argument( (string) $record['action'] );
		foreach ( array( 'submission_public_id', 'receipt_public_id', 'audit_public_id', 'outbox_public_id', 'expected_official_revision_id' ) as $field ) {
			new Public_Id( (string) $record[ $field ] );
		}
		if ( Control_Teacher_Submission_Command::REOPEN === $record['action'] ) {
			new Public_Id( (string) $record['revision_public_id'] );
		} elseif ( null !== $record['revision_public_id'] ) {
			throw new InvalidArgumentException( 'Teacher lock must not create a revision.' );
		}
		foreach ( array( 'request_digest', 'expected_schedule_hash', 'expected_publication_hash' ) as $field ) {
			$this->assert_hash_argument( (string) $record[ $field ] );
		}
		$this->assert_storage_key( (string) $record['idempotency_storage_key'] );
		$this->assert_utc_argument( (string) $record['occurred_at_utc'] );
		$allowed_states = array(
			Control_Teacher_Submission_Command::REOPEN => array( 'submitted', 'auto_submitted', 'proxy_submitted', 'locked' ),
			Control_Teacher_Submission_Command::LOCK   => array( 'submitted', 'auto_submitted', 'proxy_submitted', 'reopened' ),
		);
		if (
			! in_array( $record['expected_state'], $allowed_states[ $record['action'] ], true )
			|| ! is_int( $record['expected_lock_version'] ) || $record['expected_lock_version'] < 0
			|| ! is_int( $record['expected_official_attempt_no'] ) || $record['expected_official_attempt_no'] < 1
			|| ! is_int( $record['expected_schedule_version'] ) || $record['expected_schedule_version'] < 1
			|| ! is_string( $record['reason'] ) || '' === $record['reason'] || strlen( $record['reason'] ) > 500
		) {
			throw new InvalidArgumentException( 'Teacher submission control record values are invalid.' );
		}
		$this->token_argument( (string) $record['command_id'], 128 );
		$this->token_argument( (string) $record['correlation_id'], 128 );
		$this->token_argument( (string) $record['authorized_context_type'], 32 );
		$this->token_argument( (string) $record['authorized_context_id'], 191 );
		$this->token_argument( (string) $record['authorized_context_revision'], 191 );
	}

	/**
	 * Event type.
	 *
	 * @param string $action Action.
	 * @return string
	 */
	private function event_type( string $action ): string {
		$this->assert_action_argument( $action );
		return Control_Teacher_Submission_Command::REOPEN === $action ? 'submission.reopened' : 'submission.locked';
	}

	/**
	 * Decode json.
	 *
	 * @param string $json Json.
	 * @param string $hash Hash.
	 * @return array<string,mixed>
	 */
	private function decode_json( string $json, string $hash ): array {
		if ( ! hash_equals( $hash, hash( 'sha256', $json ) ) ) {
			$this->stored_invalid();
		}
		try {
			$value = json_decode( $json, true, 64, JSON_THROW_ON_ERROR );
		} catch ( JsonException ) {
			$this->stored_invalid();
		}
		if ( ! is_array( $value ) || array_is_list( $value ) || ! hash_equals( $json, $this->canonical->encode( $value ) ) ) {
			$this->stored_invalid();
		}
		return $value;
	}

	/**
	 * Stored exact keys.
	 *
	 * @param array<string,mixed> $value Value.
	 * @param array<int,string>   $expected Expected.
	 * @return void
	 */
	private function stored_exact_keys( array $value, array $expected ): void {
		$actual = array_keys( $value );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected ) {
			$this->stored_invalid();
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
	 * Target state.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function target_state( mixed $value ): string {
		if ( ! is_string( $value ) || ! in_array( $value, array( 'active', 'revoked' ), true ) ) {
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
			throw new InvalidArgumentException( 'Teacher submission control hash is invalid.' );
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
			throw new InvalidArgumentException( 'Teacher submission control storage key is invalid.' );
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
			throw new InvalidArgumentException( 'Teacher submission control UTC value is invalid.' );
		}
	}
	/**
	 * Reject invalid action argument.
	 *
	 * @param string $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_action_argument( string $value ): void {
		if ( ! in_array( $value, array( Control_Teacher_Submission_Command::REOPEN, Control_Teacher_Submission_Command::LOCK ), true ) ) {
			throw new InvalidArgumentException( 'Teacher submission control action is invalid.' );
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
		if ( '' === $value || strlen( $value ) > $maximum || 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@\/-]*$/D', $value ) ) {
			throw new InvalidArgumentException( 'Teacher submission control token is invalid.' );
		}
	}
	/**
	 * Stored invalid.
	 *
	 * @return never
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function stored_invalid(): never {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
		throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
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
