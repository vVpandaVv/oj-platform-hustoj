<?php
/**
 * Literal-table lease and completion repository for submission ZIP scans.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\ZipImport\Infrastructure;

use InvalidArgumentException;
use JsonException;
use OJAI\Dashboard\Submission\ZipImport\Application\Zip_Import_Failure;
use OJAI\Dashboard\Submission\ZipImport\Domain\Submission_Zip_Scan_Job;
use OJAI\Dashboard\Submission\ZipImport\Port\Submission_Zip_Scan_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Uses the ZIP root lock_version and updated_at as a reclaimable five-minute lease.
 */
final class WordPress_Submission_Zip_Scan_Repository implements Submission_Zip_Scan_Repository_Port_Interface {
	private const TABLE_UPLOADS       = 'oj_ai_zip_uploads';
	private const TABLE_ITEMS         = 'oj_ai_zip_upload_items';
	private const TABLE_ASSIGNMENTS   = 'oj_ai_assignments';
	private const TABLE_TARGETS       = 'oj_ai_assignment_targets';
	private const TABLE_PUBLICATIONS  = 'oj_ai_worksheet_publications';
	private const TABLE_PRIVATE_FILES = 'oj_ai_private_files';
	private const TABLE_AUDIT         = 'oj_ai_audit_events';
	private const TABLE_OUTBOX        = 'oj_ai_outbox_events';
	private const MAPPING_SCHEMA      = 'oj_ai.submission_zip_mapping';
	private const RESPONSE_SCHEMA     = 'oj_ai.student_response_schema';
	private const EVENT_COMPLETE      = 'submission_zip.scan.completed';
	private const EVENT_REJECT        = 'submission_zip.scan.rejected';
	private const PAYLOAD_SCHEMA      = 'oj_ai.submission_zip_scan_event';
	private const AUDIT_SCHEMA        = 'oj_ai.submission_zip_scan_audit';
	private const VERSION             = 1;

	/**
	 * Canonical JSON encoder used for stable digests.
	 *
	 * @var Canonical_Json
	 */
	private Canonical_Json $canonical;

	/**
	 * Initialize WordPress submission zip scan repository dependencies and state.
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
	 * @return Submission_Zip_Scan_Job|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function claim_due( string $now_utc, string $stale_before_utc ): ?Submission_Zip_Scan_Job {
		$this->assert_utc( $now_utc );
		$this->assert_utc( $stale_before_utc );
		if ( $stale_before_utc > $now_utc ) {
			throw new InvalidArgumentException( 'Submission ZIP scan lease is invalid.' );
		}
		$row = $this->candidate( $stale_before_utc );
		if ( null === $row ) {
			return null;
		}

		$upload_pk = $this->positive_integer( $row['upload_pk'] ?? null );
		$old_lock  = $this->non_negative_integer( $row['upload_lock_version'] ?? null );
		$upload_id = $this->public_id( $row['upload_id'] ?? null );
		$actor     = $this->positive_integer( $row['actor_user_id'] ?? null );
		$changed   = $this->change_count(
			$this->prepare(
				'UPDATE ' . self::TABLE_UPLOADS . ' SET lock_version = lock_version + 1, updated_by_user_id = %d, updated_at_utc = %s '
				. 'WHERE id = %d AND public_id = %s AND lock_version = %d AND state = %s AND item_total = 0 AND success_total = 0 AND failure_total = 0',
				array( $actor, $now_utc, $upload_pk, $upload_id, $old_lock, 'scanning' )
			)
		);
		if ( 1 !== $changed ) {
			return null;
		}
		$row['upload_lock_version'] = $old_lock + 1;
		$row['claimed_at_utc']      = $now_utc;
		$row['targets']             = $this->targets( $this->positive_integer( $row['assignment_pk'] ?? null ) );
		return $this->job( $row );
	}

	/**
	 * Complete.
	 *
	 * @param Submission_Zip_Scan_Job        $job Job.
	 * @param array<int,array<string,mixed>> $items Items.
	 * @param array<string,mixed>            $mapping Mapping.
	 * @param string                         $audit_public_id Audit public id.
	 * @param string                         $outbox_public_id Outbox public id.
	 * @param string                         $occurred_at_utc Occurred at utc.
	 * @return bool
	 */
	public function complete(
		Submission_Zip_Scan_Job $job,
		array $items,
		array $mapping,
		string $audit_public_id,
		string $outbox_public_id,
		string $occurred_at_utc
	): bool {
		$this->assert_items( $items );
		$this->assert_mapping( $mapping, $job, $items, null );
		return $this->finish( $job, 'ready', $items, $mapping, null, $audit_public_id, $outbox_public_id, $occurred_at_utc );
	}

	/**
	 * Reject.
	 *
	 * @param Submission_Zip_Scan_Job $job Job.
	 * @param string                  $reason_code Reason code.
	 * @param string                  $audit_public_id Audit public id.
	 * @param string                  $outbox_public_id Outbox public id.
	 * @param string                  $occurred_at_utc Occurred at utc.
	 * @return bool
	 */
	public function reject(
		Submission_Zip_Scan_Job $job,
		string $reason_code,
		string $audit_public_id,
		string $outbox_public_id,
		string $occurred_at_utc
	): bool {
		$this->assert_reason( $reason_code );
		$mapping = array(
			'items'            => array(),
			'mapping_revision' => $job->mapping_revision() + 1,
			'scan_error_code'  => $reason_code,
			'source'           => $job->mapping_source(),
		);
		$this->assert_mapping( $mapping, $job, array(), $reason_code );
		return $this->finish( $job, 'rejected', array(), $mapping, $reason_code, $audit_public_id, $outbox_public_id, $occurred_at_utc );
	}

	/**
	 * Finish.
	 *
	 * @param Submission_Zip_Scan_Job        $job Job.
	 * @param string                         $state State.
	 * @param array<int,array<string,mixed>> $items Items.
	 * @param array<string,mixed>            $mapping Mapping.
	 * @param string|null                    $reason_code Reason code.
	 * @param string                         $audit_public_id Audit public id.
	 * @param string                         $outbox_public_id Outbox public id.
	 * @param string                         $occurred_at_utc Occurred at utc.
	 * @return bool
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function finish(
		Submission_Zip_Scan_Job $job,
		string $state,
		array $items,
		array $mapping,
		?string $reason_code,
		string $audit_public_id,
		string $outbox_public_id,
		string $occurred_at_utc
	): bool {
		new Public_Id( $audit_public_id );
		new Public_Id( $outbox_public_id );
		$this->assert_utc( $occurred_at_utc );
		$mapping_json  = $this->canonical->encode( $mapping );
		$mapping_hash  = hash( 'sha256', $mapping_json );
		$success       = count( array_filter( $items, static fn ( array $item ): bool => 'ready' === $item['state'] ) );
		$failure       = count( $items ) - $success;
		$cleanup_after = null;
		if ( 'rejected' === $state ) {
			$time = strtotime( $occurred_at_utc . ' UTC' );
			if ( false === $time ) {
				throw new InvalidArgumentException( 'Submission ZIP completion time is invalid.' );
			}
			$cleanup_after = gmdate( 'Y-m-d H:i:s', $time + 86400 );
		}

		$transaction = false;
		try {
			$this->query_or_fail( 'START TRANSACTION' );
			$transaction = true;
			$root        = $this->root( $job->upload_id(), true );
			$file        = $this->source_file( $job->source_file_id(), true );
			if ( null === $root || null === $file || ! $this->same_claim( $job, $root, $file ) ) {
				$this->rollback();
				return false;
			}
			if ( 0 !== $this->non_negative_integer(
				$this->get_var(
					$this->prepare(
						'SELECT COUNT(*) FROM ' . self::TABLE_ITEMS . ' WHERE zip_upload_id = %d',
						array( $this->positive_integer( $root['id'] ?? null ) )
					)
				)
			) ) {
				$this->rollback();
				return false;
			}

			foreach ( $items as $item ) {
				$this->insert_item( $root, $job, $item, $occurred_at_utc );
			}
			$root_changed = $this->change_count(
				$this->prepare(
					'UPDATE ' . self::TABLE_UPLOADS . ' SET state = %s, mapping_json = %s, mapping_hash = %s, item_total = %d, '
					. 'success_total = %d, failure_total = %d, cleanup_after_utc = %s, lock_version = lock_version + 1, '
					. 'updated_by_user_id = %d, updated_at_utc = %s WHERE id = %d AND public_id = %s AND lock_version = %d '
					. 'AND state = %s AND mapping_hash = %s AND item_total = 0 AND success_total = 0 AND failure_total = 0',
					array(
						$state,
						$mapping_json,
						$mapping_hash,
						count( $items ),
						$success,
						$failure,
						$cleanup_after,
						$job->actor_user_id(),
						$occurred_at_utc,
						$this->positive_integer( $root['id'] ?? null ),
						$job->upload_id(),
						$job->upload_lock_version(),
						'scanning',
						$job->mapping_hash(),
					)
				)
			);
			if ( 1 !== $root_changed ) {
				$this->rollback();
				return false;
			}

			$file_state   = 'ready' === $state ? 'quarantined' : 'rejected';
			$scan_state   = 'ready' === $state ? 'clean' : 'rejected';
			$file_changed = $this->change_count(
				$this->prepare(
					'UPDATE ' . self::TABLE_PRIVATE_FILES . ' SET state = %s, scan_state = %s, lock_version = lock_version + 1, '
					. 'updated_by_user_id = %d, updated_at_utc = %s WHERE id = %d AND public_id = %s AND lock_version = %d '
					. 'AND state = %s AND scan_state = %s AND owner_type = %s AND owner_public_id = %s AND purpose = %s '
					. 'AND retention_class = %s AND purged_at_utc IS NULL',
					array(
						$file_state,
						$scan_state,
						$job->actor_user_id(),
						$occurred_at_utc,
						$this->positive_integer( $file['id'] ?? null ),
						$job->source_file_id(),
						$job->source_file_lock_version(),
						'quarantined',
						'pending',
						'assignment',
						$job->assignment_id(),
						'submission_zip_source',
						'submission_zip_staging',
					)
				)
			);
			if ( 1 !== $file_changed ) {
				$this->rollback();
				return false;
			}

			$this->insert_audit( $job, $state, count( $items ), $success, $failure, $reason_code, $audit_public_id, $occurred_at_utc );
			$this->insert_outbox( $job, $state, count( $items ), $success, $failure, $reason_code, $outbox_public_id, $occurred_at_utc );
			$this->query_or_fail( 'COMMIT' );
			$transaction = false;
			return true;
		} catch ( Zip_Import_Failure $failure_value ) {
			if ( $transaction ) {
				$this->rollback();
			}
			throw $failure_value;
		} catch ( Throwable ) {
			if ( $transaction ) {
				$this->rollback();
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Zip_Import_Failure::because( Zip_Import_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Candidate.
	 *
	 * @param string $stale_before_utc Stale before utc.
	 * @return array<string,mixed>|null
	 */
	private function candidate( string $stale_before_utc ): ?array {
		$sql = 'SELECT u.id AS upload_pk, u.public_id AS upload_id, u.lock_version AS upload_lock_version, u.created_by_user_id AS actor_user_id, u.assignment_id AS assignment_pk, u.file_public_id AS source_file_id, u.mapping_schema, u.mapping_version, u.mapping_json, u.mapping_hash, a.public_id AS assignment_id, a.context_type, a.context_id, a.context_revision, f.id AS source_file_pk, f.lock_version AS source_file_lock_version, f.storage_key AS source_storage_key, f.original_name AS source_original_name, f.byte_size AS source_byte_size, f.sha256 AS source_sha256, f.expires_at_utc AS source_expires_at_utc, p.response_schema_schema, p.response_schema_version, p.response_schema_json, p.response_schema_hash FROM ' . self::TABLE_UPLOADS . ' u '
			. 'INNER JOIN ' . self::TABLE_ASSIGNMENTS . ' a ON a.id = u.assignment_id '
			. 'INNER JOIN ' . self::TABLE_PRIVATE_FILES . ' f ON f.public_id = u.file_public_id '
			. 'INNER JOIN ' . self::TABLE_PUBLICATIONS . ' p ON p.public_id = a.worksheet_publication_public_id '
			. 'WHERE u.state = %s AND u.item_total = 0 AND u.success_total = 0 AND u.failure_total = 0 '
			. 'AND u.mapping_schema = %s AND u.mapping_version = %d '
			. 'AND f.state = %s AND f.scan_state = %s AND f.owner_type = %s AND f.owner_public_id = a.public_id '
			. 'AND f.purpose = %s AND f.retention_class = %s AND f.purged_at_utc IS NULL '
			. 'AND (u.lock_version = 0 OR u.updated_at_utc <= %s) ORDER BY u.id ASC LIMIT 1';
		return $this->get_row(
			$this->prepare(
				$sql,
				array(
					'scanning',
					self::MAPPING_SCHEMA,
					self::VERSION,
					'quarantined',
					'pending',
					'assignment',
					'submission_zip_source',
					'submission_zip_staging',
					$stale_before_utc,
				)
			)
		);
	}

	/**
	 * Job.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return Submission_Zip_Scan_Job
	 */
	private function job( array $row ): Submission_Zip_Scan_Job {
		if ( self::MAPPING_SCHEMA !== ( $row['mapping_schema'] ?? null ) || self::VERSION !== $this->positive_integer( $row['mapping_version'] ?? null ) ) {
			$this->stored_invalid();
		}
		$mapping = $this->decode_json( (string) ( $row['mapping_json'] ?? '' ), $this->hash_value( $row['mapping_hash'] ?? null ) );
		$this->exact_keys( $mapping, array( 'items', 'mapping_revision', 'source' ) );
		if ( array() !== ( $mapping['items'] ?? null ) || 0 !== $this->non_negative_integer( $mapping['mapping_revision'] ?? null ) ) {
			$this->stored_invalid();
		}
		$source = $mapping['source'] ?? null;
		if ( ! is_array( $source ) || array_is_list( $source ) ) {
			$this->stored_invalid();
		}
		$this->exact_keys( $source, array( 'byte_size', 'compressed_entry_bytes', 'entry_count', 'sha256', 'uncompressed_bytes' ) );
		return new Submission_Zip_Scan_Job(
			array(
				'actor_user_id'            => $this->positive_integer( $row['actor_user_id'] ?? null ),
				'assignment_id'            => $this->public_id( $row['assignment_id'] ?? null ),
				'claimed_at_utc'           => $this->required_utc( $row['claimed_at_utc'] ?? null ),
				'context_id'               => $this->token( $row['context_id'] ?? null, 191 ),
				'context_revision'         => $this->token( $row['context_revision'] ?? null, 191 ),
				'context_type'             => $this->token( $row['context_type'] ?? null, 16 ),
				'mapping_hash'             => $this->hash_value( $row['mapping_hash'] ?? null ),
				'mapping_revision'         => 0,
				'mapping_source'           => $source,
				'source_byte_size'         => $this->positive_integer( $row['source_byte_size'] ?? null ),
				'source_expires_at_utc'    => $this->required_utc( $row['source_expires_at_utc'] ?? null ),
				'source_file_id'           => $this->public_id( $row['source_file_id'] ?? null ),
				'source_file_lock_version' => $this->non_negative_integer( $row['source_file_lock_version'] ?? null ),
				'source_original_name'     => $this->filename( $row['source_original_name'] ?? null ),
				'source_sha256'            => $this->hash_value( $row['source_sha256'] ?? null ),
				'source_storage_key'       => $this->storage_key( $row['source_storage_key'] ?? null ),
				'targets'                  => is_array( $row['targets'] ?? null ) ? $row['targets'] : array(),
				'upload_id'                => $this->public_id( $row['upload_id'] ?? null ),
				'upload_lock_version'      => $this->non_negative_integer( $row['upload_lock_version'] ?? null ),
				'upload_responses'         => $this->response_profile( $row ),
			)
		);
	}

	/**
	 * Targets.
	 *
	 * @param int $assignment_pk Assignment pk.
	 * @return array<int,array<string,mixed>>
	 */
	private function targets( int $assignment_pk ): array {
		$rows   = $this->get_results(
			$this->prepare(
				'SELECT public_id AS target_id, learner_user_id, state, display_snapshot_schema, display_snapshot_version, display_snapshot_json, display_snapshot_hash FROM ' . self::TABLE_TARGETS
				. ' WHERE assignment_id = %d ORDER BY public_id ASC',
				array( $assignment_pk )
			)
		);
		$result = array();
		foreach ( $rows as $row ) {
			if ( 'oj_ai.assignment_target_display' !== ( $row['display_snapshot_schema'] ?? null ) || 1 !== $this->positive_integer( $row['display_snapshot_version'] ?? null ) ) {
				$this->stored_invalid();
			}
			$display = $this->decode_json( (string) ( $row['display_snapshot_json'] ?? '' ), $this->hash_value( $row['display_snapshot_hash'] ?? null ) );
			$this->exact_keys( $display, array( 'display_name' ) );
			$result[] = array(
				'display_name'    => $this->text( $display['display_name'] ?? null, 255 ),
				'learner_user_id' => $this->positive_integer( $row['learner_user_id'] ?? null ),
				'state'           => in_array( $row['state'] ?? null, array( 'active', 'removed' ), true ) ? $row['state'] : $this->stored_invalid(),
				'target_id'       => $this->public_id( $row['target_id'] ?? null ),
			);
		}
		return $result;
	}

	/**
	 * Response profile.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return array<int,array<string,mixed>>
	 */
	private function response_profile( array $row ): array {
		if ( self::RESPONSE_SCHEMA !== ( $row['response_schema_schema'] ?? null ) || 1 !== $this->positive_integer( $row['response_schema_version'] ?? null ) ) {
			$this->stored_invalid();
		}
		$profile = $this->decode_json( (string) ( $row['response_schema_json'] ?? '' ), $this->hash_value( $row['response_schema_hash'] ?? null ) );
		$items   = $profile['payload']['items'] ?? null;
		if ( self::RESPONSE_SCHEMA !== ( $profile['schema_id'] ?? null ) || ! is_array( $items ) || ! array_is_list( $items ) ) {
			$this->stored_invalid();
		}
		$result = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || array_is_list( $item ) || ! in_array( $item['kind'] ?? null, array( 'image_upload', 'file_upload' ), true ) ) {
				continue;
			}
			$constraints = $item['constraints'] ?? array();
			if ( ! is_array( $constraints ) || array_is_list( $constraints ) ) {
				$this->stored_invalid();
			}
			$mime_types = $constraints['mime_types'] ?? ( 'image_upload' === $item['kind'] ? array( 'image/jpeg', 'image/png', 'image/webp' ) : null );
			if ( ! is_array( $mime_types ) || ! array_is_list( $mime_types ) || array() === $mime_types ) {
				$this->stored_invalid();
			}
			foreach ( $mime_types as $mime ) {
				if ( ! is_string( $mime ) || strlen( $mime ) > 191 ) {
					$this->stored_invalid();
				}
			}
			$max_bytes = $constraints['max_bytes'] ?? 26214400;
			if ( ! is_int( $max_bytes ) || $max_bytes < 1 || $max_bytes > 26214400 ) {
				$this->stored_invalid();
			}
			$result[] = array(
				'id'         => $this->public_id( $item['response_id'] ?? null ),
				'kind'       => $item['kind'],
				'max_bytes'  => $max_bytes,
				'mime_types' => array_values( array_unique( $mime_types ) ),
			);
		}
		return $result;
	}

	/**
	 * Root.
	 *
	 * @param string $upload_id Upload id.
	 * @param bool   $for_update For update.
	 * @return array<string,mixed>|null
	 */
	private function root( string $upload_id, bool $for_update ): ?array {
		$sql = 'SELECT id, public_id, lock_version, created_by_user_id, assignment_id, file_public_id, state, mapping_schema, mapping_version, mapping_json, mapping_hash, item_total, success_total, failure_total FROM '
			. self::TABLE_UPLOADS . ' WHERE public_id = %s LIMIT 1';
		if ( $for_update ) {
			$sql .= ' FOR UPDATE';
		}
		return $this->get_row( $this->prepare( $sql, array( $upload_id ) ) );
	}

	/**
	 * Source file.
	 *
	 * @param string $file_id File id.
	 * @param bool   $for_update For update.
	 * @return array<string,mixed>|null
	 */
	private function source_file( string $file_id, bool $for_update ): ?array {
		$sql = 'SELECT id, public_id, lock_version, created_by_user_id, owner_type, owner_public_id, purpose, storage_key, original_name, byte_size, sha256, state, scan_state, retention_class, purged_at_utc FROM '
			. self::TABLE_PRIVATE_FILES . ' WHERE public_id = %s LIMIT 1';
		if ( $for_update ) {
			$sql .= ' FOR UPDATE';
		}
		return $this->get_row( $this->prepare( $sql, array( $file_id ) ) );
	}

	/**
	 * Same claim.
	 *
	 * @param Submission_Zip_Scan_Job $job Job.
	 * @param array<string,mixed>     $root Root.
	 * @param array<string,mixed>     $file File.
	 * @return bool
	 */
	private function same_claim( Submission_Zip_Scan_Job $job, array $root, array $file ): bool {
		return $job->upload_id() === ( $root['public_id'] ?? null )
			&& $job->upload_lock_version() === $this->non_negative_integer( $root['lock_version'] ?? null )
			&& $job->actor_user_id() === $this->positive_integer( $root['created_by_user_id'] ?? null )
			&& $job->source_file_id() === ( $root['file_public_id'] ?? null )
			&& 'scanning' === ( $root['state'] ?? null )
			&& self::MAPPING_SCHEMA === ( $root['mapping_schema'] ?? null )
			&& self::VERSION === $this->positive_integer( $root['mapping_version'] ?? null )
			&& hash_equals( $job->mapping_hash(), (string) ( $root['mapping_hash'] ?? '' ) )
			&& 0 === $this->non_negative_integer( $root['item_total'] ?? null )
			&& 0 === $this->non_negative_integer( $root['success_total'] ?? null )
			&& 0 === $this->non_negative_integer( $root['failure_total'] ?? null )
			&& $job->source_file_id() === ( $file['public_id'] ?? null )
			&& $job->source_file_lock_version() === $this->non_negative_integer( $file['lock_version'] ?? null )
			&& $job->actor_user_id() === $this->positive_integer( $file['created_by_user_id'] ?? null )
			&& 'assignment' === ( $file['owner_type'] ?? null ) && $job->assignment_id() === ( $file['owner_public_id'] ?? null )
			&& 'submission_zip_source' === ( $file['purpose'] ?? null )
			&& $job->source_storage_key() === ( $file['storage_key'] ?? null )
			&& $job->source_original_name() === ( $file['original_name'] ?? null )
			&& $job->source_byte_size() === $this->positive_integer( $file['byte_size'] ?? null )
			&& hash_equals( $job->source_sha256(), (string) ( $file['sha256'] ?? '' ) )
			&& 'quarantined' === ( $file['state'] ?? null ) && 'pending' === ( $file['scan_state'] ?? null )
			&& 'submission_zip_staging' === ( $file['retention_class'] ?? null ) && null === ( $file['purged_at_utc'] ?? null );
	}

	/**
	 * Insert item.
	 *
	 * @param array<string,mixed>     $root Root.
	 * @param Submission_Zip_Scan_Job $job Job.
	 * @param array<string,mixed>     $item Item.
	 * @param string                  $occurred_at_utc Occurred at utc.
	 * @return void
	 */
	private function insert_item( array $root, Submission_Zip_Scan_Job $job, array $item, string $occurred_at_utc ): void {
		$this->insert_or_fail(
			self::TABLE_ITEMS,
			array(
				'lock_version'                => 0,
				'created_by_user_id'          => $job->actor_user_id(),
				'created_at_utc'              => $occurred_at_utc,
				'updated_by_user_id'          => $job->actor_user_id(),
				'updated_at_utc'              => $occurred_at_utc,
				'zip_upload_id'               => $this->positive_integer( $root['id'] ?? null ),
				'item_no'                     => $item['item_no'],
				'relative_path'               => $item['relative_path'],
				'file_public_id'              => $item['file_public_id'],
				'learner_user_id'             => null,
				'state'                       => $item['state'],
				'byte_size'                   => $item['byte_size'],
				'sha256'                      => $item['sha256'],
				'result_submission_public_id' => null,
				'error_code'                  => $item['error_code'],
			)
		);
	}

	/**
	 * Insert audit.
	 *
	 * @param Submission_Zip_Scan_Job $job Job.
	 * @param string                  $state State.
	 * @param int                     $total Total.
	 * @param int                     $success Success.
	 * @param int                     $failure Failure.
	 * @param string|null             $reason Reason.
	 * @param string                  $public_id Public id.
	 * @param string                  $occurred_at Occurred at.
	 * @return void
	 */
	private function insert_audit( Submission_Zip_Scan_Job $job, string $state, int $total, int $success, int $failure, ?string $reason, string $public_id, string $occurred_at ): void {
		$previous = $this->get_var( 'SELECT event_hash FROM ' . self::TABLE_AUDIT . ' ORDER BY id DESC LIMIT 1 FOR UPDATE' );
		if ( null !== $previous ) {
			$this->hash_value( $previous );
		}
		$event_type    = 'ready' === $state ? self::EVENT_COMPLETE : self::EVENT_REJECT;
		$metadata_json = $this->canonical->encode(
			array(
				'failure_total' => $failure,
				'item_total'    => $total,
				'reason_code'   => $reason,
				'success_total' => $success,
			)
		);
		$before        = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'mapping_revision' => $job->mapping_revision(),
					'state'            => 'scanning',
				)
			)
		);
		$after         = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'failure_total'    => $failure,
					'item_total'       => $total,
					'mapping_revision' => $job->mapping_revision() + 1,
					'state'            => $state,
					'success_total'    => $success,
				)
			)
		);
		$event_hash    = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'action_key'          => $event_type,
					'after_digest'        => $after,
					'before_digest'       => $before,
					'correlation_id'      => 'submission-zip-scan:' . $job->upload_id(),
					'metadata_hash'       => hash( 'sha256', $metadata_json ),
					'object_public_id'    => $job->upload_id(),
					'object_type'         => 'submission_zip',
					'occurred_at_utc'     => $occurred_at,
					'previous_event_hash' => null === $previous ? null : (string) $previous,
				)
			)
		);
		$this->insert_or_fail(
			self::TABLE_AUDIT,
			array(
				'public_id'           => $public_id,
				'occurred_at_utc'     => $occurred_at,
				'actor_user_id'       => null,
				'action_key'          => $event_type,
				'object_type'         => 'submission_zip',
				'object_public_id'    => $job->upload_id(),
				'context_type'        => $job->context_type(),
				'context_id'          => $job->context_id(),
				'context_revision'    => $job->context_revision(),
				'correlation_id'      => 'submission-zip-scan:' . $job->upload_id(),
				'before_digest'       => $before,
				'after_digest'        => $after,
				'metadata_schema'     => self::AUDIT_SCHEMA,
				'metadata_version'    => self::VERSION,
				'metadata_json'       => $metadata_json,
				'metadata_hash'       => hash( 'sha256', $metadata_json ),
				'previous_event_hash' => null === $previous ? null : (string) $previous,
				'event_hash'          => $event_hash,
			)
		);
	}

	/**
	 * Insert outbox.
	 *
	 * @param Submission_Zip_Scan_Job $job Job.
	 * @param string                  $state State.
	 * @param int                     $total Total.
	 * @param int                     $success Success.
	 * @param int                     $failure Failure.
	 * @param string|null             $reason Reason.
	 * @param string                  $public_id Public id.
	 * @param string                  $occurred_at Occurred at.
	 * @return void
	 */
	private function insert_outbox( Submission_Zip_Scan_Job $job, string $state, int $total, int $success, int $failure, ?string $reason, string $public_id, string $occurred_at ): void {
		$event_type   = 'ready' === $state ? self::EVENT_COMPLETE : self::EVENT_REJECT;
		$payload_json = $this->canonical->encode(
			array(
				'assignment_id'   => $job->assignment_id(),
				'failure_total'   => $failure,
				'item_total'      => $total,
				'mapping_version' => $job->mapping_revision() + 1,
				'reason_code'     => $reason,
				'state'           => $state,
				'success_total'   => $success,
				'upload_id'       => $job->upload_id(),
			)
		);
		$this->insert_or_fail(
			self::TABLE_OUTBOX,
			array(
				'public_id'            => $public_id,
				'lock_version'         => 0,
				'created_by_user_id'   => $job->actor_user_id(),
				'created_at_utc'       => $occurred_at,
				'updated_by_user_id'   => $job->actor_user_id(),
				'updated_at_utc'       => $occurred_at,
				'aggregate_type'       => 'submission_zip',
				'aggregate_public_id'  => $job->upload_id(),
				'event_type'           => $event_type,
				'idempotency_key'      => 'submission-zip-scan:' . $job->upload_id(),
				'payload_schema'       => self::PAYLOAD_SCHEMA,
				'payload_version'      => self::VERSION,
				'payload_json'         => $payload_json,
				'payload_hash'         => hash( 'sha256', $payload_json ),
				'available_at_utc'     => $occurred_at,
				'published_at_utc'     => null,
				'lease_token'          => null,
				'lease_expires_at_utc' => null,
				'attempt_count'        => 0,
				'last_error_code'      => null,
			)
		);
	}

	/**
	 * Reject invalid items.
	 *
	 * @param array<array-key,mixed> $items Items.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_items( array $items ): void {
		if ( ! array_is_list( $items ) || array() === $items || count( $items ) > 500 ) {
			throw new InvalidArgumentException( 'Submission ZIP scan items are invalid.' );
		}
		foreach ( $items as $index => $item ) {
			if ( ! is_array( $item ) || array_is_list( $item ) ) {
				throw new InvalidArgumentException( 'Submission ZIP scan item is invalid.' );
			}
			$this->exact_keys( $item, array( 'byte_size', 'error_code', 'file_public_id', 'item_no', 'relative_path', 'sha256', 'state' ) );
			if (
				$index + 1 !== ( $item['item_no'] ?? null ) || ! is_int( $item['byte_size'] ?? null ) || $item['byte_size'] < 0
				|| ! is_string( $item['relative_path'] ?? null ) || '' === $item['relative_path'] || strlen( $item['relative_path'] ) > 512
				|| ! is_string( $item['sha256'] ?? null ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $item['sha256'] )
				|| ! in_array( $item['state'] ?? null, array( 'ready', 'rejected' ), true )
			) {
				throw new InvalidArgumentException( 'Submission ZIP scan item value is invalid.' );
			}
			if ( 'ready' === $item['state'] ) {
				new Public_Id( is_string( $item['file_public_id'] ?? null ) ? $item['file_public_id'] : '' );
				if ( null !== $item['error_code'] ) {
					throw new InvalidArgumentException( 'Ready ZIP item has an error.' );
				}
			} else {
				if ( null !== $item['file_public_id'] || ! is_string( $item['error_code'] ?? null ) ) {
					throw new InvalidArgumentException( 'Rejected ZIP item lacks an error.' );
				}
				$this->assert_reason( $item['error_code'] );
			}
		}
	}

	/**
	 * Reject invalid mapping.
	 *
	 * @param array<string,mixed>            $mapping Mapping.
	 * @param Submission_Zip_Scan_Job        $job Job.
	 * @param array<int,array<string,mixed>> $items Items.
	 * @param string|null                    $reason Reason.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_mapping( array $mapping, Submission_Zip_Scan_Job $job, array $items, ?string $reason ): void {
		$this->exact_keys( $mapping, array( 'items', 'mapping_revision', 'scan_error_code', 'source' ) );
		if (
			$mapping['source'] !== $job->mapping_source() || $mapping['mapping_revision'] !== $job->mapping_revision() + 1
			|| ! is_array( $mapping['items'] ?? null ) || ! array_is_list( $mapping['items'] )
			|| count( $mapping['items'] ) !== count( $items ) || $mapping['scan_error_code'] !== $reason
		) {
			throw new InvalidArgumentException( 'Submission ZIP scan mapping is invalid.' );
		}
		if ( null !== $reason ) {
			$this->assert_reason( $reason );
		}
	}

	/**
	 * Reject invalid reason.
	 *
	 * @param string $reason Reason.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_reason( string $reason ): void {
		if ( 1 !== preg_match( '/^zip_[a-z0-9_]{2,95}$/D', $reason ) ) {
			throw new InvalidArgumentException( 'Submission ZIP scan reason is invalid.' );
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
			$decoded = json_decode( $json, true, 512, JSON_THROW_ON_ERROR ); } catch ( JsonException ) {
			$this->stored_invalid();
			}
			if ( ! is_array( $decoded ) || array_is_list( $decoded ) || $this->canonical->encode( $decoded ) !== $json ) {
				$this->stored_invalid();
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
			throw new InvalidArgumentException( 'Submission ZIP stored object shape is invalid.' );
		}
	}

	/**
	 * Public id.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function public_id( mixed $value ): string {
		if ( ! is_string( $value ) ) {
			$this->stored_invalid();
		}
		try {
			return ( new Public_Id( $value ) )->value();
		} catch ( Throwable ) {
			$this->stored_invalid();
		}
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
	 * Hash value.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function hash_value( mixed $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $value ) ) {
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
	 * Token.
	 *
	 * @param mixed $value Value.
	 * @param int   $maximum Maximum.
	 * @return string
	 */
	private function token( mixed $value, int $maximum ): string {
		if ( ! is_string( $value ) || '' === $value || strlen( $value ) > $maximum || 1 === preg_match( '/[\x00-\x1F\x7F]/', $value ) ) {
			$this->stored_invalid();
		}
		return $value;
	}

	/**
	 * Filename.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function filename( mixed $value ): string {
		if ( ! is_string( $value ) || '' === $value || strlen( $value ) > 255 || str_contains( $value, "\0" ) ) {
			$this->stored_invalid();
		}
		return $value;
	}

	/**
	 * Storage key.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function storage_key( mixed $value ): string {
		if ( ! is_string( $value ) || strlen( $value ) > 512 || 1 !== preg_match( '/^[a-z0-9][a-z0-9._\/-]*$/D', $value ) || in_array( '..', explode( '/', $value ), true ) ) {
			$this->stored_invalid();
		}
		return $value;
	}

	/**
	 * Text.
	 *
	 * @param mixed $value Value.
	 * @param int   $maximum Maximum.
	 * @return string
	 */
	private function text( mixed $value, int $maximum ): string {
		if ( ! is_string( $value ) || '' === trim( $value ) || strlen( $value ) > $maximum || 1 !== preg_match( '//u', $value ) ) {
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
			throw new InvalidArgumentException( 'Submission ZIP UTC is invalid.' );
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
	 * @param array<int,mixed> $values Values.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function prepare( string $sql, array $values ): string {
		$prepared = $this->database()->prepare( $sql, ...$values );
		if ( ! is_string( $prepared ) || '' === $prepared ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Zip_Import_Failure::because( Zip_Import_Failure::STORAGE_UNAVAILABLE );
		}
		return $prepared;
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
		$this->assert_database_ok( $db );
		if ( ! is_array( $rows ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Zip_Import_Failure::because( Zip_Import_Failure::STORAGE_UNAVAILABLE );
		}
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Zip_Import_Failure::because( Zip_Import_Failure::STORAGE_UNAVAILABLE );
			}
		}
		return $rows;
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
		$db = $this->database ?? ( $GLOBALS['wpdb'] ?? null );
		if ( ! is_object( $db ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Zip_Import_Failure::because( Zip_Import_Failure::STORAGE_UNAVAILABLE );
		}
		foreach ( array( 'prepare', 'get_row', 'get_results', 'get_var', 'insert', 'query' ) as $method ) {
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
