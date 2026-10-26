<?php
/**
 * WordPress student submission attachment repository.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Student\Infrastructure;

use InvalidArgumentException;
use JsonException;
use OJAI\Dashboard\Submission\Assignment\Application\Assignment_Failure;
use OJAI\Dashboard\Submission\Student\Domain\Submission_Attachment_Upload_Profile;
use OJAI\Dashboard\Submission\Student\Domain\Submission_Save_Token;
use OJAI\Dashboard\Submission\Student\Port\Student_Attachment_Repository_Port_Interface;
use OJAI\Dashboard\Submission\Student\Port\Student_Attachment_Removal_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns literal submission attachment SQL and serializes C24 quota checks.
 */
final class WordPress_Student_Attachment_Repository implements Student_Attachment_Repository_Port_Interface, Student_Attachment_Removal_Port_Interface {
	private const TABLE_ASSIGNMENTS         = 'oj_ai_assignments';
	private const TABLE_TARGETS             = 'oj_ai_assignment_targets';
	private const TABLE_PUBLICATIONS        = 'oj_ai_worksheet_publications';
	private const TABLE_WORKSHEET_REVISIONS = 'oj_ai_worksheet_revisions';
	private const TABLE_SUBMISSIONS         = 'oj_ai_submissions';
	private const TABLE_ATTACHMENTS         = 'oj_ai_submission_attachments';
	private const TABLE_PRIVATE_FILES       = 'oj_ai_private_files';
	private const TABLE_AUDIT               = 'oj_ai_audit_events';
	private const TABLE_OUTBOX              = 'oj_ai_outbox_events';

	private const REMOVED_EVENT          = 'submission.attachment.removed';
	private const REMOVED_PAYLOAD_SCHEMA = 'oj_ai.submission_attachment_removed_event';
	private const REMOVED_AUDIT_SCHEMA   = 'oj_ai.submission_attachment_audit';
	private const REMOVED_VERSION        = 1;

	/**
	 * Canonical JSON encoder used for stable digests.
	 *
	 * @var Canonical_Json
	 */
	private Canonical_Json $canonical;
	/**
	 * Save tokens.
	 *
	 * @var Submission_Save_Token
	 */
	private Submission_Save_Token $save_tokens;

	/**
	 * Initialize WordPress student attachment repository dependencies and state.
	 *
	 * @param object|null         $database Database.
	 * @param Canonical_Json|null $canonical Canonical JSON encoder used for stable digests.
	 */
	public function __construct( private ?object $database = null, ?Canonical_Json $canonical = null ) {
		$this->canonical   = $canonical ?? new Canonical_Json();
		$this->save_tokens = new Submission_Save_Token( $this->canonical );
	}

	/**
	 * Staging profile.
	 *
	 * @param string $submission_id Submission public identifier.
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @return array|null
	 */
	public function staging_profile( string $submission_id, int $actor_user_id ): ?array {
		new Public_Id( $submission_id );
		$this->assert_actor( $actor_user_id );
		$row = $this->submission_context( $submission_id, $actor_user_id, false );
		if ( null === $row ) {
			return null;
		}
		return $this->project_profile( $row );
	}

	/**
	 * Find by file public id.
	 *
	 * @param string $submission_id Submission public identifier.
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $file_id File id.
	 * @return array|null
	 */
	public function find_by_file_public_id( string $submission_id, int $actor_user_id, string $file_id ): ?array {
		new Public_Id( $submission_id );
		new Public_Id( $file_id );
		$this->assert_actor( $actor_user_id );
		$sql = 'SELECT at.public_id, at.lock_version, at.file_public_id, at.response_key, at.state, at.original_name, at.mime_type, at.byte_size, at.sha256, at.bound_at_utc FROM ' . self::TABLE_ATTACHMENTS . ' at '
			. 'INNER JOIN ' . self::TABLE_SUBMISSIONS . ' s ON s.id = at.submission_id '
			. 'WHERE s.public_id = %s AND s.learner_user_id = %d AND at.file_public_id = %s LIMIT 1';
		return $this->get_row( $this->prepare( $sql, array( $submission_id, $actor_user_id, $file_id ) ) );
	}

	/**
	 * Find removal receipt.
	 *
	 * @param string $submission_id Submission public identifier.
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $storage_key Storage key.
	 * @param string $request_digest Request digest.
	 * @return array|null
	 */
	public function find_removal_receipt(
		string $submission_id,
		int $actor_user_id,
		string $storage_key,
		string $request_digest
	): ?array {
		new Public_Id( $submission_id );
		$this->assert_actor( $actor_user_id );
		$this->assert_idempotency_storage_key( $storage_key );
		$this->assert_hash_argument( $request_digest );
		return $this->removal_receipt( $submission_id, $actor_user_id, $storage_key, $request_digest, false );
	}

	/**
	 * Remove staged.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function remove_staged( array $record ): array {
		$this->assert_removal_record( $record );
		$replay = $this->removal_receipt(
			$record['submission_public_id'],
			$record['actor_user_id'],
			$record['idempotency_storage_key'],
			$record['request_digest'],
			false
		);
		if ( is_array( $replay ) ) {
			return $replay;
		}

		$transaction = false;
		try {
			$this->query_or_fail( 'START TRANSACTION' );
			$transaction = true;
			$replay      = $this->removal_receipt(
				$record['submission_public_id'],
				$record['actor_user_id'],
				$record['idempotency_storage_key'],
				$record['request_digest'],
				true
			);
			if ( is_array( $replay ) ) {
				$this->query_or_fail( 'COMMIT' );
				return $replay;
			}

			$current = $this->submission_context( $record['submission_public_id'], $record['actor_user_id'], true );
			if ( null === $current || 'active' !== ( $current['target_state'] ?? null ) ) {
				throw Assignment_Failure::because( Assignment_Failure::NOT_FOUND );
			}
			$this->assert_expected( $current, $record );
			$submission_pk = $this->positive_integer( $current['submission_pk'] ?? null );
			$attachment    = $this->attachment_by_public_id( $submission_pk, $record['attachment_public_id'], true );
			if ( null === $attachment ) {
				throw Assignment_Failure::because( Assignment_Failure::NOT_FOUND );
			}
			$file   = $this->private_file_by_public_id( (string) ( $attachment['file_public_id'] ?? '' ), true );
			$result = $this->assert_removable_rows( $attachment, $file, $record );

			$attachment_changed = $this->change_count(
				$this->prepare(
					'UPDATE ' . self::TABLE_ATTACHMENTS . ' SET state = %s, lock_version = lock_version + 1, '
					. 'updated_by_user_id = %d, updated_at_utc = %s WHERE id = %d AND lock_version = %d '
					. 'AND state IN (%s,%s,%s) AND submission_revision_id IS NULL AND bound_at_utc IS NULL',
					array(
						'removed',
						$record['actor_user_id'],
						$record['occurred_at_utc'],
						$this->positive_integer( $attachment['id'] ?? null ),
						$this->non_negative_integer( $attachment['lock_version'] ?? null ),
						'staged',
						'scanning',
						'ready',
					)
				)
			);
			if ( 1 !== $attachment_changed ) {
				throw Assignment_Failure::because( Assignment_Failure::SUBMISSION_REVISION_CONFLICT );
			}

			$file_changed = $this->change_count(
				$this->prepare(
					'UPDATE ' . self::TABLE_PRIVATE_FILES . ' SET state = %s, lock_version = lock_version + 1, '
					. 'updated_by_user_id = %d, updated_at_utc = %s, expires_at_utc = NULL, purged_at_utc = %s '
					. 'WHERE public_id = %s AND lock_version = %d AND created_by_user_id = %d AND owner_type = %s '
					. 'AND owner_public_id = %s AND purpose = %s AND state IN (%s,%s) AND scan_state IN (%s,%s) '
					. 'AND legal_hold_until_utc IS NULL',
					array(
						'purged',
						$record['actor_user_id'],
						$record['occurred_at_utc'],
						$record['occurred_at_utc'],
						$result['file_id'],
						$this->non_negative_integer( $file['lock_version'] ?? null ),
						$record['actor_user_id'],
						'submission',
						$record['submission_public_id'],
						'submission_attachment',
						'quarantined',
						'ready',
						'pending',
						'clean',
					)
				)
			);
			if ( 1 !== $file_changed ) {
				throw Assignment_Failure::because( Assignment_Failure::SUBMISSION_REVISION_CONFLICT );
			}

			$this->insert_removal_audit( $current, $attachment, $file, $record, $result );
			$this->insert_removal_outbox( $record, $result );
			$this->query_or_fail( 'COMMIT' );
			$transaction = false;
			return $result;
		} catch ( Assignment_Failure $failure ) {
			if ( $transaction ) {
				$this->rollback();
			}
			$replay = $this->removal_receipt(
				$record['submission_public_id'],
				$record['actor_user_id'],
				$record['idempotency_storage_key'],
				$record['request_digest'],
				false
			);
			if ( is_array( $replay ) ) {
				return $replay;
			}
			throw $failure;
		} catch ( Throwable ) {
			if ( $transaction ) {
				$this->rollback();
			}
			$replay = $this->removal_receipt(
				$record['submission_public_id'],
				$record['actor_user_id'],
				$record['idempotency_storage_key'],
				$record['request_digest'],
				false
			);
			if ( is_array( $replay ) ) {
				return $replay;
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Stage.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function stage( array $record ): array {
		$this->assert_record( $record );
		$transaction = false;
		try {
			$this->query_or_fail( 'START TRANSACTION' );
			$transaction = true;
			$current     = $this->submission_context( $record['submission_public_id'], $record['actor_user_id'], true );
			if ( null === $current || 'active' !== ( $current['target_state'] ?? null ) ) {
				throw Assignment_Failure::because( Assignment_Failure::NOT_FOUND );
			}
			$this->assert_expected( $current, $record );

			$existing = $this->attachment_by_file( $this->positive_integer( $current['submission_pk'] ?? null ), $record['file_public_id'], true );
			if ( is_array( $existing ) ) {
				$result = $this->replay_row( $existing, $record );
				$this->query_or_fail( 'COMMIT' );
				return $result;
			}

			$active         = $this->active_staged_rows( $this->positive_integer( $current['submission_pk'] ?? null ) );
			$total_bytes    = 0;
			$response_files = 0;
			foreach ( $active as $row ) {
				$total_bytes += $this->positive_integer( $row['byte_size'] ?? null );
				if ( $record['response_id'] === ( $row['response_key'] ?? null ) ) {
					++$response_files;
				}
			}
			if (
				count( $active ) >= Submission_Attachment_Upload_Profile::MAX_TOTAL_FILES
				|| $total_bytes + $record['byte_size'] > Submission_Attachment_Upload_Profile::MAX_TOTAL_BYTES
				|| $response_files >= $record['max_files']
			) {
				throw Assignment_Failure::because( Assignment_Failure::ATTACHMENT_LIMIT_EXCEEDED );
			}

			$this->insert_or_fail(
				self::TABLE_ATTACHMENTS,
				array(
					'public_id'                 => $record['attachment_public_id'],
					'lock_version'              => 0,
					'created_by_user_id'        => $record['actor_user_id'],
					'created_at_utc'            => $record['occurred_at_utc'],
					'updated_by_user_id'        => $record['actor_user_id'],
					'updated_at_utc'            => $record['occurred_at_utc'],
					'submission_id'             => $this->positive_integer( $current['submission_pk'] ?? null ),
					'submission_revision_id'    => null,
					'response_key'              => $record['response_id'],
					'file_public_id'            => $record['file_public_id'],
					'state'                     => 'scanning',
					'original_name'             => $record['original_name'],
					'mime_type'                 => $record['mime_type'],
					'byte_size'                 => $record['byte_size'],
					'sha256'                    => $record['sha256'],
					'bound_at_utc'              => null,
					'ai_representation_schema'  => null,
					'ai_representation_version' => null,
					'ai_representation_json'    => null,
					'ai_representation_hash'    => null,
				)
			);
			$stored = $this->attachment_by_file( $this->positive_integer( $current['submission_pk'] ?? null ), $record['file_public_id'], true );
			if ( null === $stored ) {
				$this->stored_invalid();
			}
			$result = $this->replay_row( $stored, $record, false );
			$this->query_or_fail( 'COMMIT' );
			return $result;
		} catch ( Assignment_Failure $failure ) {
			$this->rollback();
			throw $failure;
		} catch ( Throwable ) {
			if ( $transaction ) {
				$this->rollback();
			}
			$race = $this->find_by_file_public_id( $record['submission_public_id'], $record['actor_user_id'], $record['file_public_id'] );
			if ( is_array( $race ) ) {
				return $this->replay_row( $race, $record );
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Submission context.
	 *
	 * @param string $submission_id Submission public identifier.
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param bool   $for_update For update.
	 * @return array<string,mixed>|null
	 */
	private function submission_context( string $submission_id, int $actor_user_id, bool $for_update ): ?array {
		$sql = 'SELECT s.id AS submission_pk, s.public_id AS submission_id, s.lock_version AS submission_lock_version, s.state AS submission_state, s.current_revision_public_id, a.public_id AS assignment_id, a.context_type, a.context_id, a.context_revision, a.state AS assignment_state, a.worksheet_publication_public_id, a.publication_hash, a.opens_at_utc, a.due_at_utc, a.closes_at_utc, a.schedule_version, a.schedule_hash, a.auto_submit_enabled, t.state AS target_state, p.content_hash AS publication_content_hash, p.publication_hash AS stored_publication_hash, p.response_schema_schema, p.response_schema_version, p.response_schema_json, p.response_schema_hash, wr.document_hash AS publication_source_hash FROM ' . self::TABLE_SUBMISSIONS . ' s '
			. 'INNER JOIN ' . self::TABLE_ASSIGNMENTS . ' a ON a.id = s.assignment_id '
			. 'INNER JOIN ' . self::TABLE_TARGETS . ' t ON t.assignment_id = a.id '
			. 'INNER JOIN ' . self::TABLE_PUBLICATIONS . ' p ON p.public_id = a.worksheet_publication_public_id '
			. 'INNER JOIN ' . self::TABLE_WORKSHEET_REVISIONS . ' wr ON wr.id = p.worksheet_revision_id '
			. 'WHERE s.public_id = %s AND s.learner_user_id = %d AND t.learner_user_id = %d LIMIT 1';
		if ( $for_update ) {
			$sql .= ' FOR UPDATE';
		}
		return $this->get_row( $this->prepare( $sql, array( $submission_id, $actor_user_id, $actor_user_id ) ) );
	}

	/**
	 * Project profile.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 */
	private function project_profile( array $row ): array {
		$submission_id    = $this->stored_public_id( $row['submission_id'] ?? null );
		$assignment_id    = $this->stored_public_id( $row['assignment_id'] ?? null );
		$publication_id   = $this->stored_public_id( $row['worksheet_publication_public_id'] ?? null );
		$publication_hash = $this->stored_hash( $row['publication_hash'] ?? null );
		if (
			! hash_equals( $publication_hash, $this->stored_hash( $row['publication_content_hash'] ?? null ) )
			|| ! hash_equals( $publication_hash, $this->stored_hash( $row['stored_publication_hash'] ?? null ) )
		) {
			$this->stored_invalid();
		}
		if ( 'oj_ai.student_response_schema' !== ( $row['response_schema_schema'] ?? null ) || 1 !== $this->positive_integer( $row['response_schema_version'] ?? null ) ) {
			$this->stored_invalid();
		}
		$profile = $this->decode_json( (string) ( $row['response_schema_json'] ?? '' ), $this->stored_hash( $row['response_schema_hash'] ?? null ) );
		if ( ! hash_equals( $this->stored_hash( $row['publication_source_hash'] ?? null ), (string) ( $profile['source_hash'] ?? '' ) ) ) {
			$this->stored_invalid();
		}
		$lock_version        = $this->non_negative_integer( $row['submission_lock_version'] ?? null );
		$current_revision_id = $this->optional_stored_public_id( $row['current_revision_public_id'] ?? null );
		$schedule_hash       = $this->stored_hash( $row['schedule_hash'] ?? null );
		return array(
			'submission_id'       => $submission_id,
			'assignment_id'       => $assignment_id,
			'context_type'        => $this->context_type( $row['context_type'] ?? null ),
			'context_id'          => $this->token( $row['context_id'] ?? null, 191 ),
			'context_revision'    => $this->token( $row['context_revision'] ?? null, 191 ),
			'target_state'        => $this->target_state( $row['target_state'] ?? null ),
			'assignment_state'    => $this->assignment_state( $row['assignment_state'] ?? null ),
			'state'               => $this->submission_state( $row['submission_state'] ?? null ),
			'lock_version'        => $lock_version,
			'current_revision_id' => $current_revision_id,
			'publication_id'      => $publication_id,
			'publication_hash'    => $publication_hash,
			'schedule_version'    => $this->positive_integer( $row['schedule_version'] ?? null ),
			'schedule_hash'       => $schedule_hash,
			'save_token'          => $this->save_tokens->create( $submission_id, $assignment_id, $lock_version, $current_revision_id, $publication_hash, $schedule_hash ),
			'payload'             => $profile['payload'] ?? null,
		);
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
		$state         = $this->submission_state( $current['submission_state'] ?? null );
		if ( ! in_array( $state, array( 'draft', 'conflict', 'reopened' ), true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::SUBMISSION_ALREADY_OFFICIAL );
		}
		if ( 'open' !== $this->assignment_state( $current['assignment_state'] ?? null ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STATE_CONFLICT );
		}
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
		$lock_version        = $this->non_negative_integer( $current['submission_lock_version'] ?? null );
		$current_revision_id = $this->optional_stored_public_id( $current['current_revision_public_id'] ?? null );
		$save_token          = $this->save_tokens->create( $submission_id, $assignment_id, $lock_version, $current_revision_id, $publication_hash, $schedule_hash );
		if (
			! hash_equals( $record['submission_public_id'], $submission_id )
			|| $record['expected_state'] !== $state
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
	 * Attachment by file.
	 *
	 * @param int    $submission_pk Submission pk.
	 * @param string $file_id File id.
	 * @param bool   $for_update For update.
	 * @return array<string,mixed>|null
	 */
	private function attachment_by_file( int $submission_pk, string $file_id, bool $for_update ): ?array {
		$sql = 'SELECT id, public_id, lock_version, submission_revision_id, response_key, file_public_id, state, original_name, mime_type, byte_size, sha256, bound_at_utc FROM ' . self::TABLE_ATTACHMENTS
			. ' WHERE submission_id = %d AND file_public_id = %s LIMIT 1';
		if ( $for_update ) {
			$sql .= ' FOR UPDATE';
		}
		return $this->get_row( $this->prepare( $sql, array( $submission_pk, $file_id ) ) );
	}

	/**
	 * Attachment by public id.
	 *
	 * @param int    $submission_pk Submission pk.
	 * @param string $attachment_id Attachment id.
	 * @param bool   $for_update For update.
	 * @return array<string,mixed>|null
	 */
	private function attachment_by_public_id( int $submission_pk, string $attachment_id, bool $for_update ): ?array {
		new Public_Id( $attachment_id );
		$sql = 'SELECT id, public_id, lock_version, created_by_user_id, updated_at_utc, submission_revision_id, response_key, file_public_id, state, original_name, mime_type, byte_size, sha256, bound_at_utc FROM '
			. self::TABLE_ATTACHMENTS . ' WHERE submission_id = %d AND public_id = %s LIMIT 1';
		if ( $for_update ) {
			$sql .= ' FOR UPDATE';
		}
		return $this->get_row( $this->prepare( $sql, array( $submission_pk, $attachment_id ) ) );
	}

	/**
	 * Private file by public id.
	 *
	 * @param string $file_id File id.
	 * @param bool   $for_update For update.
	 * @return array<string,mixed>|null
	 */
	private function private_file_by_public_id( string $file_id, bool $for_update ): ?array {
		$file_id = $this->stored_public_id( $file_id );
		$sql     = 'SELECT public_id, lock_version, created_by_user_id, owner_type, owner_public_id, purpose, storage_key, original_name, mime_type, byte_size, sha256, state, scan_state, retention_class, expires_at_utc, legal_hold_until_utc, purged_at_utc FROM ' . self::TABLE_PRIVATE_FILES . ' WHERE public_id = %s LIMIT 1';
		if ( $for_update ) {
			$sql .= ' FOR UPDATE';
		}
		return $this->get_row( $this->prepare( $sql, array( $file_id ) ) );
	}

	/**
	 * Reject invalid removable rows.
	 *
	 * @param array<string,mixed>      $attachment Attachment.
	 * @param array<string,mixed>|null $file File.
	 * @param array<string,mixed>      $record Record.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_removable_rows( array $attachment, ?array $file, array $record ): array {
		if (
			$record['attachment_public_id'] !== ( $attachment['public_id'] ?? null )
			|| $record['actor_user_id'] !== $this->positive_integer( $attachment['created_by_user_id'] ?? null )
			|| null !== ( $attachment['submission_revision_id'] ?? null )
			|| null !== ( $attachment['bound_at_utc'] ?? null )
			|| ! in_array( $attachment['state'] ?? null, array( 'staged', 'scanning', 'ready' ), true )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STATE_CONFLICT );
		}
		$file_id       = $this->stored_public_id( $attachment['file_public_id'] ?? null );
		$response_id   = $this->stored_public_id( $attachment['response_key'] ?? null );
		$original_name = $this->stored_text( $attachment['original_name'] ?? null, 255 );
		$mime_type     = $this->stored_text( $attachment['mime_type'] ?? null, 191 );
		$byte_size     = $this->positive_integer( $attachment['byte_size'] ?? null );
		$sha256        = $this->stored_hash( $attachment['sha256'] ?? null );
		if (
			null === $file
			|| $file_id !== ( $file['public_id'] ?? null )
			|| $record['actor_user_id'] !== $this->positive_integer( $file['created_by_user_id'] ?? null )
			|| 'submission' !== ( $file['owner_type'] ?? null )
			|| $record['submission_public_id'] !== ( $file['owner_public_id'] ?? null )
			|| 'submission_attachment' !== ( $file['purpose'] ?? null )
			|| 'submission_staging' !== ( $file['retention_class'] ?? null )
			|| ! in_array( $file['state'] ?? null, array( 'quarantined', 'ready' ), true )
			|| ! in_array( $file['scan_state'] ?? null, array( 'pending', 'clean' ), true )
			|| null !== ( $file['legal_hold_until_utc'] ?? null )
			|| $original_name !== ( $file['original_name'] ?? null )
			|| $mime_type !== ( $file['mime_type'] ?? null )
			|| $byte_size !== $this->positive_integer( $file['byte_size'] ?? null )
			|| ! hash_equals( $sha256, $this->stored_hash( $file['sha256'] ?? null ) )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STATE_CONFLICT );
		}
		$storage_key = $this->stored_storage_key( $file['storage_key'] ?? null );
		return array(
			'attachment_id'  => $record['attachment_public_id'],
			'file_id'        => $file_id,
			'response_id'    => $response_id,
			'state'          => 'removed',
			'removed_at_utc' => $record['occurred_at_utc'],
			'storage_key'    => $storage_key,
			'replayed'       => false,
		);
	}

	/**
	 * Insert removal audit.
	 *
	 * @param array<string,mixed> $current Current.
	 * @param array<string,mixed> $attachment Attachment.
	 * @param array<string,mixed> $file File.
	 * @param array<string,mixed> $record Record.
	 * @param array<string,mixed> $result Result.
	 * @return void
	 */
	private function insert_removal_audit( array $current, array $attachment, array $file, array $record, array $result ): void {
		$previous = $this->get_var( 'SELECT event_hash FROM ' . self::TABLE_AUDIT . ' ORDER BY id DESC LIMIT 1 FOR UPDATE' );
		if ( null !== $previous ) {
			$this->stored_hash( $previous );
		}
		$before        = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'attachment_id'           => $result['attachment_id'],
					'attachment_state'        => $attachment['state'],
					'attachment_lock_version' => $this->non_negative_integer( $attachment['lock_version'] ?? null ),
					'file_id'                 => $result['file_id'],
					'file_state'              => $file['state'],
					'file_lock_version'       => $this->non_negative_integer( $file['lock_version'] ?? null ),
				)
			)
		);
		$after         = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'attachment_id'    => $result['attachment_id'],
					'attachment_state' => 'removed',
					'file_id'          => $result['file_id'],
					'file_state'       => 'purged',
					'removed_at_utc'   => $result['removed_at_utc'],
				)
			)
		);
		$metadata_json = $this->canonical->encode(
			array(
				'command_id'                   => $record['command_id'],
				'request_digest'               => $record['request_digest'],
				'expected_current_revision_id' => $record['expected_current_revision_id'],
				'expected_lock_version'        => $record['expected_lock_version'],
				'response_id'                  => $result['response_id'],
			)
		);
		$metadata_hash = hash( 'sha256', $metadata_json );
		$event_hash    = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'action_key'          => self::REMOVED_EVENT,
					'actor_user_id'       => $record['actor_user_id'],
					'after_digest'        => $after,
					'before_digest'       => $before,
					'correlation_id'      => $record['correlation_id'],
					'metadata_hash'       => $metadata_hash,
					'object_public_id'    => $result['attachment_id'],
					'object_type'         => 'submission_attachment',
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
				'action_key'          => self::REMOVED_EVENT,
				'object_type'         => 'submission_attachment',
				'object_public_id'    => $result['attachment_id'],
				'context_type'        => $current['context_type'],
				'context_id'          => $current['context_id'],
				'context_revision'    => $record['authorized_context_revision'],
				'correlation_id'      => $record['correlation_id'],
				'before_digest'       => $before,
				'after_digest'        => $after,
				'metadata_schema'     => self::REMOVED_AUDIT_SCHEMA,
				'metadata_version'    => self::REMOVED_VERSION,
				'metadata_json'       => $metadata_json,
				'metadata_hash'       => $metadata_hash,
				'previous_event_hash' => null === $previous ? null : (string) $previous,
				'event_hash'          => $event_hash,
			)
		);
	}

	/**
	 * Insert removal outbox.
	 *
	 * @param array<string,mixed> $record Record.
	 * @param array<string,mixed> $result Result.
	 * @return void
	 */
	private function insert_removal_outbox( array $record, array $result ): void {
		$payload_json = $this->canonical->encode(
			array(
				'request_digest' => $record['request_digest'],
				'result'         => array(
					'attachment_id'  => $result['attachment_id'],
					'file_id'        => $result['file_id'],
					'removed_at_utc' => $result['removed_at_utc'],
					'response_id'    => $result['response_id'],
					'state'          => 'removed',
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
				'event_type'           => self::REMOVED_EVENT,
				'idempotency_key'      => $record['idempotency_storage_key'],
				'payload_schema'       => self::REMOVED_PAYLOAD_SCHEMA,
				'payload_version'      => self::REMOVED_VERSION,
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
	 * Removal receipt.
	 *
	 * @param string $submission_id Submission public identifier.
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $storage_key Storage key.
	 * @param string $request_digest Request digest.
	 * @param bool   $for_update For update.
	 * @return array<string,mixed>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function removal_receipt( string $submission_id, int $actor_user_id, string $storage_key, string $request_digest, bool $for_update ): ?array {
		$sql = 'SELECT aggregate_type, aggregate_public_id, event_type, payload_schema, payload_version, payload_json, payload_hash FROM ' . self::TABLE_OUTBOX . ' WHERE idempotency_key = %s LIMIT 1';
		if ( $for_update ) {
			$sql .= ' FOR UPDATE';
		}
		$row = $this->get_row( $this->prepare( $sql, array( $storage_key ) ) );
		if ( null === $row ) {
			return null;
		}
		if (
			'submission' !== ( $row['aggregate_type'] ?? null ) || $submission_id !== ( $row['aggregate_public_id'] ?? null )
			|| self::REMOVED_EVENT !== ( $row['event_type'] ?? null )
			|| self::REMOVED_PAYLOAD_SCHEMA !== ( $row['payload_schema'] ?? null )
			|| self::REMOVED_VERSION !== $this->positive_integer( $row['payload_version'] ?? null )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::IDEMPOTENCY_CONFLICT );
		}
		$payload = $this->decode_json( (string) ( $row['payload_json'] ?? '' ), $this->stored_hash( $row['payload_hash'] ?? null ) );
		$this->stored_exact_keys( $payload, array( 'request_digest', 'result' ) );
		if ( ! is_string( $payload['request_digest'] ?? null ) || ! hash_equals( $request_digest, $payload['request_digest'] ) || ! is_array( $payload['result'] ?? null ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::IDEMPOTENCY_CONFLICT );
		}
		$result = $payload['result'];
		$this->stored_exact_keys( $result, array( 'attachment_id', 'file_id', 'removed_at_utc', 'response_id', 'state' ) );
		$attachment_id = $this->stored_public_id( $result['attachment_id'] ?? null );
		$file_id       = $this->stored_public_id( $result['file_id'] ?? null );
		$response_id   = $this->stored_public_id( $result['response_id'] ?? null );
		$removed_at    = $this->required_utc( $result['removed_at_utc'] ?? null );
		if ( 'removed' !== ( $result['state'] ?? null ) ) {
			$this->stored_invalid();
		}
		$current = $this->submission_context( $submission_id, $actor_user_id, false );
		if ( null === $current ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::NOT_FOUND );
		}
		$attachment = $this->attachment_by_public_id( $this->positive_integer( $current['submission_pk'] ?? null ), $attachment_id, false );
		if (
			null === $attachment || 'removed' !== ( $attachment['state'] ?? null )
			|| null !== ( $attachment['submission_revision_id'] ?? null ) || null !== ( $attachment['bound_at_utc'] ?? null )
			|| $file_id !== ( $attachment['file_public_id'] ?? null ) || $response_id !== ( $attachment['response_key'] ?? null )
			|| $actor_user_id !== $this->positive_integer( $attachment['created_by_user_id'] ?? null )
			|| $removed_at !== ( $attachment['updated_at_utc'] ?? null )
		) {
			$this->stored_invalid();
		}
		$file = $this->private_file_by_public_id( $file_id, false );
		if (
			null === $file || 'purged' !== ( $file['state'] ?? null ) || $removed_at !== ( $file['purged_at_utc'] ?? null )
			|| $actor_user_id !== $this->positive_integer( $file['created_by_user_id'] ?? null )
			|| 'submission' !== ( $file['owner_type'] ?? null ) || $submission_id !== ( $file['owner_public_id'] ?? null )
			|| 'submission_attachment' !== ( $file['purpose'] ?? null )
		) {
			$this->stored_invalid();
		}
		return array(
			'attachment_id'  => $attachment_id,
			'file_id'        => $file_id,
			'response_id'    => $response_id,
			'state'          => 'removed',
			'removed_at_utc' => $removed_at,
			'storage_key'    => $this->stored_storage_key( $file['storage_key'] ?? null ),
			'replayed'       => true,
		);
	}

	/**
	 * Active staged rows.
	 *
	 * @param int $submission_pk Submission pk.
	 * @return array<int,array<string,mixed>>
	 */
	private function active_staged_rows( int $submission_pk ): array {
		$sql = 'SELECT id, response_key, byte_size, state FROM ' . self::TABLE_ATTACHMENTS
			. ' WHERE submission_id = %d AND submission_revision_id IS NULL AND state IN (%s,%s,%s) FOR UPDATE';
		return $this->get_results( $this->prepare( $sql, array( $submission_pk, 'staged', 'scanning', 'ready' ) ) );
	}

	/**
	 * Replay row.
	 *
	 * @param array<string,mixed> $row Row.
	 * @param array<string,mixed> $record Record.
	 * @param bool                $replayed Replayed.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function replay_row( array $row, array $record, bool $replayed = true ): array {
		$matches = hash_equals( $record['file_public_id'], (string) ( $row['file_public_id'] ?? '' ) )
			&& hash_equals( $record['response_id'], (string) ( $row['response_key'] ?? '' ) )
			&& hash_equals( $record['original_name'], (string) ( $row['original_name'] ?? '' ) )
			&& hash_equals( $record['mime_type'], (string) ( $row['mime_type'] ?? '' ) )
			&& $record['byte_size'] === $this->positive_integer( $row['byte_size'] ?? null )
			&& hash_equals( $record['sha256'], (string) ( $row['sha256'] ?? '' ) )
			&& null === ( $row['submission_revision_id'] ?? null )
			&& null === ( $row['bound_at_utc'] ?? null )
			&& in_array( $row['state'] ?? null, array( 'staged', 'scanning', 'ready' ), true );
		if ( ! $matches ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::IDEMPOTENCY_CONFLICT );
		}
		return array(
			'attachment_id' => $this->stored_public_id( $row['public_id'] ?? null ),
			'file_id'       => $record['file_public_id'],
			'response_id'   => $record['response_id'],
			'original_name' => $record['original_name'],
			'mime_type'     => $record['mime_type'],
			'byte_size'     => $record['byte_size'],
			'sha256'        => $record['sha256'],
			'state'         => (string) $row['state'],
			'replayed'      => $replayed,
		);
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
			'attachment_public_id',
			'byte_size',
			'expected_current_revision_id',
			'expected_lock_version',
			'expected_publication_hash',
			'expected_save_token',
			'expected_schedule_hash',
			'expected_schedule_version',
			'expected_state',
			'file_public_id',
			'max_files',
			'mime_type',
			'occurred_at_utc',
			'original_name',
			'response_id',
			'role',
			'sha256',
			'submission_public_id',
		);
		$actual   = array_keys( $record );
		sort( $actual, SORT_STRING );
		if ( $actual !== $expected ) {
			throw new InvalidArgumentException( 'Student attachment record shape is invalid.' );
		}
		$this->assert_actor( $record['actor_user_id'] );
		foreach ( array( 'attachment_public_id', 'file_public_id', 'response_id', 'submission_public_id' ) as $field ) {
			new Public_Id( (string) $record[ $field ] );
		}
		if ( null !== $record['expected_current_revision_id'] ) {
			new Public_Id( (string) $record['expected_current_revision_id'] );
		}
		foreach ( array( 'expected_publication_hash', 'expected_save_token', 'expected_schedule_hash', 'sha256' ) as $field ) {
			if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', (string) $record[ $field ] ) ) {
				throw new InvalidArgumentException( 'Student attachment hash is invalid.' );
			}
		}
		$this->assert_utc_argument( (string) $record['occurred_at_utc'] );
		if (
			! in_array( $record['role'], array( 'file', 'document', 'preview' ), true )
			|| ! in_array( $record['expected_state'], array( 'draft', 'conflict', 'reopened' ), true )
			|| ! is_int( $record['expected_lock_version'] ) || $record['expected_lock_version'] < 0
			|| ! is_int( $record['expected_schedule_version'] ) || $record['expected_schedule_version'] < 1
			|| ! is_int( $record['max_files'] ) || $record['max_files'] < 1 || $record['max_files'] > Submission_Attachment_Upload_Profile::MAX_TOTAL_FILES
			|| ! is_int( $record['byte_size'] ) || $record['byte_size'] < 1 || $record['byte_size'] > Submission_Attachment_Upload_Profile::MAX_FILE_BYTES
			|| ! is_string( $record['original_name'] ) || '' === $record['original_name'] || strlen( $record['original_name'] ) > 255
			|| ! is_string( $record['mime_type'] ) || '' === $record['mime_type'] || strlen( $record['mime_type'] ) > 191
		) {
			throw new InvalidArgumentException( 'Student attachment record values are invalid.' );
		}
	}

	/**
	 * Reject invalid removal record.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_removal_record( array $record ): void {
		$expected = array(
			'actor_user_id',
			'attachment_public_id',
			'audit_public_id',
			'authorized_context_revision',
			'command_id',
			'correlation_id',
			'expected_current_revision_id',
			'expected_lock_version',
			'expected_publication_hash',
			'expected_save_token',
			'expected_schedule_hash',
			'expected_schedule_version',
			'expected_state',
			'idempotency_storage_key',
			'occurred_at_utc',
			'outbox_public_id',
			'request_digest',
			'submission_public_id',
		);
		$actual   = array_keys( $record );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected ) {
			throw new InvalidArgumentException( 'Student attachment removal record shape is invalid.' );
		}
		$this->assert_actor( $record['actor_user_id'] );
		foreach ( array( 'attachment_public_id', 'audit_public_id', 'outbox_public_id', 'submission_public_id' ) as $field ) {
			new Public_Id( (string) $record[ $field ] );
		}
		if ( null !== $record['expected_current_revision_id'] ) {
			new Public_Id( (string) $record['expected_current_revision_id'] );
		}
		foreach ( array( 'expected_publication_hash', 'expected_save_token', 'expected_schedule_hash', 'request_digest' ) as $field ) {
			$this->assert_hash_argument( (string) $record[ $field ] );
		}
		$this->assert_idempotency_storage_key( (string) $record['idempotency_storage_key'] );
		$this->assert_utc_argument( (string) $record['occurred_at_utc'] );
		if (
			! in_array( $record['expected_state'], array( 'draft', 'conflict', 'reopened' ), true )
			|| ! is_int( $record['expected_lock_version'] ) || $record['expected_lock_version'] < 0
			|| ! is_int( $record['expected_schedule_version'] ) || $record['expected_schedule_version'] < 1
			|| ! is_string( $record['command_id'] ) || strlen( $record['command_id'] ) < 8 || strlen( $record['command_id'] ) > 128
			|| 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@-]*$/D', $record['command_id'] )
			|| ! is_string( $record['correlation_id'] ) || strlen( $record['correlation_id'] ) < 8 || strlen( $record['correlation_id'] ) > 100
			|| 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@-]*$/D', $record['correlation_id'] )
			|| ! is_string( $record['authorized_context_revision'] ) || '' === $record['authorized_context_revision']
			|| strlen( $record['authorized_context_revision'] ) > 191
		) {
			throw new InvalidArgumentException( 'Student attachment removal record values are invalid.' );
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
	 * Reject invalid actor.
	 *
	 * @param mixed $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_actor( mixed $value ): void {
		if ( ! is_int( $value ) || $value < 1 ) {
			throw new InvalidArgumentException( 'Student attachment actor is invalid.' );
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
	 * Context type.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function context_type( mixed $value ): string {
		if ( ! is_string( $value ) || ! in_array( $value, array( 'class', 'group' ), true ) ) {
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
	 * Stored text.
	 *
	 * @param mixed $value Value.
	 * @param int   $maximum Maximum.
	 * @return string
	 */
	private function stored_text( mixed $value, int $maximum ): string {
		if ( ! is_string( $value ) || '' === $value || strlen( $value ) > $maximum ) {
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
	 * Stored storage key.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function stored_storage_key( mixed $value ): string {
		if ( ! is_string( $value ) || strlen( $value ) > 512 || 1 !== preg_match( '/^[a-z0-9][a-z0-9._\/-]*$/D', $value ) || in_array( '..', explode( '/', $value ), true ) ) {
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
	 * Reject invalid utc argument.
	 *
	 * @param string $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_utc_argument( string $value ): void {
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value ) ) {
			throw new InvalidArgumentException( 'Student attachment UTC value is invalid.' );
		}
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
			throw new InvalidArgumentException( 'Student attachment hash argument is invalid.' );
		}
	}
	/**
	 * Reject invalid idempotency storage key.
	 *
	 * @param string $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_idempotency_storage_key( string $value ): void {
		if ( 1 !== preg_match( '/^submission:[a-f0-9]{64}$/D', $value ) ) {
			throw new InvalidArgumentException( 'Student attachment idempotency key is invalid.' );
		}
	}
	/**
	 * Reject unexpected keys before treating an input array as a validated object.
	 *
	 * @param array<array-key,mixed> $value Unvalidated input fields.
	 * @param array<int,string>      $expected Required field names.
	 * @return void
	 */
	private function stored_exact_keys( array $value, array $expected ): void {
		if ( array_is_list( $value ) ) {
			$this->stored_invalid();
		} $actual = array_keys( $value );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected ) {
			$this->stored_invalid();
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
	 * Get var.
	 *
	 * @param string $sql Sql.
	 * @return mixed
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function get_var( string $sql ): mixed {
		$db = $this->database();
		if ( ! method_exists( $db, 'get_var' ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORAGE_UNAVAILABLE );
		} $this->reset_error( $db );
		$value = $db->get_var( $sql );
		$this->assert_read( $db );
		return $value;
	}
	/**
	 * Change count.
	 *
	 * @param string $sql Sql.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function change_count( string $sql ): int {
		$db = $this->database();
		$this->reset_error( $db );
		$changed = $db->query( $sql );
		if ( false === $changed || '' !== trim( (string) ( $db->last_error ?? '' ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORAGE_UNAVAILABLE );
		} return (int) $changed;
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
		foreach ( array( 'prepare', 'get_row', 'get_results', 'insert', 'query' ) as $method ) {
			if ( ! method_exists( $db, $method ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Assignment_Failure::because( Assignment_Failure::STORAGE_UNAVAILABLE );
			}
		}
		return $db;
	}
}
