<?php
/**
 * D3-D7 historical submission aggregate and private attachment writer.
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
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Mapping_Manifest;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Migration_Plan;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Source_Blob;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Source_Item;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Target_Write_Result;
use OJAI\Dashboard\Operations\LegacyMigration\Port\Legacy_Migration_Submission_Writer_Port_Interface;
use OJAI\Dashboard\Operations\LegacyMigration\Port\Legacy_Source_Blob_Reader_Port_Interface;
use OJAI\Dashboard\Operations\LegacyMigration\Port\Legacy_Target_Batch_Lifecycle_Port_Interface;
use OJAI\Dashboard\Storage\Port\Private_File_Store_Port_Interface;
use OJAI\Dashboard\Submission\Assignment\Application\Assignment_Failure;
use OJAI\Dashboard\Submission\Student\Domain\Submission_Attachment_Content_Verdict;
use OJAI\Dashboard\Submission\Student\Domain\Submission_Attachment_Content_Inspector;
use OJAI\Dashboard\Submission\Student\Domain\Submission_Attachment_Malware_Verdict;
use OJAI\Dashboard\Submission\Student\Domain\Submission_Attachment_Scan_Item;
use OJAI\Dashboard\Submission\Student\Domain\Submission_Attachment_Upload_Profile;
use OJAI\Dashboard\Submission\Student\Domain\Submission_Revision_Integrity;
use OJAI\Dashboard\Submission\Student\Domain\Submission_Response_Validator;
use OJAI\Dashboard\Submission\Student\Port\Submission_Attachment_Malware_Scanner_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Restores submission roots and immutable revisions without owning a transaction.
 */
final class WordPress_Legacy_Migration_Submission_Writer implements
	Legacy_Migration_Submission_Writer_Port_Interface,
	Legacy_Target_Batch_Lifecycle_Port_Interface {

	private const TABLE_RUNS                   = 'oj_ai_import_runs';
	private const TABLE_ITEMS                  = 'oj_ai_import_items';
	private const TABLE_ASSIGNMENTS            = 'oj_ai_assignments';
	private const TABLE_TARGETS                = 'oj_ai_assignment_targets';
	private const TABLE_PUBLICATIONS           = 'oj_ai_worksheet_publications';
	private const TABLE_WORKSHEET_REVISIONS    = 'oj_ai_worksheet_revisions';
	private const TABLE_WORKSHEET_QUESTIONS    = 'oj_ai_worksheet_revision_questions';
	private const TABLE_SUBMISSIONS            = 'oj_ai_submissions';
	private const TABLE_SUBMISSION_REVISIONS   = 'oj_ai_submission_revisions';
	private const TABLE_SUBMISSION_RECEIPTS    = 'oj_ai_submission_receipts';
	private const TABLE_SUBMISSION_ATTACHMENTS = 'oj_ai_submission_attachments';
	private const TABLE_PRIVATE_FILES          = 'oj_ai_private_files';
	private const ITEM_SCHEMA                  = 'oj_ai.legacy_item_payload';
	private const ITEM_PROFILE                 = 'oj_ai.legacy_item_payload@1.0.0';
	private const PROFILE_VERSION              = 1;
	private const MAXIMUM_RELATIONS            = 250;
	private const MAXIMUM_JSON_BYTES           = 4194304;

	/**
	 * Profile.
	 *
	 * @var Legacy_Source_Profile_0_1_2
	 */
	private Legacy_Source_Profile_0_1_2 $profile;
	/**
	 * Responses.
	 *
	 * @var Legacy_Migration_Submission_Response_Projector
	 */
	private Legacy_Migration_Submission_Response_Projector $responses;
	/**
	 * Integrity.
	 *
	 * @var Submission_Revision_Integrity
	 */
	private Submission_Revision_Integrity $integrity;
	/**
	 * Attachment uploads.
	 *
	 * @var Submission_Attachment_Upload_Profile
	 */
	private Submission_Attachment_Upload_Profile $attachment_uploads;
	/**
	 * Attachment contents.
	 *
	 * @var Submission_Attachment_Content_Inspector
	 */
	private Submission_Attachment_Content_Inspector $attachment_contents;
	/**
	 * Response validator.
	 *
	 * @var Submission_Response_Validator
	 */
	private Submission_Response_Validator $response_validator;
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
	 * Initialize WordPress legacy migration submission writer dependencies and state.
	 *
	 * @param Canonical_Json                                            $canonical Canonical JSON encoder used for stable digests.
	 * @param object|null                                               $database Database.
	 * @param Public_Id_Generator|null                                  $ids Ids.
	 * @param Legacy_Source_Profile_0_1_2|null                          $profile Profile.
	 * @param Legacy_Migration_Submission_Response_Projector|null       $responses Responses.
	 * @param Legacy_Source_Blob_Reader_Port_Interface|null             $source_blobs Source blobs.
	 * @param Private_File_Store_Port_Interface|null                    $private_files Private files.
	 * @param Submission_Attachment_Malware_Scanner_Port_Interface|null $malware_scanner Malware scanner.
	 * @param Submission_Attachment_Upload_Profile|null                 $attachment_uploads Attachment uploads.
	 * @param Submission_Attachment_Content_Inspector|null              $attachment_contents Attachment contents.
	 * @param Submission_Response_Validator|null                        $response_validator Response validator.
	 */
	public function __construct(
		private Canonical_Json $canonical,
		private ?object $database = null,
		private ?Public_Id_Generator $ids = null,
		?Legacy_Source_Profile_0_1_2 $profile = null,
		?Legacy_Migration_Submission_Response_Projector $responses = null,
		private ?Legacy_Source_Blob_Reader_Port_Interface $source_blobs = null,
		private ?Private_File_Store_Port_Interface $private_files = null,
		private ?Submission_Attachment_Malware_Scanner_Port_Interface $malware_scanner = null,
		?Submission_Attachment_Upload_Profile $attachment_uploads = null,
		?Submission_Attachment_Content_Inspector $attachment_contents = null,
		?Submission_Response_Validator $response_validator = null
	) {
		$this->profile             = $profile ?? new Legacy_Source_Profile_0_1_2();
		$this->responses           = $responses ?? new Legacy_Migration_Submission_Response_Projector( $canonical );
		$this->integrity           = new Submission_Revision_Integrity( $canonical );
		$this->attachment_uploads  = $attachment_uploads ?? new Submission_Attachment_Upload_Profile();
		$this->attachment_contents = $attachment_contents ?? new Submission_Attachment_Content_Inspector();
		$this->response_validator  = $response_validator ?? new Submission_Response_Validator( $canonical );
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
			$this->compensate_from( 0 );
		} finally {
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
	public function write(
		int $run_database_id,
		Legacy_Source_Item $item,
		int $actor_user_id,
		string $created_at_utc
	): Legacy_Target_Write_Result {
		if ( 'worksheet_submissions' !== $item->source_type() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::UNSUPPORTED );
		}
		if ( $run_database_id < 1 || $actor_user_id < 1 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		$this->utc( $created_at_utc );
		try {
			$plan = $this->run_plan( $run_database_id );
			$this->assert_source_checksum( $item, $plan );
			$source_tables = $this->source_tables( $plan );
			$record        = $item->record();
			$this->assert_exact_source_row( 'worksheet_submissions', $record );
			$projection           = $this->dependencies( $run_database_id, $record, $plan, $source_tables );
			$source_revisions     = $this->source_relations(
				$source_tables['submission_revisions'],
				'submission_revisions',
				'submission_id',
				$item->source_id(),
				'revision ASC, `id` ASC'
			);
			$source_attachments   = $this->source_relations(
				$source_tables['submission_attachments'],
				'submission_attachments',
				'submission_id',
				$item->source_id(),
				'`id` ASC'
			);
			$prepared             = $this->prepare_revisions(
				$record,
				$source_revisions,
				$projection['response_profile'],
				$projection['bindings'],
				$projection['publication_public_id'],
				$projection['publication_hash'],
				$projection['learner_user_id']
			);
			$attachments          = $this->attachment_manifest( $source_attachments, $plan, $record, $projection['bindings'] );
			$deferred             = $attachments['count'] > 0 || $prepared['deferred_attachment'];
			$official             = $this->official_projection( $record, $prepared, $deferred, $actor_user_id, $projection );
			$submission_public_id = $this->ids()->create()->value();
			$prepared             = $this->bind_revision_identity( $prepared, $submission_public_id );
			$current              = array() === $prepared['rows'] ? null : $prepared['rows'][ count( $prepared['rows'] ) - 1 ];
			$official             = $this->bind_official_revision( $official, $current );

			$this->insert_or_fail(
				self::TABLE_SUBMISSIONS,
				array(
					'public_id'                   => $submission_public_id,
					'lock_version'                => 0,
					'created_by_user_id'          => $actor_user_id,
					'created_at_utc'              => $this->utc_value( $record['created_at'] ?? null ),
					'updated_by_user_id'          => $actor_user_id,
					'updated_at_utc'              => $this->utc_value( $record['updated_at'] ?? null ),
					'assignment_id'               => $projection['assignment_database_id'],
					'learner_user_id'             => $projection['learner_user_id'],
					'state'                       => $official['root_state'],
					'current_revision_public_id'  => null === $current ? null : $current['public_id'],
					'official_revision_public_id' => $official['revision_public_id'],
					'official_attempt_no'         => $official['attempt_no'],
					'official_review_public_id'   => null,
					'official_review_hash'        => null,
					'current_release_public_id'   => null,
					'current_release_hash'        => null,
					'started_at_utc'              => $this->utc_value( $record['created_at'] ?? null ),
					'submitted_at_utc'            => $official['submitted_at_utc'],
					'closed_at_utc'               => $official['closed_at_utc'],
				)
			);
			$submission_database_id = $this->insert_id();
			$revision_public_ids    = array();
			$revision_target_hashes = array();
			$revision_database_ids  = array();
			foreach ( $prepared['rows'] as $revision ) {
				$data                  = $revision['data'];
				$data['submission_id'] = $submission_database_id;
				$this->insert_or_fail( self::TABLE_SUBMISSION_REVISIONS, $data );
				$source_key                             = (string) $revision['source_id'];
				$receipt_key                            = hash( 'sha256', $source_key );
				$revision_database_ids[ $source_key ]   = $this->insert_id();
				$revision_public_ids[ $receipt_key ]    = $revision['public_id'];
				$revision_target_hashes[ $receipt_key ] = $revision['content_hash'];
			}

			$receipt_public_ids = array();
			$receipt_hashes     = array();
			if ( $official['attempt_no'] > 0 ) {
				$source_key = (string) $official['source_revision_id'];
				$receipt    = $this->receipt_row(
					$submission_database_id,
					$submission_public_id,
					$revision_database_ids[ $source_key ],
					(string) $official['revision_public_id'],
					(string) $official['revision_hash'],
					$actor_user_id,
					$official,
					$projection
				);
				$this->insert_or_fail( self::TABLE_SUBMISSION_RECEIPTS, $receipt['row'] );
				$receipt_key                        = hash( 'sha256', '1' );
				$receipt_public_ids[ $receipt_key ] = $receipt['public_id'];
				$receipt_hashes[ $receipt_key ]     = $receipt['content_hash'];
			}

			$this->assert_postcondition(
				$submission_database_id,
				$submission_public_id,
				null === $current ? null : $current['public_id'],
				$official['revision_public_id'],
				count( $prepared['rows'] ),
				count( $receipt_public_ids )
			);
			$metadata = array(
				'assignment_public_id'           => $projection['assignment_public_id'],
				'publication_public_id'          => $projection['publication_public_id'],
				'publication_hash'               => $projection['publication_hash'],
				'response_schema_hash'           => $projection['response_schema_hash'],
				'submission_source_key'          => $item->source_key(),
				'revision_count'                 => count( $prepared['rows'] ),
				'revision_public_ids'            => $revision_public_ids,
				'revision_source_hashes'         => $prepared['source_hashes'],
				'revision_target_hashes'         => $revision_target_hashes,
				'attachment_count'               => $attachments['count'],
				'deferred_attachment_count'      => $attachments['count'] + ( $prepared['deferred_attachment'] && 0 === $attachments['count'] ? 1 : 0 ),
				'attachment_manifest_hash'       => $attachments['manifest_hash'],
				'attachment_source_hashes'       => $attachments['source_hashes'],
				'attachment_response_public_ids' => $attachments['response_public_ids'],
				'official_count'                 => $official['attempt_no'] > 0 ? 1 : 0,
				'receipt_count'                  => count( $receipt_public_ids ),
				'receipt_public_ids'             => $receipt_public_ids,
				'receipt_hashes'                 => $receipt_hashes,
			);
			if ( null !== $current ) {
				$metadata['current_revision_public_id'] = $current['public_id'];
				$metadata['current_revision_hash']      = $current['content_hash'];
			}
			if ( null !== $official['revision_public_id'] ) {
				$metadata['official_revision_public_id'] = $official['revision_public_id'];
			}
			$content_hash = hash(
				'sha256',
				$this->canonical->encode(
					array(
						'attachment_manifest_hash' => $attachments['manifest_hash'],
						'current_revision_hash'    => null === $current ? null : $current['content_hash'],
						'official_revision_hash'   => $official['revision_hash'],
						'publication_hash'         => $projection['publication_hash'],
						'source_checksum'          => $item->source_checksum(),
						'state'                    => $official['root_state'],
						'submission_public_id'     => $submission_public_id,
					)
				)
			);
			return new Legacy_Target_Write_Result( 'submission', $submission_public_id, $content_hash, $metadata );
		} catch ( Legacy_Target_Transformation_Failure | Legacy_Migration_Failure $failure ) {
			throw $failure;
		} catch ( InvalidArgumentException | JsonException $error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::SOURCE_INVALID );
		} catch ( Throwable $error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE, $error );
		}
	}

	/**
	 * Verify revision.
	 *
	 * @param int                $run_database_id Run database id.
	 * @param Legacy_Source_Item $item Item.
	 * @param int                $actor_user_id Authenticated actor from the access context.
	 * @param string             $created_at_utc Created at utc.
	 * @return Legacy_Target_Write_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function verify_revision(
		int $run_database_id,
		Legacy_Source_Item $item,
		int $actor_user_id,
		string $created_at_utc
	): Legacy_Target_Write_Result {
		unset( $actor_user_id );
		$this->utc( $created_at_utc );
		if ( 'submission_revisions' !== $item->source_type() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::UNSUPPORTED );
		}
		return $this->verify_relation( $run_database_id, $item, true );
	}

	/**
	 * Verify attachment.
	 *
	 * @param int                $run_database_id Run database id.
	 * @param Legacy_Source_Item $item Item.
	 * @param int                $actor_user_id Authenticated actor from the access context.
	 * @param string             $created_at_utc Created at utc.
	 * @return Legacy_Target_Write_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function verify_attachment(
		int $run_database_id,
		Legacy_Source_Item $item,
		int $actor_user_id,
		string $created_at_utc
	): Legacy_Target_Write_Result {
		$this->utc( $created_at_utc );
		if ( 'submission_attachments' !== $item->source_type() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::UNSUPPORTED );
		}
		if ( $this->file_migration_enabled() ) {
			return $this->migrate_attachment( $run_database_id, $item, $actor_user_id, $created_at_utc );
		}
		unset( $actor_user_id );
		return $this->verify_relation( $run_database_id, $item, false );
	}

	/**
	 * File migration enabled.
	 *
	 * @return bool
	 */
	private function file_migration_enabled(): bool {
		return $this->source_blobs instanceof Legacy_Source_Blob_Reader_Port_Interface
			&& $this->private_files instanceof Private_File_Store_Port_Interface
			&& $this->malware_scanner instanceof Submission_Attachment_Malware_Scanner_Port_Interface;
	}

	/**
	 * Migrate attachment.
	 *
	 * @param int                $run_database_id Run database id.
	 * @param Legacy_Source_Item $item Item.
	 * @param int                $actor_user_id Authenticated actor from the access context.
	 * @param string             $created_at_utc Created at utc.
	 * @return Legacy_Target_Write_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function migrate_attachment(
		int $run_database_id,
		Legacy_Source_Item $item,
		int $actor_user_id,
		string $created_at_utc
	): Legacy_Target_Write_Result {
		if ( ! $this->batch_active || $actor_user_id < 1 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		$storage_marker = count( $this->created_storage_keys );
		try {
			$plan = $this->run_plan( $run_database_id );
			$this->assert_exact_source_row( 'submission_attachments', $item->record() );
			$this->assert_source_checksum( $item, $plan );
			$source_tables = $this->source_tables( $plan );
			$source        = $this->source_row_by_id( $source_tables['submission_attachments'], 'submission_attachments', $item->source_id() );
			if (
				null !== ( $source['deleted_at'] ?? null )
				|| ! hash_equals( $item->source_checksum(), $this->source_checksum( $plan, 'submission_attachments', $item->source_id(), $source ) )
			) {
				throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::SOURCE_INVALID );
			}

			$submission_source_id = $this->positive_integer( $source['submission_id'] ?? null );
			$parent               = $this->import_receipt( $run_database_id, 'worksheet_submissions', $submission_source_id, 'submission' );
			$metadata             = $parent['result']->metadata();
			$relation_key         = hash( 'sha256', $item->source_key() );
			$source_hashes        = $metadata['attachment_source_hashes'] ?? null;
			$expected_checksum    = is_array( $source_hashes ) ? ( $source_hashes[ $relation_key ] ?? null ) : null;
			if ( ! is_string( $expected_checksum ) || ! hash_equals( $item->source_checksum(), $expected_checksum ) ) {
				throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::SOURCE_INVALID );
			}

			$submission_source = $this->source_row_by_id( $source_tables['worksheet_submissions'], 'worksheet_submissions', $submission_source_id );
			if ( ! hash_equals( $parent['source_checksum'], $this->source_checksum( $plan, 'worksheet_submissions', $submission_source_id, $submission_source ) ) ) {
				throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::SOURCE_INVALID );
			}
			$response_ids = $metadata['attachment_response_public_ids'] ?? null;
			$response_id  = is_array( $response_ids ) ? ( $response_ids[ $relation_key ] ?? null ) : null;
			if ( ! is_string( $response_id ) ) {
				return $this->verify_relation( $run_database_id, $item, false );
			}
			new Public_Id( $response_id );
			$expected_count = $this->positive_integer( $metadata['attachment_count'] ?? null );
			if ( $expected_count > Submission_Attachment_Upload_Profile::MAX_TOTAL_FILES ) {
				throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::SOURCE_INVALID );
			}

			$dependency = $this->dependencies( $run_database_id, $submission_source, $plan, $source_tables );
			$submission = $this->attachment_submission_root( $parent['result']->target_public_id(), $dependency );
			$current    = $this->attachment_current_revision( $submission );
			$existing   = $this->attachment_target_rows( $submission );
			if ( count( $existing ) >= $expected_count ) {
				throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
			}

			$file_public_id       = $this->ids()->create()->value();
			$attachment_public_id = $this->ids()->create()->value();
			$blob                 = $this->source_blobs->read( $this->positive_integer( $source['file_id'] ?? null ) );
			$role                 = $this->attachment_role( (string) ( $source['attachment_type'] ?? '' ), $blob->original_name() );
			if ( ! in_array( $source['status'] ?? null, array( 'uploaded', 'linked' ), true ) ) {
				throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::SOURCE_INVALID );
			}
			$upload = $this->attachment_uploads->inspect(
				array(
					'error'    => UPLOAD_ERR_OK,
					'name'     => $blob->original_name(),
					'size'     => $blob->byte_size(),
					'tmp_name' => $blob->path(),
					'type'     => 'application/octet-stream',
				),
				$dependency['response_profile'],
				$response_id,
				$role
			);
			if ( $upload['byte_size'] !== $blob->byte_size() || ! hash_equals( $upload['sha256'], $blob->sha256() ) ) {
				throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::SOURCE_INVALID );
			}
			$storage_key = $this->attachment_storage_key( $submission['public_id'], $item->source_id(), $upload['sha256'], $upload['extension'] );
			$scan_item   = new Submission_Attachment_Scan_Item(
				array(
					'attachment_byte_size'     => $upload['byte_size'],
					'attachment_id'            => $attachment_public_id,
					'attachment_lock_version'  => 0,
					'attachment_mime_type'     => $upload['mime_type'],
					'attachment_original_name' => $upload['original_name'],
					'attachment_sha256'        => $upload['sha256'],
					'claimed_at_utc'           => $created_at_utc,
					'file_byte_size'           => $upload['byte_size'],
					'file_id'                  => $file_public_id,
					'file_lock_version'        => 0,
					'file_mime_type'           => $upload['mime_type'],
					'file_original_name'       => $upload['original_name'],
					'file_sha256'              => $upload['sha256'],
					'owner_user_id'            => $dependency['learner_user_id'],
					'response_id'              => $response_id,
					'storage_key'              => $storage_key,
					'submission_id'            => $submission['public_id'],
				)
			);
			$malware     = $this->malware_scanner->scan( $blob->path() );
			if ( Submission_Attachment_Malware_Verdict::UNAVAILABLE === $malware->state() ) {
				throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
			}
			if ( Submission_Attachment_Malware_Verdict::INFECTED === $malware->state() ) {
				throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::SOURCE_INVALID );
			}
			$content_verdict = $this->attachment_contents->inspect( $blob->path(), $scan_item );
			if ( Submission_Attachment_Content_Verdict::UNAVAILABLE === $content_verdict->state() ) {
				throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
			}
			if ( Submission_Attachment_Content_Verdict::REJECTED === $content_verdict->state() ) {
				throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::SOURCE_INVALID );
			}
			$rechecked = $this->source_blobs->read( $blob->file_id() );
			if ( ! $blob->same_bytes( $rechecked ) ) {
				throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::SOURCE_INVALID );
			}

			$total_bytes    = $upload['byte_size'];
			$response_files = 0;
			foreach ( $existing as $row ) {
				$total_bytes += $row['byte_size'];
				if ( $response_id === $row['response_id'] ) {
					++$response_files;
				}
			}
			if ( $total_bytes > Submission_Attachment_Upload_Profile::MAX_TOTAL_BYTES || $response_files >= $upload['max_files'] ) {
				throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::SOURCE_INVALID );
			}
			$candidate    = array(
				'database_id'    => null,
				'public_id'      => $attachment_public_id,
				'file_public_id' => $file_public_id,
				'response_id'    => $response_id,
				'role'           => $role,
				'original_name'  => $upload['original_name'],
				'mime_type'      => $upload['mime_type'],
				'byte_size'      => $upload['byte_size'],
				'sha256'         => $upload['sha256'],
			);
			$finalization = count( $existing ) + 1 === $expected_count
				? $this->prepare_attachment_finalization(
					$run_database_id,
					$submission_source_id,
					$parent,
					$metadata,
					$submission_source,
					$submission,
					$current,
					$existing,
					$candidate,
					$dependency,
					$actor_user_id,
					$created_at_utc
				)
				: null;

			$result_metadata = array(
				'attachment_hash'       => $item->source_checksum(),
				'file_public_id'        => $file_public_id,
				'response_public_id'    => $response_id,
				'submission_public_id'  => $submission['public_id'],
				'submission_source_key' => (string) $submission_source_id,
			);
			if ( is_array( $finalization ) ) {
				$result_metadata['revision_public_id'] = $finalization['revision']['public_id'];
				if ( null !== $finalization['official']['revision_public_id'] ) {
					$result_metadata['official_revision_public_id'] = $finalization['official']['revision_public_id'];
				}
			}
			$result_hash = hash(
				'sha256',
				$this->canonical->encode(
					array(
						'attachment_public_id' => $attachment_public_id,
						'file_public_id'       => $file_public_id,
						'response_public_id'   => $response_id,
						'sha256'               => $upload['sha256'],
						'submission_public_id' => $submission['public_id'],
					)
				)
			);
			$result      = new Legacy_Target_Write_Result( 'submission_attachment', $attachment_public_id, $result_hash, $result_metadata );

			$created = $this->private_files->put_quarantined( $blob->path(), $storage_key, $upload['sha256'] );
			if ( $created ) {
				$this->created_storage_keys[] = $storage_key;
			}
			$this->insert_private_attachment_file( $file_public_id, $submission['public_id'], $storage_key, $upload, $actor_user_id, $created_at_utc );
			$this->insert_or_fail(
				self::TABLE_SUBMISSION_ATTACHMENTS,
				array(
					'public_id'                 => $attachment_public_id,
					'lock_version'              => 0,
					'created_by_user_id'        => $actor_user_id,
					'created_at_utc'            => $created_at_utc,
					'updated_by_user_id'        => $actor_user_id,
					'updated_at_utc'            => $created_at_utc,
					'submission_id'             => $submission['database_id'],
					'submission_revision_id'    => null,
					'response_key'              => $response_id,
					'file_public_id'            => $file_public_id,
					'state'                     => 'ready',
					'original_name'             => $upload['original_name'],
					'mime_type'                 => $upload['mime_type'],
					'byte_size'                 => $upload['byte_size'],
					'sha256'                    => $upload['sha256'],
					'bound_at_utc'              => null,
					'ai_representation_schema'  => null,
					'ai_representation_version' => null,
					'ai_representation_json'    => null,
					'ai_representation_hash'    => null,
				)
			);
			$candidate_database_id = $this->insert_id();
			if ( is_array( $finalization ) ) {
				$this->commit_attachment_finalization(
					$run_database_id,
					$submission_source_id,
					$parent,
					$submission,
					$existing,
					$candidate_database_id,
					$finalization,
					$actor_user_id,
					$created_at_utc
				);
			}
			return $result;
		} catch ( Legacy_Target_Transformation_Failure $failure ) {
			$this->compensate_from( $storage_marker );
			throw $failure;
		} catch ( Assignment_Failure | InvalidArgumentException | JsonException $error ) {
			$this->compensate_from( $storage_marker );
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::SOURCE_INVALID );
		} catch ( Legacy_Migration_Failure $failure ) {
			throw $failure;
		} catch ( Throwable $error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE, $error );
		}
	}

	/**
	 * Attachment role.
	 *
	 * @param string $type Type.
	 * @param string $name Name.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function attachment_role( string $type, string $name ): string {
		if ( in_array( $type, array( 'image', 'file' ), true ) ) {
			return 'file';
		}
		if ( 'drawing' !== $type ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::SOURCE_INVALID );
		}
		return match ( strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) ) ) {
			'json' => 'document', 'png' => 'preview',
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			default => throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::SOURCE_INVALID ),
		};
	}

	/**
	 * Attachment storage key.
	 *
	 * @param string $submission_id Submission public identifier.
	 * @param int    $source_id Source id.
	 * @param string $sha256 Sha256.
	 * @param string $extension Extension.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function attachment_storage_key( string $submission_id, int $source_id, string $sha256, string $extension ): string {
		new Public_Id( $submission_id );
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $sha256 ) || 1 !== preg_match( '/^[a-z0-9]{1,10}$/D', $extension ) ) {
			throw new InvalidArgumentException( 'Legacy attachment storage identity is invalid.' );
		}
		return 'legacy-migration/submission-attachments/' . strtolower( $submission_id ) . '/'
			. $source_id . '-' . $sha256 . '.' . $extension;
	}

	/**
	 * Attachment submission root.
	 *
	 * @param string              $submission_id Submission public identifier.
	 * @param array<string,mixed> $dependency Dependency.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function attachment_submission_root( string $submission_id, array $dependency ): array {
		$row = $this->row(
			$this->prepare(
				'SELECT id, public_id, lock_version, assignment_id, learner_user_id, state, current_revision_public_id, official_revision_public_id, official_attempt_no FROM ' . self::TABLE_SUBMISSIONS
				. ' WHERE public_id = %s LIMIT 1 FOR UPDATE',
				array( $submission_id )
			)
		);
		if (
			null === $row || ! hash_equals( $submission_id, (string) ( $row['public_id'] ?? '' ) )
			|| $dependency['assignment_database_id'] !== $this->positive_integer( $row['assignment_id'] ?? null )
			|| $dependency['learner_user_id'] !== $this->positive_integer( $row['learner_user_id'] ?? null )
			|| ! in_array( $row['state'] ?? null, array( 'draft', 'conflict' ), true )
			|| null !== ( $row['official_revision_public_id'] ?? null )
			|| 0 !== $this->non_negative_integer( $row['official_attempt_no'] ?? null )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		$row['database_id']  = $this->positive_integer( $row['id'] ?? null );
		$row['lock_version'] = $this->non_negative_integer( $row['lock_version'] ?? null );
		return $row;
	}

	/**
	 * Attachment current revision.
	 *
	 * @param array<string,mixed> $submission Submission.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function attachment_current_revision( array $submission ): array {
		$current_id = $submission['current_revision_public_id'] ?? null;
		if ( ! is_string( $current_id ) || ! Public_Id::is_valid( $current_id ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		$row = $this->row(
			$this->prepare(
				'SELECT id, public_id, content_hash, revision_no, base_revision_no, state, response_schema_version, response_schema, response_version, response_json, response_hash, attachment_set_hash FROM ' . self::TABLE_SUBMISSION_REVISIONS
				. ' WHERE submission_id = %d AND public_id = %s LIMIT 1 FOR UPDATE',
				array( $submission['database_id'], $current_id )
			)
		);
		if ( null === $row || ! hash_equals( $current_id, (string) ( $row['public_id'] ?? '' ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		$row['database_id'] = $this->positive_integer( $row['id'] ?? null );
		return $row;
	}

	/**
	 * Attachment target rows.
	 *
	 * @param array<string,mixed> $submission Submission.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function attachment_target_rows( array $submission ): array {
		$rows   = $this->results(
			$this->prepare(
				'SELECT at.id, at.public_id, at.submission_revision_id, at.response_key, at.file_public_id, at.state, at.original_name, at.mime_type, at.byte_size, at.sha256, at.bound_at_utc, f.owner_type, f.owner_public_id, f.purpose, f.state AS file_state, f.scan_state, f.retention_class, f.byte_size AS file_byte_size, f.sha256 AS file_sha256, f.purged_at_utc FROM ' . self::TABLE_SUBMISSION_ATTACHMENTS . ' at INNER JOIN '
				. self::TABLE_PRIVATE_FILES . ' f ON f.public_id = at.file_public_id WHERE at.submission_id = %d ORDER BY at.id ASC FOR UPDATE',
				array( $submission['database_id'] )
			)
		);
		$result = array();
		$files  = array();
		foreach ( $rows as $row ) {
			$public_id = (string) ( $row['public_id'] ?? '' );
			$file_id   = (string) ( $row['file_public_id'] ?? '' );
			if (
				! Public_Id::is_valid( $public_id ) || ! Public_Id::is_valid( $file_id ) || isset( $files[ $file_id ] )
				|| null !== ( $row['submission_revision_id'] ?? null ) || null !== ( $row['bound_at_utc'] ?? null )
				|| 'ready' !== ( $row['state'] ?? null ) || 'submission' !== ( $row['owner_type'] ?? null )
				|| ! hash_equals( (string) $submission['public_id'], (string) ( $row['owner_public_id'] ?? '' ) )
				|| 'submission_attachment' !== ( $row['purpose'] ?? null ) || 'ready' !== ( $row['file_state'] ?? null )
				|| 'clean' !== ( $row['scan_state'] ?? null ) || 'submission_staging' !== ( $row['retention_class'] ?? null )
				|| null !== ( $row['purged_at_utc'] ?? null )
				|| $this->positive_integer( $row['byte_size'] ?? null ) !== $this->positive_integer( $row['file_byte_size'] ?? null )
				|| ! hash_equals( (string) ( $row['sha256'] ?? '' ), (string) ( $row['file_sha256'] ?? '' ) )
			) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
			}
			$files[ $file_id ] = true;
			$result[]          = array(
				'database_id'    => $this->positive_integer( $row['id'] ?? null ),
				'public_id'      => $public_id,
				'file_public_id' => $file_id,
				'response_id'    => (string) ( $row['response_key'] ?? '' ),
				'role'           => null,
				'original_name'  => (string) ( $row['original_name'] ?? '' ),
				'mime_type'      => (string) ( $row['mime_type'] ?? '' ),
				'byte_size'      => $this->positive_integer( $row['byte_size'] ?? null ),
				'sha256'         => (string) ( $row['sha256'] ?? '' ),
			);
		}
		return $result;
	}

	/**
	 * Prepare attachment finalization.
	 *
	 * @param int                            $run_database_id Run database id.
	 * @param int                            $submission_source_id Submission source id.
	 * @param array<string,mixed>            $parent Parent.
	 * @param array<string,mixed>            $metadata Metadata.
	 * @param array<string,mixed>            $source_root Source root.
	 * @param array<string,mixed>            $submission Submission.
	 * @param array<string,mixed>            $current Current.
	 * @param array<int,array<string,mixed>> $existing Existing.
	 * @param array<string,mixed>            $candidate Candidate.
	 * @param array<string,mixed>            $dependency Dependency.
	 * @param int                            $actor_user_id Authenticated actor from the access context.
	 * @param string                         $created_at_utc Created at utc.
	 * @return array<string,mixed>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function prepare_attachment_finalization(
		int $run_database_id,
		int $submission_source_id,
		array $parent, // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.parentFound -- Preserve the established named-argument signature; these parameter names are legal in supported PHP versions.
		array $metadata,
		array $source_root,
		array $submission,
		array $current,
		array $existing,
		array $candidate,
		array $dependency,
		int $actor_user_id,
		string $created_at_utc
	): ?array {
		$response = $this->decode_stored( (string) ( $current['response_json'] ?? '' ), (string) ( $current['response_hash'] ?? '' ) );
		$this->exact_keys( $response, array( 'answers', 'publication_hash', 'publication_id', 'response_schema_version' ) );
		if (
			! is_array( $response['answers'] ) || ( array() !== $response['answers'] && array_is_list( $response['answers'] ) )
			|| ! hash_equals( $dependency['publication_public_id'], (string) $response['publication_id'] )
			|| ! hash_equals( $dependency['publication_hash'], (string) $response['publication_hash'] )
			|| Submission_Revision_Integrity::RESPONSE_SCHEMA_VERSION !== ( $response['response_schema_version'] ?? null )
			|| 'saved' !== ( $current['state'] ?? null )
			|| Submission_Revision_Integrity::RESPONSE_SCHEMA !== ( $current['response_schema'] ?? null )
			|| Submission_Revision_Integrity::RESPONSE_VERSION !== $this->positive_integer( $current['response_version'] ?? null )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		$revision_no         = $this->positive_integer( $current['revision_no'] ?? null );
		$base_revision_no    = null === ( $current['base_revision_no'] ?? null ) ? null : $this->positive_integer( $current['base_revision_no'] );
		$stored_content_hash = $this->integrity->content_hash(
			$submission['public_id'],
			$revision_no,
			$base_revision_no,
			'saved',
			Submission_Revision_Integrity::RESPONSE_SCHEMA_VERSION,
			(string) $current['response_hash'],
			(string) $current['attachment_set_hash']
		);
		if ( ! hash_equals( $stored_content_hash, (string) ( $current['content_hash'] ?? '' ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}

		$items = $dependency['response_profile']['payload']['items'] ?? null;
		if ( ! is_array( $items ) || ! array_is_list( $items ) ) {
			throw new InvalidArgumentException( 'Response profile items are invalid.' );
		}
		$kinds = array();
		foreach ( $items as $profile_item ) {
			if ( is_array( $profile_item ) && is_string( $profile_item['response_id'] ?? null ) && is_string( $profile_item['kind'] ?? null ) ) {
				$kinds[ $profile_item['response_id'] ] = $profile_item['kind'];
			}
		}
		$descriptors = array_merge( $existing, array( $candidate ) );
		$grouped     = array();
		$all_ids     = array();
		foreach ( $descriptors as $descriptor ) {
			$response_id = (string) $descriptor['response_id'];
			if ( ! isset( $kinds[ $response_id ] ) || isset( $all_ids[ $descriptor['public_id'] ] ) ) {
				throw new InvalidArgumentException( 'Legacy attachment response binding is invalid.' );
			}
			$all_ids[ $descriptor['public_id'] ] = true;
			$grouped[ $response_id ][]           = $descriptor;
		}
		$answers = $response['answers'];
		foreach ( $grouped as $response_id => $descriptors_for_response ) {
			if ( array_key_exists( $response_id, $answers ) ) {
				throw new InvalidArgumentException( 'Legacy attachment answer was already populated.' );
			}
			$kind = $kinds[ $response_id ];
			if ( in_array( $kind, array( 'image_upload', 'file_upload' ), true ) ) {
				$answers[ $response_id ] = array_map( static fn ( array $value ): string => $value['public_id'], $descriptors_for_response );
				continue;
			}
			if ( 'drawing' !== $kind ) {
				throw new InvalidArgumentException( 'Legacy attachment kind is invalid.' );
			}
			$drawing = array();
			foreach ( $descriptors_for_response as $descriptor ) {
				$role = $descriptor['role'];
				if ( ! is_string( $role ) ) {
					$role = 'application/json' === $descriptor['mime_type'] ? 'document' : ( 'image/png' === $descriptor['mime_type'] ? 'preview' : null );
				}
				if ( ! in_array( $role, array( 'document', 'preview' ), true ) || isset( $drawing[ $role ] ) ) {
					return null;
				}
				$drawing[ $role ] = $descriptor['public_id'];
			}
			if ( ! isset( $drawing['document'], $drawing['preview'] ) ) {
				return null;
			}
			$answers[ $response_id ] = array(
				'document_attachment_id' => $drawing['document'],
				'preview_attachment_id'  => $drawing['preview'],
			);
		}
		$validated     = $this->response_validator->validate( $dependency['response_profile'], $answers );
		$validated_ids = array_keys( $validated['attachments'] );
		sort( $validated_ids, SORT_STRING );
		$expected_ids = array_keys( $all_ids );
		sort( $expected_ids, SORT_STRING );
		if ( $validated_ids !== $expected_ids ) {
			return null;
		}
		$complete = true;
		try {
			$this->response_validator->validate_complete( $dependency['response_profile'], $validated['answers'] ); } catch ( Assignment_Failure ) {
			$complete = false;
			}

			$attachment_set_hash = hash( 'sha256', $this->canonical->encode( $validated_ids ) );
			$new_response        = array(
				'answers'                 => $validated['answers'],
				'publication_hash'        => $dependency['publication_hash'],
				'publication_id'          => $dependency['publication_public_id'],
				'response_schema_version' => Submission_Revision_Integrity::RESPONSE_SCHEMA_VERSION,
			);
			$response_json       = $this->canonical->encode( $new_response );
			$response_hash       = hash( 'sha256', $response_json );
			$new_revision_no     = $revision_no + 1;
			$revision_public_id  = $this->ids()->create()->value();
			$revision_hash       = $this->integrity->content_hash(
				$submission['public_id'],
				$new_revision_no,
				$revision_no,
				'saved',
				Submission_Revision_Integrity::RESPONSE_SCHEMA_VERSION,
				$response_hash,
				$attachment_set_hash
			);
		$revision                = array(
			'public_id'               => $revision_public_id,
			'content_hash'            => $revision_hash,
			'created_by_user_id'      => $dependency['learner_user_id'],
			'created_at_utc'          => $created_at_utc,
			'submission_id'           => $submission['database_id'],
			'revision_no'             => $new_revision_no,
			'base_revision_no'        => $revision_no,
			'state'                   => 'saved',
			'response_schema_version' => Submission_Revision_Integrity::RESPONSE_SCHEMA_VERSION,
			'response_schema'         => Submission_Revision_Integrity::RESPONSE_SCHEMA,
			'response_version'        => Submission_Revision_Integrity::RESPONSE_VERSION,
			'response_json'           => $response_json,
			'response_hash'           => $response_hash,
			'attachment_set_hash'     => $attachment_set_hash,
		);
		$official                = $this->official_projection(
			$source_root,
			array(
				'rows'     => array( $revision ),
				'complete' => $complete,
			),
			false,
			$actor_user_id,
			$dependency
		);
		if ( $official['attempt_no'] > 0 ) {
			$official['revision_public_id'] = $revision_public_id;
			$official['revision_hash']      = $revision_hash;
		}
		$receipt = null;
		if ( $official['attempt_no'] > 0 ) {
			$receipt = $this->receipt_row(
				$submission['database_id'],
				$submission['public_id'],
				1,
				$revision_public_id,
				$revision_hash,
				$actor_user_id,
				$official,
				$dependency,
				$attachment_set_hash
			);
		}

		$parent_metadata                               = $metadata;
		$parent_metadata['current_revision_public_id'] = $revision_public_id;
		$parent_metadata['current_revision_hash']      = $revision_hash;
		$parent_metadata['revision_count']             = $this->non_negative_integer( $metadata['revision_count'] ?? null ) + 1;
		$parent_metadata['deferred_attachment_count']  = 0;
		$parent_metadata['official_count']             = null === $receipt ? 0 : 1;
		$parent_metadata['receipt_count']              = null === $receipt ? 0 : 1;
		$parent_metadata['receipt_public_ids']         = null === $receipt ? array() : array( hash( 'sha256', '1' ) => $receipt['public_id'] );
		$parent_metadata['receipt_hashes']             = null === $receipt ? array() : array( hash( 'sha256', '1' ) => $receipt['content_hash'] );
		if ( null !== $official['revision_public_id'] ) {
			$parent_metadata['official_revision_public_id'] = $official['revision_public_id'];
		}
		$parent_hash         = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'attachment_manifest_hash' => $parent_metadata['attachment_manifest_hash'],
					'current_revision_hash'    => $revision_hash,
					'official_revision_hash'   => $official['revision_hash'],
					'publication_hash'         => $dependency['publication_hash'],
					'source_checksum'          => $parent['source_checksum'],
					'state'                    => $official['root_state'],
					'submission_public_id'     => $submission['public_id'],
				)
			)
		);
		$parent_result       = new Legacy_Target_Write_Result( 'submission', $submission['public_id'], $parent_hash, $parent_metadata );
		$parent_payload      = array(
			'profile'    => self::ITEM_PROFILE,
			'source'     => array(
				'type'     => 'worksheet_submissions',
				'key'      => (string) $submission_source_id,
				'checksum' => $parent['source_checksum'],
			),
			'state'      => 'success',
			'target'     => array(
				'type'         => 'submission',
				'public_id'    => $submission['public_id'],
				'content_hash' => $parent_hash,
				'metadata'     => $parent_metadata,
			),
			'error_code' => null,
		);
		$parent_payload_json = $this->canonical->encode( $parent_payload );
		return array(
			'revision'            => $revision,
			'official'            => $official,
			'receipt'             => $receipt,
			'parent_payload_json' => $parent_payload_json,
			'parent_payload_hash' => hash( 'sha256', $parent_payload_json ),
			'parent_result'       => $parent_result,
			'attachment_set_hash' => $attachment_set_hash,
		);
	}

	/**
	 * Insert private attachment file.
	 *
	 * @param string              $file_id File id.
	 * @param string              $submission_id Submission public identifier.
	 * @param string              $storage_key Storage key.
	 * @param array<string,mixed> $upload Upload.
	 * @param int                 $actor_user_id Authenticated actor from the access context.
	 * @param string              $created_at_utc Created at utc.
	 * @return void
	 */
	private function insert_private_attachment_file(
		string $file_id,
		string $submission_id,
		string $storage_key,
		array $upload,
		int $actor_user_id,
		string $created_at_utc
	): void {
		$this->insert_or_fail(
			self::TABLE_PRIVATE_FILES,
			array(
				'public_id'            => $file_id,
				'lock_version'         => 0,
				'created_by_user_id'   => $actor_user_id,
				'created_at_utc'       => $created_at_utc,
				'updated_by_user_id'   => $actor_user_id,
				'updated_at_utc'       => $created_at_utc,
				'owner_type'           => 'submission',
				'owner_public_id'      => $submission_id,
				'purpose'              => 'submission_attachment',
				'storage_key'          => $storage_key,
				'original_name'        => $upload['original_name'],
				'mime_type'            => $upload['mime_type'],
				'byte_size'            => $upload['byte_size'],
				'sha256'               => $upload['sha256'],
				'state'                => 'ready',
				'scan_state'           => 'clean',
				'retention_class'      => 'submission_staging',
				'expires_at_utc'       => null,
				'legal_hold_until_utc' => null,
				'purged_at_utc'        => null,
			)
		);
	}

	/**
	 * Commit attachment finalization.
	 *
	 * @param int                            $run_database_id Run database id.
	 * @param int                            $submission_source_id Submission source id.
	 * @param array<string,mixed>            $parent Parent.
	 * @param array<string,mixed>            $submission Submission.
	 * @param array<int,array<string,mixed>> $existing Existing.
	 * @param int                            $candidate_database_id Candidate database id.
	 * @param array<string,mixed>            $finalization Finalization.
	 * @param int                            $actor_user_id Authenticated actor from the access context.
	 * @param string                         $created_at_utc Created at utc.
	 * @return void
	 */
	private function commit_attachment_finalization(
		int $run_database_id,
		int $submission_source_id,
		array $parent, // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.parentFound -- Preserve the established named-argument signature; these parameter names are legal in supported PHP versions.
		array $submission,
		array $existing,
		int $candidate_database_id,
		array $finalization,
		int $actor_user_id,
		string $created_at_utc
	): void {
		$this->insert_or_fail( self::TABLE_SUBMISSION_REVISIONS, $finalization['revision'] );
		$revision_database_id      = $this->insert_id();
		$attachment_database_ids   = array_map( static fn ( array $row ): int => $row['database_id'], $existing );
		$attachment_database_ids[] = $candidate_database_id;
		foreach ( $attachment_database_ids as $attachment_database_id ) {
			$this->change_one(
				$this->prepare(
					'UPDATE ' . self::TABLE_SUBMISSION_ATTACHMENTS . ' SET submission_revision_id = %d, state = %s, bound_at_utc = %s, '
					. 'lock_version = lock_version + 1, updated_by_user_id = %d, updated_at_utc = %s '
					. 'WHERE id = %d AND submission_id = %d AND submission_revision_id IS NULL AND state = %s AND bound_at_utc IS NULL',
					array(
						$revision_database_id,
						'bound',
						$created_at_utc,
						$actor_user_id,
						$created_at_utc,
						$attachment_database_id,
						$submission['database_id'],
						'ready',
					)
				)
			);
		}
		$receipt = $finalization['receipt'];
		if ( is_array( $receipt ) ) {
			$receipt['row']['submission_revision_id'] = $revision_database_id;
			$this->insert_or_fail( self::TABLE_SUBMISSION_RECEIPTS, $receipt['row'] );
		}
		$official = $finalization['official'];
		$this->change_one(
			$this->prepare(
				'UPDATE ' . self::TABLE_SUBMISSIONS . ' SET state = %s, current_revision_public_id = %s, official_revision_public_id = %s, '
				. 'official_attempt_no = %d, submitted_at_utc = %s, closed_at_utc = %s, lock_version = lock_version + 1, '
				. 'updated_by_user_id = %d, updated_at_utc = %s WHERE id = %d AND public_id = %s AND lock_version = %d '
				. 'AND state = %s AND current_revision_public_id = %s AND official_revision_public_id IS NULL AND official_attempt_no = 0',
				array(
					$official['root_state'],
					$finalization['revision']['public_id'],
					$official['revision_public_id'],
					$official['attempt_no'],
					$official['submitted_at_utc'],
					$official['closed_at_utc'],
					$actor_user_id,
					$created_at_utc,
					$submission['database_id'],
					$submission['public_id'],
					$submission['lock_version'],
					$submission['state'],
					$submission['current_revision_public_id'],
				)
			)
		);
		$this->change_one(
			$this->prepare(
				'UPDATE ' . self::TABLE_ITEMS . ' SET payload_json = %s, payload_hash = %s, lock_version = lock_version + 1, '
				. 'updated_by_user_id = %d, updated_at_utc = %s WHERE id = %d AND import_run_id = %d AND lock_version = %d '
				. 'AND source_type = %s AND source_key = %s AND state = %s AND target_type = %s AND target_public_id = %s',
				array(
					$finalization['parent_payload_json'],
					$finalization['parent_payload_hash'],
					$actor_user_id,
					$created_at_utc,
					$this->positive_integer( $parent['database_id'] ?? null ),
					$run_database_id,
					$this->non_negative_integer( $parent['lock_version'] ?? null ),
					'worksheet_submissions',
					(string) $submission_source_id,
					'success',
					'submission',
					$submission['public_id'],
				)
			)
		);
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
				$this->private_files?->delete( $this->created_storage_keys[ $index ] ); } catch ( Throwable ) {
				$failed = true;
				}
		}
		$this->created_storage_keys = array_slice( $this->created_storage_keys, 0, $marker );
		if ( $failed ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Verify relation.
	 *
	 * @param int                $run_database_id Run database id.
	 * @param Legacy_Source_Item $item Item.
	 * @param bool               $revision Revision.
	 * @return Legacy_Target_Write_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function verify_relation( int $run_database_id, Legacy_Source_Item $item, bool $revision ): Legacy_Target_Write_Result {
		try {
			$plan        = $this->run_plan( $run_database_id );
			$source_type = $item->source_type();
			$this->assert_exact_source_row( $item->source_type(), $item->record() );
			$this->assert_source_checksum( $item, $plan );
			$source_tables = $this->source_tables( $plan );
			$source_table  = $source_tables[ $source_type ] ?? null;
			if ( ! is_string( $source_table ) ) {
				throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::SOURCE_INVALID );
			}
			$current_source = $this->source_row_by_id( $source_table, $source_type, $item->source_id() );
			$this->assert_exact_source_row( $source_type, $current_source );
			if (
				null !== ( $current_source['deleted_at'] ?? null )
				|| ! hash_equals( $item->source_checksum(), $this->source_checksum( $plan, $source_type, $item->source_id(), $current_source ) )
			) {
				throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::SOURCE_INVALID );
			}
			$parent_source_id = $this->positive_integer( $item->record()['submission_id'] ?? null );
			$parent           = $this->import_receipt( $run_database_id, 'worksheet_submissions', $parent_source_id, 'submission' );
			$metadata         = $parent['result']->metadata();
			$map              = $metadata[ $revision ? 'revision_source_hashes' : 'attachment_source_hashes' ] ?? null;
			$relation_key     = hash( 'sha256', $item->source_key() );
			$expected         = is_array( $map ) ? ( $map[ $relation_key ] ?? null ) : null;
			if ( ! is_string( $expected ) || ! hash_equals( $expected, $item->source_checksum() ) ) {
				throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::SOURCE_INVALID );
			}
			$submission_public_id = $parent['result']->target_public_id();
			if ( ! $revision ) {
				$this->assert_submission_root( $submission_public_id );
				return new Legacy_Target_Write_Result(
					'submission_attachment_manifest',
					$submission_public_id,
					$item->source_checksum(),
					array(
						'attachment_manifest_hash' => (string) $metadata['attachment_manifest_hash'],
						'submission_public_id'     => $submission_public_id,
						'submission_source_key'    => (string) $parent_source_id,
					)
				);
			}
			$public_ids    = $metadata['revision_public_ids'] ?? null;
			$target_hashes = $metadata['revision_target_hashes'] ?? null;
			$public_id     = is_array( $public_ids ) ? ( $public_ids[ $relation_key ] ?? null ) : null;
			$content_hash  = is_array( $target_hashes ) ? ( $target_hashes[ $relation_key ] ?? null ) : null;
			if ( ! is_string( $public_id ) || ! is_string( $content_hash ) ) {
				throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
			}
			$row = $this->row(
				$this->prepare(
					'SELECT r.public_id, r.content_hash, s.public_id AS submission_public_id FROM ' . self::TABLE_SUBMISSION_REVISIONS
						. ' r INNER JOIN ' . self::TABLE_SUBMISSIONS . ' s ON s.id = r.submission_id WHERE r.public_id = %s LIMIT 1 FOR UPDATE',
					array( $public_id )
				)
			);
			if (
				null === $row
				|| ! hash_equals( $public_id, (string) ( $row['public_id'] ?? '' ) )
				|| ! hash_equals( $content_hash, (string) ( $row['content_hash'] ?? '' ) )
				|| ! hash_equals( $submission_public_id, (string) ( $row['submission_public_id'] ?? '' ) )
			) {
				throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
			}
			return new Legacy_Target_Write_Result(
				'submission_revision',
				$public_id,
				$content_hash,
				array(
					'submission_public_id'  => $submission_public_id,
					'submission_source_key' => (string) $parent_source_id,
				)
			);
		} catch ( Legacy_Target_Transformation_Failure | Legacy_Migration_Failure $failure ) {
			throw $failure;
		} catch ( Throwable $error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::SOURCE_INVALID );
		}
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
		if (
			null === $row || 'running' !== ( $row['state'] ?? null )
			|| Approve_Legacy_Migration::PREVIEW_SCHEMA !== ( $row['preview_schema'] ?? null )
			|| Approve_Legacy_Migration::PREVIEW_DATABASE_VERSION !== $this->positive_integer( $row['preview_version'] ?? null )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		$preview = $this->decode_stored( (string) ( $row['preview_json'] ?? '' ), (string) ( $row['preview_hash'] ?? '' ) );
		$this->exact_keys( $preview, array( 'plan', 'profile', 'state' ) );
		$plan = $preview['plan'] ?? null;
		if (
			Approve_Legacy_Migration::PREVIEW_PROFILE !== ( $preview['profile'] ?? null )
			|| 'approved' !== ( $preview['state'] ?? null ) || ! is_array( $plan ) || array_is_list( $plan )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		try {
			( new Legacy_Migration_Plan( $this->canonical ) )->assert_executable( $plan ); } catch ( InvalidArgumentException $error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE, $error );
			}
			$source         = $plan['source'] ?? null;
			$source_profile = is_array( $source ) ? ( $source['profile'] ?? null ) : null;
			$source_kind    = is_string( $source_profile ) ? strstr( $source_profile, '@', true ) : false;
			if (
			Legacy_Migration_Plan::PROFILE !== ( $plan['profile'] ?? null )
			|| Legacy_Source_Profile_0_1_2::PROFILE !== $source_profile
			|| ! is_string( $source_kind ) || ! hash_equals( $source_kind, (string) ( $row['source_kind'] ?? '' ) )
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
	 * @param array<string,mixed> $plan Plan.
	 * @return array<string,string>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function source_tables( array $plan ): array {
		$tables = $plan['inventory']['tables'] ?? null;
		if ( ! is_array( $tables ) || ! array_is_list( $tables ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		$required = array( 'worksheet_assignments', 'worksheet_submissions', 'worksheet_questions', 'submission_revisions', 'submission_attachments' );
		$result   = array();
		$profiles = $this->profile->tables();
		foreach ( $tables as $table ) {
			$logical = is_array( $table ) ? ( $table['logical_table'] ?? null ) : null;
			if ( ! is_string( $logical ) || ! in_array( $logical, $required, true ) ) {
				continue;
			}
			$physical = $table['source_table'] ?? null;
			$suffix   = $profiles[ $logical ]['suffix'] ?? null;
			if (
				isset( $result[ $logical ] ) || 'migrate' !== ( $table['disposition'] ?? null )
				|| ! is_string( $physical ) || 1 !== preg_match( '/^[A-Za-z0-9_]{4,191}$/D', $physical )
				|| ! is_string( $suffix ) || $physical === $suffix || ! str_ends_with( $physical, $suffix )
			) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
			}
			$result[ $logical ] = $physical;
		}
		sort( $required, SORT_STRING );
		$actual = array_keys( $result );
		sort( $actual, SORT_STRING );
		if ( $actual !== $required ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		return $result;
	}

	/**
	 * Reject invalid source checksum.
	 *
	 * @param Legacy_Source_Item  $item Item.
	 * @param array<string,mixed> $plan Plan.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_source_checksum( Legacy_Source_Item $item, array $plan ): void {
		$expected = $this->source_checksum( $plan, $item->source_type(), $item->source_id(), $item->record() );
		if ( ! hash_equals( $expected, $item->source_checksum() ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::SOURCE_INVALID );
		}
	}

	/**
	 * Dependencies.
	 *
	 * @param int                  $run_database_id Run database id.
	 * @param array<string,mixed>  $record Record.
	 * @param array<string,mixed>  $plan Plan.
	 * @param array<string,string> $source_tables Source tables.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function dependencies( int $run_database_id, array $record, array $plan, array $source_tables ): array {
		if ( null !== ( $record['deleted_at'] ?? null ) ) {
			throw new InvalidArgumentException( 'Legacy submission was deleted.' );
		}
		$assignment_source_id       = $this->positive_integer( $record['assignment_id'] ?? null );
		$student_source_id          = $this->positive_integer( $record['student_user_id'] ?? null );
		$assignment_source          = $this->source_row_by_id( $source_tables['worksheet_assignments'], 'worksheet_assignments', $assignment_source_id );
		$assignment_receipt         = $this->import_receipt( $run_database_id, 'worksheet_assignments', $assignment_source_id, 'assignment' );
		$assignment_source_checksum = $this->source_checksum( $plan, 'worksheet_assignments', $assignment_source_id, $assignment_source );
		if ( ! hash_equals( $assignment_source_checksum, $assignment_receipt['source_checksum'] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::SOURCE_INVALID );
		}
		$mapping        = new Legacy_Mapping_Manifest( $this->canonical, (string) $plan['source']['profile'] );
		$context_ref    = $mapping->context_ref_for_assignment( $assignment_source );
		$assignment_ref = $mapping->assignment_ref( $assignment_source_id );
		if ( null === $context_ref ) {
			throw new InvalidArgumentException( 'Legacy submission Context is invalid.' );
		}
		$user_ref     = $mapping->user_ref( $context_ref, $assignment_ref, $student_source_id );
		$decisions    = array();
		$requirements = array();
		foreach ( $plan['mapping_decisions'] as $decision ) {
			$decisions[ (string) $decision['source_ref'] ] = $decision;
		}
		foreach ( $plan['mapping_manifest'] as $requirement ) {
			$requirements[ (string) $requirement['source_ref'] ] = $requirement;
		}
		$context_requirement = $requirements[ $context_ref ] ?? null;
		$user_requirement    = $requirements[ $user_ref ] ?? null;
		$context_decision    = $decisions[ $context_ref ]['target'] ?? null;
		$user_decision       = $decisions[ $user_ref ]['target'] ?? null;
		if (
			! is_array( $context_requirement ) || 'context' !== ( $context_requirement['kind'] ?? null )
			|| ! is_array( $user_requirement ) || 'user' !== ( $user_requirement['kind'] ?? null )
			|| $assignment_ref !== ( $user_requirement['assignment_source_ref'] ?? null )
			|| $context_ref !== ( $user_requirement['context_source_ref'] ?? null )
			|| ! is_array( $context_decision ) || ! is_array( $user_decision )
		) {
			throw new InvalidArgumentException( 'Legacy submission mapping is missing.' );
		}
		$learner_user_id      = $this->positive_integer( $user_decision['learner_user_id'] ?? null );
		$assignment_public_id = $assignment_receipt['result']->target_public_id();
		$sql                  = 'SELECT a.id AS assignment_database_id, a.public_id AS assignment_public_id, a.context_type, a.context_id, a.context_revision, a.state AS assignment_state, a.worksheet_publication_public_id, a.publication_hash, a.due_at_utc, a.schedule_version, a.schedule_hash, a.resubmission_policy_hash, t.target_type, t.target_context_id, t.target_context_revision, t.membership_revision, t.learner_user_id, t.state AS target_state FROM ' . self::TABLE_ASSIGNMENTS . ' a INNER JOIN ' . self::TABLE_TARGETS
			. ' t ON t.assignment_id = a.id WHERE a.public_id = %s AND t.learner_user_id = %d LIMIT 1 FOR UPDATE';
		$assignment           = $this->row( $this->prepare( $sql, array( $assignment_public_id, $learner_user_id ) ) );
		if (
			null === $assignment || ! hash_equals( $assignment_public_id, (string) ( $assignment['assignment_public_id'] ?? '' ) )
			|| ! in_array( $assignment['assignment_state'] ?? null, array( 'closed', 'withdrawn' ), true )
			|| ( $context_decision['context_type'] ?? null ) !== ( $assignment['context_type'] ?? null )
			|| ( $context_decision['context_id'] ?? null ) !== ( $assignment['context_id'] ?? null )
			|| ( $context_decision['context_revision'] ?? null ) !== ( $assignment['context_revision'] ?? null )
			|| ( $context_decision['context_type'] ?? null ) !== ( $assignment['target_type'] ?? null )
			|| ( $context_decision['context_id'] ?? null ) !== ( $assignment['target_context_id'] ?? null )
			|| ( $context_decision['context_revision'] ?? null ) !== ( $assignment['target_context_revision'] ?? null )
			|| ( $user_decision['membership_revision'] ?? null ) !== ( $assignment['membership_revision'] ?? null )
			|| $learner_user_id !== $this->positive_integer( $assignment['learner_user_id'] ?? null )
			|| 'active' !== ( $assignment['target_state'] ?? null )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		$assignment_metadata   = $assignment_receipt['result']->metadata();
		$publication_public_id = (string) ( $assignment_metadata['publication_public_id'] ?? '' );
		$publication_hash      = (string) ( $assignment_metadata['publication_hash'] ?? '' );
		if (
			! hash_equals( $publication_public_id, (string) ( $assignment['worksheet_publication_public_id'] ?? '' ) )
			|| ! hash_equals( $publication_hash, (string) ( $assignment['publication_hash'] ?? '' ) )
			|| ! hash_equals( (string) ( $assignment_metadata['schedule_hash'] ?? '' ), (string) ( $assignment['schedule_hash'] ?? '' ) )
			|| ! hash_equals( (string) ( $assignment_metadata['resubmission_policy_hash'] ?? '' ), (string) ( $assignment['resubmission_policy_hash'] ?? '' ) )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}

		$sql         = 'SELECT p.id AS publication_database_id, p.public_id AS publication_public_id, p.content_hash AS publication_content_hash, p.publication_hash, p.state AS publication_state, p.worksheet_revision_id, p.response_schema_schema, p.response_schema_version, p.response_schema_json, p.response_schema_hash, wr.id AS revision_database_id, wr.public_id AS revision_public_id, wr.document_hash FROM ' . self::TABLE_PUBLICATIONS . ' p INNER JOIN ' . self::TABLE_WORKSHEET_REVISIONS
			. ' wr ON wr.id = p.worksheet_revision_id WHERE p.public_id = %s LIMIT 1 FOR UPDATE';
		$publication = $this->row( $this->prepare( $sql, array( $publication_public_id ) ) );
		if (
			null === $publication || ! hash_equals( $publication_public_id, (string) ( $publication['publication_public_id'] ?? '' ) )
			|| ! hash_equals( $publication_hash, (string) ( $publication['publication_content_hash'] ?? '' ) )
			|| ! hash_equals( $publication_hash, (string) ( $publication['publication_hash'] ?? '' ) )
			|| 'retired' !== ( $publication['publication_state'] ?? null )
			|| 'oj_ai.student_response_schema' !== ( $publication['response_schema_schema'] ?? null )
			|| self::PROFILE_VERSION !== $this->positive_integer( $publication['response_schema_version'] ?? null )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		$response_profile = $this->decode_stored( (string) ( $publication['response_schema_json'] ?? '' ), (string) ( $publication['response_schema_hash'] ?? '' ) );
		if ( ! hash_equals( (string) ( $publication['document_hash'] ?? '' ), (string) ( $response_profile['source_hash'] ?? '' ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		$template_source_id = $this->positive_integer( $assignment_source['template_id'] ?? null );
		$worksheet_receipt  = $this->import_receipt( $run_database_id, 'worksheet_templates', $template_source_id, 'worksheet' );
		$worksheet_metadata = $worksheet_receipt['result']->metadata();
		if (
			! hash_equals( (string) ( $worksheet_metadata['revision_public_id'] ?? '' ), (string) ( $publication['revision_public_id'] ?? '' ) )
			|| ! hash_equals( (string) ( $worksheet_metadata['document_hash'] ?? '' ), (string) ( $publication['document_hash'] ?? '' ) )
			|| ! hash_equals( (string) ( $assignment_metadata['revision_public_id'] ?? '' ), (string) ( $publication['revision_public_id'] ?? '' ) )
			|| ! hash_equals( (string) ( $assignment_metadata['revision_document_hash'] ?? '' ), (string) ( $publication['document_hash'] ?? '' ) )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		$bindings = $this->question_bindings(
			$run_database_id,
			$template_source_id,
			$source_tables['worksheet_questions'],
			$worksheet_metadata,
			$this->positive_integer( $publication['revision_database_id'] ?? null ),
			$response_profile,
			$plan
		);
		return array(
			'assignment_database_id'   => $this->positive_integer( $assignment['assignment_database_id'] ?? null ),
			'assignment_public_id'     => $assignment_public_id,
			'learner_user_id'          => $learner_user_id,
			'context_revision'         => (string) $assignment['context_revision'],
			'due_at_utc'               => $this->nullable_utc( $assignment['due_at_utc'] ?? null ),
			'schedule_version'         => $this->positive_integer( $assignment['schedule_version'] ?? null ),
			'schedule_hash'            => (string) $assignment['schedule_hash'],
			'resubmission_policy_hash' => (string) $assignment['resubmission_policy_hash'],
			'publication_public_id'    => $publication_public_id,
			'publication_hash'         => $publication_hash,
			'response_schema_hash'     => (string) $publication['response_schema_hash'],
			'response_profile'         => $response_profile,
			'bindings'                 => $bindings,
		);
	}

	/**
	 * Question bindings.
	 *
	 * @param int                 $run_database_id Run database id.
	 * @param int                 $template_source_id Template source id.
	 * @param string              $source_table Source table.
	 * @param array<string,mixed> $worksheet_metadata Worksheet metadata.
	 * @param int                 $revision_database_id Revision database id.
	 * @param array<string,mixed> $response_profile Response profile.
	 * @param array<string,mixed> $plan Plan.
	 * @return array<string,array{response_id:string,kind:string,question:array<string,mixed>}>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function question_bindings(
		int $run_database_id,
		int $template_source_id,
		string $source_table,
		array $worksheet_metadata,
		int $revision_database_id,
		array $response_profile,
		array $plan
	): array {
		$columns = $this->profile->columns( 'worksheet_questions' );
		$select  = implode( ', ', array_map( static fn ( string $column ): string => '`' . $column . '`', $columns ) );
		$sql     = 'SELECT ' . $select . ' FROM `' . $source_table . '` WHERE `template_id` = %d ORDER BY `sort_order` ASC, `id` ASC LIMIT ' . ( self::MAXIMUM_RELATIONS + 1 ) . ' FOR UPDATE';
		$rows    = $this->results( $this->prepare( $sql, array( $template_source_id ) ) );
		if ( count( $rows ) > self::MAXIMUM_RELATIONS || count( $rows ) !== ( $worksheet_metadata['question_count'] ?? null ) ) {
			throw new InvalidArgumentException( 'Legacy question inventory drifted.' );
		}
		$items = $response_profile['payload']['items'] ?? null;
		if ( ! is_array( $items ) || ! array_is_list( $items ) || count( $items ) !== count( $rows ) ) {
			throw new InvalidArgumentException( 'Current response inventory drifted.' );
		}
		$items_by_id = array();
		foreach ( $items as $profile_item ) {
			if ( ! is_array( $profile_item ) || ! is_string( $profile_item['response_id'] ?? null ) ) {
				throw new InvalidArgumentException( 'Current response item is invalid.' );
			}
			$items_by_id[ $profile_item['response_id'] ] = $profile_item;
		}
		$bindings = array();
		foreach ( $rows as $row ) {
			$this->assert_exact_source_row( 'worksheet_questions', $row );
			if ( null !== ( $row['deleted_at'] ?? null ) || $template_source_id !== $this->positive_integer( $row['template_id'] ?? null ) ) {
				throw new InvalidArgumentException( 'Legacy question relation is invalid.' );
			}
			$source_id              = $this->positive_integer( $row['id'] ?? null );
			$legacy_question_id     = $this->text( $row['question_id'] ?? null, 191 );
			$question               = $this->decode_source_object( $row['question_schema_json'] ?? null );
			$source_hash_key        = hash( 'sha256', $legacy_question_id );
			$question_public_id     = $worksheet_metadata['question_public_ids'][ $source_hash_key ] ?? null;
			$expected_question_hash = $worksheet_metadata['question_source_hashes'][ $source_hash_key ] ?? null;
			if ( ! is_string( $question_public_id ) || ! is_string( $expected_question_hash ) || ! hash_equals( $expected_question_hash, $this->legacy_object_hash( $question ) ) ) {
				throw new InvalidArgumentException( 'Legacy question receipt drifted.' );
			}
			$child_receipt  = $this->import_receipt( $run_database_id, 'worksheet_questions', $source_id, 'worksheet_question' );
			$child_checksum = $this->source_checksum( $plan, 'worksheet_questions', $source_id, $row );
			if ( ! hash_equals( $child_checksum, $child_receipt['source_checksum'] ) || ! hash_equals( $question_public_id, $child_receipt['result']->target_public_id() ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::SOURCE_INVALID );
			}
			$target       = $this->row(
				$this->prepare(
					'SELECT public_id, content_hash, worksheet_revision_id, question_key, response_type FROM ' . self::TABLE_WORKSHEET_QUESTIONS . ' WHERE public_id = %s LIMIT 1 FOR UPDATE',
					array( $question_public_id )
				)
			);
			$response_id  = is_array( $target ) ? ( $target['question_key'] ?? null ) : null;
			$profile_item = is_string( $response_id ) ? ( $items_by_id[ $response_id ] ?? null ) : null;
			if (
				null === $target || ! hash_equals( $question_public_id, (string) ( $target['public_id'] ?? '' ) )
				|| ! hash_equals( $child_receipt['result']->content_hash(), (string) ( $target['content_hash'] ?? '' ) )
				|| $revision_database_id !== $this->positive_integer( $target['worksheet_revision_id'] ?? null )
				|| ! is_array( $profile_item ) || ( $profile_item['kind'] ?? null ) !== ( $target['response_type'] ?? null )
				|| isset( $bindings[ $legacy_question_id ] )
			) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
			}
			$bindings[ $legacy_question_id ] = array(
				'response_id' => (string) $response_id,
				'kind'        => (string) $target['response_type'],
				'question'    => $question,
			);
		}
		return $bindings;
	}

	/**
	 * Source relations.
	 *
	 * @param string $source_table Source table.
	 * @param string $logical_table Logical table.
	 * @param string $foreign_key Foreign key.
	 * @param int    $source_id Source id.
	 * @param string $order Order.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function source_relations( string $source_table, string $logical_table, string $foreign_key, int $source_id, string $order ): array {
		$columns = $this->profile->columns( $logical_table );
		$select  = implode( ', ', array_map( static fn ( string $column ): string => '`' . $column . '`', $columns ) );
		$sql     = 'SELECT ' . $select . ' FROM `' . $source_table . '` WHERE `' . $foreign_key . '` = %d ORDER BY ' . $order
			. ' LIMIT ' . ( self::MAXIMUM_RELATIONS + 1 ) . ' FOR UPDATE';
		$rows    = $this->results( $this->prepare( $sql, array( $source_id ) ) );
		if ( count( $rows ) > self::MAXIMUM_RELATIONS ) {
			throw new InvalidArgumentException( 'Legacy submission relation limit exceeded.' );
		}
		foreach ( $rows as $row ) {
			$this->assert_exact_source_row( $logical_table, $row );
			if ( $source_id !== $this->positive_integer( $row[ $foreign_key ] ?? null ) || null !== ( $row['deleted_at'] ?? null ) ) {
				throw new InvalidArgumentException( 'Legacy submission relation is invalid.' );
			}
		}
		return $rows;
	}

	/**
	 * Prepare revisions.
	 *
	 * @param array<string,mixed>                                                              $root Root.
	 * @param array<int,array<string,mixed>>                                                   $source_revisions Source revisions.
	 * @param array<string,mixed>                                                              $response_profile Response profile.
	 * @param array<string,array{response_id:string,kind:string,question:array<string,mixed>}> $bindings Bindings.
	 * @param string                                                                           $publication_public_id Publication public id.
	 * @param string                                                                           $publication_hash Publication hash.
	 * @param int                                                                              $learner_user_id Learner user id.
	 * @return array{rows:array<int,array<string,mixed>>,source_hashes:array<string,string>,complete:bool,deferred_attachment:bool}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function prepare_revisions(
		array $root,
		array $source_revisions,
		array $response_profile,
		array $bindings,
		string $publication_public_id,
		string $publication_hash,
		int $learner_user_id
	): array {
		$server_revision = $this->non_negative_integer( $root['server_revision'] ?? null );
		if ( $server_revision !== count( $source_revisions ) ) {
			throw new InvalidArgumentException( 'Legacy submission revision count drifted.' );
		}
		if ( 0 === $server_revision ) {
			if ( null !== ( $root['content_json'] ?? null ) && '' !== trim( (string) $root['content_json'] ) ) {
				throw new InvalidArgumentException( 'Legacy submission has content without a revision.' );
			}
			return array(
				'rows'                => array(),
				'source_hashes'       => array(),
				'complete'            => false,
				'deferred_attachment' => false,
			);
		}
		$rows          = array();
		$source_hashes = array();
		$last_content  = null;
		$complete      = false;
		$deferred      = false;
		foreach ( $source_revisions as $index => $source ) {
			$revision_no = $this->positive_integer( $source['revision'] ?? null );
			if ( $revision_no !== $index + 1 ) {
				throw new InvalidArgumentException( 'Legacy submission revisions are not contiguous.' );
			}
			$source_id           = $this->positive_integer( $source['id'] ?? null );
			$content             = $this->decode_source_object( $source['content_json'] ?? null );
			$projected           = $this->responses->project( $response_profile, $content, $bindings );
			$response            = array(
				'answers'                 => $projected['answers'],
				'publication_hash'        => $publication_hash,
				'publication_id'          => $publication_public_id,
				'response_schema_version' => Submission_Revision_Integrity::RESPONSE_SCHEMA_VERSION,
			);
			$response_json       = $this->canonical->encode( $response );
			$response_hash       = hash( 'sha256', $response_json );
			$attachment_set_hash = hash( 'sha256', $this->canonical->encode( array() ) );
			$public_id           = $this->ids()->create()->value();
			$source_checksum     = hash(
				'sha256',
				$this->canonical->encode(
					array(
						'profile'     => Legacy_Source_Profile_0_1_2::PROFILE,
						'source_key'  => (string) $source_id,
						'source_type' => 'submission_revisions',
						'record'      => $source,
					)
				)
			);
			$rows[]              = array(
				'source_id'           => $source_id,
				'public_id'           => $public_id,
				'response_hash'       => $response_hash,
				'attachment_set_hash' => $attachment_set_hash,
				'content_hash'        => '',
				'data'                => array(
					'public_id'               => $public_id,
					'content_hash'            => '',
					'created_by_user_id'      => $learner_user_id,
					'created_at_utc'          => $this->utc_value( $source['created_at'] ?? null ),
					'revision_no'             => $revision_no,
					'base_revision_no'        => 1 === $revision_no ? null : $revision_no - 1,
					'state'                   => 'saved',
					'response_schema_version' => Submission_Revision_Integrity::RESPONSE_SCHEMA_VERSION,
					'response_schema'         => Submission_Revision_Integrity::RESPONSE_SCHEMA,
					'response_version'        => Submission_Revision_Integrity::RESPONSE_VERSION,
					'response_json'           => $response_json,
					'response_hash'           => $response_hash,
					'attachment_set_hash'     => $attachment_set_hash,
				),
			);
			$source_hashes[ hash( 'sha256', (string) $source_id ) ] = $source_checksum;
			$last_content = $content;
			$complete     = $projected['complete'];
			$deferred     = $deferred || $projected['deferred_attachment'];
		}
		$root_content = $this->decode_source_object( $root['content_json'] ?? null );
		if ( ! is_array( $last_content ) || ! hash_equals( $this->legacy_object_hash( $last_content ), $this->legacy_object_hash( $root_content ) ) ) {
			throw new InvalidArgumentException( 'Legacy submission current content drifted.' );
		}
		return array(
			'rows'                => $rows,
			'source_hashes'       => $source_hashes,
			'complete'            => $complete,
			'deferred_attachment' => $deferred,
		);
	}

	/**
	 * Bind revision identity.
	 *
	 * @param array<string,mixed> $prepared Prepared.
	 * @param string              $submission_public_id Submission public id.
	 * @return array<string,mixed>
	 */
	private function bind_revision_identity( array $prepared, string $submission_public_id ): array {
		foreach ( $prepared['rows'] as &$revision ) {
			$data                             = $revision['data'];
			$content_hash                     = $this->integrity->content_hash(
				$submission_public_id,
				$data['revision_no'],
				$data['base_revision_no'],
				$data['state'],
				$data['response_schema_version'],
				$data['response_hash'],
				$data['attachment_set_hash']
			);
			$revision['content_hash']         = $content_hash;
			$revision['data']['content_hash'] = $content_hash;
		}
		unset( $revision );
		return $prepared;
	}

	/**
	 * Attachment manifest.
	 *
	 * @param array<int,array<string,mixed>>                                                   $rows Rows.
	 * @param array<string,mixed>                                                              $plan Plan.
	 * @param array<string,mixed>                                                              $root Root.
	 * @param array<string,array{response_id:string,kind:string,question:array<string,mixed>}> $bindings Bindings.
	 * @return array{count:int,manifest_hash:string,source_hashes:array<string,string>,response_public_ids:array<string,string>}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function attachment_manifest( array $rows, array $plan, array $root, array $bindings ): array {
		$manifest            = array();
		$hashes              = array();
		$response_public_ids = array();
		$zip_submission      = false;
		$answers             = array();
		if ( array() !== $rows ) {
			$content        = $this->decode_source_object( $root['content_json'] ?? null );
			$zip_submission = 'zip_upload_submission' === ( $content['engine'] ?? null );
			if ( $zip_submission ) {
				$this->exact_keys( $content, array( 'answers', 'engine', 'files', 'version', 'zip_upload' ) );
			} elseif ( 'worksheet_student_answers' !== ( $content['engine'] ?? null ) || ! is_array( $content['answers'] ?? null ) || array_is_list( $content['answers'] ) ) {
				throw new InvalidArgumentException( 'Legacy attachment response is invalid.' );
			}
			foreach ( $zip_submission ? array() : $content['answers'] as $legacy_question_id => $answer ) {
				$binding = $bindings[ (string) $legacy_question_id ] ?? null;
				if ( ! is_array( $answer ) || array_is_list( $answer ) || ! is_array( $binding ) ) {
					throw new InvalidArgumentException( 'Legacy attachment binding is invalid.' );
				}
				$block_id = $this->text( $answer['block_id'] ?? null, 191 );
				if ( isset( $answers[ $block_id ] ) || (string) $legacy_question_id !== (string) ( $answer['question_id'] ?? '' ) ) {
					throw new InvalidArgumentException( 'Legacy attachment block is ambiguous.' );
				}
				$answers[ $block_id ] = $binding;
			}
		}
		foreach ( $rows as $row ) {
			$source_id = $this->positive_integer( $row['id'] ?? null );
			$checksum  = $this->source_checksum( $plan, 'submission_attachments', $source_id, $row );
			$key       = hash( 'sha256', (string) $source_id );
			if ( $zip_submission ) {
				if ( 'zip_upload' !== ( $row['block_id'] ?? null ) || 'file' !== ( $row['attachment_type'] ?? null ) ) {
					throw new InvalidArgumentException( 'Legacy ZIP attachment relation is invalid.' );
				}
				$hashes[ $key ] = $checksum;
				$manifest[]     = array(
					'source_checksum' => $checksum,
					'source_key'      => (string) $source_id,
				);
				continue;
			}
			$binding       = $answers[ $this->text( $row['block_id'] ?? null, 191 ) ] ?? null;
			$expected_kind = match ( $row['attachment_type'] ?? null ) {
				'image' => 'image_upload', 'file' => 'file_upload', 'drawing' => 'drawing', default => null,
			};
			$response_id = is_array( $binding ) ? ( $binding['response_id'] ) : null;
			if ( ! is_string( $response_id ) || ! Public_Id::is_valid( $response_id ) || null === $expected_kind ) {
				throw new InvalidArgumentException( 'Legacy attachment response kind drifted.' );
			}
			if ( $expected_kind === ( $binding['kind'] ) ) {
				$response_public_ids[ $key ] = $response_id;
			}
			$hashes[ $key ] = $checksum;
			$manifest[]     = array(
				'source_checksum' => $checksum,
				'source_key'      => (string) $source_id,
			);
		}
		return array(
			'count'               => count( $rows ),
			'manifest_hash'       => hash( 'sha256', $this->canonical->encode( $manifest ) ),
			'source_hashes'       => $hashes,
			'response_public_ids' => $response_public_ids,
		);
	}

	/**
	 * Official projection.
	 *
	 * @param array<string,mixed> $root Root.
	 * @param array<string,mixed> $prepared Prepared.
	 * @param bool                $deferred Deferred.
	 * @param int                 $actor_user_id Authenticated actor from the access context.
	 * @param array<string,mixed> $dependency Dependency.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function official_projection( array $root, array $prepared, bool $deferred, int $actor_user_id, array $dependency ): array {
		$status  = $root['status'] ?? null;
		$allowed = array( 'draft', 'autosave_conflict', 'submitted', 'auto_submitted', 'proxy_submitted', 'submitted_with_pending_attachment_review', 'locked' );
		if ( ! is_string( $status ) || ! in_array( $status, $allowed, true ) ) {
			throw new InvalidArgumentException( 'Legacy submission state is invalid.' );
		}
		$candidate    = in_array( $status, array( 'submitted', 'auto_submitted', 'proxy_submitted', 'locked' ), true );
		$official     = $candidate && array() !== $prepared['rows'] && $prepared['complete'] && ! $deferred;
		$root_state   = $official ? $status : ( 'draft' === $status && ! $deferred ? 'draft' : 'conflict' );
		$submitted_at = $official ? ( $this->nullable_utc( $root['submitted_at'] ?? null ) ?? $this->utc_value( $root['updated_at'] ?? null ) ) : null;
		$closed_at    = $official && 'locked' === $status ? ( $this->nullable_utc( $root['locked_at'] ?? null ) ?? $submitted_at ) : null;
		$action       = match ( $status ) {
			'submitted' => 'submit',
			'auto_submitted' => 'auto_submit',
			'proxy_submitted' => 'proxy_submit',
			'locked' => 'lock',
			default => null,
		};
		$actor_type = 'submit' === $action ? 'learner' : ( 'lock' === $action ? 'teacher' : 'system' );
		return array(
			'root_state'         => $root_state,
			'attempt_no'         => $official ? 1 : 0,
			'revision_public_id' => null,
			'revision_hash'      => null,
			'source_revision_id' => null,
			'action'             => $official ? $action : null,
			'actor_type'         => $official ? $actor_type : null,
			'actor_user_id'      => ! $official || 'system' === $actor_type ? null : ( 'learner' === $actor_type ? $dependency['learner_user_id'] : $actor_user_id ),
			'reason'             => $official && 'lock' === $action ? 'Historical lock restored by legacy migration.' : null,
			'received_at_utc'    => $official ? ( $closed_at ?? $submitted_at ) : null,
			'submitted_at_utc'   => $submitted_at,
			'closed_at_utc'      => $closed_at,
		);
	}

	/**
	 * Bind official revision.
	 *
	 * @param array<string,mixed>      $official Official.
	 * @param array<string,mixed>|null $current Current.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function bind_official_revision( array $official, ?array $current ): array {
		if ( $official['attempt_no'] > 0 ) {
			if ( null === $current ) {
				throw new InvalidArgumentException( 'Official legacy submission has no revision.' );
			}
			$official['revision_public_id'] = $current['public_id'];
			$official['revision_hash']      = $current['content_hash'];
			$official['source_revision_id'] = $current['source_id'];
		}
		return $official;
	}

	/**
	 * Receipt row.
	 *
	 * @param int                 $submission_database_id Submission database id.
	 * @param string              $submission_public_id Submission public id.
	 * @param int                 $revision_database_id Revision database id.
	 * @param string              $revision_public_id Revision public id.
	 * @param string              $revision_hash Revision hash.
	 * @param int                 $actor_user_id Authenticated actor from the access context.
	 * @param array<string,mixed> $official Official.
	 * @param array<string,mixed> $dependency Dependency.
	 * @param string|null         $attachment_set_hash Attachment set hash.
	 * @return array{row:array<string,mixed>,public_id:string,content_hash:string}
	 */
	private function receipt_row(
		int $submission_database_id,
		string $submission_public_id,
		int $revision_database_id,
		string $revision_public_id,
		string $revision_hash,
		int $actor_user_id,
		array $official,
		array $dependency,
		?string $attachment_set_hash = null
	): array {
		unset( $actor_user_id );
		$receipt_public_id = $this->ids()->create()->value();
		$received_at       = (string) $official['received_at_utc'];
		$timeliness        = null === $dependency['due_at_utc'] || $received_at <= $dependency['due_at_utc'] ? 'on_time' : 'late';
		$idempotency_key   = 'legacy-migration:submission:' . $submission_public_id . ':' . $official['action'];
		$content           = array(
			'action'                      => $official['action'],
			'actor_type'                  => $official['actor_type'],
			'actor_user_id'               => $official['actor_user_id'],
			'assignment_schedule_hash'    => $dependency['schedule_hash'],
			'assignment_schedule_version' => $dependency['schedule_version'],
			'attachment_set_hash'         => $attachment_set_hash ?? hash( 'sha256', $this->canonical->encode( array() ) ),
			'context_revision'            => $dependency['context_revision'],
			'idempotency_key'             => $idempotency_key,
			'attempt_no'                  => 1,
			'publication_hash'            => $dependency['publication_hash'],
			'reason'                      => $official['reason'],
			'receipt_no'                  => 1,
			'received_at_utc'             => $received_at,
			'resubmission_policy_hash'    => $dependency['resubmission_policy_hash'],
			'submission_id'               => $submission_public_id,
			'submission_revision_hash'    => $revision_hash,
			'submission_revision_id'      => $revision_public_id,
			'timeliness'                  => $timeliness,
		);
		$content_hash      = hash( 'sha256', $this->canonical->encode( $content ) );
		return array(
			'public_id'    => $receipt_public_id,
			'content_hash' => $content_hash,
			'row'          => array(
				'public_id'                   => $receipt_public_id,
				'created_at_utc'              => $received_at,
				'content_hash'                => $content_hash,
				'submission_id'               => $submission_database_id,
				'submission_revision_id'      => $revision_database_id,
				'receipt_no'                  => 1,
				'action'                      => $official['action'],
				'idempotency_key'             => $idempotency_key,
				'context_revision'            => $dependency['context_revision'],
				'actor_type'                  => $official['actor_type'],
				'actor_user_id'               => $official['actor_user_id'],
				'reason'                      => $official['reason'],
				'attempt_no'                  => 1,
				'submission_revision_hash'    => $revision_hash,
				'publication_hash'            => $dependency['publication_hash'],
				'attachment_set_hash'         => $content['attachment_set_hash'],
				'assignment_schedule_version' => $dependency['schedule_version'],
				'assignment_schedule_hash'    => $dependency['schedule_hash'],
				'resubmission_policy_hash'    => $dependency['resubmission_policy_hash'],
				'timeliness'                  => $timeliness,
				'received_at_utc'             => $received_at,
			),
		);
	}

	/**
	 * Import receipt.
	 *
	 * @param int    $run_database_id Run database id.
	 * @param string $source_type Source type.
	 * @param int    $source_id Source id.
	 * @param string $target_type Target type.
	 * @return array{result:Legacy_Target_Write_Result,source_checksum:string}
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
		if (
			null === $row || 'success' !== ( $row['state'] ?? null ) || $target_type !== ( $row['target_type'] ?? null )
			|| self::ITEM_SCHEMA !== ( $row['payload_schema'] ?? null ) || self::PROFILE_VERSION !== $this->positive_integer( $row['payload_version'] ?? null )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::DEPENDENCY_MISSING );
		}
		$payload = $this->decode_stored( (string) ( $row['payload_json'] ?? '' ), (string) ( $row['payload_hash'] ?? '' ) );
		$source  = $payload['source'] ?? null;
		$target  = $payload['target'] ?? null;
		if (
			self::ITEM_PROFILE !== ( $payload['profile'] ?? null ) || 'success' !== ( $payload['state'] ?? null ) || null !== ( $payload['error_code'] ?? null )
			|| ! is_array( $source ) || ! is_array( $target )
			|| $source_type !== ( $source['type'] ?? null ) || (string) $source_id !== ( $source['key'] ?? null )
			|| $target_type !== ( $target['type'] ?? null ) || ! is_array( $target['metadata'] ?? null )
			|| ! hash_equals( (string) ( $target['public_id'] ?? '' ), (string) ( $row['target_public_id'] ?? '' ) )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		$result          = new Legacy_Target_Write_Result( $target_type, (string) $target['public_id'], (string) $target['content_hash'], $target['metadata'] );
		$source_checksum = $source['checksum'] ?? null;
		if ( ! is_string( $source_checksum ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $source_checksum ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		return array(
			'database_id'     => null === ( $row['id'] ?? null ) ? null : $this->positive_integer( $row['id'] ),
			'lock_version'    => null === ( $row['lock_version'] ?? null ) ? null : $this->non_negative_integer( $row['lock_version'] ),
			'result'          => $result,
			'source_checksum' => $source_checksum,
		);
	}

	/**
	 * Reject invalid postcondition.
	 *
	 * @param int         $database_id Database id.
	 * @param string      $public_id Public id.
	 * @param string|null $current_id Current id.
	 * @param string|null $official_id Official id.
	 * @param int         $revision_count Revision count.
	 * @param int         $receipt_count Receipt count.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_postcondition( int $database_id, string $public_id, ?string $current_id, ?string $official_id, int $revision_count, int $receipt_count ): void {
		$row = $this->row(
			$this->prepare(
				'SELECT s.public_id, s.current_revision_public_id, s.official_revision_public_id, (SELECT COUNT(*) FROM ' . self::TABLE_SUBMISSION_REVISIONS . ' r WHERE r.submission_id = s.id) AS revision_count, '
					. '(SELECT COUNT(*) FROM ' . self::TABLE_SUBMISSION_RECEIPTS . ' p WHERE p.submission_id = s.id) AS receipt_count FROM '
					. self::TABLE_SUBMISSIONS . ' s WHERE s.id = %d LIMIT 1 FOR UPDATE',
				array( $database_id )
			)
		);
		if (
			null === $row || ! hash_equals( $public_id, (string) ( $row['public_id'] ?? '' ) )
			|| $current_id !== ( $row['current_revision_public_id'] ?? null ) || $official_id !== ( $row['official_revision_public_id'] ?? null )
			|| $revision_count !== $this->non_negative_integer( $row['revision_count'] ?? null )
			|| $receipt_count !== $this->non_negative_integer( $row['receipt_count'] ?? null )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Reject invalid submission root.
	 *
	 * @param string $public_id Public id.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_submission_root( string $public_id ): void {
		new Public_Id( $public_id );
		$row = $this->row( $this->prepare( 'SELECT public_id FROM ' . self::TABLE_SUBMISSIONS . ' WHERE public_id = %s LIMIT 1 FOR UPDATE', array( $public_id ) ) );
		if ( null === $row || ! hash_equals( $public_id, (string) ( $row['public_id'] ?? '' ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Source row by id.
	 *
	 * @param string $source_table Source table.
	 * @param string $logical_table Logical table.
	 * @param int    $source_id Source id.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function source_row_by_id( string $source_table, string $logical_table, int $source_id ): array {
		$columns = $this->profile->columns( $logical_table );
		$select  = implode( ', ', array_map( static fn ( string $column ): string => '`' . $column . '`', $columns ) );
		$row     = $this->row( $this->prepare( 'SELECT ' . $select . ' FROM `' . $source_table . '` WHERE `id` = %d LIMIT 1 FOR UPDATE', array( $source_id ) ) );
		if ( null === $row ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::DEPENDENCY_MISSING );
		}
		$this->assert_exact_source_row( $logical_table, $row );
		return $row;
	}

	/**
	 * Reject invalid exact source row.
	 *
	 * @param string              $logical_table Logical table.
	 * @param array<string,mixed> $row Row.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_exact_source_row( string $logical_table, array $row ): void {
		if ( array_keys( $row ) !== $this->profile->columns( $logical_table ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::SOURCE_INVALID );
		}
	}

	/**
	 * Source checksum.
	 *
	 * @param array<string,mixed> $plan Plan.
	 * @param string              $source_type Source type.
	 * @param int                 $source_id Source id.
	 * @param array<string,mixed> $record Record.
	 * @return string
	 */
	private function source_checksum( array $plan, string $source_type, int $source_id, array $record ): string {
		return hash(
			'sha256',
			$this->canonical->encode(
				array(
					'profile'     => $plan['source']['profile'],
					'source_key'  => (string) $source_id,
					'source_type' => $source_type,
					'record'      => $record,
				)
			)
		);
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
	 * Decode source object.
	 *
	 * @param mixed $value Value.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function decode_source_object( mixed $value ): array {
		if ( ! is_string( $value ) || '' === trim( $value ) || strlen( $value ) > self::MAXIMUM_JSON_BYTES ) {
			throw new InvalidArgumentException( 'Legacy JSON object is invalid.' );
		}
		$decoded = json_decode( $value, true, 128, JSON_THROW_ON_ERROR );
		if ( ! is_array( $decoded ) || array_is_list( $decoded ) ) {
			throw new InvalidArgumentException( 'Legacy JSON object is invalid.' );
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
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Legacy object hash.
	 *
	 * @param array<string,mixed> $content Content.
	 * @return string
	 */
	private function legacy_object_hash( array $content ): string {
		$json = json_encode( $this->normalize_legacy_json( $content ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Domain JSON uses explicit flags and error handling; fallback re-encoding would change canonical bytes.
		return hash( 'sha256', $json );
	}

	/**
	 * Normalize legacy json.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function normalize_legacy_json( mixed $value ): mixed {
		if ( null === $value || is_bool( $value ) || is_int( $value ) || is_string( $value ) ) {
			return $value;
		}
		if ( is_float( $value ) ) {
			if ( ! is_finite( $value ) ) {
				throw new InvalidArgumentException( 'Legacy JSON number is invalid.' );
			}
			return floor( $value ) === $value ? (int) $value : $value;
		}
		if ( ! is_array( $value ) ) {
			throw new InvalidArgumentException( 'Legacy JSON value is invalid.' );
		}
		$result = array();
		foreach ( $value as $key => $item ) {
			$result[ $key ] = $this->normalize_legacy_json( $item );
		}
		if ( ! array_is_list( $result ) ) {
			ksort( $result, SORT_STRING );
		}
		return $result;
	}

	/**
	 * Text.
	 *
	 * @param mixed $value Value.
	 * @param int   $maximum Maximum.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function text( mixed $value, int $maximum ): string {
		if ( ! is_string( $value ) ) {
			throw new InvalidArgumentException( 'Legacy text is invalid.' );
		}
		$value = $this->canonical->text( trim( $value ) );
		if ( '' === $value || strlen( $value ) > $maximum ) {
			throw new InvalidArgumentException( 'Legacy text is invalid.' );
		}
		return $value;
	}

	/**
	 * Utc.
	 *
	 * @param string $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function utc( string $value ): void {
		if ( $value !== $this->utc_value( $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Nullable utc.
	 *
	 * @param mixed $value Value.
	 * @return string|null
	 */
	private function nullable_utc( mixed $value ): ?string {
		if ( null === $value || '' === $value ) {
			return null;
		}
		return $this->utc_value( $value );
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
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Insert id.
	 *
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function insert_id(): int {
		try {
			return $this->positive_integer( $this->database()->insert_id ?? null ); } catch ( InvalidArgumentException $error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE, $error );
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
}
