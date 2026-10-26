<?php
/**
 * Literal-table lease and completion repository for submission attachment scans.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Student\Infrastructure;

use InvalidArgumentException;
use OJAI\Dashboard\Submission\Assignment\Application\Assignment_Failure;
use OJAI\Dashboard\Submission\Student\Domain\Submission_Attachment_Scan_Item;
use OJAI\Dashboard\Submission\Student\Port\Student_Attachment_Scan_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Uses private-file lock_version and updated_at as a reclaimable five-minute lease.
 */
final class WordPress_Student_Attachment_Scan_Repository implements Student_Attachment_Scan_Repository_Port_Interface {
	private const TABLE_SUBMISSIONS   = 'oj_ai_submissions';
	private const TABLE_ATTACHMENTS   = 'oj_ai_submission_attachments';
	private const TABLE_PRIVATE_FILES = 'oj_ai_private_files';
	private const TABLE_AUDIT         = 'oj_ai_audit_events';
	private const TABLE_OUTBOX        = 'oj_ai_outbox_events';

	private const EVENT_TYPE     = 'submission.attachment.scan.completed';
	private const PAYLOAD_SCHEMA = 'oj_ai.submission_attachment_scan_event';
	private const AUDIT_SCHEMA   = 'oj_ai.submission_attachment_scan_audit';
	private const VERSION        = 1;

	/**
	 * Canonical JSON encoder used for stable digests.
	 *
	 * @var Canonical_Json
	 */
	private Canonical_Json $canonical;

	/**
	 * Initialize WordPress student attachment scan repository dependencies and state.
	 *
	 * @param object|null         $database Database.
	 * @param Canonical_Json|null $canonical Canonical JSON encoder used for stable digests.
	 */
	public function __construct( private ?object $database = null, ?Canonical_Json $canonical = null ) {
		$this->canonical = $canonical ?? new Canonical_Json();
	}

	/**
	 * Claim due.
	 *
	 * @param string $now_utc Now utc.
	 * @param string $stale_before_utc Stale before utc.
	 * @return Submission_Attachment_Scan_Item|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function claim_due( string $now_utc, string $stale_before_utc ): ?Submission_Attachment_Scan_Item {
		$this->assert_utc( $now_utc );
		$this->assert_utc( $stale_before_utc );
		if ( $stale_before_utc > $now_utc ) {
			throw new InvalidArgumentException( 'Submission attachment scan lease window is invalid.' );
		}

		$row = $this->candidate( $stale_before_utc );
		if ( null === $row ) {
			return null;
		}
		$file_pk       = $this->positive_integer( $row['private_file_pk'] ?? null );
		$old_lock      = $this->non_negative_integer( $row['file_lock_version'] ?? null );
		$file_id       = $this->stored_public_id( $row['file_id'] ?? null );
		$submission_id = $this->stored_public_id( $row['submission_id'] ?? null );
		$owner_user_id = $this->positive_integer( $row['owner_user_id'] ?? null );

		// Validate the remaining, less-trusted descriptive fields (original
		// name, MIME type, hashes -- values ultimately sourced from an
		// upload) *before* claiming. Otherwise a row that can never pass
		// validation would be claimed, fail to construct, and then be
		// silently reclaimed and fail identically forever: it would never
		// reach a terminal state, and the cron entrypoint discards the
		// resulting exception with nothing recorded.
		$preview                      = $row;
		$preview['file_lock_version'] = $old_lock;
		$preview['claimed_at_utc']    = $now_utc;
		try {
			$this->item( $preview );
		} catch ( Throwable ) {
			$this->reject_unclaimable( $row, $now_utc );
			return null;
		}

		$changed = $this->change_count(
			$this->prepare(
				'UPDATE ' . self::TABLE_PRIVATE_FILES . ' SET lock_version = lock_version + 1, updated_by_user_id = %d, updated_at_utc = %s '
				. 'WHERE id = %d AND public_id = %s AND lock_version = %d AND state = %s AND scan_state = %s '
				. 'AND owner_type = %s AND owner_public_id = %s AND purpose = %s AND retention_class = %s AND purged_at_utc IS NULL',
				array(
					$owner_user_id,
					$now_utc,
					$file_pk,
					$file_id,
					$old_lock,
					'quarantined',
					'pending',
					'submission',
					$submission_id,
					'submission_attachment',
					'submission_staging',
				)
			)
		);
		if ( 1 !== $changed ) {
			return null;
		}
		$row['file_lock_version'] = $old_lock + 1;
		$row['claimed_at_utc']    = $now_utc;
		return $this->item( $row );
	}

	/**
	 * Complete.
	 *
	 * @param Submission_Attachment_Scan_Item $item Item.
	 * @param string                          $outcome Outcome.
	 * @param string|null                     $reason_code Reason code.
	 * @param string                          $audit_public_id Audit public id.
	 * @param string                          $outbox_public_id Outbox public id.
	 * @param string                          $occurred_at_utc Occurred at utc.
	 * @return bool
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function complete(
		Submission_Attachment_Scan_Item $item,
		string $outcome,
		?string $reason_code,
		string $audit_public_id,
		string $outbox_public_id,
		string $occurred_at_utc
	): bool {
		if ( ! in_array( $outcome, array( 'ready', 'rejected' ), true ) ) {
			throw new InvalidArgumentException( 'Submission attachment scan outcome is invalid.' );
		}
		if ( 'ready' === $outcome && null !== $reason_code ) {
			throw new InvalidArgumentException( 'A ready attachment cannot have a rejection reason.' );
		}
		if ( 'rejected' === $outcome && ( null === $reason_code || 1 !== preg_match( '/^[a-z][a-z0-9_]{2,99}$/D', $reason_code ) ) ) {
			throw new InvalidArgumentException( 'Submission attachment rejection reason is invalid.' );
		}
		new Public_Id( $audit_public_id );
		new Public_Id( $outbox_public_id );
		$this->assert_utc( $occurred_at_utc );
		$scan_state = 'ready' === $outcome ? 'clean' : 'rejected';

		$transaction = false;
		try {
			$this->query_or_fail( 'START TRANSACTION' );
			$transaction = true;
			$attachment  = $this->attachment( $item->attachment_id(), true );
			if ( null === $attachment ) {
				$this->rollback();
				return false;
			}
			$file = $this->private_file( $item->file_id(), true );
			if ( null === $file || ! $this->same_claim( $item, $attachment, $file ) ) {
				$this->rollback();
				return false;
			}

			$attachment_changed = $this->change_count(
				$this->prepare(
					'UPDATE ' . self::TABLE_ATTACHMENTS . ' SET state = %s, lock_version = lock_version + 1, '
					. 'updated_by_user_id = %d, updated_at_utc = %s WHERE id = %d AND public_id = %s AND lock_version = %d AND state = %s '
					. 'AND submission_revision_id IS NULL AND bound_at_utc IS NULL',
					array(
						$outcome,
						$item->owner_user_id(),
						$occurred_at_utc,
						$this->positive_integer( $attachment['id'] ?? null ),
						$item->attachment_id(),
						$item->attachment_lock_version(),
						'scanning',
					)
				)
			);
			if ( 1 !== $attachment_changed ) {
				$this->rollback();
				return false;
			}

			$file_changed = $this->change_count(
				$this->prepare(
					'UPDATE ' . self::TABLE_PRIVATE_FILES . ' SET state = %s, scan_state = %s, '
					. 'lock_version = lock_version + 1, updated_by_user_id = %d, updated_at_utc = %s WHERE id = %d AND public_id = %s '
					. 'AND lock_version = %d AND state = %s AND scan_state = %s AND owner_type = %s '
					. 'AND owner_public_id = %s AND purpose = %s AND retention_class = %s AND purged_at_utc IS NULL',
					array(
						$outcome,
						$scan_state,
						$item->owner_user_id(),
						$occurred_at_utc,
						$this->positive_integer( $file['id'] ?? null ),
						$item->file_id(),
						$item->file_lock_version(),
						'quarantined',
						'pending',
						'submission',
						$item->submission_id(),
						'submission_attachment',
						'submission_staging',
					)
				)
			);
			if ( 1 !== $file_changed ) {
				$this->rollback();
				return false;
			}

			$this->insert_audit( $item, $outcome, $scan_state, $reason_code, $audit_public_id, $occurred_at_utc );
			$this->insert_outbox( $item, $outcome, $scan_state, $reason_code, $outbox_public_id, $occurred_at_utc );
			$this->query_or_fail( 'COMMIT' );
			$transaction = false;
			return true;
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
	 * A candidate row that cannot construct a valid scan item can never be
	 * claimed successfully -- reject it outright instead so it is never
	 * re-selected, fail identically, and get silently discarded by the cron
	 * entrypoint on every future sweep. Content-free: only a fixed reason
	 * code is recorded, never the malformed field's actual value. Best
	 * effort only -- if even the row's identity fields are unusable, or the
	 * write itself fails, the row is left untouched for manual review; it
	 * was never claimed, so nothing is stranded by leaving it alone.
	 *
	 * @param array<string,mixed> $row Row.
	 * @param string              $now_utc Now utc.
	 * @return void
	 */
	private function reject_unclaimable( array $row, string $now_utc ): void {
		try {
			$attachment_pk   = $this->positive_integer( $row['attachment_pk'] ?? null );
			$attachment_id   = $this->stored_public_id( $row['attachment_id'] ?? null );
			$attachment_lock = $this->non_negative_integer( $row['attachment_lock_version'] ?? null );
			$file_pk         = $this->positive_integer( $row['private_file_pk'] ?? null );
			$file_id         = $this->stored_public_id( $row['file_id'] ?? null );
			$file_lock       = $this->non_negative_integer( $row['file_lock_version'] ?? null );
			$owner_user_id   = $this->positive_integer( $row['owner_user_id'] ?? null );
			$submission_id   = $this->stored_public_id( $row['submission_id'] ?? null );
		} catch ( Throwable ) {
			return;
		}
		$response_id = null;
		try {
			$response_id = $this->stored_public_id( $row['response_id'] ?? null ); } catch ( Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Preserve the documented recovery or optional-operation outcome; the catch body explains the fallback.
			// This optional step must not replace the surrounding operation outcome.
 // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- This optional step must not replace the surrounding operation outcome.
			}

			$transaction = false;
			try {
				$this->query_or_fail( 'START TRANSACTION' );
				$transaction = true;

				$attachment_changed = $this->change_count(
					$this->prepare(
						'UPDATE ' . self::TABLE_ATTACHMENTS . ' SET state = %s, lock_version = lock_version + 1, '
						. 'updated_by_user_id = %d, updated_at_utc = %s WHERE id = %d AND public_id = %s AND lock_version = %d '
						. 'AND state = %s AND submission_revision_id IS NULL AND bound_at_utc IS NULL',
						array( 'rejected', $owner_user_id, $now_utc, $attachment_pk, $attachment_id, $attachment_lock, 'scanning' )
					)
				);
				if ( 1 !== $attachment_changed ) {
					$this->rollback();
					return;
				}

				$file_changed = $this->change_count(
					$this->prepare(
						'UPDATE ' . self::TABLE_PRIVATE_FILES . ' SET state = %s, scan_state = %s, lock_version = lock_version + 1, '
						. 'updated_by_user_id = %d, updated_at_utc = %s WHERE id = %d AND public_id = %s AND lock_version = %d '
						. 'AND state = %s AND scan_state = %s AND owner_type = %s AND owner_public_id = %s AND purpose = %s '
						. 'AND retention_class = %s AND purged_at_utc IS NULL',
						array(
							'rejected',
							'rejected',
							$owner_user_id,
							$now_utc,
							$file_pk,
							$file_id,
							$file_lock,
							'quarantined',
							'pending',
							'submission',
							$submission_id,
							'submission_attachment',
							'submission_staging',
						)
					)
				);
				if ( 1 !== $file_changed ) {
					$this->rollback();
					return;
				}

				$this->insert_corrupt_rejection_events( $attachment_id, $file_id, $submission_id, $response_id, $owner_user_id, $now_utc );
				$this->query_or_fail( 'COMMIT' );
				$transaction = false;
			} catch ( Throwable ) {
				if ( $transaction ) {
					$this->rollback();
				}
			}
	}

	/**
	 * Insert corrupt rejection events.
	 *
	 * @param string      $attachment_id Attachment id.
	 * @param string      $file_id File id.
	 * @param string      $submission_id Submission public identifier.
	 * @param string|null $response_id Response id.
	 * @param int         $owner_user_id Owner user id.
	 * @param string      $occurred_at_utc Occurred at utc.
	 * @return void
	 */
	private function insert_corrupt_rejection_events(
		string $attachment_id,
		string $file_id,
		string $submission_id,
		?string $response_id,
		int $owner_user_id,
		string $occurred_at_utc
	): void {
		$reason_code = 'attachment_metadata_corrupt';
		$previous    = $this->get_var( 'SELECT event_hash FROM ' . self::TABLE_AUDIT . ' ORDER BY id DESC LIMIT 1 FOR UPDATE' );
		if ( null !== $previous ) {
			$this->stored_hash( $previous );
		}
		$before           = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'attachment_id'    => $attachment_id,
					'attachment_state' => 'scanning',
					'file_id'          => $file_id,
					'file_state'       => 'quarantined',
					'scan_state'       => 'pending',
				)
			)
		);
		$after            = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'attachment_id'    => $attachment_id,
					'attachment_state' => 'rejected',
					'file_id'          => $file_id,
					'file_state'       => 'rejected',
					'reason_code'      => $reason_code,
					'scan_state'       => 'rejected',
				)
			)
		);
		$metadata_json    = $this->canonical->encode(
			array(
				'file_id'     => $file_id,
				'reason_code' => $reason_code,
				'response_id' => $response_id,
				'scan_state'  => 'rejected',
			)
		);
		$metadata_hash    = hash( 'sha256', $metadata_json );
		$correlation_id   = 'attachment-scan:' . $attachment_id;
		$event_hash       = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'action_key'          => self::EVENT_TYPE,
					'actor_user_id'       => null,
					'after_digest'        => $after,
					'before_digest'       => $before,
					'correlation_id'      => $correlation_id,
					'metadata_hash'       => $metadata_hash,
					'object_public_id'    => $attachment_id,
					'object_type'         => 'submission_attachment',
					'occurred_at_utc'     => $occurred_at_utc,
					'previous_event_hash' => null === $previous ? null : (string) $previous,
				)
			)
		);
		$ids              = new Public_Id_Generator();
		$audit_public_id  = $ids->create()->value();
		$outbox_public_id = $ids->create()->value();
		$this->insert_or_fail(
			self::TABLE_AUDIT,
			array(
				'public_id'           => $audit_public_id,
				'occurred_at_utc'     => $occurred_at_utc,
				'actor_user_id'       => null,
				'action_key'          => self::EVENT_TYPE,
				'object_type'         => 'submission_attachment',
				'object_public_id'    => $attachment_id,
				'context_type'        => null,
				'context_id'          => null,
				'context_revision'    => null,
				'correlation_id'      => $correlation_id,
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
		$payload_json = $this->canonical->encode(
			array(
				'attachment_id' => $attachment_id,
				'file_id'       => $file_id,
				'reason_code'   => $reason_code,
				'response_id'   => $response_id,
				'scan_state'    => 'rejected',
				'state'         => 'rejected',
			)
		);
		$this->insert_or_fail(
			self::TABLE_OUTBOX,
			array(
				'public_id'            => $outbox_public_id,
				'lock_version'         => 0,
				'created_by_user_id'   => $owner_user_id,
				'created_at_utc'       => $occurred_at_utc,
				'updated_by_user_id'   => $owner_user_id,
				'updated_at_utc'       => $occurred_at_utc,
				'aggregate_type'       => 'submission',
				'aggregate_public_id'  => $submission_id,
				'event_type'           => self::EVENT_TYPE,
				'idempotency_key'      => 'attachment-scan:' . $attachment_id,
				'payload_schema'       => self::PAYLOAD_SCHEMA,
				'payload_version'      => self::VERSION,
				'payload_json'         => $payload_json,
				'payload_hash'         => hash( 'sha256', $payload_json ),
				'available_at_utc'     => $occurred_at_utc,
				'published_at_utc'     => null,
				'lease_token'          => null,
				'lease_expires_at_utc' => null,
				'attempt_count'        => 0,
				'last_error_code'      => null,
			)
		);
	}

	/**
	 * Candidate.
	 *
	 * @param string $stale_before_utc Stale before utc.
	 * @return array<string,mixed>|null
	 */
	private function candidate( string $stale_before_utc ): ?array {
		$sql = 'SELECT a.id AS attachment_pk, a.public_id AS attachment_id, a.lock_version AS attachment_lock_version, a.response_key AS response_id, a.file_public_id AS file_id, a.original_name AS attachment_original_name, a.mime_type AS attachment_mime_type, a.byte_size AS attachment_byte_size, a.sha256 AS attachment_sha256, s.public_id AS submission_id, f.id AS private_file_pk, f.lock_version AS file_lock_version, f.created_by_user_id AS owner_user_id, f.storage_key, f.original_name AS file_original_name, f.mime_type AS file_mime_type, f.byte_size AS file_byte_size, f.sha256 AS file_sha256 FROM ' . self::TABLE_ATTACHMENTS . ' a INNER JOIN ' . self::TABLE_SUBMISSIONS . ' s ON s.id = a.submission_id '
			. 'INNER JOIN ' . self::TABLE_PRIVATE_FILES . ' f ON f.public_id = a.file_public_id '
			. 'WHERE a.state = %s AND a.submission_revision_id IS NULL AND a.bound_at_utc IS NULL '
			. 'AND f.state = %s AND f.scan_state = %s AND f.owner_type = %s AND f.owner_public_id = s.public_id '
			. 'AND f.purpose = %s AND f.retention_class = %s AND f.purged_at_utc IS NULL '
			. 'AND (f.lock_version = 0 OR f.updated_at_utc <= %s) ORDER BY f.id ASC LIMIT 1';
		return $this->get_row(
			$this->prepare(
				$sql,
				array( 'scanning', 'quarantined', 'pending', 'submission', 'submission_attachment', 'submission_staging', $stale_before_utc )
			)
		);
	}

	/**
	 * Item.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return Submission_Attachment_Scan_Item
	 */
	private function item( array $row ): Submission_Attachment_Scan_Item {
		return new Submission_Attachment_Scan_Item(
			array(
				'attachment_byte_size'     => $this->positive_integer( $row['attachment_byte_size'] ?? null ),
				'attachment_id'            => $this->stored_public_id( $row['attachment_id'] ?? null ),
				'attachment_lock_version'  => $this->non_negative_integer( $row['attachment_lock_version'] ?? null ),
				'attachment_mime_type'     => $this->stored_mime( $row['attachment_mime_type'] ?? null ),
				'attachment_original_name' => $this->stored_filename( $row['attachment_original_name'] ?? null ),
				'attachment_sha256'        => $this->stored_hash( $row['attachment_sha256'] ?? null ),
				'claimed_at_utc'           => $this->required_utc( $row['claimed_at_utc'] ?? null ),
				'file_byte_size'           => $this->positive_integer( $row['file_byte_size'] ?? null ),
				'file_id'                  => $this->stored_public_id( $row['file_id'] ?? null ),
				'file_lock_version'        => $this->non_negative_integer( $row['file_lock_version'] ?? null ),
				'file_mime_type'           => $this->stored_mime_or_empty( $row['file_mime_type'] ?? null ),
				'file_original_name'       => $this->stored_filename_or_empty( $row['file_original_name'] ?? null ),
				'file_sha256'              => $this->stored_hash_or_zero( $row['file_sha256'] ?? null ),
				'owner_user_id'            => $this->positive_integer( $row['owner_user_id'] ?? null ),
				'response_id'              => $this->stored_public_id( $row['response_id'] ?? null ),
				'storage_key'              => $this->stored_storage_key( $row['storage_key'] ?? null ),
				'submission_id'            => $this->stored_public_id( $row['submission_id'] ?? null ),
			)
		);
	}

	/**
	 * Attachment.
	 *
	 * @param string $attachment_id Attachment id.
	 * @param bool   $for_update For update.
	 * @return array<string,mixed>|null
	 */
	private function attachment( string $attachment_id, bool $for_update ): ?array {
		$sql = 'SELECT id, public_id, lock_version, submission_id, submission_revision_id, response_key, file_public_id, state, original_name, mime_type, byte_size, sha256, bound_at_utc FROM ' . self::TABLE_ATTACHMENTS
			. ' WHERE public_id = %s LIMIT 1';
		if ( $for_update ) {
			$sql .= ' FOR UPDATE';
		}
		return $this->get_row( $this->prepare( $sql, array( $attachment_id ) ) );
	}

	/**
	 * Private file.
	 *
	 * @param string $file_id File id.
	 * @param bool   $for_update For update.
	 * @return array<string,mixed>|null
	 */
	private function private_file( string $file_id, bool $for_update ): ?array {
		$sql = 'SELECT id, public_id, lock_version, created_by_user_id, owner_type, owner_public_id, purpose, storage_key, original_name, mime_type, byte_size, sha256, state, scan_state, retention_class, purged_at_utc FROM '
			. self::TABLE_PRIVATE_FILES
			. ' WHERE public_id = %s LIMIT 1';
		if ( $for_update ) {
			$sql .= ' FOR UPDATE';
		}
		return $this->get_row( $this->prepare( $sql, array( $file_id ) ) );
	}

	/**
	 * Same claim.
	 *
	 * @param Submission_Attachment_Scan_Item $item Item.
	 * @param array<string,mixed>             $attachment Attachment.
	 * @param array<string,mixed>             $file File.
	 * @return bool
	 */
	private function same_claim( Submission_Attachment_Scan_Item $item, array $attachment, array $file ): bool {
		return hash_equals( $item->attachment_id(), (string) ( $attachment['public_id'] ?? '' ) )
			&& $item->attachment_lock_version() === $this->non_negative_integer( $attachment['lock_version'] ?? null )
			&& 'scanning' === ( $attachment['state'] ?? null )
			&& null === ( $attachment['submission_revision_id'] ?? null )
			&& null === ( $attachment['bound_at_utc'] ?? null )
			&& hash_equals( $item->file_id(), (string) ( $attachment['file_public_id'] ?? '' ) )
			&& hash_equals( $item->response_id(), (string) ( $attachment['response_key'] ?? '' ) )
			&& hash_equals( $item->original_name(), (string) ( $attachment['original_name'] ?? '' ) )
			&& hash_equals( $item->mime_type(), (string) ( $attachment['mime_type'] ?? '' ) )
			&& $item->byte_size() === $this->positive_integer( $attachment['byte_size'] ?? null )
			&& hash_equals( $item->sha256(), (string) ( $attachment['sha256'] ?? '' ) )
			&& hash_equals( $item->file_id(), (string) ( $file['public_id'] ?? '' ) )
			&& $item->file_lock_version() === $this->non_negative_integer( $file['lock_version'] ?? null )
			&& $item->owner_user_id() === $this->positive_integer( $file['created_by_user_id'] ?? null )
			&& 'quarantined' === ( $file['state'] ?? null )
			&& 'pending' === ( $file['scan_state'] ?? null )
			&& 'submission' === ( $file['owner_type'] ?? null )
			&& hash_equals( $item->submission_id(), (string) ( $file['owner_public_id'] ?? '' ) )
			&& 'submission_attachment' === ( $file['purpose'] ?? null )
			&& 'submission_staging' === ( $file['retention_class'] ?? null )
			&& null === ( $file['purged_at_utc'] ?? null )
			&& hash_equals( $item->storage_key(), (string) ( $file['storage_key'] ?? '' ) )
			&& hash_equals( $item->file_original_name(), (string) ( $file['original_name'] ?? '' ) )
			&& hash_equals( $item->file_mime_type(), (string) ( $file['mime_type'] ?? '' ) )
			&& $item->file_byte_size() === $this->positive_integer( $file['byte_size'] ?? null )
			&& hash_equals( $item->file_sha256(), (string) ( $file['sha256'] ?? '' ) );
	}

	/**
	 * Insert audit.
	 *
	 * @param Submission_Attachment_Scan_Item $item Item.
	 * @param string                          $outcome Outcome.
	 * @param string                          $scan_state Scan state.
	 * @param string|null                     $reason_code Reason code.
	 * @param string                          $public_id Public id.
	 * @param string                          $occurred_at_utc Occurred at utc.
	 * @return void
	 */
	private function insert_audit(
		Submission_Attachment_Scan_Item $item,
		string $outcome,
		string $scan_state,
		?string $reason_code,
		string $public_id,
		string $occurred_at_utc
	): void {
		$previous = $this->get_var( 'SELECT event_hash FROM ' . self::TABLE_AUDIT . ' ORDER BY id DESC LIMIT 1 FOR UPDATE' );
		if ( null !== $previous ) {
			$this->stored_hash( $previous );
		}
		$before         = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'attachment_id'           => $item->attachment_id(),
					'attachment_lock_version' => $item->attachment_lock_version(),
					'attachment_state'        => 'scanning',
					'file_id'                 => $item->file_id(),
					'file_lock_version'       => $item->file_lock_version(),
					'file_state'              => 'quarantined',
					'scan_state'              => 'pending',
				)
			)
		);
		$after          = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'attachment_id'    => $item->attachment_id(),
					'attachment_state' => $outcome,
					'file_id'          => $item->file_id(),
					'file_state'       => $outcome,
					'reason_code'      => $reason_code,
					'scan_state'       => $scan_state,
				)
			)
		);
		$metadata_json  = $this->canonical->encode(
			array(
				'file_id'     => $item->file_id(),
				'reason_code' => $reason_code,
				'response_id' => $item->response_id(),
				'scan_state'  => $scan_state,
			)
		);
		$metadata_hash  = hash( 'sha256', $metadata_json );
		$correlation_id = 'attachment-scan:' . $item->attachment_id();
		$event_hash     = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'action_key'          => self::EVENT_TYPE,
					'actor_user_id'       => null,
					'after_digest'        => $after,
					'before_digest'       => $before,
					'correlation_id'      => $correlation_id,
					'metadata_hash'       => $metadata_hash,
					'object_public_id'    => $item->attachment_id(),
					'object_type'         => 'submission_attachment',
					'occurred_at_utc'     => $occurred_at_utc,
					'previous_event_hash' => null === $previous ? null : (string) $previous,
				)
			)
		);
		$this->insert_or_fail(
			self::TABLE_AUDIT,
			array(
				'public_id'           => $public_id,
				'occurred_at_utc'     => $occurred_at_utc,
				'actor_user_id'       => null,
				'action_key'          => self::EVENT_TYPE,
				'object_type'         => 'submission_attachment',
				'object_public_id'    => $item->attachment_id(),
				'context_type'        => null,
				'context_id'          => null,
				'context_revision'    => null,
				'correlation_id'      => $correlation_id,
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
	 * @param Submission_Attachment_Scan_Item $item Item.
	 * @param string                          $outcome Outcome.
	 * @param string                          $scan_state Scan state.
	 * @param string|null                     $reason_code Reason code.
	 * @param string                          $public_id Public id.
	 * @param string                          $occurred_at_utc Occurred at utc.
	 * @return void
	 */
	private function insert_outbox(
		Submission_Attachment_Scan_Item $item,
		string $outcome,
		string $scan_state,
		?string $reason_code,
		string $public_id,
		string $occurred_at_utc
	): void {
		$payload_json = $this->canonical->encode(
			array(
				'attachment_id' => $item->attachment_id(),
				'file_id'       => $item->file_id(),
				'reason_code'   => $reason_code,
				'response_id'   => $item->response_id(),
				'scan_state'    => $scan_state,
				'state'         => $outcome,
			)
		);
		$this->insert_or_fail(
			self::TABLE_OUTBOX,
			array(
				'public_id'            => $public_id,
				'lock_version'         => 0,
				'created_by_user_id'   => $item->owner_user_id(),
				'created_at_utc'       => $occurred_at_utc,
				'updated_by_user_id'   => $item->owner_user_id(),
				'updated_at_utc'       => $occurred_at_utc,
				'aggregate_type'       => 'submission',
				'aggregate_public_id'  => $item->submission_id(),
				'event_type'           => self::EVENT_TYPE,
				'idempotency_key'      => 'attachment-scan:' . $item->attachment_id(),
				'payload_schema'       => self::PAYLOAD_SCHEMA,
				'payload_version'      => self::VERSION,
				'payload_json'         => $payload_json,
				'payload_hash'         => hash( 'sha256', $payload_json ),
				'available_at_utc'     => $occurred_at_utc,
				'published_at_utc'     => null,
				'lease_token'          => null,
				'lease_expires_at_utc' => null,
				'attempt_count'        => 0,
				'last_error_code'      => null,
			)
		);
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
		}
		try {
			return ( new Public_Id( $value ) )->value(); } catch ( Throwable ) {
			$this->stored_invalid();
			}
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
		}
		return $value;
	}

	/**
	 * Stored hash or zero.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function stored_hash_or_zero( mixed $value ): string {
		return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $value ) ? $value : str_repeat( '0', 64 );
	}

	/**
	 * Stored mime.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function stored_mime( mixed $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9!#$&^_.+-]*\/[A-Za-z0-9][A-Za-z0-9!#$&^_.+-]*$/D', $value ) ) {
			$this->stored_invalid();
		}
		return $value;
	}

	/**
	 * Stored mime or empty.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function stored_mime_or_empty( mixed $value ): string {
		return is_string( $value ) && 1 === preg_match( '/^[A-Za-z0-9][A-Za-z0-9!#$&^_.+-]*\/[A-Za-z0-9][A-Za-z0-9!#$&^_.+-]*$/D', $value ) ? $value : 'application/octet-stream';
	}

	/**
	 * Stored filename.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function stored_filename( mixed $value ): string {
		if ( ! is_string( $value ) || '' === $value || strlen( $value ) > 255 || str_contains( $value, "\0" ) ) {
			$this->stored_invalid();
		}
		return $value;
	}

	/**
	 * Stored filename or empty.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function stored_filename_or_empty( mixed $value ): string {
		return is_string( $value ) && strlen( $value ) <= 255 && ! str_contains( $value, "\0" ) ? $value : '';
	}

	/**
	 * Stored storage key.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function stored_storage_key( mixed $value ): string {
		if (
			! is_string( $value ) || strlen( $value ) > 512
			|| 1 !== preg_match( '/^[a-z0-9][a-z0-9._\/-]*$/D', $value )
			|| in_array( '..', explode( '/', $value ), true )
		) {
			$this->stored_invalid();
		}
		return $value;
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
		}
		if ( ! is_int( $value ) || $value < 1 ) {
			$this->stored_invalid();
		}
		return $value;
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
		}
		if ( ! is_int( $value ) || $value < 0 ) {
			$this->stored_invalid();
		}
		return $value;
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
		}
		return $value;
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
			throw new InvalidArgumentException( 'Submission attachment scan UTC value is invalid.' );
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
	 * @param array<int,mixed> $arguments Arguments.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function prepare( string $sql, array $arguments ): string {
		$result = $this->database()->prepare( $sql, ...$arguments );
		if ( ! is_string( $result ) || '' === $result ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORAGE_UNAVAILABLE );
		}
		return $result;
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
		$this->assert_database_ok( $db );
		if ( null === $row ) {
			return null;
		}
		if ( ! is_array( $row ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORAGE_UNAVAILABLE );
		}
		return $row;
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
		$this->assert_database_ok( $db );
		return $value;
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
		if ( false === $db->query( $sql ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORAGE_UNAVAILABLE );
		}
		$this->assert_database_ok( $db );
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
		$this->assert_database_ok( $db );
		if ( ! is_int( $changed ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORAGE_UNAVAILABLE );
		}
		return $changed;
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
		$written = $db->insert( $table, $data );
		$this->assert_database_ok( $db );
		if ( 1 !== $written ) {
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
	 * Database.
	 *
	 * @return object
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function database(): object {
		$db = $this->database;
		if ( null === $db ) {
			global $wpdb;
			$db = isset( $wpdb ) && is_object( $wpdb ) ? $wpdb : null;
		}
		if ( ! is_object( $db ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORAGE_UNAVAILABLE );
		}
		foreach ( array( 'get_row', 'get_var', 'insert', 'prepare', 'query' ) as $method ) {
			if ( ! method_exists( $db, $method ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Assignment_Failure::because( Assignment_Failure::STORAGE_UNAVAILABLE );
			}
		}
		return $db;
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
	 * Reject invalid database ok.
	 *
	 * @param object $db Db.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_database_ok( object $db ): void {
		if ( '' !== trim( (string) ( $db->last_error ?? '' ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORAGE_UNAVAILABLE );
		}
	}
}
