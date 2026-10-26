<?php
/**
 * WordPress legacy target writer.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\LegacyMigration\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Legacy_Migration_Failure;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Legacy_Target_Transformation_Failure;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Source_Item;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Target_Write_Result;
use OJAI\Dashboard\Operations\LegacyMigration\Port\Legacy_Migration_Assignment_Writer_Port_Interface;
use OJAI\Dashboard\Operations\LegacyMigration\Port\Legacy_Migration_Evaluation_Writer_Port_Interface;
use OJAI\Dashboard\Operations\LegacyMigration\Port\Legacy_Migration_Submission_Writer_Port_Interface;
use OJAI\Dashboard\Operations\LegacyMigration\Port\Legacy_Migration_Zip_Writer_Port_Interface;
use OJAI\Dashboard\Operations\LegacyMigration\Port\Legacy_Target_Batch_Lifecycle_Port_Interface;
use OJAI\Dashboard\Operations\LegacyMigration\Port\Legacy_Target_Writer_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Canonicalizer;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Profile;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Revision_Projector;
use OJAI\Dashboard\Worksheet\Plate_Document_Service;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes only explicitly supported legacy records into their modern owner aggregate.
 */
final class WordPress_Legacy_Target_Writer implements
	Legacy_Target_Writer_Port_Interface,
	Legacy_Target_Batch_Lifecycle_Port_Interface {
	private const TABLE_ITEMS                = 'oj_ai_import_items';
	private const TABLE_CURRICULUMS          = 'oj_ai_curriculums';
	private const TABLE_STANDARDS            = 'oj_ai_achievement_standards';
	private const TABLE_WORKSHEETS           = 'oj_ai_worksheets';
	private const TABLE_REVISIONS            = 'oj_ai_worksheet_revisions';
	private const TABLE_QUESTIONS            = 'oj_ai_worksheet_revision_questions';
	private const TABLE_WORKSHEET_LINKS      = 'oj_ai_worksheet_achievement_links';
	private const TABLE_QUESTION_LINKS       = 'oj_ai_question_achievement_links';
	private const ITEM_SCHEMA                = 'oj_ai.legacy_item_payload';
	private const ITEM_PROFILE               = 'oj_ai.legacy_item_payload@1.0.0';
	private const CURRICULUM_METADATA_SCHEMA = 'oj_ai.legacy_curriculum_metadata';
	private const STANDARD_METADATA_SCHEMA   = 'oj_ai.legacy_achievement_standard_metadata';
	private const SOURCE_KIND                = 'legacy_oj_ai_dashboard';
	private const SOURCE_VERSION             = Legacy_Source_Profile_0_1_2::VERSION;
	private const MAX_METADATA_BYTES         = 65536;
	private const EVALUATION_SOURCE_TYPES    = array(
		'assessment_plans',
		'assessment_runs',
		'grading_inputs',
		'rubric_snapshots',
		'grading_items',
		'assessment_results',
		'regrade_reviews',
		'feedback',
		'feedback_approvals',
		'special_notes',
	);

	/**
	 * Profile.
	 *
	 * @var Legacy_Source_Profile_0_1_2
	 */
	private Legacy_Source_Profile_0_1_2 $profile;
	/**
	 * Owners.
	 *
	 * @var Legacy_Migration_Owner_Matrix
	 */
	private Legacy_Migration_Owner_Matrix $owners;
	/**
	 * Worksheet converter.
	 *
	 * @var Legacy_Worksheet_Document_Converter|null
	 */
	private ?Legacy_Worksheet_Document_Converter $worksheet_converter;
	/**
	 * Worksheet projector.
	 *
	 * @var Worksheet_Revision_Projector|null
	 */
	private ?Worksheet_Revision_Projector $worksheet_projector = null;
	/**
	 * Batch active.
	 *
	 * @var bool
	 */
	private bool $batch_active = false;

	/**
	 * Initialize WordPress legacy target writer dependencies and state.
	 *
	 * @param Canonical_Json                                         $canonical Canonical JSON encoder used for stable digests.
	 * @param object|null                                            $database Database.
	 * @param Public_Id_Generator|null                               $ids Ids.
	 * @param Legacy_Source_Profile_0_1_2|null                       $profile Profile.
	 * @param Legacy_Worksheet_Document_Converter|null               $worksheet_converter Worksheet converter.
	 * @param Legacy_Migration_Assignment_Writer_Port_Interface|null $assignment_writer Assignment writer.
	 * @param Legacy_Migration_Submission_Writer_Port_Interface|null $submission_writer Submission writer.
	 * @param Legacy_Migration_Evaluation_Writer_Port_Interface|null $evaluation_writer Evaluation writer.
	 * @param Legacy_Migration_Zip_Writer_Port_Interface|null        $zip_writer Zip writer.
	 * @param Legacy_Migration_Owner_Matrix|null                     $owners Owners.
	 */
	public function __construct(
		private Canonical_Json $canonical,
		private ?object $database = null,
		private ?Public_Id_Generator $ids = null,
		?Legacy_Source_Profile_0_1_2 $profile = null,
		?Legacy_Worksheet_Document_Converter $worksheet_converter = null,
		private ?Legacy_Migration_Assignment_Writer_Port_Interface $assignment_writer = null,
		private ?Legacy_Migration_Submission_Writer_Port_Interface $submission_writer = null,
		private ?Legacy_Migration_Evaluation_Writer_Port_Interface $evaluation_writer = null,
		private ?Legacy_Migration_Zip_Writer_Port_Interface $zip_writer = null,
		?Legacy_Migration_Owner_Matrix $owners = null
	) {
		$this->profile             = $profile ?? new Legacy_Source_Profile_0_1_2();
		$this->owners              = $owners ?? new Legacy_Migration_Owner_Matrix( $this->profile );
		$this->worksheet_converter = $worksheet_converter;
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
		$started = array();
		try {
			foreach ( $this->lifecycle_writers() as $writer ) {
				$writer->begin_batch();
				$started[] = $writer;
			}
			$this->batch_active = true;
		} catch ( Throwable $error ) {
			foreach ( array_reverse( $started ) as $writer ) {
				try {
					$writer->rollback_batch(); } catch ( Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Preserve the documented recovery or optional-operation outcome; the catch body explains the fallback.
					// This optional step must not replace the surrounding operation outcome.
 // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- This optional step must not replace the surrounding operation outcome.
					}
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE, $error );
		}
	}

	/**
	 * Commit batch.
	 *
	 * @return void
	 */
	public function commit_batch(): void {
		if ( ! $this->batch_active ) {
			return;
		}
		$this->batch_active = false;
		foreach ( $this->lifecycle_writers() as $writer ) {
			$writer->commit_batch();
		}
	}

	/**
	 * Rollback batch.
	 *
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function rollback_batch(): void {
		if ( ! $this->batch_active ) {
			return;
		}
		$this->batch_active = false;
		$failed             = false;
		foreach ( array_reverse( $this->lifecycle_writers() ) as $writer ) {
			try {
				$writer->rollback_batch();
			} catch ( Throwable ) {
				$failed = true;
			}
		}
		if ( $failed ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Lifecycle writers.
	 *
	 * @return array<int,Legacy_Target_Batch_Lifecycle_Port_Interface>
	 */
	private function lifecycle_writers(): array {
		$result = array();
		$seen   = array();
		foreach ( array( $this->assignment_writer, $this->submission_writer, $this->evaluation_writer, $this->zip_writer ) as $writer ) {
			if ( ! $writer instanceof Legacy_Target_Batch_Lifecycle_Port_Interface ) {
				continue;
			}
			$key = spl_object_id( $writer );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$result[]     = $writer;
		}
		return $result;
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
		if ( $run_database_id < 1 || $actor_user_id < 1 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		$this->utc( $created_at_utc );

		try {
			$this->assert_source_item( $item );
			$handler = $this->owners->handler( $item->source_type() );
			if ( in_array( $item->source_type(), self::EVALUATION_SOURCE_TYPES, true ) ) {
				if ( Legacy_Migration_Owner_Matrix::HANDLER_EVALUATION !== $handler ) {
					throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::UNSUPPORTED );
				}
				if ( ! $this->evaluation_writer instanceof Legacy_Migration_Evaluation_Writer_Port_Interface ) {
					throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::UNSUPPORTED );
				}
				return $this->evaluation_writer->write( $run_database_id, $item, $actor_user_id, $created_at_utc );
			}
			if ( in_array( $item->source_type(), array( 'zip_uploads', 'zip_upload_items' ), true ) ) {
				if ( Legacy_Migration_Owner_Matrix::HANDLER_ZIP !== $handler ) {
					throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::UNSUPPORTED );
				}
				if ( ! $this->zip_writer instanceof Legacy_Migration_Zip_Writer_Port_Interface ) {
					throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::UNSUPPORTED );
				}
				return $this->zip_writer->write( $run_database_id, $item, $actor_user_id, $created_at_utc );
			}
			if ( 'worksheet_assignments' === $item->source_type() ) {
				if ( Legacy_Migration_Owner_Matrix::HANDLER_ASSIGNMENT !== $handler ) {
					throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::UNSUPPORTED );
				}
				if ( ! $this->assignment_writer instanceof Legacy_Migration_Assignment_Writer_Port_Interface ) {
					throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::UNSUPPORTED );
				}
				return $this->assignment_writer->write( $run_database_id, $item, $actor_user_id, $created_at_utc );
			}
			if ( 'worksheet_submissions' === $item->source_type() ) {
				if ( Legacy_Migration_Owner_Matrix::HANDLER_SUBMISSION !== $handler ) {
					throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::UNSUPPORTED );
				}
				if ( ! $this->submission_writer instanceof Legacy_Migration_Submission_Writer_Port_Interface ) {
					throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::UNSUPPORTED );
				}
				return $this->submission_writer->write( $run_database_id, $item, $actor_user_id, $created_at_utc );
			}
			if ( 'submission_revisions' === $item->source_type() ) {
				if ( Legacy_Migration_Owner_Matrix::HANDLER_SUBMISSION !== $handler ) {
					throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::UNSUPPORTED );
				}
				if ( ! $this->submission_writer instanceof Legacy_Migration_Submission_Writer_Port_Interface ) {
					throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::UNSUPPORTED );
				}
				return $this->submission_writer->verify_revision( $run_database_id, $item, $actor_user_id, $created_at_utc );
			}
			if ( 'submission_attachments' === $item->source_type() ) {
				if ( Legacy_Migration_Owner_Matrix::HANDLER_SUBMISSION !== $handler ) {
					throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::UNSUPPORTED );
				}
				if ( ! $this->submission_writer instanceof Legacy_Migration_Submission_Writer_Port_Interface ) {
					throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::UNSUPPORTED );
				}
				return $this->submission_writer->verify_attachment( $run_database_id, $item, $actor_user_id, $created_at_utc );
			}
			if ( Legacy_Migration_Owner_Matrix::HANDLER_DIRECT !== $handler ) {
				throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::UNSUPPORTED );
			}
			if ( 'worksheet_questions' === $item->source_type() ) {
				return $this->verify_worksheet_question( $run_database_id, $item );
			}
			if ( 'worksheet_achievement_links' === $item->source_type() ) {
				return $this->verify_worksheet_achievement_link( $run_database_id, $item );
			}
			if ( 'question_achievement_links' === $item->source_type() ) {
				return $this->verify_question_achievement_link( $run_database_id, $item );
			}
			$write = match ( $item->source_type() ) {
				'curriculums' => $this->transform_curriculum( $item, $actor_user_id, $created_at_utc ),
				'achievement_standards' => $this->transform_standard( $run_database_id, $item, $actor_user_id, $created_at_utc ),
				'worksheet_templates' => $this->transform_worksheet( $run_database_id, $item, $actor_user_id, $created_at_utc ),
				default => throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::UNSUPPORTED ),
			};
		} catch ( Legacy_Target_Transformation_Failure $failure ) {
			throw $failure;
		} catch ( Legacy_Migration_Failure $failure ) {
			throw $failure;
		} catch ( Throwable ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::SOURCE_INVALID );
		}

		if ( isset( $write['worksheet'], $write['revision'], $write['questions'] ) ) {
			return $this->write_worksheet_aggregate( $write );
		}

		$this->insert_or_fail( $write['table'], $write['data'] );
		return new Legacy_Target_Write_Result(
			$write['target_type'],
			$write['target_public_id'],
			$write['content_hash'],
			$write['receipt_metadata']
		);
	}

	/**
	 * Transform curriculum.
	 *
	 * @param Legacy_Source_Item $item Item.
	 * @param int                $actor_user_id Authenticated actor from the access context.
	 * @param string             $created_at_utc Created at utc.
	 * @return array{table:string,data:array<string,mixed>,target_type:string,target_public_id:string,content_hash:string,receipt_metadata:array<string,mixed>}
	 */
	private function transform_curriculum( Legacy_Source_Item $item, int $actor_user_id, string $created_at_utc ): array {
		$record         = $item->record();
		$title          = $this->text( $record['title'] ?? null, 'title', 255, false );
		$legacy_key     = $this->text( $record['curriculum_key'] ?? null, 'curriculum_key', 191, false );
		$legacy_version = $this->optional_text( $record['version'] ?? null, 'version', 64 ) ?? self::SOURCE_VERSION;
		$description    = $this->optional_text( $record['description'] ?? null, 'description', 20000 );
		$tenant_context = $this->text( $record['tenant_context'] ?? null, 'tenant_context', 191, false );
		$active         = $this->boolean_flag( $record['active'] ?? null );
		$public_id      = $this->ids()->create()->value();
		$metadata       = array(
			'description'           => $description,
			'legacy_curriculum_key' => $legacy_key,
			'legacy_tenant_context' => $tenant_context,
		);
		$metadata_json  = $this->metadata_json( $metadata );
		$metadata_hash  = hash( 'sha256', $metadata_json );
		$source_key     = 'curriculum:' . $item->source_key();
		$state          = $active ? 'active' : 'archived';
		$content_hash   = $this->content_hash(
			array(
				'locale'         => 'ko-KR',
				'metadata_hash'  => $metadata_hash,
				'source_key'     => $source_key,
				'source_kind'    => self::SOURCE_KIND,
				'source_version' => $legacy_version,
				'state'          => $state,
				'title'          => $title,
			)
		);

		return array(
			'table'            => self::TABLE_CURRICULUMS,
			'data'             => array(
				'public_id'          => $public_id,
				'lock_version'       => 0,
				'created_by_user_id' => $actor_user_id,
				'created_at_utc'     => $created_at_utc,
				'updated_by_user_id' => $actor_user_id,
				'updated_at_utc'     => $created_at_utc,
				'source_kind'        => self::SOURCE_KIND,
				'source_key'         => $source_key,
				'source_version'     => $legacy_version,
				'title'              => $title,
				'locale'             => 'ko-KR',
				'state'              => $state,
				'metadata_schema'    => self::CURRICULUM_METADATA_SCHEMA,
				'metadata_version'   => 1,
				'metadata_json'      => $metadata_json,
				'metadata_hash'      => $metadata_hash,
				'content_hash'       => $content_hash,
			),
			'target_type'      => 'curriculum',
			'target_public_id' => $public_id,
			'content_hash'     => $content_hash,
			'receipt_metadata' => array(),
		);
	}

	/**
	 * Transform standard.
	 *
	 * @param int                $run_database_id Run database id.
	 * @param Legacy_Source_Item $item Item.
	 * @param int                $actor_user_id Authenticated actor from the access context.
	 * @param string             $created_at_utc Created at utc.
	 * @return array{table:string,data:array<string,mixed>,target_type:string,target_public_id:string,content_hash:string,receipt_metadata:array<string,mixed>}
	 */
	private function transform_standard( int $run_database_id, Legacy_Source_Item $item, int $actor_user_id, string $created_at_utc ): array {
		$record               = $item->record();
		$legacy_curriculum_id = $this->positive_integer( $record['curriculum_id'] ?? null );
		$curriculum           = $this->curriculum_dependency( $run_database_id, $legacy_curriculum_id );
		$stable_key           = $this->text( $record['standard_key'] ?? null, 'standard_key', 191, false );
		$code                 = $this->text( $record['standard_key'] ?? null, 'standard_key', 100, false );
		$title                = $this->text( $record['title'] ?? null, 'title', 255, false );
		$description          = $this->optional_text( $record['description'] ?? null, 'description', 20000 ) ?? '';
		$legacy_metadata      = $this->decode_metadata( $record['metadata_json'] ?? null );
		$metadata             = array(
			'legacy_active'         => $this->boolean_flag( $record['active'] ?? null ),
			'legacy_metadata'       => $legacy_metadata,
			'legacy_tenant_context' => $this->text( $record['tenant_context'] ?? null, 'tenant_context', 191, false ),
		);
		$metadata_json        = $this->metadata_json( $metadata );
		$metadata_hash        = hash( 'sha256', $metadata_json );
		$public_id            = $this->ids()->create()->value();
		$content_hash         = $this->content_hash(
			array(
				'code'                 => $code,
				'curriculum_public_id' => $curriculum['public_id'],
				'description'          => $description,
				'grade_label'          => null,
				'metadata_hash'        => $metadata_hash,
				'stable_key'           => $stable_key,
				'title'                => $title,
			)
		);

		return array(
			'table'            => self::TABLE_STANDARDS,
			'data'             => array(
				'public_id'          => $public_id,
				'lock_version'       => 0,
				'created_by_user_id' => $actor_user_id,
				'created_at_utc'     => $created_at_utc,
				'updated_by_user_id' => $actor_user_id,
				'updated_at_utc'     => $created_at_utc,
				'curriculum_id'      => $curriculum['database_id'],
				'stable_key'         => $stable_key,
				'code'               => $code,
				'title'              => $title,
				'description'        => $description,
				'grade_label'        => null,
				'metadata_schema'    => self::STANDARD_METADATA_SCHEMA,
				'metadata_version'   => 1,
				'metadata_json'      => $metadata_json,
				'metadata_hash'      => $metadata_hash,
				'content_hash'       => $content_hash,
			),
			'target_type'      => 'achievement_standard',
			'target_public_id' => $public_id,
			'content_hash'     => $content_hash,
			'receipt_metadata' => array( 'curriculum_public_id' => $curriculum['public_id'] ),
		);
	}

	/**
	 * Transform worksheet.
	 *
	 * @param int                $run_database_id Run database id.
	 * @param Legacy_Source_Item $item Item.
	 * @param int                $actor_user_id Authenticated actor from the access context.
	 * @param string             $created_at_utc Created at utc.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function transform_worksheet( int $run_database_id, Legacy_Source_Item $item, int $actor_user_id, string $created_at_utc ): array {
		$record = $item->record();
		$status = $record['status'] ?? null;
		if ( ! is_string( $status ) || ! in_array( $status, array( 'draft', 'active', 'archived' ), true ) ) {
			throw new InvalidArgumentException( 'Legacy worksheet status is invalid.' );
		}
		$deleted_at = $record['deleted_at'] ?? null;
		if ( null !== $deleted_at ) {
			$this->legacy_datetime( $deleted_at );
		}

		$converted             = $this->worksheet_converter()->convert( $record );
		$document              = $converted['document'];
		$legacy_map            = $converted['legacy_question_response_ids'];
		$legacy_questions      = $converted['legacy_questions'];
		$link_input            = $this->worksheet_link_projection_input( $run_database_id, $converted );
		$projection            = $this->worksheet_projector()->project(
			$document,
			$link_input['requests'],
			$link_input['standard_snapshots']
		);
		$questions             = $projection->questions();
		$questions_by_response = array();
		foreach ( $questions as $question ) {
			$response_id = $question['question_key'] ?? null;
			if ( ! is_string( $response_id ) || isset( $questions_by_response[ $response_id ] ) ) {
				throw new InvalidArgumentException( 'Converted worksheet question projection is invalid.' );
			}
			$questions_by_response[ $response_id ] = $question;
		}
		if ( count( $legacy_map ) !== count( $questions_by_response ) || count( $legacy_map ) !== count( $legacy_questions ) || count( $legacy_map ) > 250 ) {
			throw new InvalidArgumentException( 'Converted worksheet question inventory is invalid.' );
		}

		$question_public_ids    = array();
		$question_source_hashes = array();
		foreach ( $legacy_map as $legacy_question_id => $response_id ) {
			if ( ! isset( $questions_by_response[ $response_id ] ) || ! is_array( $legacy_questions[ $legacy_question_id ] ?? null ) ) {
				throw new InvalidArgumentException( 'Converted worksheet question mapping is invalid.' );
			}
			$source_hash = hash( 'sha256', $legacy_question_id );
			$public_id   = $questions_by_response[ $response_id ]['public_id'] ?? null;
			if ( isset( $question_public_ids[ $source_hash ] ) || isset( $question_source_hashes[ $source_hash ] ) || ! is_string( $public_id ) || ! Public_Id::is_valid( $public_id ) ) {
				throw new InvalidArgumentException( 'Converted worksheet question receipt is invalid.' );
			}
			$question_public_ids[ $source_hash ]    = $public_id;
			$question_source_hashes[ $source_hash ] = $this->legacy_object_hash( $legacy_questions[ $legacy_question_id ] );
		}

		$worksheet_links = $projection->worksheet_achievement_links();
		$question_links  = $projection->question_achievement_links();
		if (
			count( $worksheet_links ) !== count( $link_input['worksheet_source_hashes'] )
			|| count( $question_links ) !== count( $link_input['question_source_hashes'] )
		) {
			throw new InvalidArgumentException( 'Converted worksheet achievement link inventory is invalid.' );
		}
		$worksheet_link_hashes = array();
		foreach ( $worksheet_links as $index => $link ) {
			$source_hash  = $link_input['worksheet_source_hashes'][ $index ];
			$content_hash = $link['content_hash'] ?? null;
			if ( isset( $worksheet_link_hashes[ $source_hash ] ) || ! is_string( $content_hash ) ) {
				throw new InvalidArgumentException( 'Converted worksheet achievement link receipt is invalid.' );
			}
			$worksheet_link_hashes[ $source_hash ] = $content_hash;
		}
		$question_link_hashes = array();
		foreach ( $question_links as $index => $link ) {
			$source_hash  = $link_input['question_source_hashes'][ $index ];
			$content_hash = $link['content_hash'] ?? null;
			if ( isset( $question_link_hashes[ $source_hash ] ) || ! is_string( $content_hash ) ) {
				throw new InvalidArgumentException( 'Converted question achievement link receipt is invalid.' );
			}
			$question_link_hashes[ $source_hash ] = $content_hash;
		}

		$worksheet_public_id   = $this->ids()->create()->value();
		$revision_public_id    = $this->ids()->create()->value();
		$archived              = 'archived' === $status || null !== $deleted_at;
		$document_hash         = $document->document_hash();
		$revision_content_hash = $this->revision_content_hash( $document_hash, $worksheet_links, $question_links );

		return array(
			'worksheet'        => array(
				'public_id'                    => $worksheet_public_id,
				'lock_version'                 => 0,
				'created_by_user_id'           => $actor_user_id,
				'created_at_utc'               => $created_at_utc,
				'updated_by_user_id'           => $actor_user_id,
				'updated_at_utc'               => $created_at_utc,
				'title'                        => $converted['title'],
				'owner_user_id'                => $actor_user_id,
				'state'                        => $archived ? 'archived' : 'draft',
				'current_revision_public_id'   => $revision_public_id,
				'latest_publication_public_id' => null,
				'archived_at_utc'              => $archived ? $created_at_utc : null,
			),
			'revision'         => array(
				'public_id'                 => $revision_public_id,
				'content_hash'              => $revision_content_hash,
				'created_by_user_id'        => $actor_user_id,
				'created_at_utc'            => $created_at_utc,
				'revision_no'               => 1,
				'state'                     => 'draft',
				'source_revision_id'        => null,
				'editor_profile_version'    => Worksheet_Document_Profile::EDITOR_PROFILE,
				'document_schema_version'   => Worksheet_Document_Profile::SCHEMA_VERSION,
				'teacher_document_schema'   => Worksheet_Document_Profile::SCHEMA_ID,
				'teacher_document_version'  => 1,
				'teacher_document_json'     => $document->document_json(),
				'teacher_document_hash'     => $document_hash,
				'document_hash'             => $document_hash,
				'validation_schema_version' => Worksheet_Document_Profile::VALIDATION_VERSION,
				'validation_hash'           => $document->validation_hash(),
				'approved_by_user_id'       => null,
				'approved_at_utc'           => null,
			),
			'questions'        => $questions,
			'worksheet_links'  => $worksheet_links,
			'question_links'   => $question_links,
			'actor_user_id'    => $actor_user_id,
			'created_at_utc'   => $created_at_utc,
			'target_type'      => 'worksheet',
			'target_public_id' => $worksheet_public_id,
			'content_hash'     => $revision_content_hash,
			'receipt_metadata' => array(
				'document_hash'          => $document_hash,
				'revision_content_hash'  => $revision_content_hash,
				'question_count'         => count( $questions ),
				'question_public_ids'    => $question_public_ids,
				'question_source_hashes' => $question_source_hashes,
				'worksheet_link_count'   => count( $worksheet_links ),
				'worksheet_link_hashes'  => $worksheet_link_hashes,
				'question_link_count'    => count( $question_links ),
				'question_link_hashes'   => $question_link_hashes,
				'revision_public_id'     => $revision_public_id,
			),
		);
	}

	/**
	 * Worksheet link projection input.
	 *
	 * @param int                 $run_database_id Run database id.
	 * @param array<string,mixed> $converted Converted.
	 * @return array{requests:array<int,array<string,mixed>>,standard_snapshots:array<int,array<string,mixed>>,worksheet_source_hashes:array<int,string>,question_source_hashes:array<int,string>}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function worksheet_link_projection_input( int $run_database_id, array $converted ): array {
		$worksheet_links  = $converted['legacy_worksheet_links'] ?? null;
		$legacy_map       = $converted['legacy_question_response_ids'] ?? null;
		$legacy_questions = $converted['legacy_questions'] ?? null;
		if ( ! is_array( $worksheet_links ) || ! is_array( $legacy_map ) || array_is_list( $legacy_map ) || ! is_array( $legacy_questions ) || array_is_list( $legacy_questions ) ) {
			throw new InvalidArgumentException( 'Converted worksheet achievement link source is invalid.' );
		}

		$standards = array();
		$snapshots = array();
		$resolve   = function ( int $legacy_standard_id ) use ( $run_database_id, &$standards, &$snapshots ): array {
			if ( ! isset( $standards[ $legacy_standard_id ] ) ) {
				$standards[ $legacy_standard_id ] = $this->standard_dependency( $run_database_id, $legacy_standard_id );
			}
			$standard  = $standards[ $legacy_standard_id ];
			$public_id = $standard['public_id'];
			if ( ! isset( $snapshots[ $public_id ] ) ) {
				$snapshots[ $public_id ] = array(
					'standard_public_id'      => $public_id,
					'standard_source_version' => $standard['source_version'],
					'standard_code_snapshot'  => $standard['code'],
					'standard_label_snapshot' => $standard['title'],
				);
			}
			return $standard;
		};

		$requests                = array();
		$worksheet_source_hashes = array();
		foreach ( $this->legacy_standard_ids( $worksheet_links ) as $legacy_standard_id ) {
			$standard                  = $resolve( $legacy_standard_id );
			$requests[]                = array(
				'scope'              => 'worksheet',
				'response_id'        => null,
				'standard_public_id' => $standard['public_id'],
				'alignment_type'     => 'primary',
			);
			$worksheet_source_hashes[] = hash( 'sha256', (string) $legacy_standard_id );
		}

		$question_source_hashes = array();
		foreach ( $legacy_map as $legacy_question_id => $response_id ) {
			$legacy_question = $legacy_questions[ $legacy_question_id ] ?? null;
			if ( ! is_string( $legacy_question_id ) || ! is_string( $response_id ) || ! is_array( $legacy_question ) || array_is_list( $legacy_question ) ) {
				throw new InvalidArgumentException( 'Converted question achievement link source is invalid.' );
			}
			foreach ( $this->legacy_standard_ids( $legacy_question['curriculum_links'] ?? array() ) as $legacy_standard_id ) {
				$standard                 = $resolve( $legacy_standard_id );
				$requests[]               = array(
					'scope'              => 'response',
					'response_id'        => $response_id,
					'standard_public_id' => $standard['public_id'],
					'alignment_type'     => 'primary',
				);
				$question_source_hashes[] = hash( 'sha256', $legacy_question_id . '|' . $legacy_standard_id );
			}
		}

		if ( count( $worksheet_source_hashes ) > 250 || count( $question_source_hashes ) > 250 ) {
			throw new InvalidArgumentException( 'Converted worksheet achievement link inventory is too large.' );
		}
		return array(
			'requests'                => $requests,
			'standard_snapshots'      => array_values( $snapshots ),
			'worksheet_source_hashes' => $worksheet_source_hashes,
			'question_source_hashes'  => $question_source_hashes,
		);
	}

	/**
	 * Legacy standard ids.
	 *
	 * @param mixed $links Links.
	 * @return array<int,int>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function legacy_standard_ids( mixed $links ): array {
		if ( ! is_array( $links ) ) {
			throw new InvalidArgumentException( 'Legacy achievement links are invalid.' );
		}
		$result = array();
		$seen   = array();
		foreach ( $links as $key => $link ) {
			$candidate = $link;
			if ( is_array( $link ) ) {
				if ( array_key_exists( 'standard_id', $link ) ) {
					$candidate = $link['standard_id'];
				} elseif ( array_key_exists( 'id', $link ) ) {
					$candidate = $link['id'];
				} elseif ( is_int( $key ) && array_key_exists( 0, $link ) ) {
					$candidate = $link[0];
				} else {
					throw new InvalidArgumentException( 'Legacy achievement link entry is invalid.' );
				}
			}
			$standard_id = $this->positive_integer( $candidate );
			if ( ! isset( $seen[ $standard_id ] ) ) {
				$seen[ $standard_id ] = true;
				$result[]             = $standard_id;
			}
		}
		return $result;
	}

	/**
	 * Revision content hash.
	 *
	 * @param string                         $document_hash Document hash.
	 * @param array<int,array<string,mixed>> $worksheet_links Worksheet links.
	 * @param array<int,array<string,mixed>> $question_links Question links.
	 * @return string
	 */
	private function revision_content_hash( string $document_hash, array $worksheet_links, array $question_links ): string {
		if ( array() === $worksheet_links && array() === $question_links ) {
			return $document_hash;
		}
		return $this->content_hash(
			array(
				'document_hash'                     => $document_hash,
				'worksheet_achievement_link_hashes' => array_column( $worksheet_links, 'content_hash' ),
				'question_achievement_link_hashes'  => array_column( $question_links, 'content_hash' ),
			)
		);
	}

	/**
	 * Write worksheet aggregate.
	 *
	 * @param array<string,mixed> $write Write.
	 * @return Legacy_Target_Write_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function write_worksheet_aggregate( array $write ): Legacy_Target_Write_Result {
		$this->insert_or_fail( self::TABLE_WORKSHEETS, $write['worksheet'] );
		$worksheet_database_id    = $this->last_insert_id();
		$revision                 = $write['revision'];
		$revision['worksheet_id'] = $worksheet_database_id;
		$this->insert_or_fail( self::TABLE_REVISIONS, $revision );
		$revision_database_id  = $this->last_insert_id();
		$question_database_ids = array();
		foreach ( $write['questions'] as $question ) {
			$this->insert_or_fail(
				self::TABLE_QUESTIONS,
				array(
					'public_id'                => $question['public_id'],
					'content_hash'             => $question['content_hash'],
					'created_by_user_id'       => $write['actor_user_id'],
					'created_at_utc'           => $write['created_at_utc'],
					'worksheet_revision_id'    => $revision_database_id,
					'question_key'             => $question['question_key'],
					'position'                 => $question['position'],
					'response_type'            => $question['response_type'],
					'question_schema'          => $question['question_schema'],
					'question_version'         => $question['question_version'],
					'question_json'            => $question['question_json'],
					'question_hash'            => $question['question_hash'],
					'private_metadata_schema'  => $question['private_metadata_schema'],
					'private_metadata_version' => $question['private_metadata_version'],
					'private_metadata_json'    => $question['private_metadata_json'],
					'private_metadata_hash'    => $question['private_metadata_hash'],
				)
			);
			$question_database_ids[ $question['public_id'] ] = $this->last_insert_id();
		}
		foreach ( $write['worksheet_links'] as $link ) {
			$this->insert_or_fail(
				self::TABLE_WORKSHEET_LINKS,
				array(
					'content_hash'            => $link['content_hash'],
					'created_by_user_id'      => $write['actor_user_id'],
					'created_at_utc'          => $write['created_at_utc'],
					'worksheet_revision_id'   => $revision_database_id,
					'standard_public_id'      => $link['standard_public_id'],
					'standard_source_version' => $link['standard_source_version'],
					'standard_code_snapshot'  => $link['standard_code_snapshot'],
					'standard_label_snapshot' => $link['standard_label_snapshot'],
					'alignment_type'          => $link['alignment_type'],
					'position'                => $link['position'],
				)
			);
		}
		foreach ( $write['question_links'] as $link ) {
			$question_database_id = $question_database_ids[ $link['question_public_id'] ] ?? null;
			if ( ! is_int( $question_database_id ) || $question_database_id < 1 ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
			}
			$this->insert_or_fail(
				self::TABLE_QUESTION_LINKS,
				array(
					'content_hash'            => $link['content_hash'],
					'created_by_user_id'      => $write['actor_user_id'],
					'created_at_utc'          => $write['created_at_utc'],
					'question_id'             => $question_database_id,
					'standard_public_id'      => $link['standard_public_id'],
					'standard_source_version' => $link['standard_source_version'],
					'standard_code_snapshot'  => $link['standard_code_snapshot'],
					'standard_label_snapshot' => $link['standard_label_snapshot'],
					'alignment_type'          => $link['alignment_type'],
					'position'                => $link['position'],
				)
			);
		}
		return new Legacy_Target_Write_Result( $write['target_type'], $write['target_public_id'], $write['content_hash'], $write['receipt_metadata'] );
	}

	/**
	 * Verify worksheet question.
	 *
	 * @param int                $run_database_id Run database id.
	 * @param Legacy_Source_Item $item Item.
	 * @return Legacy_Target_Write_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function verify_worksheet_question( int $run_database_id, Legacy_Source_Item $item ): Legacy_Target_Write_Result {
		$record               = $item->record();
		$template_source_id   = $this->positive_integer( $record['template_id'] ?? null );
		$legacy_question_id   = $this->text( $record['question_id'] ?? null, 'question_id', 191, false );
		$question_source_hash = $this->assert_legacy_question_record( $record, $legacy_question_id );
		$worksheet            = $this->worksheet_dependency( $run_database_id, $template_source_id );
		$source_hash          = hash( 'sha256', $legacy_question_id );
		$question_public_id   = $worksheet['question_public_ids'][ $source_hash ] ?? null;
		$expected_source_hash = $worksheet['question_source_hashes'][ $source_hash ] ?? null;
		if (
			! is_string( $question_public_id ) || ! Public_Id::is_valid( $question_public_id )
			|| ! is_string( $expected_source_hash ) || ! hash_equals( $expected_source_hash, $question_source_hash )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::SOURCE_INVALID );
		}

		$sql      = 'SELECT public_id, content_hash, worksheet_revision_id, question_key FROM '
			. self::TABLE_QUESTIONS . ' WHERE public_id = %s LIMIT 1';
		$question = $this->row( $this->prepare( $sql, array( $question_public_id ) ) );
		if (
			null === $question
			|| ! hash_equals( $question_public_id, (string) ( $question['public_id'] ?? '' ) )
			|| $worksheet['revision_database_id'] !== $this->stored_positive_integer( $question['worksheet_revision_id'] ?? null )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', (string) ( $question['content_hash'] ?? '' ) )
			|| ! Public_Id::is_valid( (string) ( $question['question_key'] ?? '' ) )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}

		return new Legacy_Target_Write_Result(
			'worksheet_question',
			$question_public_id,
			(string) $question['content_hash'],
			array(
				'revision_public_id'  => $worksheet['revision_public_id'],
				'template_source_key' => (string) $template_source_id,
				'worksheet_public_id' => $worksheet['public_id'],
			)
		);
	}

	/**
	 * Verify worksheet achievement link.
	 *
	 * @param int                $run_database_id Run database id.
	 * @param Legacy_Source_Item $item Item.
	 * @return Legacy_Target_Write_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function verify_worksheet_achievement_link( int $run_database_id, Legacy_Source_Item $item ): Legacy_Target_Write_Result {
		$record = $item->record();
		if ( null !== ( $record['deleted_at'] ?? null ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::SOURCE_INVALID );
		}
		$template_source_id = $this->positive_integer( $record['template_id'] ?? null );
		$legacy_standard_id = $this->positive_integer( $record['standard_id'] ?? null );
		$worksheet          = $this->worksheet_dependency( $run_database_id, $template_source_id );
		$standard           = $this->standard_dependency( $run_database_id, $legacy_standard_id );
		$expected_hash      = $worksheet['worksheet_link_hashes'][ hash( 'sha256', (string) $legacy_standard_id ) ] ?? null;
		if ( ! is_string( $expected_hash ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $expected_hash ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::SOURCE_INVALID );
		}

		$sql  = 'SELECT content_hash, worksheet_revision_id, standard_public_id, standard_source_version, standard_code_snapshot, standard_label_snapshot, alignment_type, position FROM '
			. self::TABLE_WORKSHEET_LINKS
			. ' WHERE worksheet_revision_id = %d AND standard_public_id = %s AND alignment_type = %s LIMIT 1';
		$link = $this->row(
			$this->prepare( $sql, array( $worksheet['revision_database_id'], $standard['public_id'], 'primary' ) )
		);
		if (
			null === $link
			|| $worksheet['revision_database_id'] !== $this->stored_positive_integer( $link['worksheet_revision_id'] ?? null )
			|| ! hash_equals( $standard['public_id'], (string) ( $link['standard_public_id'] ?? '' ) )
			|| ! hash_equals( $standard['source_version'], (string) ( $link['standard_source_version'] ?? '' ) )
			|| ! hash_equals( $standard['code'], (string) ( $link['standard_code_snapshot'] ?? '' ) )
			|| ! hash_equals( $standard['title'], (string) ( $link['standard_label_snapshot'] ?? '' ) )
			|| 'primary' !== ( $link['alignment_type'] ?? null )
			|| ! hash_equals( $expected_hash, $this->stored_link_content_hash( $link, null ) )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}

		return new Legacy_Target_Write_Result(
			'worksheet_achievement_link',
			$worksheet['public_id'],
			$expected_hash,
			array(
				'revision_public_id'  => $worksheet['revision_public_id'],
				'standard_public_id'  => $standard['public_id'],
				'worksheet_public_id' => $worksheet['public_id'],
			)
		);
	}

	/**
	 * Verify question achievement link.
	 *
	 * @param int                $run_database_id Run database id.
	 * @param Legacy_Source_Item $item Item.
	 * @return Legacy_Target_Write_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function verify_question_achievement_link( int $run_database_id, Legacy_Source_Item $item ): Legacy_Target_Write_Result {
		$record = $item->record();
		if ( null !== ( $record['deleted_at'] ?? null ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::SOURCE_INVALID );
		}
		$template_source_id = $this->positive_integer( $record['template_id'] ?? null );
		$legacy_question_id = $this->text( $record['question_id'] ?? null, 'question_id', 191, false );
		$legacy_standard_id = $this->positive_integer( $record['standard_id'] ?? null );
		$worksheet          = $this->worksheet_dependency( $run_database_id, $template_source_id );
		$standard           = $this->standard_dependency( $run_database_id, $legacy_standard_id );
		$question_public_id = $worksheet['question_public_ids'][ hash( 'sha256', $legacy_question_id ) ] ?? null;
		$expected_hash      = $worksheet['question_link_hashes'][ hash( 'sha256', $legacy_question_id . '|' . $legacy_standard_id ) ] ?? null;
		if (
			! is_string( $question_public_id ) || ! Public_Id::is_valid( $question_public_id )
			|| ! is_string( $expected_hash ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $expected_hash )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::SOURCE_INVALID );
		}
		$question = $this->worksheet_question_dependency( $worksheet['revision_database_id'], $question_public_id );

		$sql  = 'SELECT content_hash, question_id, standard_public_id, standard_source_version, standard_code_snapshot, standard_label_snapshot, alignment_type, position FROM ' . self::TABLE_QUESTION_LINKS
			. ' WHERE question_id = %d AND standard_public_id = %s AND alignment_type = %s LIMIT 1';
		$link = $this->row( $this->prepare( $sql, array( $question['database_id'], $standard['public_id'], 'primary' ) ) );
		if (
			null === $link
			|| $question['database_id'] !== $this->stored_positive_integer( $link['question_id'] ?? null )
			|| ! hash_equals( $standard['public_id'], (string) ( $link['standard_public_id'] ?? '' ) )
			|| ! hash_equals( $standard['source_version'], (string) ( $link['standard_source_version'] ?? '' ) )
			|| ! hash_equals( $standard['code'], (string) ( $link['standard_code_snapshot'] ?? '' ) )
			|| ! hash_equals( $standard['title'], (string) ( $link['standard_label_snapshot'] ?? '' ) )
			|| 'primary' !== ( $link['alignment_type'] ?? null )
			|| ! hash_equals( $expected_hash, $this->stored_link_content_hash( $link, $question['question_key'] ) )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}

		return new Legacy_Target_Write_Result(
			'question_achievement_link',
			$question_public_id,
			$expected_hash,
			array(
				'revision_public_id'  => $worksheet['revision_public_id'],
				'standard_public_id'  => $standard['public_id'],
				'worksheet_public_id' => $worksheet['public_id'],
			)
		);
	}

	/**
	 * Worksheet question dependency.
	 *
	 * @param int    $revision_database_id Revision database id.
	 * @param string $question_public_id Question public id.
	 * @return array{database_id:int,question_key:string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function worksheet_question_dependency( int $revision_database_id, string $question_public_id ): array {
		$sql      = 'SELECT id, public_id, worksheet_revision_id, question_key FROM ' . self::TABLE_QUESTIONS
			. ' WHERE public_id = %s LIMIT 1';
		$question = $this->row( $this->prepare( $sql, array( $question_public_id ) ) );
		if (
			null === $question
			|| ! hash_equals( $question_public_id, (string) ( $question['public_id'] ?? '' ) )
			|| $revision_database_id !== $this->stored_positive_integer( $question['worksheet_revision_id'] ?? null )
			|| ! Public_Id::is_valid( (string) ( $question['question_key'] ?? '' ) )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		return array(
			'database_id'  => $this->stored_positive_integer( $question['id'] ?? null ),
			'question_key' => (string) $question['question_key'],
		);
	}

	/**
	 * Stored link content hash.
	 *
	 * @param array<string,mixed> $row Row.
	 * @param string|null         $question_key Question key.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function stored_link_content_hash( array $row, ?string $question_key ): string {
		$common          = array(
			'standard_public_id'      => $this->stored_public_id( $row['standard_public_id'] ?? null ),
			'standard_source_version' => $this->stored_text( $row['standard_source_version'] ?? null, 'standard_source_version', 64 ),
			'standard_code_snapshot'  => $this->stored_text( $row['standard_code_snapshot'] ?? null, 'standard_code_snapshot', 100 ),
			'standard_label_snapshot' => $this->stored_text( $row['standard_label_snapshot'] ?? null, 'standard_label_snapshot', 255 ),
			'alignment_type'          => $this->stored_text( $row['alignment_type'] ?? null, 'alignment_type', 32 ),
		);
		$position        = $this->stored_nonnegative_integer( $row['position'] ?? null );
		$content         = null === $question_key
			? array_merge( array( 'scope' => 'worksheet' ), $common, array( 'position' => $position ) )
			: array_merge(
				array(
					'scope'        => 'response',
					'question_key' => $question_key,
				),
				$common,
				array( 'position' => $position )
			);
		$stored_hash     = $this->stored_hash( $row['content_hash'] ?? null );
		$calculated_hash = $this->content_hash( $content );
		if ( ! hash_equals( $stored_hash, $calculated_hash ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		return $stored_hash;
	}

	/**
	 * Worksheet dependency.
	 *
	 * @param int $run_database_id Run database id.
	 * @param int $template_source_id Template source id.
	 * @return array{public_id:string,revision_public_id:string,revision_database_id:int,question_public_ids:array<string,string>,question_source_hashes:array<string,string>,worksheet_link_hashes:array<string,string>,question_link_hashes:array<string,string>}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function worksheet_dependency( int $run_database_id, int $template_source_id ): array {
		$sql  = 'SELECT state, target_type, target_public_id, payload_schema, payload_version, payload_json, payload_hash FROM '
			. self::TABLE_ITEMS . ' WHERE import_run_id = %d AND source_type = %s AND source_key = %s LIMIT 1';
		$item = $this->row( $this->prepare( $sql, array( $run_database_id, 'worksheet_templates', (string) $template_source_id ) ) );
		if ( null === $item || 'success' !== ( $item['state'] ?? null ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::DEPENDENCY_MISSING );
		}
		if (
			'worksheet' !== ( $item['target_type'] ?? null )
			|| self::ITEM_SCHEMA !== ( $item['payload_schema'] ?? null )
			|| 1 !== $this->stored_positive_integer( $item['payload_version'] ?? null )
			|| ! Public_Id::is_valid( (string) ( $item['target_public_id'] ?? '' ) )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}

		$payload = $this->decode_stored( (string) ( $item['payload_json'] ?? '' ), (string) ( $item['payload_hash'] ?? '' ) );
		$target  = $payload['target'] ?? null;
		if (
			self::ITEM_PROFILE !== ( $payload['profile'] ?? null )
			|| 'success' !== ( $payload['state'] ?? null )
			|| 'worksheet_templates' !== ( $payload['source']['type'] ?? null )
			|| (string) $template_source_id !== ( $payload['source']['key'] ?? null )
			|| ! is_array( $target ) || array_is_list( $target )
			|| 'worksheet' !== ( $target['type'] ?? null )
			|| ! hash_equals( (string) $item['target_public_id'], (string) ( $target['public_id'] ?? '' ) )
			|| ! is_array( $target['metadata'] ?? null )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		try {
			$receipt = new Legacy_Target_Write_Result(
				'worksheet',
				(string) $target['public_id'],
				(string) ( $target['content_hash'] ?? '' ),
				$target['metadata']
			);
		} catch ( InvalidArgumentException $error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE, $error );
		}
		$metadata      = $receipt->metadata();
		$metadata_keys = array_keys( $metadata );
		sort( $metadata_keys, SORT_STRING );
		if (
			array( 'document_hash', 'question_count', 'question_link_count', 'question_link_hashes', 'question_public_ids', 'question_source_hashes', 'revision_content_hash', 'revision_public_id', 'worksheet_link_count', 'worksheet_link_hashes' ) !== $metadata_keys
			|| ! hash_equals( $receipt->content_hash(), (string) $metadata['revision_content_hash'] )
			|| $metadata['question_count'] !== count( $metadata['question_public_ids'] )
			|| $metadata['question_count'] !== count( $metadata['question_source_hashes'] )
			|| $metadata['worksheet_link_count'] !== count( $metadata['worksheet_link_hashes'] )
			|| $metadata['question_link_count'] !== count( $metadata['question_link_hashes'] )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}

		$public_id          = $receipt->target_public_id();
		$revision_public_id = (string) $metadata['revision_public_id'];
		$sql                = 'SELECT id, public_id, current_revision_public_id FROM ' . self::TABLE_WORKSHEETS . ' WHERE public_id = %s LIMIT 1';
		$root               = $this->row( $this->prepare( $sql, array( $public_id ) ) );
		if (
			null === $root || ! hash_equals( $public_id, (string) ( $root['public_id'] ?? '' ) )
			|| ! hash_equals( $revision_public_id, (string) ( $root['current_revision_public_id'] ?? '' ) )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		$worksheet_database_id = $this->stored_positive_integer( $root['id'] ?? null );
		$sql                   = 'SELECT id, public_id, content_hash, worksheet_id, document_hash FROM ' . self::TABLE_REVISIONS . ' WHERE public_id = %s LIMIT 1';
		$revision              = $this->row( $this->prepare( $sql, array( $revision_public_id ) ) );
		if (
			null === $revision || ! hash_equals( $revision_public_id, (string) ( $revision['public_id'] ?? '' ) )
			|| $worksheet_database_id !== $this->stored_positive_integer( $revision['worksheet_id'] ?? null )
			|| ! hash_equals( $receipt->content_hash(), (string) ( $revision['content_hash'] ?? '' ) )
			|| ! hash_equals( (string) $metadata['document_hash'], (string) ( $revision['document_hash'] ?? '' ) )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		return array(
			'public_id'              => $public_id,
			'revision_public_id'     => $revision_public_id,
			'revision_database_id'   => $this->stored_positive_integer( $revision['id'] ?? null ),
			'question_public_ids'    => $metadata['question_public_ids'],
			'question_source_hashes' => $metadata['question_source_hashes'],
			'worksheet_link_hashes'  => $metadata['worksheet_link_hashes'],
			'question_link_hashes'   => $metadata['question_link_hashes'],
		);
	}

	/**
	 * Reject invalid legacy question record.
	 *
	 * @param array<string,mixed> $record Record.
	 * @param string              $question_id Question id.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_legacy_question_record( array $record, string $question_id ): string {
		$schema = $this->decode_legacy_object( $record['question_schema_json'] ?? null );
		if (
			$question_id !== ( $schema['question_id'] ?? null )
			|| $this->text( $record['title'] ?? null, 'title', 191, true ) !== $this->text( $schema['title'] ?? null, 'title', 191, true )
			|| $this->nonnegative_integer( $record['sort_order'] ?? null ) !== $this->nonnegative_integer( $schema['sort_order'] ?? null )
			|| $this->decimal( $record['points'] ?? null ) !== $this->decimal( $schema['points'] ?? null )
			|| $this->boolean_flag( $record['grading_enabled'] ?? null ) !== $this->boolean_flag( $schema['grading_enabled'] ?? null )
		) {
			throw new InvalidArgumentException( 'Legacy worksheet question row is inconsistent.' );
		}
		return $this->legacy_object_hash( $schema );
	}

	/**
	 * Reject invalid source item.
	 *
	 * @param Legacy_Source_Item $item Item.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_source_item( Legacy_Source_Item $item ): void {
		$expected_keys = $this->profile->columns( $item->source_type() );
		$record        = $item->record();
		if ( array_keys( $record ) !== $expected_keys ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::SOURCE_INVALID );
		}
		$checksum = hash(
			'sha256',
			$this->canonical->encode(
				array(
					'profile'     => Legacy_Source_Profile_0_1_2::PROFILE,
					'source_key'  => $item->source_key(),
					'source_type' => $item->source_type(),
					'record'      => $record,
				)
			)
		);
		if ( ! hash_equals( $item->source_checksum(), $checksum ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::SOURCE_INVALID );
		}
	}

	/**
	 * Standard dependency.
	 *
	 * @param int $run_database_id Run database id.
	 * @param int $legacy_source_id Legacy source id.
	 * @return array{database_id:int,public_id:string,content_hash:string,source_version:string,code:string,title:string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function standard_dependency( int $run_database_id, int $legacy_source_id ): array {
		$sql  = 'SELECT state, target_type, target_public_id, payload_schema, payload_version, payload_json, payload_hash FROM '
			. self::TABLE_ITEMS . ' WHERE import_run_id = %d AND source_type = %s AND source_key = %s LIMIT 1';
		$item = $this->row( $this->prepare( $sql, array( $run_database_id, 'achievement_standards', (string) $legacy_source_id ) ) );
		if ( null === $item || 'success' !== ( $item['state'] ?? null ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::DEPENDENCY_MISSING );
		}
		if (
			'achievement_standard' !== ( $item['target_type'] ?? null )
			|| self::ITEM_SCHEMA !== ( $item['payload_schema'] ?? null )
			|| 1 !== $this->stored_positive_integer( $item['payload_version'] ?? null )
			|| ! Public_Id::is_valid( (string) ( $item['target_public_id'] ?? '' ) )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}

		$payload = $this->decode_stored( (string) ( $item['payload_json'] ?? '' ), (string) ( $item['payload_hash'] ?? '' ) );
		$target  = $payload['target'] ?? null;
		if (
			self::ITEM_PROFILE !== ( $payload['profile'] ?? null )
			|| 'success' !== ( $payload['state'] ?? null )
			|| 'achievement_standards' !== ( $payload['source']['type'] ?? null )
			|| (string) $legacy_source_id !== ( $payload['source']['key'] ?? null )
			|| ! is_array( $target ) || array_is_list( $target )
			|| 'achievement_standard' !== ( $target['type'] ?? null )
			|| ! hash_equals( (string) $item['target_public_id'], (string) ( $target['public_id'] ?? '' ) )
			|| ! is_array( $target['metadata'] ?? null )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		try {
			$receipt = new Legacy_Target_Write_Result(
				'achievement_standard',
				(string) $target['public_id'],
				(string) ( $target['content_hash'] ?? '' ),
				$target['metadata']
			);
		} catch ( InvalidArgumentException $error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE, $error );
		}
		$metadata = $receipt->metadata();
		if ( array( 'curriculum_public_id' ) !== array_keys( $metadata ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}

		$public_id  = $receipt->target_public_id();
		$sql        = 'SELECT standard.id, standard.public_id, standard.content_hash, standard.code, standard.title, curriculum.public_id AS curriculum_public_id, curriculum.source_version AS source_version FROM '
			. self::TABLE_STANDARDS . ' standard INNER JOIN ' . self::TABLE_CURRICULUMS
			. ' curriculum ON curriculum.id = standard.curriculum_id WHERE standard.public_id = %s LIMIT 1';
		$target_row = $this->row( $this->prepare( $sql, array( $public_id ) ) );
		if (
			null === $target_row
			|| ! hash_equals( $public_id, (string) ( $target_row['public_id'] ?? '' ) )
			|| ! hash_equals( $receipt->content_hash(), (string) ( $target_row['content_hash'] ?? '' ) )
			|| ! hash_equals( (string) $metadata['curriculum_public_id'], (string) ( $target_row['curriculum_public_id'] ?? '' ) )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}

		return array(
			'database_id'    => $this->stored_positive_integer( $target_row['id'] ?? null ),
			'public_id'      => $public_id,
			'content_hash'   => $receipt->content_hash(),
			'source_version' => $this->stored_text( $target_row['source_version'] ?? null, 'source_version', 64 ),
			'code'           => $this->stored_text( $target_row['code'] ?? null, 'code', 100 ),
			'title'          => $this->stored_text( $target_row['title'] ?? null, 'title', 255 ),
		);
	}

	/**
	 * Curriculum dependency.
	 *
	 * @param int $run_database_id Run database id.
	 * @param int $legacy_source_id Legacy source id.
	 * @return array{database_id:int,public_id:string,source_version:string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function curriculum_dependency( int $run_database_id, int $legacy_source_id ): array {
		$sql  = 'SELECT state, target_type, target_public_id, payload_schema, payload_version, payload_json, payload_hash FROM '
			. self::TABLE_ITEMS . ' WHERE import_run_id = %d AND source_type = %s AND source_key = %s LIMIT 1';
		$item = $this->row( $this->prepare( $sql, array( $run_database_id, 'curriculums', (string) $legacy_source_id ) ) );
		if ( null === $item || 'success' !== ( $item['state'] ?? null ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::DEPENDENCY_MISSING );
		}
		if (
			'curriculum' !== ( $item['target_type'] ?? null )
			|| self::ITEM_SCHEMA !== ( $item['payload_schema'] ?? null )
			|| 1 !== $this->stored_positive_integer( $item['payload_version'] ?? null )
			|| ! Public_Id::is_valid( (string) ( $item['target_public_id'] ?? '' ) )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		$payload = $this->decode_stored( (string) ( $item['payload_json'] ?? '' ), (string) ( $item['payload_hash'] ?? '' ) );
		if (
			self::ITEM_PROFILE !== ( $payload['profile'] ?? null )
			|| 'success' !== ( $payload['state'] ?? null )
			|| 'curriculums' !== ( $payload['source']['type'] ?? null )
			|| (string) $legacy_source_id !== ( $payload['source']['key'] ?? null )
			|| 'curriculum' !== ( $payload['target']['type'] ?? null )
			|| ! hash_equals( (string) $item['target_public_id'], (string) ( $payload['target']['public_id'] ?? '' ) )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}

		$public_id = (string) $item['target_public_id'];
		$sql       = 'SELECT id, public_id, source_version FROM ' . self::TABLE_CURRICULUMS . ' WHERE public_id = %s LIMIT 1';
		$target    = $this->row( $this->prepare( $sql, array( $public_id ) ) );
		if ( null === $target ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		return array(
			'database_id'    => $this->stored_positive_integer( $target['id'] ?? null ),
			'public_id'      => $public_id,
			'source_version' => $this->text( $target['source_version'] ?? null, 'source_version', 64, false ),
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
		try {
			$value = json_decode( $json, true, 64, JSON_THROW_ON_ERROR );
		} catch ( JsonException $error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE, $error );
		}
		if ( ! is_array( $value ) || array_is_list( $value ) || ! hash_equals( $json, $this->canonical->encode( $value ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		return $value;
	}

	/**
	 * Decode legacy object.
	 *
	 * @param mixed $value Value.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function decode_legacy_object( mixed $value ): array {
		if ( ! is_string( $value ) || '' === trim( $value ) || strlen( $value ) > self::MAX_METADATA_BYTES ) {
			throw new InvalidArgumentException( 'Legacy object JSON is invalid.' );
		}
		try {
			$decoded = json_decode( $value, true, 64, JSON_THROW_ON_ERROR );
		} catch ( JsonException $error ) {
			throw new InvalidArgumentException( 'Legacy object JSON is invalid.', 0, $error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The diagnostic text is literal; a chained Throwable is not HTML output.
		}
		if ( ! is_array( $decoded ) || array() === $decoded || array_is_list( $decoded ) ) {
			throw new InvalidArgumentException( 'Legacy object JSON is invalid.' );
		}
		return $decoded;
	}

	/**
	 * Decode metadata.
	 *
	 * @param mixed $value Value.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function decode_metadata( mixed $value ): array {
		if ( null === $value ) {
			return array();
		}
		if ( ! is_string( $value ) || strlen( $value ) > self::MAX_METADATA_BYTES ) {
			throw new InvalidArgumentException( 'Legacy metadata is invalid.' );
		}
		if ( '' === trim( $value ) ) {
			return array();
		}
		try {
			$decoded = json_decode( $value, true, 64, JSON_THROW_ON_ERROR );
		} catch ( JsonException $error ) {
			throw new InvalidArgumentException( 'Legacy metadata is invalid.', 0, $error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The diagnostic text is literal; a chained Throwable is not HTML output.
		}
		if ( ! is_array( $decoded ) || ( array() !== $decoded && array_is_list( $decoded ) ) ) {
			throw new InvalidArgumentException( 'Legacy metadata is invalid.' );
		}
		return $decoded;
	}

	/**
	 * Metadata json.
	 *
	 * @param array<string,mixed> $metadata Metadata.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function metadata_json( array $metadata ): string {
		$json = $this->canonical->encode( $metadata );
		if ( strlen( $json ) > self::MAX_METADATA_BYTES ) {
			throw new InvalidArgumentException( 'Legacy metadata is too large.' );
		}
		return $json;
	}

	/**
	 * Content hash.
	 *
	 * @param array<string,mixed> $content Content.
	 * @return string
	 */
	private function content_hash( array $content ): string {
		return hash( 'sha256', $this->canonical->encode( $content ) );
	}

	/**
	 * Legacy object hash.
	 *
	 * @param array<string,mixed> $content Content.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function legacy_object_hash( array $content ): string {
		try {
			$json = json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Domain JSON uses explicit flags and error handling; fallback re-encoding would change canonical bytes.
				$this->normalize_legacy_json( $content ),
				JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
			);
		} catch ( JsonException $error ) {
			throw new InvalidArgumentException( 'Legacy object JSON is invalid.', 0, $error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The diagnostic text is literal; a chained Throwable is not HTML output.
		}
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
				throw new InvalidArgumentException( 'Legacy object number is invalid.' );
			}
			return floor( $value ) === $value ? (int) $value : $value;
		}
		if ( ! is_array( $value ) ) {
			throw new InvalidArgumentException( 'Legacy object value is invalid.' );
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
	 * @param mixed  $value Value.
	 * @param string $field Field.
	 * @param int    $maximum Maximum.
	 * @param bool   $allow_empty Allow empty.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function text( mixed $value, string $field, int $maximum, bool $allow_empty ): string {
		if ( ! is_string( $value ) ) {
			throw new InvalidArgumentException( $field . ' is invalid.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- This internal exception is mapped to stable errors at presentation boundaries, not rendered directly.
		} // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception details stay in the application failure channel; presentation maps and escapes displayed errors.
		$value = $this->canonical->text( trim( str_replace( array( "\r\n", "\r" ), "\n", $value ) ) );
		if ( ( ! $allow_empty && '' === $value ) || $this->character_length( $value ) > $maximum || 1 === preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value ) ) {
			throw new InvalidArgumentException( $field . ' is invalid.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception details stay in the application failure channel; presentation maps and escapes displayed errors.
		}
		return $value;
	}

	/**
	 * Optional text.
	 *
	 * @param mixed  $value Value.
	 * @param string $field Field.
	 * @param int    $maximum Maximum.
	 * @return string|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function optional_text( mixed $value, string $field, int $maximum ): ?string {
		if ( null === $value ) {
			return null;
		}
		if ( ! is_string( $value ) ) {
			throw new InvalidArgumentException( $field . ' is invalid.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- This internal exception is mapped to stable errors at presentation boundaries, not rendered directly.
		} // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception details stay in the application failure channel; presentation maps and escapes displayed errors.
		if ( '' === trim( $value ) ) {
			return null;
		}
		return $this->text( $value, $field, $maximum, false );
	}

	/**
	 * Boolean flag.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function boolean_flag( mixed $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( 1 === $value || '1' === $value ) {
			return true;
		}
		if ( 0 === $value || '0' === $value ) {
			return false;
		}
		throw new InvalidArgumentException( 'Legacy boolean flag is invalid.' );
	}

	/**
	 * Positive integer.
	 *
	 * @param mixed $value Value.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function positive_integer( mixed $value ): int {
		if ( is_int( $value ) && $value > 0 ) {
			return $value;
		}
		if ( is_string( $value ) && 1 === preg_match( '/^[1-9][0-9]*$/D', $value ) ) {
			return (int) $value;
		}
		throw new InvalidArgumentException( 'Legacy positive integer is invalid.' );
	}

	/**
	 * Nonnegative integer.
	 *
	 * @param mixed $value Value.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function nonnegative_integer( mixed $value ): int {
		if ( is_int( $value ) && $value >= 0 ) {
			return $value;
		}
		if ( is_string( $value ) && 1 === preg_match( '/^(?:0|[1-9][0-9]*)$/D', $value ) ) {
			return (int) $value;
		}
		throw new InvalidArgumentException( 'Legacy nonnegative integer is invalid.' );
	}

	/**
	 * Decimal.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function decimal( mixed $value ): string {
		if ( is_int( $value ) ) {
			$value = (float) $value;
		}
		if ( is_string( $value ) ) {
			if ( 1 !== preg_match( '/^(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,2})?$/D', $value ) ) {
				throw new InvalidArgumentException( 'Legacy decimal is invalid.' );
			}
			$value = (float) $value;
		}
		if (
			! is_float( $value ) || ! is_finite( $value ) || $value < 0 || $value > 99999999.99
			|| abs( ( $value * 100 ) - round( $value * 100 ) ) > 0.000001
		) {
			throw new InvalidArgumentException( 'Legacy decimal is invalid.' );
		}
		return number_format( $value, 2, '.', '' );
	}


	/**
	 * Stored positive integer.
	 *
	 * @param mixed $value Value.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function stored_positive_integer( mixed $value ): int {
		try {
			return $this->positive_integer( $value );
		} catch ( InvalidArgumentException $error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE, $error );
		}
	}

	/**
	 * Stored nonnegative integer.
	 *
	 * @param mixed $value Value.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function stored_nonnegative_integer( mixed $value ): int {
		try {
			return $this->nonnegative_integer( $value );
		} catch ( InvalidArgumentException $error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE, $error );
		}
	}

	/**
	 * Stored text.
	 *
	 * @param mixed  $value Value.
	 * @param string $field Field.
	 * @param int    $maximum Maximum.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function stored_text( mixed $value, string $field, int $maximum ): string {
		try {
			return $this->text( $value, $field, $maximum, false );
		} catch ( InvalidArgumentException $error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE, $error );
		}
	}

	/**
	 * Stored public id.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function stored_public_id( mixed $value ): string {
		if ( ! is_string( $value ) || ! Public_Id::is_valid( $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		return $value;
	}

	/**
	 * Stored hash.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function stored_hash( mixed $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		return $value;
	}

	/**
	 * Character length.
	 *
	 * @param string $value Value.
	 * @return int
	 */
	private function character_length( string $value ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : strlen( $value );
	}

	/**
	 * Legacy datetime.
	 *
	 * @param mixed $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function legacy_datetime( mixed $value ): void {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value ) ) {
			throw new InvalidArgumentException( 'Legacy datetime is invalid.' );
		}
		$time = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new DateTimeZone( 'UTC' ) );
		if ( ! $time instanceof DateTimeImmutable || $time->format( 'Y-m-d H:i:s' ) !== $value ) {
			throw new InvalidArgumentException( 'Legacy datetime is invalid.' );
		}
	}

	/**
	 * Utc.
	 *
	 * @param string $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function utc( string $value ): void {
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		$time = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new DateTimeZone( 'UTC' ) );
		if ( ! $time instanceof DateTimeImmutable || $time->format( 'Y-m-d H:i:s' ) !== $value ) {
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
	 * Worksheet projector.
	 *
	 * @return Worksheet_Revision_Projector
	 */
	private function worksheet_projector(): Worksheet_Revision_Projector {
		return $this->worksheet_projector ??= new Worksheet_Revision_Projector( $this->canonical, $this->ids() );
	}

	/**
	 * Worksheet converter.
	 *
	 * @return Legacy_Worksheet_Document_Converter
	 */
	private function worksheet_converter(): Legacy_Worksheet_Document_Converter {
		$ids                                = $this->ids();
		return $this->worksheet_converter ??= new Legacy_Worksheet_Document_Converter(
			new Plate_Document_Service(),
			new Worksheet_Document_Canonicalizer( $this->canonical, $ids ),
			$this->worksheet_projector(),
			$ids
		);
	}

	/**
	 * Database.
	 *
	 * @return object
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function database(): object {
		$database = $this->database ?? ( $GLOBALS['wpdb'] ?? null );
		if ( ! is_object( $database ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		return $database;
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
		$database = $this->database();
		$this->reset_error( $database );
		$row = $database->get_row( $sql, defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A' );
		if ( '' !== trim( (string) ( $database->last_error ?? '' ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
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
	 * Insert or fail.
	 *
	 * @param string              $table Table.
	 * @param array<string,mixed> $data Data.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function insert_or_fail( string $table, array $data ): void {
		$database = $this->database();
		$this->reset_error( $database );
		if ( false === $database->insert( $table, $data ) || '' !== trim( (string) ( $database->last_error ?? '' ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Last insert id.
	 *
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function last_insert_id(): int {
		$database = $this->database();
		try {
			return $this->positive_integer( $database->insert_id ?? null );
		} catch ( InvalidArgumentException $error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE, $error );
		}
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
}
