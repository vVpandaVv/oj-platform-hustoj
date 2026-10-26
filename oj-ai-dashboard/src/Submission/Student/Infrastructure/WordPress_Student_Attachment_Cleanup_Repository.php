<?php
/**
 * Durable expiration and purge repository for submission attachments.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Student\Infrastructure;

use InvalidArgumentException;
use OJAI\Dashboard\Submission\Assignment\Application\Assignment_Failure;
use OJAI\Dashboard\Submission\Student\Domain\Submission_Attachment_Cleanup_Item;
use OJAI\Dashboard\Submission\Student\Port\Student_Attachment_Cleanup_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps legal-hold checks and binary deletion inside the same private-file row lock.
 */
final class WordPress_Student_Attachment_Cleanup_Repository implements Student_Attachment_Cleanup_Repository_Port_Interface {
	private const TABLE_SUBMISSIONS   = 'oj_ai_submissions';
	private const TABLE_ATTACHMENTS   = 'oj_ai_submission_attachments';
	private const TABLE_PRIVATE_FILES = 'oj_ai_private_files';
	private const TABLE_AUDIT         = 'oj_ai_audit_events';
	private const TABLE_OUTBOX        = 'oj_ai_outbox_events';

	private const EVENT_EXPIRED  = 'submission.attachment.expired';
	private const EVENT_PURGED   = 'submission.attachment.purged';
	private const PAYLOAD_SCHEMA = 'oj_ai.submission_attachment_cleanup_event';
	private const AUDIT_SCHEMA   = 'oj_ai.submission_attachment_cleanup_audit';
	private const VERSION        = 1;

	/**
	 * Canonical JSON encoder used for stable digests.
	 *
	 * @var Canonical_Json
	 */
	private Canonical_Json $canonical;

	/**
	 * Initialize WordPress student attachment cleanup repository dependencies and state.
	 *
	 * @param object|null         $database Database.
	 * @param Canonical_Json|null $canonical Canonical JSON encoder used for stable digests.
	 */
	public function __construct( private ?object $database = null, ?Canonical_Json $canonical = null ) {
		$this->canonical = $canonical ?? new Canonical_Json();
	}

	/**
	 * Claim purge due.
	 *
	 * @param string $now_utc Now utc.
	 * @param string $stale_before_utc Stale before utc.
	 * @return Submission_Attachment_Cleanup_Item|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function claim_purge_due( string $now_utc, string $stale_before_utc ): ?Submission_Attachment_Cleanup_Item {
		$this->assert_utc( $now_utc );
		$this->assert_utc( $stale_before_utc );
		if ( $stale_before_utc > $now_utc ) {
			throw new InvalidArgumentException( 'Submission attachment cleanup retry window is invalid.' );
		}

		$row = $this->purge_candidate( $stale_before_utc );
		if ( null === $row ) {
			return null;
		}
		$file_pk       = $this->positive_integer( $row['private_file_pk'] ?? null );
		$file_id       = $this->stored_public_id( $row['file_id'] ?? null );
		$file_lock     = $this->non_negative_integer( $row['file_lock_version'] ?? null );
		$owner_user_id = $this->positive_integer( $row['owner_user_id'] ?? null );
		$submission_id = $this->stored_public_id( $row['submission_id'] ?? null );
		$changed       = $this->change_count(
			$this->prepare(
				'UPDATE ' . self::TABLE_PRIVATE_FILES . ' SET lock_version = lock_version + 1, updated_by_user_id = %d, updated_at_utc = %s '
				. 'WHERE id = %d AND public_id = %s AND lock_version = %d AND state = %s AND owner_type = %s '
				. 'AND owner_public_id = %s AND purpose = %s AND retention_class = %s '
				. 'AND legal_hold_until_utc IS NULL AND purged_at_utc IS NULL',
				array(
					$owner_user_id,
					$now_utc,
					$file_pk,
					$file_id,
					$file_lock,
					'expired',
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
		$row['file_lock_version'] = $file_lock + 1;
		$row['claimed_at_utc']    = $now_utc;
		return $this->item( $row );
	}

	/**
	 * Expire due.
	 *
	 * @param string $now_utc Now utc.
	 * @param string $audit_public_id Audit public id.
	 * @param string $outbox_public_id Outbox public id.
	 * @return Submission_Attachment_Cleanup_Item|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function expire_due(
		string $now_utc,
		string $audit_public_id,
		string $outbox_public_id
	): ?Submission_Attachment_Cleanup_Item {
		$this->assert_utc( $now_utc );
		new Public_Id( $audit_public_id );
		new Public_Id( $outbox_public_id );
		$candidate = $this->expiration_candidate( $now_utc );
		if ( null === $candidate ) {
			return null;
		}
		$file_id = $this->stored_public_id( $candidate['file_id'] ?? null );

		$transaction = false;
		try {
			$this->query_or_fail( 'START TRANSACTION' );
			$transaction = true;

			// All cleanup paths acquire the attachment lock before the private-file lock.
			$attachment = $this->attachment_by_file( $file_id, true );
			$file       = $this->private_file( $file_id, true );
			if ( null === $file || ! $this->same_expiration_candidate( $candidate, $attachment, $file, $now_utc ) ) {
				$this->rollback();
				return null;
			}

			$owner_user_id     = $this->positive_integer( $file['created_by_user_id'] ?? null );
			$attachment_lock   = null;
			$attachment_id     = null;
			$attachment_before = null;
			if ( null !== $attachment ) {
				$attachment_id      = $this->stored_public_id( $attachment['public_id'] ?? null );
				$attachment_lock    = $this->non_negative_integer( $attachment['lock_version'] ?? null );
				$attachment_before  = (string) ( $attachment['state'] ?? '' );
				$attachment_changed = $this->change_count(
					$this->prepare(
						'UPDATE ' . self::TABLE_ATTACHMENTS . ' SET state = %s, lock_version = lock_version + 1, '
						. 'updated_by_user_id = %d, updated_at_utc = %s WHERE id = %d AND public_id = %s '
						. 'AND file_public_id = %s AND lock_version = %d AND state = %s '
						. 'AND submission_revision_id IS NULL AND bound_at_utc IS NULL',
						array(
							'expired',
							$owner_user_id,
							$now_utc,
							$this->positive_integer( $attachment['id'] ?? null ),
							$attachment_id,
							$file_id,
							$attachment_lock,
							$attachment_before,
						)
					)
				);
				if ( 1 !== $attachment_changed ) {
					$this->rollback();
					return null;
				}
				++$attachment_lock;
			}

			$file_lock    = $this->non_negative_integer( $file['lock_version'] ?? null );
			$file_before  = (string) ( $file['state'] ?? '' );
			$file_changed = $this->change_count(
				$this->prepare(
					'UPDATE ' . self::TABLE_PRIVATE_FILES . ' SET state = %s, lock_version = lock_version + 1, '
					. 'updated_by_user_id = %d, updated_at_utc = %s WHERE id = %d AND public_id = %s '
					. 'AND lock_version = %d AND state = %s AND scan_state = %s AND owner_type = %s '
					. 'AND owner_public_id = %s AND purpose = %s AND retention_class = %s '
					. 'AND expires_at_utc IS NOT NULL AND expires_at_utc <= %s '
					. 'AND legal_hold_until_utc IS NULL AND purged_at_utc IS NULL',
					array(
						'expired',
						$owner_user_id,
						$now_utc,
						$this->positive_integer( $file['id'] ?? null ),
						$file_id,
						$file_lock,
						$file_before,
						(string) $file['scan_state'],
						'submission',
						(string) $file['owner_public_id'],
						'submission_attachment',
						'submission_staging',
						$now_utc,
					)
				)
			);
			if ( 1 !== $file_changed ) {
				$this->rollback();
				return null;
			}

			$item = $this->item(
				array(
					'attachment_id'           => $attachment_id,
					'attachment_lock_version' => $attachment_lock,
					'claimed_at_utc'          => $now_utc,
					'file_id'                 => $file_id,
					'file_lock_version'       => $file_lock + 1,
					'owner_user_id'           => $owner_user_id,
					'scan_state'              => $file['scan_state'],
					'sha256'                  => $file['sha256'],
					'storage_key'             => $file['storage_key'],
					'submission_id'           => $file['owner_public_id'],
				)
			);
			$this->insert_audit( $item, self::EVENT_EXPIRED, $attachment_before, $file_before, $audit_public_id, $now_utc );
			$this->insert_outbox( $item, self::EVENT_EXPIRED, $outbox_public_id, $now_utc );
			$this->query_or_fail( 'COMMIT' );
			$transaction = false;
			return $item;
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
	 * Purge claimed.
	 *
	 * @param Submission_Attachment_Cleanup_Item $item Item.
	 * @param callable                           $delete Delete.
	 * @param string                             $audit_public_id Audit public id.
	 * @param string                             $outbox_public_id Outbox public id.
	 * @param string                             $occurred_at_utc Occurred at utc.
	 * @return bool
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function purge_claimed(
		Submission_Attachment_Cleanup_Item $item,
		callable $delete,
		string $audit_public_id,
		string $outbox_public_id,
		string $occurred_at_utc
	): bool {
		new Public_Id( $audit_public_id );
		new Public_Id( $outbox_public_id );
		$this->assert_utc( $occurred_at_utc );

		$transaction = false;
		try {
			$this->query_or_fail( 'START TRANSACTION' );
			$transaction = true;

			// Keep this lock order identical to expiration to avoid cleanup deadlocks.
			$attachment = $this->attachment_by_file( $item->file_id(), true );
			$file       = $this->private_file( $item->file_id(), true );
			if ( null === $file || ! $this->same_purge_claim( $item, $attachment, $file ) ) {
				$this->rollback();
				return false;
			}

			// The delete is idempotent and runs only after legal hold was rechecked under lock.
			$delete( $item->storage_key() );
			$file_changed = $this->change_count(
				$this->prepare(
					'UPDATE ' . self::TABLE_PRIVATE_FILES . ' SET state = %s, lock_version = lock_version + 1, '
					. 'updated_by_user_id = %d, updated_at_utc = %s, expires_at_utc = NULL, purged_at_utc = %s '
					. 'WHERE id = %d AND public_id = %s AND lock_version = %d AND state = %s AND scan_state = %s '
					. 'AND owner_type = %s AND owner_public_id = %s AND purpose = %s AND retention_class = %s '
					. 'AND legal_hold_until_utc IS NULL AND purged_at_utc IS NULL',
					array(
						'purged',
						$item->owner_user_id(),
						$occurred_at_utc,
						$occurred_at_utc,
						$this->positive_integer( $file['id'] ?? null ),
						$item->file_id(),
						$item->file_lock_version(),
						'expired',
						$item->scan_state(),
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

			$this->insert_audit( $item, self::EVENT_PURGED, null === $attachment ? null : 'expired', 'expired', $audit_public_id, $occurred_at_utc );
			$this->insert_outbox( $item, self::EVENT_PURGED, $outbox_public_id, $occurred_at_utc );
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
	 * Expiration candidate.
	 *
	 * @param string $now_utc Now utc.
	 * @return array<string,mixed>|null
	 */
	private function expiration_candidate( string $now_utc ): ?array {
		$sql = 'SELECT f.id AS private_file_pk, f.public_id AS file_id, f.lock_version AS file_lock_version, f.created_by_user_id AS owner_user_id, f.owner_public_id AS submission_id, f.storage_key, f.sha256, f.scan_state, a.public_id AS attachment_id, a.lock_version AS attachment_lock_version FROM ' . self::TABLE_PRIVATE_FILES . ' f LEFT JOIN ' . self::TABLE_ATTACHMENTS . ' a ON a.file_public_id = f.public_id '
			. 'LEFT JOIN ' . self::TABLE_SUBMISSIONS . ' s ON s.id = a.submission_id '
			. 'WHERE f.owner_type = %s AND f.purpose = %s AND f.retention_class = %s '
			. 'AND f.state IN (%s,%s,%s) AND f.scan_state IN (%s,%s,%s) '
			. 'AND f.expires_at_utc IS NOT NULL AND f.expires_at_utc <= %s '
			. 'AND f.legal_hold_until_utc IS NULL AND f.purged_at_utc IS NULL '
			. 'AND (a.id IS NULL OR (a.state IN (%s,%s,%s,%s) AND a.submission_revision_id IS NULL '
			. 'AND a.bound_at_utc IS NULL AND a.created_by_user_id = f.created_by_user_id AND s.public_id = f.owner_public_id)) '
			. 'ORDER BY f.expires_at_utc ASC, f.id ASC LIMIT 1';
		return $this->get_row(
			$this->prepare(
				$sql,
				array(
					'submission',
					'submission_attachment',
					'submission_staging',
					'quarantined',
					'ready',
					'rejected',
					'pending',
					'clean',
					'rejected',
					$now_utc,
					'staged',
					'scanning',
					'ready',
					'rejected',
				)
			)
		);
	}

	/**
	 * Purge candidate.
	 *
	 * @param string $stale_before_utc Stale before utc.
	 * @return array<string,mixed>|null
	 */
	private function purge_candidate( string $stale_before_utc ): ?array {
		$sql = 'SELECT f.id AS private_file_pk, f.public_id AS file_id, f.lock_version AS file_lock_version, f.created_by_user_id AS owner_user_id, f.owner_public_id AS submission_id, f.storage_key, f.sha256, f.scan_state, a.public_id AS attachment_id, a.lock_version AS attachment_lock_version FROM ' . self::TABLE_PRIVATE_FILES . ' f LEFT JOIN ' . self::TABLE_ATTACHMENTS . ' a ON a.file_public_id = f.public_id '
			. 'LEFT JOIN ' . self::TABLE_SUBMISSIONS . ' s ON s.id = a.submission_id '
			. 'WHERE f.owner_type = %s AND f.purpose = %s AND f.retention_class = %s '
			. 'AND f.state = %s AND f.scan_state IN (%s,%s,%s) AND f.updated_at_utc <= %s '
			. 'AND f.legal_hold_until_utc IS NULL AND f.purged_at_utc IS NULL '
			. 'AND (a.id IS NULL OR (a.state = %s AND a.submission_revision_id IS NULL AND a.bound_at_utc IS NULL '
			. 'AND a.created_by_user_id = f.created_by_user_id AND s.public_id = f.owner_public_id)) '
			. 'ORDER BY f.updated_at_utc ASC, f.id ASC LIMIT 1';
		return $this->get_row(
			$this->prepare(
				$sql,
				array(
					'submission',
					'submission_attachment',
					'submission_staging',
					'expired',
					'pending',
					'clean',
					'rejected',
					$stale_before_utc,
					'expired',
				)
			)
		);
	}

	/**
	 * Attachment by file.
	 *
	 * @param string $file_id File id.
	 * @param bool   $for_update For update.
	 * @return array<string,mixed>|null
	 */
	private function attachment_by_file( string $file_id, bool $for_update ): ?array {
		$sql = 'SELECT a.id, a.public_id, a.lock_version, a.created_by_user_id, a.submission_id, a.submission_revision_id, a.file_public_id, a.state, a.bound_at_utc, s.public_id AS submission_public_id FROM ' . self::TABLE_ATTACHMENTS . ' a LEFT JOIN ' . self::TABLE_SUBMISSIONS . ' s ON s.id = a.submission_id '
			. 'WHERE a.file_public_id = %s LIMIT 1';
		if ( $for_update ) {
			$sql .= ' FOR UPDATE';
		}
		return $this->get_row( $this->prepare( $sql, array( $file_id ) ) );
	}

	/**
	 * Private file.
	 *
	 * @param string $file_id File id.
	 * @param bool   $for_update For update.
	 * @return array<string,mixed>|null
	 */
	private function private_file( string $file_id, bool $for_update ): ?array {
		$sql = 'SELECT id, public_id, lock_version, created_by_user_id, owner_type, owner_public_id, purpose, storage_key, sha256, state, scan_state, retention_class, expires_at_utc, legal_hold_until_utc, purged_at_utc FROM '
			. self::TABLE_PRIVATE_FILES . ' WHERE public_id = %s LIMIT 1';
		if ( $for_update ) {
			$sql .= ' FOR UPDATE';
		}
		return $this->get_row( $this->prepare( $sql, array( $file_id ) ) );
	}

	/**
	 * Same expiration candidate.
	 *
	 * @param array<string,mixed>      $candidate Candidate.
	 * @param array<string,mixed>|null $attachment Attachment.
	 * @param array<string,mixed>      $file File.
	 * @param string                   $now_utc Now utc.
	 * @return bool
	 */
	private function same_expiration_candidate( array $candidate, ?array $attachment, array $file, string $now_utc ): bool {
		$file_id       = $this->stored_public_id( $candidate['file_id'] ?? null );
		$owner_user_id = $this->positive_integer( $file['created_by_user_id'] ?? null );
		$expires_at    = $file['expires_at_utc'] ?? null;
		if (
			! hash_equals( $file_id, (string) ( $file['public_id'] ?? '' ) )
			|| $this->non_negative_integer( $candidate['file_lock_version'] ?? null ) !== $this->non_negative_integer( $file['lock_version'] ?? null )
			|| $this->positive_integer( $candidate['owner_user_id'] ?? null ) !== $owner_user_id
			|| 'submission' !== ( $file['owner_type'] ?? null )
			|| ! hash_equals( $this->stored_public_id( $candidate['submission_id'] ?? null ), (string) ( $file['owner_public_id'] ?? '' ) )
			|| 'submission_attachment' !== ( $file['purpose'] ?? null )
			|| 'submission_staging' !== ( $file['retention_class'] ?? null )
			|| ! in_array( $file['state'] ?? null, array( 'quarantined', 'ready', 'rejected' ), true )
			|| ! in_array( $file['scan_state'] ?? null, array( 'pending', 'clean', 'rejected' ), true )
			|| ! is_string( $expires_at ) || $expires_at > $now_utc
			|| null !== ( $file['legal_hold_until_utc'] ?? null ) || null !== ( $file['purged_at_utc'] ?? null )
		) {
			return false;
		}

		if ( null === $attachment ) {
			return null === ( $candidate['attachment_id'] ?? null );
		}
		return null !== ( $candidate['attachment_id'] ?? null )
			&& hash_equals( $this->stored_public_id( $candidate['attachment_id'] ), (string) ( $attachment['public_id'] ?? '' ) )
			&& $owner_user_id === $this->positive_integer( $attachment['created_by_user_id'] ?? null )
			&& hash_equals( $file_id, (string) ( $attachment['file_public_id'] ?? '' ) )
			&& hash_equals( (string) $file['owner_public_id'], (string) ( $attachment['submission_public_id'] ?? '' ) )
			&& in_array( $attachment['state'] ?? null, array( 'staged', 'scanning', 'ready', 'rejected' ), true )
			&& null === ( $attachment['submission_revision_id'] ?? null )
			&& null === ( $attachment['bound_at_utc'] ?? null );
	}

	/**
	 * Same purge claim.
	 *
	 * @param Submission_Attachment_Cleanup_Item $item Item.
	 * @param array<string,mixed>|null           $attachment Attachment.
	 * @param array<string,mixed>                $file File.
	 * @return bool
	 */
	private function same_purge_claim( Submission_Attachment_Cleanup_Item $item, ?array $attachment, array $file ): bool {
		if (
			! hash_equals( $item->file_id(), (string) ( $file['public_id'] ?? '' ) )
			|| $item->file_lock_version() !== $this->non_negative_integer( $file['lock_version'] ?? null )
			|| $item->owner_user_id() !== $this->positive_integer( $file['created_by_user_id'] ?? null )
			|| 'submission' !== ( $file['owner_type'] ?? null )
			|| ! hash_equals( $item->submission_id(), (string) ( $file['owner_public_id'] ?? '' ) )
			|| 'submission_attachment' !== ( $file['purpose'] ?? null )
			|| 'submission_staging' !== ( $file['retention_class'] ?? null )
			|| 'expired' !== ( $file['state'] ?? null ) || $item->scan_state() !== ( $file['scan_state'] ?? null )
			|| null !== ( $file['legal_hold_until_utc'] ?? null ) || null !== ( $file['purged_at_utc'] ?? null )
			|| ! hash_equals( $item->storage_key(), (string) ( $file['storage_key'] ?? '' ) )
			|| ! hash_equals( $item->sha256(), (string) ( $file['sha256'] ?? '' ) )
		) {
			return false;
		}

		if ( null === $item->attachment_id() ) {
			return null === $attachment;
		}
		if ( null === $attachment ) {
			return false;
		}
		return hash_equals( $item->attachment_id(), (string) ( $attachment['public_id'] ?? '' ) )
			&& $item->attachment_lock_version() === $this->non_negative_integer( $attachment['lock_version'] ?? null )
			&& $item->owner_user_id() === $this->positive_integer( $attachment['created_by_user_id'] ?? null )
			&& hash_equals( $item->file_id(), (string) ( $attachment['file_public_id'] ?? '' ) )
			&& hash_equals( $item->submission_id(), (string) ( $attachment['submission_public_id'] ?? '' ) )
			&& 'expired' === ( $attachment['state'] ?? null )
			&& null === ( $attachment['submission_revision_id'] ?? null )
			&& null === ( $attachment['bound_at_utc'] ?? null );
	}

	/**
	 * Item.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return Submission_Attachment_Cleanup_Item
	 */
	private function item( array $row ): Submission_Attachment_Cleanup_Item {
		$attachment_id   = null === ( $row['attachment_id'] ?? null ) ? null : $this->stored_public_id( $row['attachment_id'] );
		$attachment_lock = null === $attachment_id ? null : $this->non_negative_integer( $row['attachment_lock_version'] ?? null );
		$scan_state      = $row['scan_state'] ?? null;
		if ( ! is_string( $scan_state ) || ! in_array( $scan_state, array( 'pending', 'clean', 'rejected' ), true ) ) {
			$this->stored_invalid();
		}
		return new Submission_Attachment_Cleanup_Item(
			array(
				'attachment_id'           => $attachment_id,
				'attachment_lock_version' => $attachment_lock,
				'claimed_at_utc'          => $this->required_utc( $row['claimed_at_utc'] ?? null ),
				'file_id'                 => $this->stored_public_id( $row['file_id'] ?? null ),
				'file_lock_version'       => $this->non_negative_integer( $row['file_lock_version'] ?? null ),
				'owner_user_id'           => $this->positive_integer( $row['owner_user_id'] ?? null ),
				'scan_state'              => $scan_state,
				'sha256'                  => $this->stored_hash( $row['sha256'] ?? null ),
				'storage_key'             => $this->stored_storage_key( $row['storage_key'] ?? null ),
				'submission_id'           => $this->stored_public_id( $row['submission_id'] ?? null ),
			)
		);
	}

	/**
	 * Insert audit.
	 *
	 * @param Submission_Attachment_Cleanup_Item $item Item.
	 * @param string                             $event_type Event type.
	 * @param string|null                        $attachment_before Attachment before.
	 * @param string                             $file_before File before.
	 * @param string                             $public_id Public id.
	 * @param string                             $occurred_at_utc Occurred at utc.
	 * @return void
	 */
	private function insert_audit(
		Submission_Attachment_Cleanup_Item $item,
		string $event_type,
		?string $attachment_before,
		string $file_before,
		string $public_id,
		string $occurred_at_utc
	): void {
		$previous = $this->get_var( 'SELECT event_hash FROM ' . self::TABLE_AUDIT . ' ORDER BY id DESC LIMIT 1 FOR UPDATE' );
		if ( null !== $previous ) {
			$this->stored_hash( $previous );
		}
		$file_after       = self::EVENT_PURGED === $event_type ? 'purged' : 'expired';
		$attachment_after = null === $item->attachment_id() ? null : 'expired';
		$before           = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'attachment_id'    => $item->attachment_id(),
					'attachment_state' => $attachment_before,
					'file_id'          => $item->file_id(),
					'file_state'       => $file_before,
					'scan_state'       => $item->scan_state(),
				)
			)
		);
		$after            = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'attachment_id'    => $item->attachment_id(),
					'attachment_state' => $attachment_after,
					'file_id'          => $item->file_id(),
					'file_state'       => $file_after,
					'scan_state'       => $item->scan_state(),
				)
			)
		);
		$metadata_json    = $this->canonical->encode(
			array(
				'attachment_id'   => $item->attachment_id(),
				'file_id'         => $item->file_id(),
				'retention_class' => 'submission_staging',
				'scan_state'      => $item->scan_state(),
			)
		);
		$metadata_hash    = hash( 'sha256', $metadata_json );
		$object_id        = $item->attachment_id() ?? $item->file_id();
		$object_type      = null === $item->attachment_id() ? 'private_file' : 'submission_attachment';
		$correlation_id   = 'attachment-cleanup:' . $item->file_id();
		$event_hash       = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'action_key'          => $event_type,
					'actor_user_id'       => null,
					'after_digest'        => $after,
					'before_digest'       => $before,
					'correlation_id'      => $correlation_id,
					'metadata_hash'       => $metadata_hash,
					'object_public_id'    => $object_id,
					'object_type'         => $object_type,
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
				'action_key'          => $event_type,
				'object_type'         => $object_type,
				'object_public_id'    => $object_id,
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
	 * @param Submission_Attachment_Cleanup_Item $item Item.
	 * @param string                             $event_type Event type.
	 * @param string                             $public_id Public id.
	 * @param string                             $occurred_at_utc Occurred at utc.
	 * @return void
	 */
	private function insert_outbox(
		Submission_Attachment_Cleanup_Item $item,
		string $event_type,
		string $public_id,
		string $occurred_at_utc
	): void {
		$file_state   = self::EVENT_PURGED === $event_type ? 'purged' : 'expired';
		$payload_json = $this->canonical->encode(
			array(
				'attachment_id'    => $item->attachment_id(),
				'attachment_state' => null === $item->attachment_id() ? null : 'expired',
				'file_id'          => $item->file_id(),
				'file_state'       => $file_state,
				'scan_state'       => $item->scan_state(),
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
				'event_type'           => $event_type,
				'idempotency_key'      => 'attachment-cleanup:' . $event_type . ':' . $item->file_id(),
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
			throw new InvalidArgumentException( 'Submission attachment cleanup UTC value is invalid.' );
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
