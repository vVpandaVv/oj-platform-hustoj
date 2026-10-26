<?php
/**
 * Validated, explicit maintenance of official submission attachment retention.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Student\Infrastructure;

use InvalidArgumentException;
use OJAI\Dashboard\Storage\Port\Private_File_Read_Port_Interface;
use OJAI\Dashboard\Submission\Assignment\Application\Assignment_Failure;
use OJAI\Dashboard\Submission\Teacher\Infrastructure\WordPress_Teacher_Attachment_Query;
use OJAI\Dashboard\Submission\Teacher\Port\Submission_Print_Source_Query_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Explicit maintenance operation; never invoked by a download or background cleanup.
 */
final class WordPress_Bound_Attachment_Retention_Repair {
	/**
	 * Construct a maintenance operation without registering automatic execution.
	 *
	 * @param object                                       $db WordPress database connection.
	 * @param Submission_Print_Source_Query_Port_Interface $submissions Official revision query.
	 * @param Private_File_Read_Port_Interface             $files Integrity-checked private storage.
	 */
	public function __construct(
		private object $db,
		private Submission_Print_Source_Query_Port_Interface $submissions,
		private Private_File_Read_Port_Interface $files
	) {
	}

	/**
	 * Inspect or repair one attachment after validating its official source and bytes.
	 *
	 * @param string                   $attachment_id Bound attachment public ID.
	 * @param int                      $actor Authorized maintenance actor.
	 * @param string                   $now UTC maintenance time.
	 * @param bool                     $apply Whether to commit instead of previewing.
	 * @param array<string,mixed>|null $expected Previously reviewed preview receipt.
	 * @return array<string,mixed> Metadata receipt without answers or storage paths.
	 * @throws InvalidArgumentException When the actor or time is invalid.
	 * @throws Throwable When the transaction fails; its changes are rolled back.
	 */
	public function repair( string $attachment_id, int $actor, string $now, bool $apply = false, ?array $expected = null ): array {
		new Public_Id( $attachment_id );
		if ( $actor < 1 || ! $this->is_utc( $now ) ) {
			throw new InvalidArgumentException( 'Invalid repair actor or time.' );
		}
		$this->query( 'START TRANSACTION' );
		try {
			$owner = $this->row(
				'SELECT s.id, s.public_id, s.official_revision_public_id FROM oj_ai_submissions s '
				. 'INNER JOIN oj_ai_submission_attachments a ON a.submission_id = s.id WHERE a.public_id = %s LIMIT 1',
				$attachment_id
			);
			if ( null === $owner ) {
				$this->deny();
			}
			$owner = $this->row( 'SELECT id, public_id, learner_user_id, official_revision_public_id FROM oj_ai_submissions WHERE id = %d FOR UPDATE', (int) $owner['id'] );
			if ( null === $owner || ! is_string( $owner['official_revision_public_id'] ?? null ) ) {
				$this->deny();
			}
			$attachment = $this->row(
				'SELECT id, file_public_id, bound_at_utc, submission_revision_id FROM oj_ai_submission_attachments '
				. 'WHERE public_id = %s AND submission_id = %d AND state = %s FOR UPDATE',
				$attachment_id,
				(int) $owner['id'],
				'bound'
			);
			if ( null === $attachment ) {
				$this->deny();
			}
			$file = $this->row( 'SELECT id, lock_version, expires_at_utc FROM oj_ai_private_files WHERE public_id = %s FOR UPDATE', $attachment['file_public_id'] );
			if ( null === $file ) {
				$this->deny();
			}
			$submission = $this->submissions->find_official_revision( $owner['official_revision_public_id'] );
			if ( null === $submission || ( $submission['id'] ?? null ) !== $owner['public_id'] ) {
				$this->deny();
			}
			$listed = null;
			foreach ( $submission['attachments'] ?? array() as $item ) {
				if ( ( $item['id'] ?? null ) === $attachment_id && in_array( 'official', $item['revision_scope'] ?? array(), true ) ) {
					$listed = $item;
				}
			}
			$row = ( new WordPress_Teacher_Attachment_Query( $this->db ) )->find( $owner['public_id'], $attachment_id );
			if ( null === $listed || null === $row || 'bound' !== ( $row['attachment_state'] ?? null )
				|| 'ready' !== ( $row['state'] ?? null ) || 'clean' !== ( $row['scan_state'] ?? null )
				|| 'submission_staging' !== ( $row['retention_class'] ?? null ) || null !== ( $row['purged_at_utc'] ?? null )
				|| (int) $row['submission_revision_id'] < 1 || (int) $row['attachment_creator'] < 1
				|| (int) $row['attachment_creator'] !== (int) $row['created_by_user_id']
				|| (int) $row['attachment_creator'] !== (int) $owner['learner_user_id']
				|| ( $row['response_key'] ?? null ) !== $listed['response_id']
				|| ! $this->contains( $submission['official_revision']['response']['answers'][ $listed['response_id'] ] ?? null, $attachment_id ) ) {
				$this->deny();
			}
			foreach ( array(
				'name'      => array( 'original_name', 'attachment_name' ),
				'mime_type' => array( 'mime_type', 'attachment_mime' ),
				'sha256'    => array( 'sha256', 'attachment_hash' ),
			) as $key => $columns ) {
				if ( ! is_string( $listed[ $key ] ?? null ) || ( $row[ $columns[0] ] ?? null ) !== $listed[ $key ]
					|| ( $row[ $columns[1] ] ?? null ) !== $listed[ $key ] ) {
					$this->deny();
				}
			}
			$size = (int) ( $row['byte_size'] ?? 0 );
			if ( $size < 1 || $size !== (int) $row['attachment_size'] || $size !== (int) $listed['byte_size'] ) {
				$this->deny();
			}
			$expires = $file['expires_at_utc'];
			if ( ! $this->is_utc( $attachment['bound_at_utc'] ?? null )
				|| ( null !== $expires && ( ! $this->is_utc( $expires ) || $attachment['bound_at_utc'] >= $expires ) ) ) {
				$this->deny();
			}
			// Verify the bytes before extending retention; missing, replaced or quarantined files stay blocked.
			$path = $this->files->readable_path( $row['storage_key'], $row['sha256'] );
			if ( filesize( $path ) !== $size ) {
				$this->deny();
			}
			$before = array(
				'id'             => $row['public_id'],
				'lock_version'   => (int) $file['lock_version'],
				'expires_at_utc' => $expires,
				'byte_size'      => $size,
				'sha256'         => $row['sha256'],
				'state'          => $row['state'],
				'scan_state'     => $row['scan_state'],
			);
			if ( null !== $expected && ( ( $expected['attachment_id'] ?? null ) !== $attachment_id
				|| ( $expected['submission_id'] ?? null ) !== $owner['public_id']
				|| ( $expected['official_revision_id'] ?? null ) !== $owner['official_revision_public_id']
				|| ( $expected['before'] ?? null ) !== $before ) ) {
				$this->deny();
			}
			if ( $apply && null !== $expires ) {
				$changed = $this->query(
					$this->prepare(
						'UPDATE oj_ai_private_files SET expires_at_utc = NULL, lock_version = lock_version + 1, '
						. 'updated_by_user_id = %d, updated_at_utc = %s WHERE id = %d AND lock_version = %d '
						. 'AND state = %s AND scan_state = %s AND purged_at_utc IS NULL AND expires_at_utc = %s',
						$actor,
						$now,
						(int) $file['id'],
						(int) $file['lock_version'],
						'ready',
						'clean',
						$expires
					)
				);
				if ( 1 !== $changed ) {
					$this->deny();
				}
			}
			$this->query( $apply ? 'COMMIT' : 'ROLLBACK' );
			return array(
				'attachment_id'        => $attachment_id,
				'submission_id'        => $owner['public_id'],
				'official_revision_id' => $owner['official_revision_public_id'],
				'before'               => $before,
				'status'               => null === $expires ? 'unchanged' : ( $apply ? 'repaired' : 'repairable' ),
			);
		} catch ( Throwable $error ) {
			$this->db->query( 'ROLLBACK' );
			throw $error;
		}
	}

	/**
	 * Check a canonical UTC timestamp.
	 *
	 * @param mixed $value Stored timestamp.
	 * @return bool Whether the value is canonical.
	 */
	private function is_utc( mixed $value ): bool {
		if ( ! is_string( $value ) ) {
			return false;
		}
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new \DateTimeZone( 'UTC' ) );
		return false !== $date && $date->format( 'Y-m-d H:i:s' ) === $value;
	}

	/**
	 * Locate an exact attachment reference in a bounded answer tree.
	 *
	 * @param mixed  $value Response subtree.
	 * @param string $id Attachment public ID.
	 * @param int    $depth Current traversal depth.
	 * @return bool Whether the attachment is referenced.
	 */
	private function contains( mixed $value, string $id, int $depth = 0 ): bool {
		if ( $depth > 32 ) {
			return false;
		}
		if ( is_string( $value ) ) {
			return $id === $value;
		}
		if ( ! is_array( $value ) ) {
			return false;
		}
		foreach ( $value as $item ) {
			if ( $this->contains( $item, $id, $depth + 1 ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Prepare a parameterized statement or fail closed.
	 *
	 * @param string $sql SQL with placeholders.
	 * @param mixed  ...$args Placeholder values.
	 * @return string Prepared statement.
	 */
	private function prepare( string $sql, mixed ...$args ): string {
		$value = $this->db->prepare( $sql, ...$args );
		if ( ! is_string( $value ) || '' === $value ) {
			$this->storage();
		}
		return $value;
	}

	/**
	 * Read a single row without treating a database failure as an empty result.
	 *
	 * @param string $sql SQL with placeholders.
	 * @param mixed  ...$args Placeholder values.
	 * @return array<string,mixed>|null Stored row.
	 */
	private function row( string $sql, mixed ...$args ): ?array {
		$this->db->last_error = '';
		$row                  = $this->db->get_row( $this->prepare( $sql, ...$args ), defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A' );
		if ( '' !== (string) $this->db->last_error || ( null !== $row && ! is_array( $row ) ) ) {
			$this->storage();
		}
		return $row;
	}

	/**
	 * Execute a transaction or compare-and-swap statement.
	 *
	 * @param string $sql Prepared SQL.
	 * @return int Affected row count.
	 */
	private function query( string $sql ): int {
		$this->db->last_error = '';
		$count                = $this->db->query( $sql );
		if ( false === $count || '' !== (string) $this->db->last_error ) {
			$this->storage();
		}
		return (int) $count;
	}

	/**
	 * Reject an unsafe attachment or changed preview.
	 *
	 * @throws Assignment_Failure The existing attachment readiness failure.
	 * @return never
	 */
	private function deny(): never {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
		throw Assignment_Failure::because( Assignment_Failure::ATTACHMENT_NOT_READY );
	}

	/**
	 * Reject a storage failure without a partial repair.
	 *
	 * @throws Assignment_Failure The existing storage failure.
	 * @return never
	 */
	private function storage(): never {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
		throw Assignment_Failure::because( Assignment_Failure::STORAGE_UNAVAILABLE );
	}
}
