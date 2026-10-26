<?php
/**
 * Literal-table migration publication writer used by the legacy assignment aggregate.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\LegacyMigration\Infrastructure;

use InvalidArgumentException;
use JsonException;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Legacy_Migration_Failure;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Legacy_Target_Transformation_Failure;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Migration_Publication;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Migration_Publication_Projector;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Target_Write_Result;
use OJAI\Dashboard\Operations\LegacyMigration\Port\Legacy_Migration_Publication_Writer_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Worksheet_Document;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Canonicalizer;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Profile;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Publication_Projection;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates one retired publication per migrated worksheet revision.
 * Transaction ownership stays with the D3-D4 assignment item writer.
 */
final class WordPress_Legacy_Migration_Publication_Writer implements Legacy_Migration_Publication_Writer_Port_Interface {
	private const TABLE_ITEMS        = 'oj_ai_import_items';
	private const TABLE_WORKSHEETS   = 'oj_ai_worksheets';
	private const TABLE_REVISIONS    = 'oj_ai_worksheet_revisions';
	private const TABLE_PUBLICATIONS = 'oj_ai_worksheet_publications';
	private const ITEM_SCHEMA        = 'oj_ai.legacy_item_payload';
	private const ITEM_PROFILE       = 'oj_ai.legacy_item_payload@1.0.0';
	private const PROFILE_VERSION    = 1;
	private const PUBLICATION_STATE  = 'retired';

	/**
	 * Documents.
	 *
	 * @var Worksheet_Document_Canonicalizer|null
	 */
	private ?Worksheet_Document_Canonicalizer $documents;
	/**
	 * Projector.
	 *
	 * @var Legacy_Migration_Publication_Projector|null
	 */
	private ?Legacy_Migration_Publication_Projector $projector;

	/**
	 * Initialize WordPress legacy migration publication writer dependencies and state.
	 *
	 * @param Canonical_Json                              $canonical Canonical JSON encoder used for stable digests.
	 * @param object|null                                 $database Database.
	 * @param Public_Id_Generator|null                    $ids Ids.
	 * @param Worksheet_Document_Canonicalizer|null       $documents Documents.
	 * @param Legacy_Migration_Publication_Projector|null $projector Projector.
	 */
	public function __construct(
		private Canonical_Json $canonical,
		private ?object $database = null,
		private ?Public_Id_Generator $ids = null,
		?Worksheet_Document_Canonicalizer $documents = null,
		?Legacy_Migration_Publication_Projector $projector = null
	) {
		$this->documents = $documents;
		$this->projector = $projector;
	}

	/**
	 * Ensure for assignment.
	 *
	 * @param int    $run_database_id Run database id.
	 * @param int    $template_source_id Template source id.
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $published_at_utc Published at utc.
	 * @return Legacy_Migration_Publication
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function ensure_for_assignment(
		int $run_database_id,
		int $template_source_id,
		int $actor_user_id,
		string $published_at_utc
	): Legacy_Migration_Publication {
		if ( $run_database_id < 1 || $template_source_id < 1 || $actor_user_id < 1 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::SOURCE_INVALID );
		}
		$this->utc( $published_at_utc );

		try {
			$dependency = $this->worksheet_dependency( $run_database_id, $template_source_id );
			$document   = $this->canonical_document( $dependency );
			$projection = $this->projector()->project( $document, $dependency['revision_public_id'] );
			$profiles   = $this->stored_profiles( $projection );
			$existing   = $this->publication_row( $dependency['revision_database_id'] );
			if ( null !== $existing ) {
				$this->assert_publication( $existing, $dependency, $projection, $profiles );
				return $this->result( $existing, $dependency, $projection, true );
			}

			$publication_public_id = $this->ids()->create()->value();
			$data                  = array(
				'public_id'                   => $publication_public_id,
				'content_hash'                => $projection->publication_hash(),
				'created_by_user_id'          => $actor_user_id,
				'created_at_utc'              => $published_at_utc,
				'worksheet_id'                => $dependency['worksheet_database_id'],
				'worksheet_revision_id'       => $dependency['revision_database_id'],
				'publication_no'              => $this->next_publication_no( $dependency['worksheet_database_id'] ),
				'state'                       => self::PUBLICATION_STATE,
				'projection_manifest_version' => $projection->manifest_version(),
				'publication_hash'            => $projection->publication_hash(),
				'published_at_utc'            => $published_at_utc,
				'retired_by_user_id'          => $actor_user_id,
				'retired_at_utc'              => $published_at_utc,
			);
			foreach ( $profiles as $name => $profile ) {
				$data[ $name . '_schema' ]  = $profile['schema'];
				$data[ $name . '_version' ] = $profile['version'];
				$data[ $name . '_json' ]    = $profile['json'];
				$data[ $name . '_hash' ]    = $profile['hash'];
			}
			$this->insert_or_fail( self::TABLE_PUBLICATIONS, $data );

			$stored = $this->publication_row( $dependency['revision_database_id'] );
			if ( null === $stored || ! hash_equals( $publication_public_id, (string) ( $stored['public_id'] ?? '' ) ) ) {
				throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
			}
			$this->assert_publication( $stored, $dependency, $projection, $profiles );
			return $this->result( $stored, $dependency, $projection, false );
		} catch ( Legacy_Target_Transformation_Failure $failure ) {
			throw $failure;
		} catch ( Legacy_Migration_Failure $failure ) {
			throw $failure;
		} catch ( InvalidArgumentException | JsonException $error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE, $error );
		} catch ( Throwable $error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE, $error );
		}
	}

	/**
	 * Worksheet dependency.
	 *
	 * @param int $run_database_id Run database id.
	 * @param int $template_source_id Template source id.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function worksheet_dependency( int $run_database_id, int $template_source_id ): array {
		$item = $this->row(
			$this->prepare(
				'SELECT state, target_type, target_public_id, payload_schema, payload_version, payload_json, payload_hash FROM '
					. self::TABLE_ITEMS . ' WHERE import_run_id = %d AND source_type = %s AND source_key = %s LIMIT 1 FOR UPDATE',
				array( $run_database_id, 'worksheet_templates', (string) $template_source_id )
			)
		);
		if ( null === $item || 'success' !== ( $item['state'] ?? null ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::DEPENDENCY_MISSING );
		}
		if (
			'worksheet' !== ( $item['target_type'] ?? null )
			|| self::ITEM_SCHEMA !== ( $item['payload_schema'] ?? null )
			|| self::PROFILE_VERSION !== $this->positive_integer( $item['payload_version'] ?? null )
			|| ! Public_Id::is_valid( (string) ( $item['target_public_id'] ?? '' ) )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}

		$payload = $this->decode_object( (string) ( $item['payload_json'] ?? '' ), (string) ( $item['payload_hash'] ?? '' ) );
		$source  = $payload['source'] ?? null;
		$target  = $payload['target'] ?? null;
		if (
			self::ITEM_PROFILE !== ( $payload['profile'] ?? null )
			|| 'success' !== ( $payload['state'] ?? null )
			|| ! is_array( $source ) || array_is_list( $source )
			|| 'worksheet_templates' !== ( $source['type'] ?? null )
			|| (string) $template_source_id !== ( $source['key'] ?? null )
			|| ! is_array( $target ) || array_is_list( $target )
			|| 'worksheet' !== ( $target['type'] ?? null )
			|| ! hash_equals( (string) $item['target_public_id'], (string) ( $target['public_id'] ?? '' ) )
			|| ! is_array( $target['metadata'] ?? null ) || array_is_list( $target['metadata'] )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}

		$receipt       = new Legacy_Target_Write_Result(
			'worksheet',
			(string) $target['public_id'],
			(string) ( $target['content_hash'] ?? '' ),
			$target['metadata']
		);
		$metadata      = $receipt->metadata();
		$metadata_keys = array_keys( $metadata );
		sort( $metadata_keys, SORT_STRING );
		if (
			array( 'document_hash', 'question_count', 'question_link_count', 'question_link_hashes', 'question_public_ids', 'question_source_hashes', 'revision_content_hash', 'revision_public_id', 'worksheet_link_count', 'worksheet_link_hashes' ) !== $metadata_keys
			|| ! hash_equals( $receipt->content_hash(), (string) $metadata['revision_content_hash'] )
			|| ! Public_Id::is_valid( (string) $metadata['revision_public_id'] )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', (string) $metadata['document_hash'] )
			|| $metadata['question_count'] !== count( $metadata['question_public_ids'] )
			|| $metadata['question_count'] !== count( $metadata['question_source_hashes'] )
			|| $metadata['worksheet_link_count'] !== count( $metadata['worksheet_link_hashes'] )
			|| $metadata['question_link_count'] !== count( $metadata['question_link_hashes'] )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}

		$worksheet_public_id = $receipt->target_public_id();
		$revision_public_id  = (string) $metadata['revision_public_id'];
		$row                 = $this->row(
			$this->prepare(
				'SELECT w.id AS worksheet_database_id, w.public_id AS worksheet_public_id, w.state AS worksheet_state, '
					. 'w.current_revision_public_id, w.latest_publication_public_id, r.id AS revision_database_id, '
					. 'r.public_id AS revision_public_id, r.content_hash AS revision_content_hash, r.worksheet_id, '
					. 'r.state AS revision_state, r.teacher_document_schema, r.teacher_document_version, '
					. 'r.teacher_document_json, r.teacher_document_hash, r.document_schema_version, r.document_hash, '
					. 'r.validation_schema_version, r.validation_hash FROM ' . self::TABLE_WORKSHEETS . ' w INNER JOIN '
					. self::TABLE_REVISIONS . ' r ON r.public_id = w.current_revision_public_id '
					. 'WHERE w.public_id = %s AND r.public_id = %s LIMIT 1 FOR UPDATE',
				array( $worksheet_public_id, $revision_public_id )
			)
		);
		if ( null === $row ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		$worksheet_database_id = $this->positive_integer( $row['worksheet_database_id'] ?? null );
		$revision_database_id  = $this->positive_integer( $row['revision_database_id'] ?? null );
		if (
			! hash_equals( $worksheet_public_id, (string) ( $row['worksheet_public_id'] ?? '' ) )
			|| ! in_array( $row['worksheet_state'] ?? null, array( 'draft', 'archived' ), true )
			|| ! hash_equals( $revision_public_id, (string) ( $row['current_revision_public_id'] ?? '' ) )
			|| null !== ( $row['latest_publication_public_id'] ?? null )
			|| ! hash_equals( $revision_public_id, (string) ( $row['revision_public_id'] ?? '' ) )
			|| 'draft' !== ( $row['revision_state'] ?? null )
			|| $worksheet_database_id !== $this->positive_integer( $row['worksheet_id'] ?? null )
			|| ! hash_equals( $receipt->content_hash(), (string) ( $row['revision_content_hash'] ?? '' ) )
			|| ! hash_equals( (string) $metadata['document_hash'], (string) ( $row['document_hash'] ?? '' ) )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}

		$row['worksheet_database_id'] = $worksheet_database_id;
		$row['revision_database_id']  = $revision_database_id;
		return $row;
	}

	/**
	 * Canonical document.
	 *
	 * @param array<string,mixed> $dependency Dependency.
	 * @return Canonical_Worksheet_Document
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function canonical_document( array $dependency ): Canonical_Worksheet_Document {
		if (
			Worksheet_Document_Profile::SCHEMA_ID !== ( $dependency['teacher_document_schema'] ?? null )
			|| 1 !== $this->positive_integer( $dependency['teacher_document_version'] ?? null )
			|| Worksheet_Document_Profile::SCHEMA_VERSION !== ( $dependency['document_schema_version'] ?? null )
			|| Worksheet_Document_Profile::VALIDATION_VERSION !== ( $dependency['validation_schema_version'] ?? null )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		$json = $dependency['teacher_document_json'] ?? null;
		if ( ! is_string( $json ) || ! hash_equals( (string) $dependency['teacher_document_hash'], hash( 'sha256', $json ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		$decoded = json_decode( $json, true, 128, JSON_THROW_ON_ERROR );
		if ( ! is_array( $decoded ) || array_is_list( $decoded ) || ! hash_equals( $json, $this->canonical->encode( $decoded ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		$document = $this->documents()->canonicalize( $decoded );
		if (
			! hash_equals( $json, $document->document_json() )
			|| ! hash_equals( (string) $dependency['document_hash'], $document->document_hash() )
			|| ! hash_equals( (string) $dependency['validation_hash'], $document->validation_hash() )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		return $document;
	}

	/**
	 * Stored profiles.
	 *
	 * @param Worksheet_Publication_Projection $projection Projection.
	 * @return array<string,array{schema:string,version:int,json:string,hash:string}>
	 */
	private function stored_profiles( Worksheet_Publication_Projection $projection ): array {
		$result = array();
		foreach ( $projection->profiles() as $name => $profile ) {
			$json            = $this->canonical->encode( $profile );
			$result[ $name ] = array(
				'schema'  => (string) $profile['schema_id'],
				'version' => self::PROFILE_VERSION,
				'json'    => $json,
				'hash'    => hash( 'sha256', $json ),
			);
		}
		return $result;
	}

	/**
	 * Publication row.
	 *
	 * @param int $revision_database_id Revision database id.
	 * @return array<string,mixed>|null
	 */
	private function publication_row( int $revision_database_id ): ?array {
		return $this->row(
			$this->prepare(
				'SELECT public_id, content_hash, created_by_user_id, created_at_utc, worksheet_id, worksheet_revision_id, '
					. 'publication_no, state, student_document_schema, student_document_version, student_document_json, student_document_hash, '
					. 'response_schema_schema, response_schema_version, response_schema_json, response_schema_hash, '
					. 'grading_schema_schema, grading_schema_version, grading_schema_json, grading_schema_hash, '
					. 'print_projection_schema, print_projection_version, print_projection_json, print_projection_hash, '
					. 'projection_manifest_version, publication_hash, published_at_utc, retired_by_user_id, retired_at_utc FROM '
					. self::TABLE_PUBLICATIONS . ' WHERE worksheet_revision_id = %d LIMIT 1 FOR UPDATE',
				array( $revision_database_id )
			)
		);
	}

	/**
	 * Reject invalid publication.
	 *
	 * @param array<string,mixed>                                                    $row Row.
	 * @param array<string,mixed>                                                    $dependency Dependency.
	 * @param Worksheet_Publication_Projection                                       $projection Projection.
	 * @param array<string,array{schema:string,version:int,json:string,hash:string}> $profiles Profiles.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_publication(
		array $row,
		array $dependency,
		Worksheet_Publication_Projection $projection,
		array $profiles
	): void {
		if (
			! Public_Id::is_valid( (string) ( $row['public_id'] ?? '' ) )
			|| ! hash_equals( $projection->publication_hash(), (string) ( $row['content_hash'] ?? '' ) )
			|| ! hash_equals( $projection->publication_hash(), (string) ( $row['publication_hash'] ?? '' ) )
			|| $dependency['worksheet_database_id'] !== $this->positive_integer( $row['worksheet_id'] ?? null )
			|| $dependency['revision_database_id'] !== $this->positive_integer( $row['worksheet_revision_id'] ?? null )
			|| $this->positive_integer( $row['publication_no'] ?? null ) < 1
			|| self::PUBLICATION_STATE !== ( $row['state'] ?? null )
			|| ! hash_equals( $projection->manifest_version(), (string) ( $row['projection_manifest_version'] ?? '' ) )
			|| $this->positive_integer( $row['created_by_user_id'] ?? null ) < 1
			|| $this->positive_integer( $row['retired_by_user_id'] ?? null ) < 1
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::SOURCE_INVALID );
		}
		$this->utc( (string) ( $row['created_at_utc'] ?? '' ) );
		$this->utc( (string) ( $row['published_at_utc'] ?? '' ) );
		$this->utc( (string) ( $row['retired_at_utc'] ?? '' ) );

		foreach ( $profiles as $name => $profile ) {
			if (
				! hash_equals( $profile['schema'], (string) ( $row[ $name . '_schema' ] ?? '' ) )
				|| $profile['version'] !== $this->positive_integer( $row[ $name . '_version' ] ?? null )
				|| ! hash_equals( $profile['json'], (string) ( $row[ $name . '_json' ] ?? '' ) )
				|| ! hash_equals( $profile['hash'], (string) ( $row[ $name . '_hash' ] ?? '' ) )
			) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::SOURCE_INVALID );
			}
		}
		$grading = $projection->profile( 'grading_schema' );
		if ( Legacy_Migration_Publication_Projector::PROJECTOR_VERSION !== ( $grading['projector_version'] ?? null ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::SOURCE_INVALID );
		}
	}

	/**
	 * Result.
	 *
	 * @param array<string,mixed>              $row Row.
	 * @param array<string,mixed>              $dependency Dependency.
	 * @param Worksheet_Publication_Projection $projection Projection.
	 * @param bool                             $replayed Replayed.
	 * @return Legacy_Migration_Publication
	 */
	private function result(
		array $row,
		array $dependency,
		Worksheet_Publication_Projection $projection,
		bool $replayed
	): Legacy_Migration_Publication {
		return new Legacy_Migration_Publication(
			(string) $dependency['worksheet_public_id'],
			(string) $dependency['revision_public_id'],
			(string) $dependency['document_hash'],
			(string) $row['public_id'],
			$projection->publication_hash(),
			$projection->manifest_version(),
			$replayed
		);
	}

	/**
	 * Next publication no.
	 *
	 * @param int $worksheet_database_id Worksheet database id.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function next_publication_no( int $worksheet_database_id ): int {
		$value   = $this->scalar(
			$this->prepare(
				'SELECT COALESCE(MAX(publication_no), 0) FROM ' . self::TABLE_PUBLICATIONS . ' WHERE worksheet_id = %d FOR UPDATE',
				array( $worksheet_database_id )
			)
		);
		$maximum = $this->non_negative_integer( $value );
		if ( $maximum >= PHP_INT_MAX ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
		return $maximum + 1;
	}

	/**
	 * Decode object.
	 *
	 * @param string $json Json.
	 * @param string $expected_hash Expected hash.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function decode_object( string $json, string $expected_hash ): array {
		if ( '' === $json || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $expected_hash ) || ! hash_equals( $expected_hash, hash( 'sha256', $json ) ) ) {
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
	 * Documents.
	 *
	 * @return Worksheet_Document_Canonicalizer
	 */
	private function documents(): Worksheet_Document_Canonicalizer {
		return $this->documents ??= new Worksheet_Document_Canonicalizer( $this->canonical, $this->ids() );
	}

	/**
	 * Projector.
	 *
	 * @return Legacy_Migration_Publication_Projector
	 */
	private function projector(): Legacy_Migration_Publication_Projector {
		return $this->projector ??= new Legacy_Migration_Publication_Projector( $this->canonical );
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
		$this->assert_database_ok( $database );
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
	 * Scalar.
	 *
	 * @param string $sql Sql.
	 * @return mixed
	 */
	private function scalar( string $sql ) {
		$database = $this->database();
		$this->reset_error( $database );
		$value = $database->get_var( $sql );
		$this->assert_database_ok( $database );
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
		$database = $this->database();
		$this->reset_error( $database );
		if ( false === $database->insert( $table, $data ) || '' !== trim( (string) ( $database->last_error ?? '' ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Positive integer.
	 *
	 * @param mixed $value Value.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function positive_integer( $value ): int {
		if ( is_string( $value ) && 1 === preg_match( '/^[1-9][0-9]*$/D', $value ) ) {
			$value = (int) $value;
		}
		if ( ! is_int( $value ) || $value < 1 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
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
	private function non_negative_integer( $value ): int {
		if ( is_string( $value ) && 1 === preg_match( '/^(?:0|[1-9][0-9]*)$/D', $value ) ) {
			$value = (int) $value;
		}
		if ( ! is_int( $value ) || $value < 0 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
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
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::STORAGE_UNAVAILABLE );
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
