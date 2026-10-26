<?php
/**
 * Durable cleanup repository for submission ZIP staging files.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\ZipImport\Infrastructure;

use InvalidArgumentException;
use OJAI\Dashboard\Submission\ZipImport\Application\Zip_Import_Failure;
use OJAI\Dashboard\Submission\ZipImport\Domain\Submission_Zip_Cleanup_Item;
use OJAI\Dashboard\Submission\ZipImport\Port\Submission_Zip_Cleanup_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Locks root, item, and private file in the same order used by ZIP finalization.
 */
final class WordPress_Submission_Zip_Cleanup_Repository implements Submission_Zip_Cleanup_Repository_Port_Interface {
	private const TABLE_ASSIGNMENTS   = 'oj_ai_assignments';
	private const TABLE_UPLOADS       = 'oj_ai_zip_uploads';
	private const TABLE_ITEMS         = 'oj_ai_zip_upload_items';
	private const TABLE_PRIVATE_FILES = 'oj_ai_private_files';
	private const TABLE_AUDIT         = 'oj_ai_audit_events';
	private const TABLE_OUTBOX        = 'oj_ai_outbox_events';

	private const EVENT_PURGED   = 'submission_zip.file.purged';
	private const PAYLOAD_SCHEMA = 'oj_ai.submission_zip_cleanup_event';
	private const AUDIT_SCHEMA   = 'oj_ai.submission_zip_cleanup_audit';
	private const VERSION        = 1;

	/**
	 * Canonical JSON encoder used for stable digests.
	 *
	 * @var Canonical_Json
	 */
	private Canonical_Json $canonical;

	/**
	 * Initialize WordPress submission zip cleanup repository dependencies and state.
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
	 * @return Submission_Zip_Cleanup_Item|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function claim_due( string $now_utc, string $stale_before_utc ): ?Submission_Zip_Cleanup_Item {
		$this->assert_utc( $now_utc );
		$this->assert_utc( $stale_before_utc );
		if ( $stale_before_utc > $now_utc ) {
			throw new InvalidArgumentException( 'Submission ZIP cleanup retry window is invalid.' );
		}

		$row = $this->earliest_candidate(
			$this->source_candidate( $now_utc, $stale_before_utc ),
			$this->item_candidate( $now_utc, $stale_before_utc )
		);
		if ( null === $row ) {
			return null;
		}

		$file_id       = $this->stored_public_id( $row['file_id'] ?? null );
		$file_lock     = $this->non_negative_integer( $row['file_lock_version'] ?? null );
		$owner_user_id = $this->positive_integer( $row['owner_user_id'] ?? null );
		$changed       = $this->change_count(
			$this->prepare(
				'UPDATE ' . self::TABLE_PRIVATE_FILES . ' SET lock_version = lock_version + 1, updated_by_user_id = %d, updated_at_utc = %s '
				. 'WHERE id = %d AND public_id = %s AND lock_version = %d AND state = %s AND scan_state = %s '
				. 'AND owner_type = %s AND owner_public_id = %s AND purpose = %s AND retention_class = %s '
				. 'AND updated_at_utc <= %s AND legal_hold_until_utc IS NULL AND purged_at_utc IS NULL',
				array(
					$owner_user_id,
					$now_utc,
					$this->positive_integer( $row['private_file_pk'] ?? null ),
					$file_id,
					$file_lock,
					(string) $row['file_state'],
					(string) $row['scan_state'],
					(string) $row['file_owner_type'],
					(string) $row['file_owner_public_id'],
					$this->purpose( (string) $row['file_kind'] ),
					'submission_zip_staging',
					$stale_before_utc,
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
	 * Purge claimed.
	 *
	 * @param Submission_Zip_Cleanup_Item $item Item.
	 * @param callable                    $delete Delete.
	 * @param string                      $audit_public_id Audit public id.
	 * @param string                      $outbox_public_id Outbox public id.
	 * @param string                      $occurred_at_utc Occurred at utc.
	 * @return bool|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function purge_claimed(
		Submission_Zip_Cleanup_Item $item,
		callable $delete,
		string $audit_public_id,
		string $outbox_public_id,
		string $occurred_at_utc
	): ?bool {
		new Public_Id( $audit_public_id );
		new Public_Id( $outbox_public_id );
		$this->assert_utc( $occurred_at_utc );

		$transaction = false;
		try {
			$this->query_or_fail( 'START TRANSACTION' );
			$transaction = true;

			$root = $this->root( $item->upload_id(), true );
			if ( null === $root || ! $this->same_root_claim( $item, $root ) ) {
				$this->rollback();
				return null;
			}

			$zip_item = null;
			if ( 'item' === $item->file_kind() ) {
				$zip_item = $this->zip_item( $this->positive_integer( $root['upload_pk'] ?? null ), (int) $item->item_no(), true );
				if ( null === $zip_item || ! $this->same_zip_item_claim( $item, $zip_item ) ) {
					$this->rollback();
					return null;
				}
			}

			$file = $this->private_file( $item->file_id(), true );
			if ( null === $file || ! $this->same_file_claim( $item, $file ) ) {
				$this->rollback();
				return null;
			}

			// Missing bytes are accepted so a crash after unlink remains recoverable.
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
						$item->file_state(),
						$item->scan_state(),
						$item->file_owner_type(),
						$item->file_owner_public_id(),
						$this->purpose( $item->file_kind() ),
						'submission_zip_staging',
					)
				)
			);
			if ( 1 !== $file_changed ) {
				$this->rollback();
				return null;
			}

			$cleanup_complete = 0 === $this->remaining_file_count( $root );
			if ( $cleanup_complete ) {
				$root_changed = $this->change_count(
					$this->prepare(
						'UPDATE ' . self::TABLE_UPLOADS . ' SET cleanup_after_utc = NULL, lock_version = lock_version + 1, '
						. 'updated_by_user_id = %d, updated_at_utc = %s WHERE id = %d AND public_id = %s '
						. 'AND lock_version = %d AND state = %s AND cleanup_after_utc = %s',
						array(
							$item->owner_user_id(),
							$occurred_at_utc,
							$this->positive_integer( $root['upload_pk'] ?? null ),
							$item->upload_id(),
							$item->upload_lock_version(),
							(string) $root['upload_state'],
							(string) $root['cleanup_after_utc'],
						)
					)
				);
				if ( 1 !== $root_changed ) {
					$this->rollback();
					return null;
				}
			}

			$this->insert_audit( $item, $root, $cleanup_complete, $audit_public_id, $occurred_at_utc );
			$this->insert_outbox( $item, $cleanup_complete, $outbox_public_id, $occurred_at_utc );
			$this->query_or_fail( 'COMMIT' );
			$transaction = false;
			return $cleanup_complete;
		} catch ( Zip_Import_Failure $failure ) {
			if ( $transaction ) {
				$this->rollback();
			}
			throw $failure;
		} catch ( Throwable ) {
			if ( $transaction ) {
				$this->rollback();
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Zip_Import_Failure::because( Zip_Import_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Source candidate.
	 *
	 * @param string $now_utc Now utc.
	 * @param string $stale_before_utc Stale before utc.
	 * @return array<string,mixed>|null
	 */
	private function source_candidate( string $now_utc, string $stale_before_utc ): ?array {
		$sql = 'SELECT u.id AS upload_pk, u.public_id AS upload_id, u.lock_version AS upload_lock_version, u.uploaded_by_user_id AS owner_user_id, u.cleanup_after_utc, a.public_id AS assignment_id, f.id AS private_file_pk, f.public_id AS file_id, f.lock_version AS file_lock_version, f.owner_type AS file_owner_type, f.owner_public_id AS file_owner_public_id, f.state AS file_state, f.scan_state, f.storage_key, f.sha256, %s AS file_kind, NULL AS item_no FROM ' . self::TABLE_UPLOADS . ' u INNER JOIN ' . self::TABLE_ASSIGNMENTS . ' a ON a.id = u.assignment_id '
			. 'INNER JOIN ' . self::TABLE_PRIVATE_FILES . ' f ON f.public_id = u.file_public_id '
			. 'WHERE u.state IN (%s,%s,%s) AND u.cleanup_after_utc IS NOT NULL AND u.cleanup_after_utc <= %s '
			. 'AND f.created_by_user_id = u.uploaded_by_user_id AND f.owner_type = %s AND f.owner_public_id = a.public_id '
			. 'AND f.purpose = %s AND f.retention_class = %s AND f.state IN (%s,%s,%s) '
			. 'AND f.scan_state IN (%s,%s,%s) AND f.updated_at_utc <= %s '
			. 'AND f.legal_hold_until_utc IS NULL AND f.purged_at_utc IS NULL '
			. 'ORDER BY u.cleanup_after_utc ASC, u.id ASC LIMIT 1';
		return $this->get_row(
			$this->prepare(
				$sql,
				array(
					'source',
					'completed',
					'canceled',
					'rejected',
					$now_utc,
					'assignment',
					'submission_zip_source',
					'submission_zip_staging',
					'quarantined',
					'ready',
					'rejected',
					'pending',
					'clean',
					'rejected',
					$stale_before_utc,
				)
			)
		);
	}

	/**
	 * Item candidate.
	 *
	 * @param string $now_utc Now utc.
	 * @param string $stale_before_utc Stale before utc.
	 * @return array<string,mixed>|null
	 */
	private function item_candidate( string $now_utc, string $stale_before_utc ): ?array {
		$sql = 'SELECT u.id AS upload_pk, u.public_id AS upload_id, u.lock_version AS upload_lock_version, u.uploaded_by_user_id AS owner_user_id, u.cleanup_after_utc, a.public_id AS assignment_id, f.id AS private_file_pk, f.public_id AS file_id, f.lock_version AS file_lock_version, f.owner_type AS file_owner_type, f.owner_public_id AS file_owner_public_id, f.state AS file_state, f.scan_state, f.storage_key, f.sha256, %s AS file_kind, i.item_no FROM ' . self::TABLE_UPLOADS . ' u INNER JOIN ' . self::TABLE_ASSIGNMENTS . ' a ON a.id = u.assignment_id '
			. 'INNER JOIN ' . self::TABLE_ITEMS . ' i ON i.zip_upload_id = u.id '
			. 'INNER JOIN ' . self::TABLE_PRIVATE_FILES . ' f ON f.public_id = i.file_public_id '
			. 'WHERE u.state IN (%s,%s,%s) AND u.cleanup_after_utc IS NOT NULL AND u.cleanup_after_utc <= %s '
			. 'AND i.state IN (%s,%s,%s,%s,%s) AND f.created_by_user_id = u.uploaded_by_user_id '
			. 'AND f.owner_type = %s AND f.owner_public_id = u.public_id AND f.purpose = %s '
			. 'AND f.retention_class = %s AND f.state IN (%s,%s,%s) AND f.scan_state IN (%s,%s,%s) '
			. 'AND f.updated_at_utc <= %s AND f.legal_hold_until_utc IS NULL AND f.purged_at_utc IS NULL '
			. 'ORDER BY u.cleanup_after_utc ASC, u.id ASC, i.item_no ASC LIMIT 1';
		return $this->get_row(
			$this->prepare(
				$sql,
				array(
					'item',
					'completed',
					'canceled',
					'rejected',
					$now_utc,
					'ready',
					'mapped',
					'failed',
					'excluded',
					'rejected',
					'submission_zip',
					'submission_zip_item',
					'submission_zip_staging',
					'quarantined',
					'ready',
					'rejected',
					'pending',
					'clean',
					'rejected',
					$stale_before_utc,
				)
			)
		);
	}

	/**
	 * Earliest candidate.
	 *
	 * @param array<string,mixed>|null $source Source.
	 * @param array<string,mixed>|null $item Item.
	 * @return array<string,mixed>|null
	 */
	private function earliest_candidate( ?array $source, ?array $item ): ?array {
		if ( null === $source ) {
			return $item;
		}
		if ( null === $item ) {
			return $source;
		}
		$source_key = sprintf( '%s:%020d:0', $this->required_utc( $source['cleanup_after_utc'] ?? null ), $this->positive_integer( $source['upload_pk'] ?? null ) );
		$item_key   = sprintf( '%s:%020d:1:%010d', $this->required_utc( $item['cleanup_after_utc'] ?? null ), $this->positive_integer( $item['upload_pk'] ?? null ), $this->positive_integer( $item['item_no'] ?? null ) );
		return $source_key <= $item_key ? $source : $item;
	}

	/**
	 * Root.
	 *
	 * @param string $upload_id Upload id.
	 * @param bool   $for_update For update.
	 * @return array<string,mixed>|null
	 */
	private function root( string $upload_id, bool $for_update ): ?array {
		$sql = 'SELECT u.id AS upload_pk, u.public_id AS upload_id, u.lock_version AS upload_lock_version, u.uploaded_by_user_id AS owner_user_id, u.file_public_id AS source_file_id, u.state AS upload_state, u.cleanup_after_utc, a.public_id AS assignment_id FROM ' . self::TABLE_UPLOADS . ' u '
			. 'INNER JOIN ' . self::TABLE_ASSIGNMENTS . ' a ON a.id = u.assignment_id WHERE u.public_id = %s LIMIT 1';
		if ( $for_update ) {
			$sql .= ' FOR UPDATE';
		}
		return $this->get_row( $this->prepare( $sql, array( $upload_id ) ) );
	}

	/**
	 * Zip item.
	 *
	 * @param int  $upload_pk Upload pk.
	 * @param int  $item_no Item no.
	 * @param bool $for_update For update.
	 * @return array<string,mixed>|null
	 */
	private function zip_item( int $upload_pk, int $item_no, bool $for_update ): ?array {
		$sql = 'SELECT id, item_no, file_public_id, state FROM ' . self::TABLE_ITEMS
			. ' WHERE zip_upload_id = %d AND item_no = %d LIMIT 1';
		if ( $for_update ) {
			$sql .= ' FOR UPDATE';
		}
		return $this->get_row( $this->prepare( $sql, array( $upload_pk, $item_no ) ) );
	}

	/**
	 * Private file.
	 *
	 * @param string $file_id File id.
	 * @param bool   $for_update For update.
	 * @return array<string,mixed>|null
	 */
	private function private_file( string $file_id, bool $for_update ): ?array {
		$sql = 'SELECT id, public_id, lock_version, created_by_user_id, owner_type, owner_public_id, purpose, storage_key, sha256, state, scan_state, retention_class, legal_hold_until_utc, purged_at_utc FROM '
			. self::TABLE_PRIVATE_FILES . ' WHERE public_id = %s LIMIT 1';
		if ( $for_update ) {
			$sql .= ' FOR UPDATE';
		}
		return $this->get_row( $this->prepare( $sql, array( $file_id ) ) );
	}

	/**
	 * Remaining file count.
	 *
	 * @param array<string,mixed> $root Root.
	 * @return int
	 */
	private function remaining_file_count( array $root ): int {
		$sql   = 'SELECT COUNT(*) FROM ' . self::TABLE_PRIVATE_FILES . ' f WHERE f.retention_class = %s '
			. 'AND f.purged_at_utc IS NULL AND ((f.public_id = %s AND f.owner_type = %s AND f.owner_public_id = %s '
			. 'AND f.purpose = %s) OR (f.owner_type = %s AND f.owner_public_id = %s AND f.purpose = %s '
			. 'AND EXISTS (SELECT 1 FROM ' . self::TABLE_ITEMS . ' i WHERE i.zip_upload_id = %d AND i.file_public_id = f.public_id)))';
		$value = $this->get_var(
			$this->prepare(
				$sql,
				array(
					'submission_zip_staging',
					$this->stored_public_id( $root['source_file_id'] ?? null ),
					'assignment',
					$this->stored_public_id( $root['assignment_id'] ?? null ),
					'submission_zip_source',
					'submission_zip',
					$this->stored_public_id( $root['upload_id'] ?? null ),
					'submission_zip_item',
					$this->positive_integer( $root['upload_pk'] ?? null ),
				)
			)
		);
		return $this->non_negative_integer( $value );
	}

	/**
	 * Same root claim.
	 *
	 * @param Submission_Zip_Cleanup_Item $item Item.
	 * @param array<string,mixed>         $root Root.
	 * @return bool
	 */
	private function same_root_claim( Submission_Zip_Cleanup_Item $item, array $root ): bool {
		$cleanup_after = $this->required_utc( $root['cleanup_after_utc'] ?? null );
		return $item->upload_id() === ( $root['upload_id'] ?? null )
			&& $item->upload_lock_version() === $this->non_negative_integer( $root['upload_lock_version'] ?? null )
			&& $item->assignment_id() === ( $root['assignment_id'] ?? null )
			&& $item->owner_user_id() === $this->positive_integer( $root['owner_user_id'] ?? null )
			&& in_array( $root['upload_state'] ?? null, array( 'completed', 'canceled', 'rejected' ), true )
			&& $cleanup_after <= $item->claimed_at_utc();
	}

	/**
	 * Same zip item claim.
	 *
	 * @param Submission_Zip_Cleanup_Item $item Item.
	 * @param array<string,mixed>         $zip_item Zip item.
	 * @return bool
	 */
	private function same_zip_item_claim( Submission_Zip_Cleanup_Item $item, array $zip_item ): bool {
		return $item->item_no() === $this->positive_integer( $zip_item['item_no'] ?? null )
			&& $item->file_id() === ( $zip_item['file_public_id'] ?? null )
			&& in_array( $zip_item['state'] ?? null, array( 'ready', 'mapped', 'failed', 'excluded', 'rejected' ), true );
	}

	/**
	 * Same file claim.
	 *
	 * @param Submission_Zip_Cleanup_Item $item Item.
	 * @param array<string,mixed>         $file File.
	 * @return bool
	 */
	private function same_file_claim( Submission_Zip_Cleanup_Item $item, array $file ): bool {
		return $item->file_id() === ( $file['public_id'] ?? null )
			&& $item->file_lock_version() === $this->non_negative_integer( $file['lock_version'] ?? null )
			&& $item->owner_user_id() === $this->positive_integer( $file['created_by_user_id'] ?? null )
			&& $item->file_owner_type() === ( $file['owner_type'] ?? null )
			&& $item->file_owner_public_id() === ( $file['owner_public_id'] ?? null )
			&& $this->purpose( $item->file_kind() ) === ( $file['purpose'] ?? null )
			&& $item->file_state() === ( $file['state'] ?? null )
			&& $item->scan_state() === ( $file['scan_state'] ?? null )
			&& 'submission_zip_staging' === ( $file['retention_class'] ?? null )
			&& null === ( $file['legal_hold_until_utc'] ?? null ) && null === ( $file['purged_at_utc'] ?? null )
			&& hash_equals( $item->storage_key(), (string) ( $file['storage_key'] ?? '' ) )
			&& hash_equals( $item->sha256(), (string) ( $file['sha256'] ?? '' ) );
	}

	/**
	 * Item.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return Submission_Zip_Cleanup_Item
	 */
	private function item( array $row ): Submission_Zip_Cleanup_Item {
		$file_kind = (string) ( $row['file_kind'] ?? '' );
		$item_no   = 'source' === $file_kind ? null : $this->positive_integer( $row['item_no'] ?? null );
		return new Submission_Zip_Cleanup_Item(
			array(
				'assignment_id'        => $this->stored_public_id( $row['assignment_id'] ?? null ),
				'claimed_at_utc'       => $this->required_utc( $row['claimed_at_utc'] ?? null ),
				'file_id'              => $this->stored_public_id( $row['file_id'] ?? null ),
				'file_kind'            => $file_kind,
				'file_lock_version'    => $this->non_negative_integer( $row['file_lock_version'] ?? null ),
				'file_owner_public_id' => $this->stored_public_id( $row['file_owner_public_id'] ?? null ),
				'file_owner_type'      => (string) ( $row['file_owner_type'] ?? '' ),
				'file_state'           => (string) ( $row['file_state'] ?? '' ),
				'item_no'              => $item_no,
				'owner_user_id'        => $this->positive_integer( $row['owner_user_id'] ?? null ),
				'scan_state'           => (string) ( $row['scan_state'] ?? '' ),
				'sha256'               => $this->stored_hash( $row['sha256'] ?? null ),
				'storage_key'          => $this->stored_storage_key( $row['storage_key'] ?? null ),
				'upload_id'            => $this->stored_public_id( $row['upload_id'] ?? null ),
				'upload_lock_version'  => $this->non_negative_integer( $row['upload_lock_version'] ?? null ),
			)
		);
	}

	/**
	 * Insert audit.
	 *
	 * @param Submission_Zip_Cleanup_Item $item Item.
	 * @param array<string,mixed>         $root Root.
	 * @param bool                        $complete Complete.
	 * @param string                      $public_id Public id.
	 * @param string                      $occurred_at_utc Occurred at utc.
	 * @return void
	 */
	private function insert_audit( Submission_Zip_Cleanup_Item $item, array $root, bool $complete, string $public_id, string $occurred_at_utc ): void {
		$previous = $this->get_var( 'SELECT event_hash FROM ' . self::TABLE_AUDIT . ' ORDER BY id DESC LIMIT 1 FOR UPDATE' );
		if ( null !== $previous ) {
			$this->stored_hash( $previous );
		}
		$before         = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'cleanup_after_utc' => $root['cleanup_after_utc'],
					'file_id'           => $item->file_id(),
					'file_state'        => $item->file_state(),
					'upload_state'      => $root['upload_state'],
				)
			)
		);
		$after          = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'cleanup_after_utc' => $complete ? null : $root['cleanup_after_utc'],
					'file_id'           => $item->file_id(),
					'file_state'        => 'purged',
					'upload_state'      => $root['upload_state'],
				)
			)
		);
		$metadata_json  = $this->canonical->encode(
			array(
				'cleanup_complete' => $complete,
				'file_id'          => $item->file_id(),
				'file_kind'        => $item->file_kind(),
				'item_no'          => $item->item_no(),
				'retention_class'  => 'submission_zip_staging',
			)
		);
		$metadata_hash  = hash( 'sha256', $metadata_json );
		$correlation_id = 'submission-zip-cleanup:' . $item->upload_id();
		$event_hash     = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'action_key'          => self::EVENT_PURGED,
					'actor_user_id'       => null,
					'after_digest'        => $after,
					'before_digest'       => $before,
					'correlation_id'      => $correlation_id,
					'metadata_hash'       => $metadata_hash,
					'object_public_id'    => $item->upload_id(),
					'object_type'         => 'submission_zip',
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
				'action_key'          => self::EVENT_PURGED,
				'object_type'         => 'submission_zip',
				'object_public_id'    => $item->upload_id(),
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
	 * @param Submission_Zip_Cleanup_Item $item Item.
	 * @param bool                        $complete Complete.
	 * @param string                      $public_id Public id.
	 * @param string                      $occurred_at_utc Occurred at utc.
	 * @return void
	 */
	private function insert_outbox( Submission_Zip_Cleanup_Item $item, bool $complete, string $public_id, string $occurred_at_utc ): void {
		$payload_json = $this->canonical->encode(
			array(
				'cleanup_complete' => $complete,
				'file_id'          => $item->file_id(),
				'file_kind'        => $item->file_kind(),
				'file_state'       => 'purged',
				'item_no'          => $item->item_no(),
				'upload_id'        => $item->upload_id(),
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
				'aggregate_type'       => 'submission_zip',
				'aggregate_public_id'  => $item->upload_id(),
				'event_type'           => self::EVENT_PURGED,
				'idempotency_key'      => 'submission-zip-cleanup:purged:' . $item->file_id(),
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
	 * Purpose.
	 *
	 * @param string $file_kind File kind.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function purpose( string $file_kind ): string {
		return match ( $file_kind ) {
			'source' => 'submission_zip_source',
			'item' => 'submission_zip_item',
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			default => throw Zip_Import_Failure::because( Zip_Import_Failure::STORED_DATA_INVALID ),
		};
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
			throw new InvalidArgumentException( 'Submission ZIP cleanup UTC value is invalid.' );
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
		throw Zip_Import_Failure::because( Zip_Import_Failure::STORED_DATA_INVALID );
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
			throw Zip_Import_Failure::because( Zip_Import_Failure::STORAGE_UNAVAILABLE );
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
			throw Zip_Import_Failure::because( Zip_Import_Failure::STORAGE_UNAVAILABLE );
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
			throw Zip_Import_Failure::because( Zip_Import_Failure::STORAGE_UNAVAILABLE );
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
			throw Zip_Import_Failure::because( Zip_Import_Failure::STORAGE_UNAVAILABLE );
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
			throw Zip_Import_Failure::because( Zip_Import_Failure::STORAGE_UNAVAILABLE );
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
			throw Zip_Import_Failure::because( Zip_Import_Failure::STORAGE_UNAVAILABLE );
		}
		foreach ( array( 'get_row', 'get_var', 'insert', 'prepare', 'query' ) as $method ) {
			if ( ! method_exists( $db, $method ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Zip_Import_Failure::because( Zip_Import_Failure::STORAGE_UNAVAILABLE );
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
			throw Zip_Import_Failure::because( Zip_Import_Failure::STORAGE_UNAVAILABLE );
		}
	}
}
