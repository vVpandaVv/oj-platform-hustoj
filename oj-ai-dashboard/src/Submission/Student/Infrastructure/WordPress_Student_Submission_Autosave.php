<?php
/**
 * WordPress immutable student submission autosave repository.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Student\Infrastructure;

use InvalidArgumentException;
use JsonException;
use OJAI\Dashboard\Submission\Assignment\Application\Assignment_Failure;
use OJAI\Dashboard\Submission\Student\Application\Autosave_Own_Submission_Result;
use OJAI\Dashboard\Submission\Student\Domain\Submission_Response_Validator;
use OJAI\Dashboard\Submission\Student\Domain\Submission_Revision_Integrity;
use OJAI\Dashboard\Submission\Student\Domain\Submission_Save_Token;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Appends C23 revisions without mutating any earlier revision.
 */
final class WordPress_Student_Submission_Autosave {
	private const TABLE_ASSIGNMENTS         = 'oj_ai_assignments';
	private const TABLE_TARGETS             = 'oj_ai_assignment_targets';
	private const TABLE_PUBLICATIONS        = 'oj_ai_worksheet_publications';
	private const TABLE_WORKSHEET_REVISIONS = 'oj_ai_worksheet_revisions';
	private const TABLE_SUBMISSIONS         = 'oj_ai_submissions';
	private const TABLE_REVISIONS           = 'oj_ai_submission_revisions';
	private const TABLE_ATTACHMENTS         = 'oj_ai_submission_attachments';
	private const TABLE_PRIVATE_FILES       = 'oj_ai_private_files';
	private const TABLE_AUDIT               = 'oj_ai_audit_events';
	private const TABLE_OUTBOX              = 'oj_ai_outbox_events';

	private const EVENT_TYPE      = 'submission.autosaved';
	private const PAYLOAD_SCHEMA  = 'oj_ai.submission_autosaved_event';
	private const AUDIT_SCHEMA    = 'oj_ai.submission_audit';
	private const VERSION         = 1;
	private const MAX_ATTACHMENTS = 20;

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
	 * Initialize WordPress student submission autosave dependencies and state.
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
	 * Find receipt.
	 *
	 * @param string $storage_key Storage key.
	 * @param string $request_digest Request digest.
	 * @return Autosave_Own_Submission_Result|null
	 */
	public function find_receipt( string $storage_key, string $request_digest ): ?Autosave_Own_Submission_Result {
		$this->assert_storage_key( $storage_key );
		$this->assert_hash_argument( $request_digest );
		return $this->receipt( $storage_key, $request_digest, false );
	}

	/**
	 * Autosave.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Autosave_Own_Submission_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function autosave( array $record ): Autosave_Own_Submission_Result {
		$this->assert_record( $record );
		$replay = $this->receipt( $record['idempotency_storage_key'], $record['request_digest'], false );
		if ( $replay instanceof Autosave_Own_Submission_Result ) {
			return $replay;
		}

		$transaction = false;
		try {
			$this->query_or_fail( 'START TRANSACTION' );
			$transaction = true;
			$replay      = $this->receipt( $record['idempotency_storage_key'], $record['request_digest'], true );
			if ( $replay instanceof Autosave_Own_Submission_Result ) {
				$this->query_or_fail( 'COMMIT' );
				return $replay;
			}

			$current = $this->lock_submission_context( $record['submission_public_id'], $record['actor_user_id'] );
			if ( null === $current || 'active' !== ( $current['target_state'] ?? null ) ) {
				throw Assignment_Failure::because( Assignment_Failure::NOT_FOUND );
			}
			$this->assert_expected( $current, $record );
			$current_revision = $this->lock_current_revision( $current );
			$this->assert_current_revision_expected( $current, $current_revision, $record );
			$profile   = $this->response_profile( $current );
			$validated = $this->responses->validate( $profile, $record['answers'] );
			if ( count( $validated['attachments'] ) > self::MAX_ATTACHMENTS ) {
				throw Assignment_Failure::because( Assignment_Failure::SUBMISSION_VALIDATION_FAILED );
			}
			$attachments = $this->lock_and_validate_attachments(
				$this->positive_integer( $current['submission_pk'] ?? null ),
				$validated['attachments'],
				$record['staged_attachment_ids']
			);

			$attachment_ids = array_keys( $validated['attachments'] );
			sort( $attachment_ids, SORT_STRING );
			$attachment_set_hash = hash( 'sha256', $this->canonical->encode( $attachment_ids ) );
			$base_revision_no    = null === $current_revision ? null : $current_revision['revision_no'];
			$revision_no         = null === $base_revision_no ? 1 : $base_revision_no + 1;
			$response            = array(
				'answers'                 => $validated['answers'],
				'publication_hash'        => (string) $current['publication_hash'],
				'publication_id'          => (string) $current['worksheet_publication_public_id'],
				'response_schema_version' => Submission_Revision_Integrity::RESPONSE_SCHEMA_VERSION,
			);
			$response_json       = $this->canonical->encode( $response );
			$response_hash       = hash( 'sha256', $response_json );
			$content_hash        = $this->integrity->content_hash(
				$record['submission_public_id'],
				$revision_no,
				$base_revision_no,
				'saved',
				Submission_Revision_Integrity::RESPONSE_SCHEMA_VERSION,
				$response_hash,
				$attachment_set_hash
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
					'state'                   => 'saved',
					'response_schema_version' => Submission_Revision_Integrity::RESPONSE_SCHEMA_VERSION,
					'response_schema'         => Submission_Revision_Integrity::RESPONSE_SCHEMA,
					'response_version'        => Submission_Revision_Integrity::RESPONSE_VERSION,
					'response_json'           => $response_json,
					'response_hash'           => $response_hash,
					'attachment_set_hash'     => $attachment_set_hash,
				)
			);
			$revision_pk = $this->insert_id();
			foreach ( $attachments as $attachment ) {
				$this->bind_attachment( $attachment, $revision_pk, $record );
			}

			$new_state        = 'reopened' === ( $current['submission_state'] ?? null )
				|| ( 'conflict' === ( $current['submission_state'] ?? null ) && null !== ( $current['official_revision_public_id'] ?? null ) )
				? 'reopened'
				: 'draft';
			$new_lock_version = $record['expected_lock_version'] + 1;
			$this->update_or_fail(
				self::TABLE_SUBMISSIONS,
				array(
					'lock_version'               => $new_lock_version,
					'updated_by_user_id'         => $record['actor_user_id'],
					'updated_at_utc'             => $record['occurred_at_utc'],
					'state'                      => $new_state,
					'current_revision_public_id' => $record['revision_public_id'],
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
				$revision_no,
				$base_revision_no,
				$new_state,
				$new_lock_version,
				$response_hash,
				$content_hash,
				$attachment_set_hash,
				true,
				false
			);
			$this->insert_audit( $current, $record, $result );
			$this->insert_outbox( $record, $result );
			$this->assert_postcondition( $current, $record, $revision_pk, $content_hash, $new_state, $new_lock_version );
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
			if ( $replay instanceof Autosave_Own_Submission_Result ) {
				return $replay;
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORAGE_UNAVAILABLE );
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
		$sql = 'SELECT s.id AS submission_pk, s.public_id AS submission_id, s.lock_version AS submission_lock_version, s.state AS submission_state, s.current_revision_public_id, s.official_revision_public_id, a.id AS assignment_pk, a.public_id AS assignment_id, a.context_type, a.context_id, a.context_revision, a.state AS assignment_state, a.worksheet_publication_public_id, a.publication_hash, a.opens_at_utc, a.due_at_utc, a.closes_at_utc, a.schedule_version, a.schedule_hash, a.auto_submit_enabled, t.state AS target_state, p.content_hash AS publication_content_hash, p.publication_hash AS stored_publication_hash, p.response_schema_schema, p.response_schema_version, p.response_schema_json, p.response_schema_hash, wr.document_hash AS publication_source_hash FROM ' . self::TABLE_SUBMISSIONS . ' s INNER JOIN ' . self::TABLE_ASSIGNMENTS . ' a ON a.id = s.assignment_id '
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
		$stored_submission_id = $this->stored_public_id( $current['submission_id'] ?? null );
		$assignment_id        = $this->stored_public_id( $current['assignment_id'] ?? null );
		$publication_id       = $this->stored_public_id( $current['worksheet_publication_public_id'] ?? null );
		if ( ! hash_equals( $record['submission_public_id'], $stored_submission_id ) ) {
			$this->stored_invalid();
		}
		$state            = $this->submission_state( $current['submission_state'] ?? null );
		$assignment_state = $this->assignment_state( $current['assignment_state'] ?? null );
		if ( ! in_array( $state, array( 'draft', 'conflict', 'reopened' ), true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::SUBMISSION_ALREADY_OFFICIAL );
		}
		if ( 'open' !== $assignment_state ) {
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
			$this->stored_invalid();
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
			$this->stored_invalid();
		}

		$publication_hash = $this->stored_hash( $current['publication_hash'] ?? null );
		if (
			! hash_equals( $publication_hash, $this->stored_hash( $current['publication_content_hash'] ?? null ) )
			|| ! hash_equals( $publication_hash, $this->stored_hash( $current['stored_publication_hash'] ?? null ) )
		) {
			$this->stored_invalid();
		}
		$current_revision_id = $this->optional_stored_public_id( $current['current_revision_public_id'] ?? null );
		$lock_version        = $this->non_negative_integer( $current['submission_lock_version'] ?? null );
		$save_token          = $this->save_tokens->create( $stored_submission_id, $assignment_id, $lock_version, $current_revision_id, $publication_hash, $schedule_hash );
		if (
			$record['expected_state'] !== $state
			|| $record['expected_lock_version'] !== $lock_version
			|| $record['expected_schedule_version'] !== $schedule_version
			|| ! hash_equals( $record['expected_schedule_hash'], $schedule_hash )
			|| ! hash_equals( $record['expected_publication_hash'], $publication_hash )
			|| ! hash_equals( $record['expected_save_token'], $save_token )
			|| $record['expected_current_revision_id'] !== $current_revision_id
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::SUBMISSION_REVISION_CONFLICT );
		}
		unset( $publication_id );
	}

	/**
	 * Lock current revision.
	 *
	 * @param array<string,mixed> $current Current.
	 * @return array{revision_no:int,public_id:string}|null
	 */
	private function lock_current_revision( array $current ): ?array {
		$revision_id = $this->optional_stored_public_id( $current['current_revision_public_id'] ?? null );
		if ( null === $revision_id ) {
			return null;
		}
		$sql = 'SELECT public_id, content_hash, revision_no, base_revision_no, state, response_schema_version, response_schema, response_version, response_json, response_hash, attachment_set_hash FROM ' . self::TABLE_REVISIONS
			. ' WHERE submission_id = %d AND public_id = %s LIMIT 1 FOR UPDATE';
		$row = $this->get_row( $this->prepare( $sql, array( $this->positive_integer( $current['submission_pk'] ?? null ), $revision_id ) ) );
		if ( null === $row || ! hash_equals( $revision_id, $this->stored_public_id( $row['public_id'] ?? null ) ) ) {
			$this->stored_invalid();
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
		$response      = $this->decode_json( (string) ( $row['response_json'] ?? '' ), $response_hash );
		$this->stored_exact_keys( $response, array( 'answers', 'publication_hash', 'publication_id', 'response_schema_version' ) );
		if (
			( $current['worksheet_publication_public_id'] ?? null ) !== ( $response['publication_id'] ?? null )
			|| ( $current['publication_hash'] ?? null ) !== ( $response['publication_hash'] ?? null )
			|| Submission_Revision_Integrity::RESPONSE_SCHEMA_VERSION !== ( $response['response_schema_version'] ?? null )
		) {
			$this->stored_invalid();
		}
		$attachment_hash       = $this->stored_hash( $row['attachment_set_hash'] ?? null );
		$expected_content_hash = $this->integrity->content_hash(
			(string) $current['submission_id'],
			$revision_no,
			$base_revision_no,
			$state,
			Submission_Revision_Integrity::RESPONSE_SCHEMA_VERSION,
			$response_hash,
			$attachment_hash
		);
		if ( ! hash_equals( $this->stored_hash( $row['content_hash'] ?? null ), $expected_content_hash ) ) {
			$this->stored_invalid();
		}
		return array(
			'revision_no' => $revision_no,
			'public_id'   => $revision_id,
		);
	}

	/**
	 * Reject invalid current revision expected.
	 *
	 * @param array<string,mixed>                          $current Current.
	 * @param array{revision_no:int,public_id:string}|null $revision Revision.
	 * @param array<string,mixed>                          $record Record.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_current_revision_expected( array $current, ?array $revision, array $record ): void {
		$expected = $record['expected_current_revision_id'];
		if ( ( null === $revision ? null : $revision['public_id'] ) !== $expected ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::SUBMISSION_REVISION_CONFLICT );
		}
		if ( null === $revision && null !== ( $current['current_revision_public_id'] ?? null ) ) {
			$this->stored_invalid();
		}
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
	 * Lock and validate attachments.
	 *
	 * @param int                                                                                                        $submission_pk Submission pk.
	 * @param array<string,array{response_id:string,kind:string,role:string,mime_types:array<int,string>,max_bytes:int}> $requirements Requirements.
	 * @param array<int,string>                                                                                          $staged_ids Staged ids.
	 * @return array<int,array<string,mixed>> newly bound rows
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function lock_and_validate_attachments( int $submission_pk, array $requirements, array $staged_ids ): array {
		$ids = array_keys( $requirements );
		if ( count( $ids ) > self::MAX_ATTACHMENTS ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::SUBMISSION_VALIDATION_FAILED );
		}
		$rows = array();
		if ( array() !== $ids ) {
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%s' ) );
			$sql          = 'SELECT id, public_id, lock_version, submission_revision_id, response_key, state, mime_type, byte_size, file_public_id, created_by_user_id, original_name, sha256 FROM ' . self::TABLE_ATTACHMENTS . ' WHERE submission_id = %d AND public_id IN (' . $placeholders . ') FOR UPDATE';
			$rows         = $this->get_results( $this->prepare( $sql, array_merge( array( $submission_pk ), $ids ) ) );
		}
		$by_id = array();
		foreach ( $rows as $row ) {
			$id = $this->stored_public_id( $row['public_id'] ?? null );
			if ( isset( $by_id[ $id ] ) ) {
				$this->stored_invalid();
			}
			$by_id[ $id ] = $row;
		}
		$staged = array_fill_keys( $staged_ids, true );
		foreach ( $staged as $id => $_ ) {
			if ( ! isset( $requirements[ $id ] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Assignment_Failure::because( Assignment_Failure::SUBMISSION_VALIDATION_FAILED );
			}
		}
		$new_rows = array();
		foreach ( $requirements as $id => $requirement ) {
			$row = $by_id[ $id ] ?? null;
			if ( ! is_array( $row ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Assignment_Failure::because( Assignment_Failure::ATTACHMENT_NOT_READY );
			}
			if (
				$requirement['response_id'] !== ( $row['response_key'] ?? null )
				|| ! in_array( $row['mime_type'] ?? null, $requirement['mime_types'], true )
				|| $this->non_negative_integer( $row['byte_size'] ?? null ) > $requirement['max_bytes']
			) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Assignment_Failure::because( Assignment_Failure::SUBMISSION_VALIDATION_FAILED );
			}
			$revision_pk = null === ( $row['submission_revision_id'] ?? null ) ? null : $this->positive_integer( $row['submission_revision_id'] );
			if ( null === $revision_pk ) {
				if ( 'ready' !== ( $row['state'] ?? null ) || ! isset( $staged[ $id ] ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					throw Assignment_Failure::because( Assignment_Failure::ATTACHMENT_NOT_READY );
				}
				$new_rows[] = $row;
			} elseif ( 'bound' !== ( $row['state'] ?? null ) || isset( $staged[ $id ] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Assignment_Failure::because( Assignment_Failure::SUBMISSION_VALIDATION_FAILED );
			}
		}
		return $new_rows;
	}

	/**
	 * Bind attachment.
	 *
	 * @param array<string,mixed> $attachment Attachment.
	 * @param int                 $revision_pk Revision pk.
	 * @param array<string,mixed> $record Record.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function bind_attachment( array $attachment, int $revision_pk, array $record ): void {
		$file = $this->get_row(
			$this->prepare(
				'SELECT id, public_id, lock_version, created_by_user_id, owner_type, owner_public_id, purpose, original_name, mime_type, byte_size, sha256, state, scan_state, retention_class, expires_at_utc, purged_at_utc FROM ' . self::TABLE_PRIVATE_FILES . ' WHERE public_id = %s LIMIT 1 FOR UPDATE',
				array( $this->stored_public_id( $attachment['file_public_id'] ?? null ) )
			)
		);
		if (
			null === $file
			|| $record['actor_user_id'] !== $this->positive_integer( $attachment['created_by_user_id'] ?? null )
			|| $record['actor_user_id'] !== $this->positive_integer( $file['created_by_user_id'] ?? null )
			|| 'submission' !== ( $file['owner_type'] ?? null )
			|| $record['submission_public_id'] !== ( $file['owner_public_id'] ?? null )
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
		$expires = $this->optional_utc( $file['expires_at_utc'] ?? null );
		if ( null !== $expires && $expires <= $record['occurred_at_utc'] ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::ATTACHMENT_NOT_READY );
		}
		// Bound revisions are retained by the submission lifecycle, not the upload TTL.
		if ( null !== $expires ) {
			$this->update_or_fail(
				self::TABLE_PRIVATE_FILES,
				array(
					'lock_version'       => $this->non_negative_integer( $file['lock_version'] ?? null ) + 1,
					'updated_by_user_id' => $record['actor_user_id'],
					'updated_at_utc'     => $record['occurred_at_utc'],
					'expires_at_utc'     => null,
				),
				array(
					'id'             => $this->positive_integer( $file['id'] ?? null ),
					'lock_version'   => $this->non_negative_integer( $file['lock_version'] ?? null ),
					'state'          => 'ready',
					'scan_state'     => 'clean',
					'expires_at_utc' => $expires,
					'purged_at_utc'  => null,
				)
			);
		}
		$this->update_or_fail(
			self::TABLE_ATTACHMENTS,
			array(
				'lock_version'           => $this->non_negative_integer( $attachment['lock_version'] ?? null ) + 1,
				'updated_by_user_id'     => $record['actor_user_id'],
				'updated_at_utc'         => $record['occurred_at_utc'],
				'submission_revision_id' => $revision_pk,
				'state'                  => 'bound',
				'bound_at_utc'           => $record['occurred_at_utc'],
			),
			array(
				'id'                     => $this->positive_integer( $attachment['id'] ?? null ),
				'lock_version'           => $this->non_negative_integer( $attachment['lock_version'] ?? null ),
				'submission_revision_id' => null,
				'state'                  => 'ready',
			)
		);
	}

	/**
	 * Result.
	 *
	 * @param array<string,mixed> $current Current.
	 * @param array<string,mixed> $record Record.
	 * @param int                 $revision_no Revision no.
	 * @param int|null            $base_revision_no Base revision no.
	 * @param string              $state State.
	 * @param int                 $lock_version Lock version.
	 * @param string              $response_hash Response hash.
	 * @param string              $content_hash Content hash.
	 * @param string              $attachment_set_hash Attachment set hash.
	 * @param bool                $created Created.
	 * @param bool                $replayed Replayed.
	 * @return Autosave_Own_Submission_Result
	 */
	private function result(
		array $current,
		array $record,
		int $revision_no,
		?int $base_revision_no,
		string $state,
		int $lock_version,
		string $response_hash,
		string $content_hash,
		string $attachment_set_hash,
		bool $created,
		bool $replayed
	): Autosave_Own_Submission_Result {
		$submission_id = (string) $current['submission_id'];
		$assignment_id = (string) $current['assignment_id'];
		$revision_id   = $record['revision_public_id'];
		$save_token    = $this->save_tokens->create(
			$submission_id,
			$assignment_id,
			$lock_version,
			$revision_id,
			(string) $current['publication_hash'],
			(string) $current['schedule_hash']
		);
		return new Autosave_Own_Submission_Result(
			array(
				'id'                   => $submission_id,
				'assignment_id'        => $assignment_id,
				'state'                => $state,
				'lock_version'         => $lock_version,
				'current_revision_id'  => $revision_id,
				'official_revision_id' => $this->optional_stored_public_id( $current['official_revision_public_id'] ?? null ),
				'save_token'           => $save_token,
				'actions'              => array( 'submission.autosave' ),
			),
			array(
				'id'                  => $revision_id,
				'revision_no'         => $revision_no,
				'base_revision_no'    => $base_revision_no,
				'state'               => 'saved',
				'response_hash'       => $response_hash,
				'content_hash'        => $content_hash,
				'attachment_set_hash' => $attachment_set_hash,
				'created_at_utc'      => $record['occurred_at_utc'],
			),
			$created,
			$replayed
		);
	}

	/**
	 * Insert audit.
	 *
	 * @param array<string,mixed>            $current Current.
	 * @param array<string,mixed>            $record Record.
	 * @param Autosave_Own_Submission_Result $result Result.
	 * @return void
	 */
	private function insert_audit( array $current, array $record, Autosave_Own_Submission_Result $result ): void {
		$previous = $this->get_var( 'SELECT event_hash FROM ' . self::TABLE_AUDIT . ' ORDER BY id DESC LIMIT 1 FOR UPDATE' );
		if ( null !== $previous ) {
			$this->stored_hash( $previous );
		}
		$before        = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'id'                  => $current['submission_id'],
					'state'               => $current['submission_state'],
					'lock_version'        => $current['submission_lock_version'],
					'current_revision_id' => $current['current_revision_public_id'],
				)
			)
		);
		$after         = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'submission' => $result->submission(),
					'revision'   => $result->revision(),
				)
			)
		);
		$metadata_json = $this->canonical->encode(
			array(
				'command_id'                   => $record['command_id'],
				'request_digest'               => $record['request_digest'],
				'expected_current_revision_id' => $record['expected_current_revision_id'],
				'expected_lock_version'        => $record['expected_lock_version'],
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
	 * @param array<string,mixed>            $record Record.
	 * @param Autosave_Own_Submission_Result $result Result.
	 * @return void
	 */
	private function insert_outbox( array $record, Autosave_Own_Submission_Result $result ): void {
		$payload_json = $this->canonical->encode(
			array(
				'request_digest' => $record['request_digest'],
				'result'         => array(
					'revision'   => $result->revision(),
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
	 * @return Autosave_Own_Submission_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function receipt( string $storage_key, string $request_digest, bool $for_update ): ?Autosave_Own_Submission_Result {
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
		$this->stored_exact_keys( $result, array( 'revision', 'submission' ) );
		if ( ! is_array( $result['submission'] ) || ! is_array( $result['revision'] ) || ( $row['aggregate_public_id'] ?? null ) !== ( $result['submission']['id'] ?? null ) ) {
			$this->stored_invalid();
		}
		try {
			return new Autosave_Own_Submission_Result( $result['submission'], $result['revision'], true, true ); } catch ( Throwable ) {
			$this->stored_invalid();
			}
	}

	/**
	 * Reject invalid postcondition.
	 *
	 * @param array<string,mixed> $current Current.
	 * @param array<string,mixed> $record Record.
	 * @param int                 $revision_pk Revision pk.
	 * @param string              $content_hash Content hash.
	 * @param string              $state State.
	 * @param int                 $lock_version Lock version.
	 * @return void
	 */
	private function assert_postcondition( array $current, array $record, int $revision_pk, string $content_hash, string $state, int $lock_version ): void {
		$root     = $this->get_row(
			$this->prepare(
				'SELECT public_id, lock_version, state, current_revision_public_id FROM ' . self::TABLE_SUBMISSIONS . ' WHERE id = %d LIMIT 1 FOR UPDATE',
				array( $this->positive_integer( $current['submission_pk'] ?? null ) )
			)
		);
		$revision = $this->get_row(
			$this->prepare(
				'SELECT public_id, content_hash FROM ' . self::TABLE_REVISIONS . ' WHERE id = %d LIMIT 1 FOR UPDATE',
				array( $revision_pk )
			)
		);
		if (
			null === $root || null === $revision || $record['submission_public_id'] !== ( $root['public_id'] ?? null )
			|| $lock_version !== $this->non_negative_integer( $root['lock_version'] ?? null ) || $state !== ( $root['state'] ?? null )
			|| $record['revision_public_id'] !== ( $root['current_revision_public_id'] ?? null )
			|| $record['revision_public_id'] !== ( $revision['public_id'] ?? null )
			|| ! hash_equals( $content_hash, (string) ( $revision['content_hash'] ?? '' ) )
		) {
			$this->stored_invalid();
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
			'submission_public_id',
			'revision_public_id',
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
			'answers',
			'staged_attachment_ids',
			'authorized_context_revision',
		);
		$actual   = array_keys( $record );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected || ! is_int( $record['actor_user_id'] ) || $record['actor_user_id'] < 1 ) {
			throw new InvalidArgumentException( 'Autosave record is invalid.' );
		}
		foreach ( array( 'submission_public_id', 'revision_public_id', 'audit_public_id', 'outbox_public_id' ) as $field ) {
			new Public_Id( (string) $record[ $field ] );
		}
		if ( null !== $record['expected_current_revision_id'] ) {
			new Public_Id( (string) $record['expected_current_revision_id'] );
		}
		foreach ( array( 'request_digest', 'expected_schedule_hash', 'expected_publication_hash', 'expected_save_token' ) as $field ) {
			$this->assert_hash_argument( (string) $record[ $field ] );
		}
		$this->assert_storage_key( (string) $record['idempotency_storage_key'] );
		$this->assert_utc_argument( (string) $record['occurred_at_utc'] );
		if (
			! in_array( $record['expected_state'], array( 'draft', 'conflict', 'reopened' ), true )
			|| ! is_int( $record['expected_lock_version'] ) || $record['expected_lock_version'] < 0
			|| ! is_int( $record['expected_schedule_version'] ) || $record['expected_schedule_version'] < 1
			|| ! is_array( $record['answers'] ) || ( array() !== $record['answers'] && array_is_list( $record['answers'] ) )
			|| ! is_array( $record['staged_attachment_ids'] ) || ! array_is_list( $record['staged_attachment_ids'] )
			|| count( $record['staged_attachment_ids'] ) > self::MAX_ATTACHMENTS
			|| ! is_string( $record['authorized_context_revision'] ) || '' === $record['authorized_context_revision']
		) {
			throw new InvalidArgumentException( 'Autosave record values are invalid.' );
		}
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
			$value = json_decode( $json, true, 64, JSON_THROW_ON_ERROR ); } catch ( JsonException ) {
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
			throw new InvalidArgumentException( 'Autosave hash is invalid.' );
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
			throw new InvalidArgumentException( 'Autosave storage key is invalid.' );
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
			throw new InvalidArgumentException( 'Autosave UTC value is invalid.' );
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
	 * Update or fail.
	 *
	 * @param string              $table Table.
	 * @param array<string,mixed> $data Data.
	 * @param array<string,mixed> $where Where.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function update_or_fail( string $table, array $data, array $where ): void {
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
