<?php
/**
 * WordPress legacy migration zip writer.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\LegacyMigration\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Approve_Legacy_Migration;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Legacy_Migration_Failure;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Legacy_Target_Transformation_Failure;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Migration_Plan;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Source_Blob;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Source_Item;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Target_Write_Result;
use OJAI\Dashboard\Operations\LegacyMigration\Port\Legacy_Migration_Zip_Writer_Port_Interface;
use OJAI\Dashboard\Operations\LegacyMigration\Port\Legacy_Source_Blob_Reader_Port_Interface;
use OJAI\Dashboard\Operations\LegacyMigration\Port\Legacy_Target_Batch_Lifecycle_Port_Interface;
use OJAI\Dashboard\Storage\Port\Private_File_Store_Port_Interface;
use OJAI\Dashboard\Submission\Student\Domain\Submission_Attachment_Malware_Verdict;
use OJAI\Dashboard\Submission\Student\Port\Submission_Attachment_Malware_Scanner_Port_Interface;
use OJAI\Dashboard\Submission\ZipImport\Application\Zip_Import_Failure;
use OJAI\Dashboard\Submission\ZipImport\Domain\Submission_Zip_Entry;
use OJAI\Dashboard\Submission\ZipImport\Domain\Submission_Zip_Entry_Reader;
use OJAI\Dashboard\Submission\ZipImport\Domain\Submission_Zip_Item_Content_Inspector;
use OJAI\Dashboard\Submission\ZipImport\Domain\Submission_Zip_Item_Content_Verdict;
use OJAI\Dashboard\Submission\ZipImport\Domain\Submission_Zip_Upload_Profile;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Restores legacy ZIP bytes through the current bounded private-file pipeline.
 */
final class WordPress_Legacy_Migration_Zip_Writer implements
	Legacy_Migration_Zip_Writer_Port_Interface,
	Legacy_Target_Batch_Lifecycle_Port_Interface {
	private const TABLE_RUNS          = 'oj_ai_import_runs';
	private const TABLE_ITEMS         = 'oj_ai_import_items';
	private const TABLE_ASSIGNMENTS   = 'oj_ai_assignments';
	private const TABLE_PUBLICATIONS  = 'oj_ai_worksheet_publications';
	private const TABLE_UPLOADS       = 'oj_ai_zip_uploads';
	private const TABLE_UPLOAD_ITEMS  = 'oj_ai_zip_upload_items';
	private const TABLE_PRIVATE_FILES = 'oj_ai_private_files';
	private const ITEM_SCHEMA         = 'oj_ai.legacy_item_payload';
	private const ITEM_PROFILE        = 'oj_ai.legacy_item_payload@1.0.0';
	private const MAPPING_SCHEMA      = 'oj_ai.submission_zip_mapping';
	private const PROFILE_VERSION     = 1;

	/**
	 * Profile.
	 *
	 * @var Legacy_Source_Profile_0_1_2
	 */
	private Legacy_Source_Profile_0_1_2 $profile;
	/**
	 * Archives.
	 *
	 * @var Submission_Zip_Upload_Profile
	 */
	private Submission_Zip_Upload_Profile $archives;
	/**
	 * Entries.
	 *
	 * @var Submission_Zip_Entry_Reader
	 */
	private Submission_Zip_Entry_Reader $entries;
	/**
	 * Contents.
	 *
	 * @var Submission_Zip_Item_Content_Inspector
	 */
	private Submission_Zip_Item_Content_Inspector $contents;
	/**
	 * Created storage keys.
	 *
	 * @var array<int,string>
	 */
	private array $created_storage_keys = array();
	/**
	 * Batch active.
	 *
	 * @var bool
	 */
	private bool $batch_active = false;

	/**
	 * Initialize WordPress legacy migration zip writer dependencies and state.
	 *
	 * @param Canonical_Json                                       $canonical Canonical JSON encoder used for stable digests.
	 * @param Legacy_Source_Blob_Reader_Port_Interface             $source_blobs Source blobs.
	 * @param Private_File_Store_Port_Interface                    $private_files Private files.
	 * @param Submission_Attachment_Malware_Scanner_Port_Interface $malware Malware.
	 * @param object|null                                          $database Database.
	 * @param Public_Id_Generator|null                             $ids Ids.
	 * @param Legacy_Source_Profile_0_1_2|null                     $profile Profile.
	 * @param Submission_Zip_Upload_Profile|null                   $archives Archives.
	 * @param Submission_Zip_Entry_Reader|null                     $entries Entries.
	 * @param Submission_Zip_Item_Content_Inspector|null           $contents Contents.
	 */
	public function __construct(
		private Canonical_Json $canonical,
		private Legacy_Source_Blob_Reader_Port_Interface $source_blobs,
		private Private_File_Store_Port_Interface $private_files,
		private Submission_Attachment_Malware_Scanner_Port_Interface $malware,
		private ?object $database = null,
		private ?Public_Id_Generator $ids = null,
		?Legacy_Source_Profile_0_1_2 $profile = null,
		?Submission_Zip_Upload_Profile $archives = null,
		?Submission_Zip_Entry_Reader $entries = null,
		?Submission_Zip_Item_Content_Inspector $contents = null
	) {
		$this->profile  = $profile ?? new Legacy_Source_Profile_0_1_2();
		$this->archives = $archives ?? new Submission_Zip_Upload_Profile();
		$this->entries  = $entries ?? new Submission_Zip_Entry_Reader( $this->archives );
		$this->contents = $contents ?? new Submission_Zip_Item_Content_Inspector( $this->entries );
	}

	/**
	 * Begin batch.
	 *
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function begin_batch(): void {
		if ( $this->batch_active ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		$this->created_storage_keys = array();
		$this->batch_active         = true;
	}

	/**
	 * Commit batch.
	 *
	 * @return void
	 */
	public function commit_batch(): void {
		$this->created_storage_keys = array();
		$this->batch_active         = false;
	}

	/**
	 * Rollback batch.
	 *
	 * @return void
	 */
	public function rollback_batch(): void {
		try {
			$this->compensate_from( 0 ); } finally {
			$this->batch_active = false;
			}
	}

	/**
	 * Write.
	 *
	 * @param int                $run_database_id Run database id.
	 * @param Legacy_Source_Item $item Item.
	 * @param int                $actor_user_id Authenticated actor from the access context.
	 * @param string             $created_at_utc Created at utc.
	 * @return Legacy_Target_Write_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function write( int $run_database_id, Legacy_Source_Item $item, int $actor_user_id, string $created_at_utc ): Legacy_Target_Write_Result {
		if ( ! $this->batch_active || $run_database_id < 1 || $actor_user_id < 1 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		$this->utc( $created_at_utc );
		$marker = count( $this->created_storage_keys );
		try {
			return match ( $item->source_type() ) {
				'zip_uploads' => $this->write_root( $run_database_id, $item, $actor_user_id, $created_at_utc ),
				'zip_upload_items' => $this->write_item( $run_database_id, $item, $actor_user_id, $created_at_utc ),
				default => throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::UNSUPPORTED ),
			};
		} catch ( Legacy_Target_Transformation_Failure $failure ) {
			$this->compensate_from( $marker );
			throw $failure;
		} catch ( Legacy_Migration_Failure $failure ) {
			throw $failure;
		} catch ( Zip_Import_Failure | InvalidArgumentException | JsonException $failure ) {
			$this->compensate_from( $marker );
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::SOURCE_INVALID );
		} catch ( Throwable $failure ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE, $failure );
		}
	}

	/**
	 * Write root.
	 *
	 * @param int                $run_database_id Run database id.
	 * @param Legacy_Source_Item $item Item.
	 * @param int                $actor_user_id Authenticated actor from the access context.
	 * @param string             $created_at_utc Created at utc.
	 * @return Legacy_Target_Write_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function write_root( int $run_database_id, Legacy_Source_Item $item, int $actor_user_id, string $created_at_utc ): Legacy_Target_Write_Result {
		$plan = $this->run_plan( $run_database_id );
		$this->assert_exact_source_row( 'zip_uploads', $item->record() );
		$this->assert_source_checksum( $item, $plan );
		$tables = $this->source_tables( $plan );
		$source = $this->source_row_by_id( $tables['zip_uploads'], 'zip_uploads', $item->source_id() );
		if ( null !== ( $source['deleted_at'] ?? null ) || ! hash_equals( $item->source_checksum(), $this->source_checksum( $plan, 'zip_uploads', $item->source_id(), $source ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::SOURCE_INVALID );
		}

		$assignment = $this->assignment_dependency( $run_database_id, $plan, $tables, $this->positive_integer( $source['assignment_id'] ?? null ) );
		$blob       = $this->source_blobs->read( $this->positive_integer( $source['file_id'] ?? null ) );
		$archive    = $this->inspect_archive( $blob, $this->filename( $source['original_filename'] ?? null ) );
		$this->assert_clean_malware( $blob->path(), true );
		$entry_rows = $this->entries->entries( $blob->path(), $blob->sha256() );
		if ( count( $entry_rows ) !== $archive['entry_count'] ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::SOURCE_INVALID );
		}
		$manifest  = $this->source_item_manifest( $plan, $tables['zip_upload_items'], $item->source_id(), $entry_rows );
		$rechecked = $this->source_blobs->read( $blob->file_id() );
		if ( ! $blob->same_bytes( $rechecked ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::SOURCE_INVALID );
		}

		$upload_id    = $this->ids()->create()->value();
		$file_id      = $this->ids()->create()->value();
		$storage_key  = 'legacy-migration/submission-zips/' . strtolower( $upload_id ) . '/source-' . $blob->sha256() . '.zip';
		$expires_at   = $this->plus_seconds( $created_at_utc, 86400 );
		$mapping      = array(
			'items'            => array(),
			'mapping_revision' => 0,
			'source'           => array(
				'byte_size'              => $archive['byte_size'],
				'compressed_entry_bytes' => $archive['compressed_entry_bytes'],
				'entry_count'            => $archive['entry_count'],
				'sha256'                 => $archive['sha256'],
				'uncompressed_bytes'     => $archive['uncompressed_bytes'],
			),
		);
		$mapping_json = $this->canonical->encode( $mapping );
		$mapping_hash = hash( 'sha256', $mapping_json );
		$metadata     = array(
			'archive_hash'           => $blob->sha256(),
			'assignment_public_id'   => $assignment['public_id'],
			'file_public_id'         => $file_id,
			'mapping_hash'           => $mapping_hash,
			'zip_item_count'         => $manifest['count'],
			'zip_item_manifest_hash' => $manifest['hash'],
			'zip_upload_source_key'  => $item->source_key(),
		);
		$content_hash = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'archive_hash'         => $blob->sha256(),
					'assignment_public_id' => $assignment['public_id'],
					'mapping_hash'         => $mapping_hash,
					'source_checksum'      => $item->source_checksum(),
					'upload_public_id'     => $upload_id,
				)
			)
		);
		$result       = new Legacy_Target_Write_Result( 'submission_zip', $upload_id, $content_hash, $metadata );

		$created = $this->private_files->put_quarantined( $blob->path(), $storage_key, $blob->sha256() );
		if ( $created ) {
			$this->created_storage_keys[] = $storage_key;
		}
		$this->insert_private_file(
			array(
				'public_id'       => $file_id,
				'actor_user_id'   => $actor_user_id,
				'created_at_utc'  => $created_at_utc,
				'owner_type'      => 'assignment',
				'owner_public_id' => $assignment['public_id'],
				'purpose'         => 'submission_zip_source',
				'storage_key'     => $storage_key,
				'original_name'   => $archive['original_name'],
				'mime_type'       => 'application/zip',
				'byte_size'       => $archive['byte_size'],
				'sha256'          => $archive['sha256'],
				'expires_at_utc'  => $expires_at,
			)
		);
		$this->insert_or_fail(
			self::TABLE_UPLOADS,
			array(
				'public_id'           => $upload_id,
				'lock_version'        => 0,
				'created_by_user_id'  => $actor_user_id,
				'created_at_utc'      => $created_at_utc,
				'updated_by_user_id'  => $actor_user_id,
				'updated_at_utc'      => $created_at_utc,
				'assignment_id'       => $assignment['database_id'],
				'uploaded_by_user_id' => $actor_user_id,
				'file_public_id'      => $file_id,
				'state'               => 'scanning',
				'idempotency_key'     => 'legacy-migration:zip:' . $run_database_id . ':' . $item->source_id(),
				'mapping_schema'      => self::MAPPING_SCHEMA,
				'mapping_version'     => self::PROFILE_VERSION,
				'mapping_json'        => $mapping_json,
				'mapping_hash'        => $mapping_hash,
				'item_total'          => 0,
				'success_total'       => 0,
				'failure_total'       => 0,
				'cleanup_after_utc'   => null,
			)
		);
		return $result;
	}

	/**
	 * Write item.
	 *
	 * @param int                $run_database_id Run database id.
	 * @param Legacy_Source_Item $item Item.
	 * @param int                $actor_user_id Authenticated actor from the access context.
	 * @param string             $created_at_utc Created at utc.
	 * @return Legacy_Target_Write_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function write_item( int $run_database_id, Legacy_Source_Item $item, int $actor_user_id, string $created_at_utc ): Legacy_Target_Write_Result {
		$plan = $this->run_plan( $run_database_id );
		$this->assert_exact_source_row( 'zip_upload_items', $item->record() );
		$this->assert_source_checksum( $item, $plan );
		$tables = $this->source_tables( $plan );
		$source = $this->source_row_by_id( $tables['zip_upload_items'], 'zip_upload_items', $item->source_id() );
		if ( null !== ( $source['deleted_at'] ?? null ) || ! hash_equals( $item->source_checksum(), $this->source_checksum( $plan, 'zip_upload_items', $item->source_id(), $source ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::SOURCE_INVALID );
		}
		$root_source_id  = $this->positive_integer( $source['zip_upload_id'] ?? null );
		$parent          = $this->import_receipt( $run_database_id, 'zip_uploads', $root_source_id, 'submission_zip' );
		$parent_metadata = $parent['result']->metadata();
		$root_source     = $this->source_row_by_id( $tables['zip_uploads'], 'zip_uploads', $root_source_id );
		if ( null !== ( $root_source['deleted_at'] ?? null ) || ! hash_equals( $parent['source_checksum'], $this->source_checksum( $plan, 'zip_uploads', $root_source_id, $root_source ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::SOURCE_STALE );
		}

		$blob    = $this->source_blobs->read( $this->positive_integer( $root_source['file_id'] ?? null ) );
		$archive = $this->inspect_archive( $blob, $this->filename( $root_source['original_filename'] ?? null ) );
		if ( ! hash_equals( (string) ( $parent_metadata['archive_hash'] ?? '' ), $blob->sha256() ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::SOURCE_STALE );
		}
		$this->assert_clean_malware( $blob->path(), true );
		$entry_rows = $this->entries->entries( $blob->path(), $blob->sha256() );
		$manifest   = $this->source_item_manifest( $plan, $tables['zip_upload_items'], $root_source_id, $entry_rows );
		if (
			$manifest['count'] !== $this->positive_integer( $parent_metadata['zip_item_count'] ?? null )
			|| ! hash_equals( $manifest['hash'], (string) ( $parent_metadata['zip_item_manifest_hash'] ?? '' ) )
			|| ! isset( $manifest['rows'][ $item->source_id() ] )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::SOURCE_STALE );
		}

		$path      = $this->source_path( $source['original_path'] ?? null );
		$entry     = $this->entry_for_path( $entry_rows, $path );
		$root      = $this->target_root( $parent['result'], $parent_metadata );
		$responses = $this->response_profile( $root );
		$existing  = $this->target_items( $root, $responses );
		if ( count( $existing ) >= $manifest['count'] ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		foreach ( $existing as $stored ) {
			if ( $stored['item_no'] === $entry->item_no() || hash_equals( $stored['relative_path'], $entry->relative_path() ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::RUN_STATE_CONFLICT );
			}
		}

		$temporary = null;
		try {
			$temporary = $this->entries->extract_to_temporary_file( $blob->path(), $entry );
			$size      = filesize( $temporary );
			$sha256    = hash_file( 'sha256', $temporary );
			if ( ! is_int( $size ) || $size !== $entry->byte_size() || ! is_string( $sha256 ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $sha256 ) ) {
				throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::SOURCE_INVALID );
			}
			$malware = $this->malware->scan( $temporary );
			if ( Submission_Attachment_Malware_Verdict::UNAVAILABLE === $malware->state() ) {
				throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
			}
			$state      = 'rejected';
			$mime       = null;
			$error      = 'zip_item_malware_detected';
			$compatible = array();
			if ( Submission_Attachment_Malware_Verdict::CLEAN === $malware->state() ) {
				$verdict = $this->contents->inspect( $temporary, $entry->relative_path(), $size, $sha256 );
				if ( Submission_Zip_Item_Content_Verdict::UNAVAILABLE === $verdict->state() ) {
					throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
				}
				if ( Submission_Zip_Item_Content_Verdict::REJECTED === $verdict->state() ) {
					$error = (string) $verdict->reason_code();
				} else {
					$mime       = (string) $verdict->mime_type();
					$compatible = $this->compatible_responses( $responses, $mime, $size );
					if ( array() === $compatible ) {
						$error = 'zip_item_response_not_allowed'; } else {
						$state = 'ready';
						$error = null;
						}
				}
			}

			$rechecked = $this->source_blobs->read( $blob->file_id() );
			if ( ! $blob->same_bytes( $rechecked ) ) {
				throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::SOURCE_STALE );
			}
			$file_id = null;
			if ( 'ready' === $state ) {
				$file_id   = $this->ids()->create()->value();
				$extension = strtolower( (string) pathinfo( $entry->relative_path(), PATHINFO_EXTENSION ) );
				if ( 1 !== preg_match( '/^[a-z0-9]{1,10}$/D', $extension ) ) {
					throw new InvalidArgumentException( 'ZIP item extension is invalid.' );
				}
				$storage_key = 'legacy-migration/submission-zips/' . strtolower( $root['public_id'] ) . '/items/'
					. sprintf( '%04d', $entry->item_no() ) . '-' . $sha256 . '.' . $extension;
				$created     = $this->private_files->put_quarantined( $temporary, $storage_key, $sha256 );
				if ( $created ) {
					$this->created_storage_keys[] = $storage_key;
				}
				$this->insert_private_file(
					array(
						'public_id'       => $file_id,
						'actor_user_id'   => $actor_user_id,
						'created_at_utc'  => $created_at_utc,
						'owner_type'      => 'submission_zip',
						'owner_public_id' => $root['public_id'],
						'purpose'         => 'submission_zip_item',
						'storage_key'     => $storage_key,
						'original_name'   => $this->filename( basename( $entry->relative_path() ) ),
						'mime_type'       => $mime,
						'byte_size'       => $size,
						'sha256'          => $sha256,
						'expires_at_utc'  => $root['expires_at_utc'],
					)
				);
			}

			$this->insert_or_fail(
				self::TABLE_UPLOAD_ITEMS,
				array(
					'lock_version'                => 0,
					'created_by_user_id'          => $actor_user_id,
					'created_at_utc'              => $created_at_utc,
					'updated_by_user_id'          => $actor_user_id,
					'updated_at_utc'              => $created_at_utc,
					'zip_upload_id'               => $root['database_id'],
					'item_no'                     => $entry->item_no(),
					'relative_path'               => $entry->relative_path(),
					'file_public_id'              => $file_id,
					'learner_user_id'             => null,
					'state'                       => $state,
					'byte_size'                   => $size,
					'sha256'                      => $sha256,
					'result_submission_public_id' => null,
					'error_code'                  => $error,
				)
			);
			$candidate = array(
				'item_no'                 => $entry->item_no(),
				'relative_path'           => $entry->relative_path(),
				'file_public_id'          => $file_id,
				'state'                   => $state,
				'byte_size'               => $size,
				'sha256'                  => $sha256,
				'error_code'              => $error,
				'mime_type'               => $mime,
				'compatible_response_ids' => $compatible,
			);
			if ( count( $existing ) + 1 === $manifest['count'] ) {
				$this->finalize_root( $run_database_id, $root_source_id, $parent, $root, array_merge( $existing, array( $candidate ) ), $actor_user_id, $created_at_utc );
			}

			$result_metadata = array(
				'archive_hash'          => $blob->sha256(),
				'zip_item_hash'         => $item->source_checksum(),
				'zip_item_number'       => $entry->item_no(),
				'zip_upload_public_id'  => $root['public_id'],
				'zip_upload_source_key' => (string) $root_source_id,
			);
			if ( is_string( $file_id ) ) {
				$result_metadata['file_public_id'] = $file_id;
			}
			$content_hash = hash(
				'sha256',
				$this->canonical->encode(
					array(
						'byte_size'        => $size,
						'error_code'       => $error,
						'file_public_id'   => $file_id,
						'item_no'          => $entry->item_no(),
						'relative_path'    => $entry->relative_path(),
						'sha256'           => $sha256,
						'state'            => $state,
						'upload_public_id' => $root['public_id'],
					)
				)
			);
			return new Legacy_Target_Write_Result( 'submission_zip_item', $root['public_id'], $content_hash, $result_metadata );
		} finally {
			if ( is_string( $temporary ) && is_file( $temporary ) ) {
				@unlink( $temporary ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort removal of an owned temporary path must not mask the primary outcome. Private local streams and owned temporary paths require native filesystem operations, not WordPress FTP transport.
			} // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort cleanup of an owned temporary path must not replace the primary outcome with an OS warning. Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
		}
	}

	/**
	 * Inspect archive.
	 *
	 * @param Legacy_Source_Blob $blob Blob.
	 * @param string             $original_name Original name.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function inspect_archive( Legacy_Source_Blob $blob, string $original_name ): array {
		$result = $this->archives->inspect(
			array(
				'error'    => UPLOAD_ERR_OK,
				'name'     => $original_name,
				'size'     => $blob->byte_size(),
				'tmp_name' => $blob->path(),
				'type'     => 'application/octet-stream',
			)
		);
		if ( $result['byte_size'] !== $blob->byte_size() || ! hash_equals( $result['sha256'], $blob->sha256() ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::SOURCE_INVALID );
		}
		return $result;
	}

	/**
	 * Reject invalid clean malware.
	 *
	 * @param string $path Path.
	 * @param bool   $reject_infected Reject infected.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_clean_malware( string $path, bool $reject_infected ): void {
		$verdict = $this->malware->scan( $path );
		if ( Submission_Attachment_Malware_Verdict::UNAVAILABLE === $verdict->state() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		if ( $reject_infected && Submission_Attachment_Malware_Verdict::INFECTED === $verdict->state() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::SOURCE_INVALID );
		}
	}

	/**
	 * Source item manifest.
	 *
	 * @param array                           $plan Plan.
	 * @param string                          $table Table.
	 * @param int                             $root_source_id Root source id.
	 * @param array<int,Submission_Zip_Entry> $entries Entries.
	 * @return array{count:int,hash:string,rows:array<int,array<string,mixed>>}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function source_item_manifest( array $plan, string $table, int $root_source_id, array $entries ): array {
		$by_path = array();
		foreach ( $entries as $entry ) {
			$path = $entry->relative_path();
			if ( isset( $by_path[ $path ] ) ) {
				throw new InvalidArgumentException( 'ZIP entry path is ambiguous.' );
			}
			$by_path[ $path ] = $entry;
		}
		$rows     = $this->source_relations( $table, 'zip_upload_items', 'zip_upload_id', $root_source_id );
		$manifest = array();
		$result   = array();
		$used     = array();
		foreach ( $rows as $row ) {
			$source_id = $this->positive_integer( $row['id'] ?? null );
			$path      = $this->source_path( $row['original_path'] ?? null );
			if ( null !== ( $row['deleted_at'] ?? null ) || ! isset( $by_path[ $path ] ) || isset( $used[ $path ] ) ) {
				throw new InvalidArgumentException( 'Legacy ZIP item relation is invalid.' );
			}
			$used[ $path ]        = true;
			$checksum             = $this->source_checksum( $plan, 'zip_upload_items', $source_id, $row );
			$manifest[]           = array(
				'entry_number'    => $by_path[ $path ]->item_no(),
				'source_hash'     => $checksum,
				'source_key_hash' => hash( 'sha256', (string) $source_id ),
			);
			$result[ $source_id ] = $row;
		}
		if ( count( $rows ) !== count( $entries ) || count( $used ) !== count( $entries ) ) {
			throw new InvalidArgumentException( 'Legacy ZIP item count drifted.' );
		}
		return array(
			'count' => count( $rows ),
			'hash'  => hash( 'sha256', $this->canonical->encode( $manifest ) ),
			'rows'  => $result,
		);
	}

	/**
	 * Entry for path.
	 *
	 * @param array<int,Submission_Zip_Entry> $entries Entries.
	 * @param string                          $path Path.
	 * @return Submission_Zip_Entry
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function entry_for_path( array $entries, string $path ): Submission_Zip_Entry {
		$result = null;
		foreach ( $entries as $entry ) {
			if ( hash_equals( $path, $entry->relative_path() ) ) {
				if ( $result instanceof Submission_Zip_Entry ) {
					throw new InvalidArgumentException( 'ZIP entry path is ambiguous.' );
				}
				$result = $entry;
			}
		}
		if ( ! $result instanceof Submission_Zip_Entry ) {
			throw new InvalidArgumentException( 'ZIP entry is missing.' );
		}
		return $result;
	}

	/**
	 * Assignment dependency.
	 *
	 * @param int   $run_database_id Run database id.
	 * @param array $plan Plan.
	 * @param array $tables Tables.
	 * @param int   $source_id Source id.
	 * @return array{database_id:int,public_id:string,publication_public_id:string,publication_hash:string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assignment_dependency( int $run_database_id, array $plan, array $tables, int $source_id ): array {
		$source  = $this->source_row_by_id( $tables['worksheet_assignments'], 'worksheet_assignments', $source_id );
		$receipt = $this->import_receipt( $run_database_id, 'worksheet_assignments', $source_id, 'assignment' );
		if ( null !== ( $source['deleted_at'] ?? null ) || ! hash_equals( $receipt['source_checksum'], $this->source_checksum( $plan, 'worksheet_assignments', $source_id, $source ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::SOURCE_INVALID );
		}
		$metadata = $receipt['result']->metadata();
		$row      = $this->row(
			$this->prepare(
				'SELECT id, public_id, worksheet_publication_public_id, publication_hash FROM ' . self::TABLE_ASSIGNMENTS
				. ' WHERE public_id = %s LIMIT 1 FOR UPDATE',
				array( $receipt['result']->target_public_id() )
			)
		);
		if (
			null === $row || ! hash_equals( $receipt['result']->target_public_id(), (string) ( $row['public_id'] ?? '' ) )
			|| ! hash_equals( (string) ( $metadata['publication_public_id'] ?? '' ), (string) ( $row['worksheet_publication_public_id'] ?? '' ) )
			|| ! hash_equals( (string) ( $metadata['publication_hash'] ?? '' ), (string) ( $row['publication_hash'] ?? '' ) )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		return array(
			'database_id'           => $this->positive_integer( $row['id'] ?? null ),
			'public_id'             => (string) $row['public_id'],
			'publication_public_id' => (string) $row['worksheet_publication_public_id'],
			'publication_hash'      => (string) $row['publication_hash'],
		);
	}

	/**
	 * Target root.
	 *
	 * @param Legacy_Target_Write_Result $parent Parent.
	 * @param array                      $metadata Metadata.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function target_root( Legacy_Target_Write_Result $parent, array $metadata ): array { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.parentFound -- Preserve the established named-argument signature; these parameter names are legal in supported PHP versions.
		$row = $this->row(
			$this->prepare(
				'SELECT u.id, u.public_id, u.lock_version, u.assignment_id, u.file_public_id, u.state, u.mapping_schema, u.mapping_version, u.mapping_json, u.mapping_hash, u.item_total, u.success_total, u.failure_total, a.public_id AS assignment_public_id, a.worksheet_publication_public_id, a.publication_hash, p.response_schema_schema, p.response_schema_version, p.response_schema_json, p.response_schema_hash, f.owner_type, f.owner_public_id, f.purpose, f.original_name, f.mime_type, f.byte_size, f.sha256, f.state AS file_state, f.scan_state, f.retention_class, f.expires_at_utc, f.purged_at_utc FROM ' . self::TABLE_UPLOADS . ' u INNER JOIN ' . self::TABLE_ASSIGNMENTS . ' a ON a.id = u.assignment_id '
				. 'INNER JOIN ' . self::TABLE_PUBLICATIONS . ' p ON p.public_id = a.worksheet_publication_public_id '
				. 'INNER JOIN ' . self::TABLE_PRIVATE_FILES . ' f ON f.public_id = u.file_public_id '
				. 'WHERE u.public_id = %s LIMIT 1 FOR UPDATE',
				array( $parent->target_public_id() )
			)
		);
		if (
			null === $row || ! hash_equals( $parent->target_public_id(), (string) ( $row['public_id'] ?? '' ) )
			|| 'scanning' !== ( $row['state'] ?? null ) || self::MAPPING_SCHEMA !== ( $row['mapping_schema'] ?? null )
			|| self::PROFILE_VERSION !== $this->positive_integer( $row['mapping_version'] ?? null )
			|| 0 !== $this->non_negative_integer( $row['item_total'] ?? null )
			|| 0 !== $this->non_negative_integer( $row['success_total'] ?? null )
			|| 0 !== $this->non_negative_integer( $row['failure_total'] ?? null )
			|| ! hash_equals( (string) ( $metadata['assignment_public_id'] ?? '' ), (string) ( $row['assignment_public_id'] ?? '' ) )
			|| ! hash_equals( (string) ( $metadata['file_public_id'] ?? '' ), (string) ( $row['file_public_id'] ?? '' ) )
			|| ! hash_equals( (string) ( $metadata['mapping_hash'] ?? '' ), (string) ( $row['mapping_hash'] ?? '' ) )
			|| ! hash_equals( (string) ( $metadata['archive_hash'] ?? '' ), (string) ( $row['sha256'] ?? '' ) )
			|| 'assignment' !== ( $row['owner_type'] ?? null ) || ! hash_equals( (string) $row['assignment_public_id'], (string) ( $row['owner_public_id'] ?? '' ) )
			|| 'submission_zip_source' !== ( $row['purpose'] ?? null ) || 'application/zip' !== ( $row['mime_type'] ?? null )
			|| 'quarantined' !== ( $row['file_state'] ?? null ) || 'clean' !== ( $row['scan_state'] ?? null )
			|| 'submission_zip_staging' !== ( $row['retention_class'] ?? null ) || null !== ( $row['purged_at_utc'] ?? null )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		$mapping = $this->decode_stored( (string) ( $row['mapping_json'] ?? '' ), (string) ( $row['mapping_hash'] ?? '' ) );
		$this->exact_keys( $mapping, array( 'items', 'mapping_revision', 'source' ) );
		$source = $mapping['source'] ?? null;
		if ( ! is_array( $source ) || array_is_list( $source ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		$this->exact_keys( $source, array( 'byte_size', 'compressed_entry_bytes', 'entry_count', 'sha256', 'uncompressed_bytes' ) );
		if (
			array() !== $mapping['items']
			|| 0 !== $this->non_negative_integer( $mapping['mapping_revision'] ?? null )
			|| $this->positive_integer( $source['byte_size'] ?? null ) !== $this->positive_integer( $row['byte_size'] ?? null )
			|| $this->non_negative_integer( $source['compressed_entry_bytes'] ?? null ) > $this->positive_integer( $source['byte_size'] ?? null )
			|| $this->positive_integer( $source['entry_count'] ?? null ) !== $this->positive_integer( $metadata['zip_item_count'] ?? null )
			|| ! hash_equals( $this->hash_value( $source['sha256'] ?? null ), $this->hash_value( $row['sha256'] ?? null ) )
			|| $this->non_negative_integer( $source['uncompressed_bytes'] ?? null ) > Submission_Zip_Upload_Profile::MAX_UNCOMPRESSED_BYTES
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		$this->filename( $row['original_name'] ?? null );
		$row['database_id']    = $this->positive_integer( $row['id'] ?? null );
		$row['lock_version']   = $this->non_negative_integer( $row['lock_version'] ?? null );
		$row['public_id']      = (string) $row['public_id'];
		$row['expires_at_utc'] = $this->utc_value( $row['expires_at_utc'] ?? null );
		$row['mapping']        = $mapping;
		return $row;
	}

	/**
	 * Response profile.
	 *
	 * @param array $root Root.
	 * @return array<int,array{id:string,max_bytes:int,mime_types:array<int,string>}>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function response_profile( array $root ): array {
		if ( 'oj_ai.student_response_schema' !== ( $root['response_schema_schema'] ?? null ) || self::PROFILE_VERSION !== $this->positive_integer( $root['response_schema_version'] ?? null ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		$profile = $this->decode_stored( (string) ( $root['response_schema_json'] ?? '' ), (string) ( $root['response_schema_hash'] ?? '' ) );
		$items   = $profile['payload']['items'] ?? null;
		if ( 'oj_ai.student_response_schema' !== ( $profile['schema_id'] ?? null ) || ! is_array( $items ) || ! array_is_list( $items ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		$result = array();
		foreach ( $items as $value ) {
			if ( ! is_array( $value ) || array_is_list( $value ) || ! in_array( $value['kind'] ?? null, array( 'image_upload', 'file_upload' ), true ) ) {
				continue;
			}
			$constraints = $value['constraints'] ?? array();
			if ( ! is_array( $constraints ) || array_is_list( $constraints ) ) {
				throw new InvalidArgumentException( 'ZIP response constraints are invalid.' );
			}
			$mimes = $constraints['mime_types'] ?? ( 'image_upload' === $value['kind'] ? array( 'image/jpeg', 'image/png', 'image/webp' ) : null );
			if ( ! is_array( $mimes ) || ! array_is_list( $mimes ) || array() === $mimes ) {
				throw new InvalidArgumentException( 'ZIP response MIME constraints are invalid.' );
			}
			foreach ( $mimes as $mime ) {
				$this->mime( $mime );
			}
			$maximum = $constraints['max_bytes'] ?? Submission_Zip_Upload_Profile::MAX_ENTRY_BYTES;
			if ( ! is_int( $maximum ) || $maximum < 1 || $maximum > Submission_Zip_Upload_Profile::MAX_ENTRY_BYTES ) {
				throw new InvalidArgumentException( 'ZIP response byte limit is invalid.' );
			}
			$id = is_string( $value['response_id'] ?? null ) ? $value['response_id'] : '';
			new Public_Id( $id );
			$result[] = array(
				'id'         => $id,
				'max_bytes'  => $maximum,
				'mime_types' => array_values( array_unique( $mimes ) ),
			);
		}
		return $result;
	}

	/**
	 * Compatible responses.
	 *
	 * @param array<int,array{id:string,max_bytes:int,mime_types:array<int,string>}> $responses Responses.
	 * @param string                                                                 $mime Mime.
	 * @param int                                                                    $bytes Bytes.
	 * @return array<int,string>
	 */
	private function compatible_responses( array $responses, string $mime, int $bytes ): array {
		$result = array();
		foreach ( $responses as $response ) {
			if ( $bytes <= $response['max_bytes'] && in_array( $mime, $response['mime_types'], true ) ) {
				$result[] = $response['id'];
			}
		}
		sort( $result, SORT_STRING );
		return array_values( array_unique( $result ) );
	}

	/**
	 * Target items.
	 *
	 * @param array $root Root.
	 * @param array $responses Responses.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function target_items( array $root, array $responses ): array {
		$rows   = $this->results(
			$this->prepare(
				'SELECT i.item_no, i.relative_path, i.file_public_id, i.learner_user_id, i.state, i.byte_size, i.sha256, i.result_submission_public_id, i.error_code, f.owner_type, f.owner_public_id, f.purpose, f.mime_type, f.byte_size AS file_byte_size, f.sha256 AS file_sha256, f.state AS file_state, f.scan_state, f.retention_class, f.expires_at_utc, f.purged_at_utc FROM ' . self::TABLE_UPLOAD_ITEMS
				. ' i LEFT JOIN ' . self::TABLE_PRIVATE_FILES . ' f ON f.public_id = i.file_public_id '
				. 'WHERE i.zip_upload_id = %d ORDER BY i.item_no ASC FOR UPDATE',
				array( $root['database_id'] )
			)
		);
		$result = array();
		foreach ( $rows as $row ) {
			$state      = $row['state'] ?? null;
			$file_id    = $row['file_public_id'] ?? null;
			$bytes      = $this->non_negative_integer( $row['byte_size'] ?? null );
			$sha        = $this->hash_value( $row['sha256'] ?? null );
			$mime       = null;
			$compatible = array();
			if ( 'ready' === $state ) {
				if (
					! is_string( $file_id ) || ! Public_Id::is_valid( $file_id ) || null !== ( $row['error_code'] ?? null )
					|| 'submission_zip' !== ( $row['owner_type'] ?? null ) || ! hash_equals( $root['public_id'], (string) ( $row['owner_public_id'] ?? '' ) )
					|| 'submission_zip_item' !== ( $row['purpose'] ?? null ) || 'quarantined' !== ( $row['file_state'] ?? null )
					|| 'clean' !== ( $row['scan_state'] ?? null ) || 'submission_zip_staging' !== ( $row['retention_class'] ?? null )
					|| null !== ( $row['purged_at_utc'] ?? null ) || $bytes !== $this->non_negative_integer( $row['file_byte_size'] ?? null )
					|| ! hash_equals( $sha, (string) ( $row['file_sha256'] ?? '' ) )
				) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
				}
				$mime       = $this->mime( $row['mime_type'] ?? null );
				$compatible = $this->compatible_responses( $responses, $mime, $bytes );
				if ( array() === $compatible ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
				}
			} elseif ( 'rejected' === $state ) {
				if ( null !== $file_id || ! is_string( $row['error_code'] ?? null ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
				}
				$this->reason( $row['error_code'] );
			} else {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
			}
			if ( null !== ( $row['learner_user_id'] ?? null ) || null !== ( $row['result_submission_public_id'] ?? null ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
			}
			$result[] = array(
				'item_no'                 => $this->positive_integer( $row['item_no'] ?? null ),
				'relative_path'           => $this->source_path( $row['relative_path'] ?? null ),
				'file_public_id'          => $file_id,
				'state'                   => $state,
				'byte_size'               => $bytes,
				'sha256'                  => $sha,
				'error_code'              => $row['error_code'] ?? null,
				'mime_type'               => $mime,
				'compatible_response_ids' => $compatible,
			);
		}
		return $result;
	}

	/**
	 * Finalize root.
	 *
	 * @param int                            $run_database_id Run database id.
	 * @param int                            $source_id Source id.
	 * @param array                          $parent Parent.
	 * @param array                          $root Root.
	 * @param array<int,array<string,mixed>> $items Items.
	 * @param int                            $actor_user_id Authenticated actor from the access context.
	 * @param string                         $created_at_utc Created at utc.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function finalize_root( int $run_database_id, int $source_id, array $parent, array $root, array $items, int $actor_user_id, string $created_at_utc ): void { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.parentFound -- Preserve the established named-argument signature; these parameter names are legal in supported PHP versions.
		usort( $items, static fn ( array $left, array $right ): int => $left['item_no'] <=> $right['item_no'] );
		$mapping_items = array();
		$success       = 0;
		foreach ( $items as $index => $item ) {
			if ( $item['item_no'] !== $index + 1 ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::SOURCE_STALE );
			}
			if ( 'ready' === $item['state'] ) {
				++$success;
			}
			$mapping_items[] = array(
				'compatible_response_ids' => $item['compatible_response_ids'],
				'detected_mime_type'      => $item['mime_type'],
				'item_no'                 => $item['item_no'],
				'selection'               => null,
				'suggestion'              => array(
					'learner'  => null,
					'response' => null,
				),
			);
		}
		$failure      = count( $items ) - $success;
		$mapping      = array(
			'items'            => $mapping_items,
			'mapping_revision' => 1,
			'scan_error_code'  => null,
			'source'           => $root['mapping']['source'],
		);
		$mapping_json = $this->canonical->encode( $mapping );
		$mapping_hash = hash( 'sha256', $mapping_json );
		$this->change_one(
			$this->prepare(
				'UPDATE ' . self::TABLE_UPLOADS . ' SET state = %s, mapping_json = %s, mapping_hash = %s, item_total = %d, '
				. 'success_total = %d, failure_total = %d, lock_version = lock_version + 1, updated_by_user_id = %d, updated_at_utc = %s '
				. 'WHERE id = %d AND public_id = %s AND lock_version = %d AND state = %s AND mapping_hash = %s '
				. 'AND item_total = 0 AND success_total = 0 AND failure_total = 0',
				array(
					'ready',
					$mapping_json,
					$mapping_hash,
					count( $items ),
					$success,
					$failure,
					$actor_user_id,
					$created_at_utc,
					$root['database_id'],
					$root['public_id'],
					$root['lock_version'],
					'scanning',
					$root['mapping_hash'],
				)
			)
		);

		$metadata                  = $parent['result']->metadata();
		$metadata['mapping_hash']  = $mapping_hash;
		$metadata['ready_count']   = $success;
		$metadata['failure_count'] = $failure;
		$content_hash              = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'archive_hash'         => $metadata['archive_hash'],
					'assignment_public_id' => $metadata['assignment_public_id'],
					'failure_count'        => $failure,
					'mapping_hash'         => $mapping_hash,
					'ready_count'          => $success,
					'source_checksum'      => $parent['source_checksum'],
					'upload_public_id'     => $root['public_id'],
				)
			)
		);
		$payload                   = array(
			'profile'    => self::ITEM_PROFILE,
			'source'     => array(
				'type'     => 'zip_uploads',
				'key'      => (string) $source_id,
				'checksum' => $parent['source_checksum'],
			),
			'state'      => 'success',
			'target'     => array(
				'type'         => 'submission_zip',
				'public_id'    => $root['public_id'],
				'content_hash' => $content_hash,
				'metadata'     => $metadata,
			),
			'error_code' => null,
		);
		$payload_json              = $this->canonical->encode( $payload );
		$this->change_one(
			$this->prepare(
				'UPDATE ' . self::TABLE_ITEMS . ' SET payload_json = %s, payload_hash = %s, lock_version = lock_version + 1, '
				. 'updated_by_user_id = %d, updated_at_utc = %s WHERE id = %d AND import_run_id = %d AND lock_version = %d '
				. 'AND source_type = %s AND source_key = %s AND state = %s AND target_type = %s AND target_public_id = %s',
				array(
					$payload_json,
					hash( 'sha256', $payload_json ),
					$actor_user_id,
					$created_at_utc,
					$this->positive_integer( $parent['database_id'] ?? null ),
					$run_database_id,
					$this->non_negative_integer( $parent['lock_version'] ?? null ),
					'zip_uploads',
					(string) $source_id,
					'success',
					'submission_zip',
					$root['public_id'],
				)
			)
		);
	}

	/**
	 * Insert private file.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return void
	 */
	private function insert_private_file( array $record ): void {
		$this->insert_or_fail(
			self::TABLE_PRIVATE_FILES,
			array(
				'public_id'            => $record['public_id'],
				'lock_version'         => 0,
				'created_by_user_id'   => $record['actor_user_id'],
				'created_at_utc'       => $record['created_at_utc'],
				'updated_by_user_id'   => $record['actor_user_id'],
				'updated_at_utc'       => $record['created_at_utc'],
				'owner_type'           => $record['owner_type'],
				'owner_public_id'      => $record['owner_public_id'],
				'purpose'              => $record['purpose'],
				'storage_key'          => $record['storage_key'],
				'original_name'        => $record['original_name'],
				'mime_type'            => $record['mime_type'],
				'byte_size'            => $record['byte_size'],
				'sha256'               => $record['sha256'],
				'state'                => 'quarantined',
				'scan_state'           => 'clean',
				'retention_class'      => 'submission_zip_staging',
				'expires_at_utc'       => $record['expires_at_utc'],
				'legal_hold_until_utc' => null,
				'purged_at_utc'        => null,
			)
		);
	}

	/**
	 * Run plan.
	 *
	 * @param int $run_database_id Run database id.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function run_plan( int $run_database_id ): array {
		$row = $this->row(
			$this->prepare(
				'SELECT state, source_kind, source_version, source_fingerprint, preview_schema, preview_version, preview_json, preview_hash FROM '
				. self::TABLE_RUNS . ' WHERE id = %d LIMIT 1 FOR UPDATE',
				array( $run_database_id )
			)
		);
		if ( null === $row || 'running' !== ( $row['state'] ?? null ) || Approve_Legacy_Migration::PREVIEW_SCHEMA !== ( $row['preview_schema'] ?? null ) || Approve_Legacy_Migration::PREVIEW_DATABASE_VERSION !== $this->positive_integer( $row['preview_version'] ?? null ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		$preview = $this->decode_stored( (string) ( $row['preview_json'] ?? '' ), (string) ( $row['preview_hash'] ?? '' ) );
		$this->exact_keys( $preview, array( 'plan', 'profile', 'state' ) );
		$plan = $preview['plan'] ?? null;
		if ( Approve_Legacy_Migration::PREVIEW_PROFILE !== ( $preview['profile'] ?? null ) || 'approved' !== ( $preview['state'] ?? null ) || ! is_array( $plan ) || array_is_list( $plan ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		( new Legacy_Migration_Plan( $this->canonical ) )->assert_executable( $plan );
		$source  = $plan['source'] ?? null;
		$profile = is_array( $source ) ? ( $source['profile'] ?? null ) : null;
		$kind    = is_string( $profile ) ? strstr( $profile, '@', true ) : false;
		if (
			Legacy_Migration_Plan::PROFILE !== ( $plan['profile'] ?? null ) || Legacy_Source_Profile_0_1_2::PROFILE !== $profile
			|| ! is_string( $kind ) || ! hash_equals( $kind, (string) ( $row['source_kind'] ?? '' ) )
			|| ! hash_equals( (string) ( $source['version'] ?? '' ), (string) ( $row['source_version'] ?? '' ) )
			|| ! hash_equals( (string) ( $source['fingerprint'] ?? '' ), (string) ( $row['source_fingerprint'] ?? '' ) )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		return $plan;
	}

	/**
	 * Source tables.
	 *
	 * @param array $plan Plan.
	 * @return array<string,string>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function source_tables( array $plan ): array {
		$tables = $plan['inventory']['tables'] ?? null;
		if ( ! is_array( $tables ) || ! array_is_list( $tables ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		$required = array( 'worksheet_assignments', 'zip_uploads', 'zip_upload_items' );
		$result   = array();
		$profiles = $this->profile->tables();
		foreach ( $tables as $table ) {
			$logical = is_array( $table ) ? ( $table['logical_table'] ?? null ) : null;
			if ( ! is_string( $logical ) || ! in_array( $logical, $required, true ) ) {
				continue;
			}
			$physical = $table['source_table'] ?? null;
			$suffix   = $profiles[ $logical ]['suffix'] ?? null;
			if ( isset( $result[ $logical ] ) || 'migrate' !== ( $table['disposition'] ?? null ) || ! is_string( $physical ) || 1 !== preg_match( '/^[A-Za-z0-9_]{4,191}$/D', $physical ) || ! is_string( $suffix ) || $physical === $suffix || ! str_ends_with( $physical, $suffix ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
			}
			$result[ $logical ] = $physical;
		}
		sort( $required, SORT_STRING );
		$actual = array_keys( $result );
		sort( $actual, SORT_STRING );
		if ( $required !== $actual ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		return $result;
	}

	/**
	 * Reject invalid source checksum.
	 *
	 * @param Legacy_Source_Item $item Item.
	 * @param array              $plan Plan.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_source_checksum( Legacy_Source_Item $item, array $plan ): void {
		if ( ! hash_equals( $item->source_checksum(), $this->source_checksum( $plan, $item->source_type(), $item->source_id(), $item->record() ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::SOURCE_INVALID );
		}
	}

	/**
	 * Source row by id.
	 *
	 * @param string $table Table.
	 * @param string $logical Logical.
	 * @param int    $source_id Source id.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function source_row_by_id( string $table, string $logical, int $source_id ): array {
		$columns = $this->profile->columns( $logical );
		$select  = implode( ', ', array_map( static fn ( string $column ): string => '`' . $column . '`', $columns ) );
		$row     = $this->row( $this->prepare( 'SELECT ' . $select . ' FROM `' . $table . '` WHERE `id` = %d LIMIT 1 FOR UPDATE', array( $source_id ) ) );
		if ( null === $row ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::DEPENDENCY_MISSING );
		}
		$this->assert_exact_source_row( $logical, $row );
		return $row;
	}

	/**
	 * Source relations.
	 *
	 * @param string $table Table.
	 * @param string $logical Logical.
	 * @param string $foreign_key Foreign key.
	 * @param int    $foreign_id Foreign id.
	 * @return array<int,array<string,mixed>>
	 */
	private function source_relations( string $table, string $logical, string $foreign_key, int $foreign_id ): array {
		$columns = $this->profile->columns( $logical );
		$select  = implode( ', ', array_map( static fn ( string $column ): string => '`' . $column . '`', $columns ) );
		$rows    = $this->results( $this->prepare( 'SELECT ' . $select . ' FROM `' . $table . '` WHERE `' . $foreign_key . '` = %d ORDER BY `id` ASC FOR UPDATE', array( $foreign_id ) ) );
		foreach ( $rows as $row ) {
			$this->assert_exact_source_row( $logical, $row );
		}
		return $rows;
	}

	/**
	 * Reject invalid exact source row.
	 *
	 * @param string $logical Logical.
	 * @param array  $row Row.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_exact_source_row( string $logical, array $row ): void {
		if ( array_keys( $row ) !== $this->profile->columns( $logical ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::SOURCE_INVALID );
		}
	}

	/**
	 * Source checksum.
	 *
	 * @param array  $plan Plan.
	 * @param string $type Type.
	 * @param int    $source_id Source id.
	 * @param array  $record Record.
	 * @return string
	 */
	private function source_checksum( array $plan, string $type, int $source_id, array $record ): string {
		return hash(
			'sha256',
			$this->canonical->encode(
				array(
					'profile'     => $plan['source']['profile'],
					'source_key'  => (string) $source_id,
					'source_type' => $type,
					'record'      => $record,
				)
			)
		);
	}

	/**
	 * Import receipt.
	 *
	 * @param int    $run_database_id Run database id.
	 * @param string $source_type Source type.
	 * @param int    $source_id Source id.
	 * @param string $target_type Target type.
	 * @return array{database_id:?int,lock_version:?int,result:Legacy_Target_Write_Result,source_checksum:string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function import_receipt( int $run_database_id, string $source_type, int $source_id, string $target_type ): array {
		$row = $this->row(
			$this->prepare(
				'SELECT id, lock_version, state, target_type, target_public_id, payload_schema, payload_version, payload_json, payload_hash FROM '
				. self::TABLE_ITEMS . ' WHERE import_run_id = %d AND source_type = %s AND source_key = %s LIMIT 1 FOR UPDATE',
				array( $run_database_id, $source_type, (string) $source_id )
			)
		);
		if ( null === $row || 'success' !== ( $row['state'] ?? null ) || $target_type !== ( $row['target_type'] ?? null ) || self::ITEM_SCHEMA !== ( $row['payload_schema'] ?? null ) || self::PROFILE_VERSION !== $this->positive_integer( $row['payload_version'] ?? null ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::DEPENDENCY_MISSING );
		}
		$payload = $this->decode_stored( (string) ( $row['payload_json'] ?? '' ), (string) ( $row['payload_hash'] ?? '' ) );
		$source  = $payload['source'] ?? null;
		$target  = $payload['target'] ?? null;
		if (
			self::ITEM_PROFILE !== ( $payload['profile'] ?? null ) || 'success' !== ( $payload['state'] ?? null ) || null !== ( $payload['error_code'] ?? null )
			|| ! is_array( $source ) || ! is_array( $target ) || $source_type !== ( $source['type'] ?? null ) || (string) $source_id !== ( $source['key'] ?? null )
			|| $target_type !== ( $target['type'] ?? null ) || ! is_array( $target['metadata'] ?? null )
			|| ! hash_equals( (string) ( $target['public_id'] ?? '' ), (string) ( $row['target_public_id'] ?? '' ) )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		$result   = new Legacy_Target_Write_Result( $target_type, (string) $target['public_id'], (string) $target['content_hash'], $target['metadata'] );
		$checksum = $source['checksum'] ?? null;
		if ( ! is_string( $checksum ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $checksum ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		return array(
			'database_id'     => null === ( $row['id'] ?? null ) ? null : $this->positive_integer( $row['id'] ),
			'lock_version'    => null === ( $row['lock_version'] ?? null ) ? null : $this->non_negative_integer( $row['lock_version'] ),
			'result'          => $result,
			'source_checksum' => $checksum,
		);
	}

	/**
	 * Source path.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function source_path( mixed $value ): string {
		if ( ! is_string( $value ) || '' === $value || strlen( $value ) > Submission_Zip_Upload_Profile::MAX_PATH_BYTES || 1 !== preg_match( '//u', $value ) || 1 === preg_match( '/[\x00-\x1F\x7F\\\\]/', $value ) ) {
			throw new InvalidArgumentException( 'Legacy ZIP path is invalid.' );
		}
		return $value;
	}

	/**
	 * Filename.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function filename( mixed $value ): string {
		if ( ! is_string( $value ) || '' === $value || strlen( $value ) > 255 || basename( str_replace( '\\', '/', $value ) ) !== $value || 1 !== preg_match( '//u', $value ) || 1 === preg_match( '/[\x00-\x1F\x7F]/', $value ) ) {
			throw new InvalidArgumentException( 'Legacy filename is invalid.' );
		}
		return $value;
	}

	/**
	 * Mime.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function mime( mixed $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9!#$&^_.+-]*\/[A-Za-z0-9][A-Za-z0-9!#$&^_.+-]*$/D', $value ) ) {
			throw new InvalidArgumentException( 'Legacy MIME is invalid.' );
		}
		return $value;
	}

	/**
	 * Return the stable failure reason used by boundary mappers.
	 *
	 * @param string $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function reason( string $value ): string {
		if ( 1 !== preg_match( '/^zip_[a-z0-9_]{2,95}$/D', $value ) ) {
			throw new InvalidArgumentException( 'ZIP rejection code is invalid.' );
		}
		return $value;
	}

	/**
	 * Decode stored.
	 *
	 * @param string $json Json.
	 * @param string $hash Hash.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function decode_stored( string $json, string $hash ): array {
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $hash ) || ! hash_equals( $hash, hash( 'sha256', $json ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		$value = json_decode( $json, true, 128, JSON_THROW_ON_ERROR );
		if ( ! is_array( $value ) || array_is_list( $value ) || ! hash_equals( $json, $this->canonical->encode( $value ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		return $value;
	}

	/**
	 * Exact keys.
	 *
	 * @param array $value Value.
	 * @param array $expected Expected.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function exact_keys( array $value, array $expected ): void {
		$actual = array_keys( $value );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Plus seconds.
	 *
	 * @param string $value Value.
	 * @param int    $seconds Seconds.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function plus_seconds( string $value, int $seconds ): string {
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $this->utc_value( $value ), new DateTimeZone( 'UTC' ) );
		if ( false === $date ) {
			throw new InvalidArgumentException( 'Legacy UTC value is invalid.' );
		}
		return $date->modify( '+' . $seconds . ' seconds' )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Utc.
	 *
	 * @param string $value Value.
	 * @return void
	 */
	private function utc( string $value ): void {
		$this->utc_value( $value );
	}

	/**
	 * Utc value.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function utc_value( mixed $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value ) ) {
			throw new InvalidArgumentException( 'Legacy UTC value is invalid.' );
		}
		$date   = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new DateTimeZone( 'UTC' ) );
		$errors = DateTimeImmutable::getLastErrors();
		if ( false === $date || ( is_array( $errors ) && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) || $date->format( 'Y-m-d H:i:s' ) !== $value ) {
			throw new InvalidArgumentException( 'Legacy UTC value is invalid.' );
		}
		return $value;
	}

	/**
	 * Positive integer.
	 *
	 * @param mixed $value Value.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function positive_integer( mixed $value ): int {
		if ( is_string( $value ) && 1 === preg_match( '/^[1-9][0-9]*$/D', $value ) ) {
			$value = (int) $value;
		}
		if ( ! is_int( $value ) || $value < 1 ) {
			throw new InvalidArgumentException( 'Legacy integer is invalid.' );
		}
		return $value;
	}

	/**
	 * Non negative integer.
	 *
	 * @param mixed $value Value.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function non_negative_integer( mixed $value ): int {
		if ( is_string( $value ) && 1 === preg_match( '/^(?:0|[1-9][0-9]*)$/D', $value ) ) {
			$value = (int) $value;
		}
		if ( ! is_int( $value ) || $value < 0 ) {
			throw new InvalidArgumentException( 'Legacy integer is invalid.' );
		}
		return $value;
	}

	/**
	 * Hash value.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function hash_value( mixed $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $value ) ) {
			throw new InvalidArgumentException( 'Legacy hash is invalid.' );
		}
		return $value;
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
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		return $prepared;
	}

	/**
	 * Row.
	 *
	 * @param string $sql Sql.
	 * @return array<string,mixed>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function row( string $sql ): ?array {
		$db = $this->database();
		$this->reset_error( $db );
		$row = $db->get_row( $sql, defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A' );
		$this->assert_database_ok( $db );
		if ( null === $row ) {
			return null;
		}
		if ( ! is_array( $row ) || array_is_list( $row ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		return $row;
	}

	/**
	 * Results.
	 *
	 * @param string $sql Sql.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function results( string $sql ): array {
		$db = $this->database();
		$this->reset_error( $db );
		$rows = $db->get_results( $sql, defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A' );
		$this->assert_database_ok( $db );
		if ( ! is_array( $rows ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || array_is_list( $row ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
			}
		}
		return $rows;
	}

	/**
	 * Change one.
	 *
	 * @param string $sql Sql.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function change_one( string $sql ): void {
		$db = $this->database();
		if ( ! method_exists( $db, 'query' ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		$this->reset_error( $db );
		$changed = $db->query( $sql );
		$this->assert_database_ok( $db );
		if ( 1 !== $changed ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Insert or fail.
	 *
	 * @param string $table Table.
	 * @param array  $data Data.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function insert_or_fail( string $table, array $data ): void {
		$db = $this->database();
		$this->reset_error( $db );
		if ( false === $db->insert( $table, $data ) || '' !== trim( (string) ( $db->last_error ?? '' ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
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
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		foreach ( array( 'prepare', 'get_row', 'get_results', 'insert' ) as $method ) {
			if ( ! method_exists( $db, $method ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
			}
		}
		return $db;
	}

	/**
	 * Reset error.
	 *
	 * @param object $database Database.
	 * @return void
	 */
	private function reset_error( object $database ): void {
		if ( property_exists( $database, 'last_error' ) ) {
			$database->last_error = '';
		}
	}
	/**
	 * Reject invalid database ok.
	 *
	 * @param object $database Database.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_database_ok( object $database ): void {
		if ( '' !== trim( (string) ( $database->last_error ?? '' ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
	}
	/**
	 * Ids.
	 *
	 * @return Public_Id_Generator
	 */
	private function ids(): Public_Id_Generator {
		return $this->ids ??= new Public_Id_Generator();
	}

	/**
	 * Compensate from.
	 *
	 * @param int $marker Marker.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function compensate_from( int $marker ): void {
		$marker = max( 0, min( $marker, count( $this->created_storage_keys ) ) );
		$failed = false;
		for ( $index = count( $this->created_storage_keys ) - 1; $index >= $marker; --$index ) {
			try {
				$this->private_files->delete( $this->created_storage_keys[ $index ] );
			} catch ( Throwable ) {
				$failed = true;
			}
		}
		$this->created_storage_keys = array_slice( $this->created_storage_keys, 0, $marker );
		if ( $failed ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
	}
}
