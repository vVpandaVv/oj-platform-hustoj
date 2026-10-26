<?php
/**
 * WordPress official source adapter for student submission evaluation.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Infrastructure;

use InvalidArgumentException;
use JsonException;
use OJAI\Dashboard\Assessment\Configuration\Domain\Assessment_Configuration_Profile;
use OJAI\Dashboard\Storage\Port\Private_File_Read_Port_Interface;
use OJAI\Dashboard\Submission\Assessment\Application\Submission_Evaluation_Failure;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Evaluation_Source;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Score_Input_Builder;
use OJAI\Dashboard\Submission\Assessment\Port\Submission_Evaluation_Source_Port_Interface;
use OJAI\Dashboard\Submission\Student\Domain\Submission_Response_Validator;
use OJAI\Dashboard\Submission\Teacher\Port\Submission_Print_Source_Query_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rehydrates only exact official rows and private representation provenance.
 */
final class WordPress_Submission_Evaluation_Source implements Submission_Evaluation_Source_Port_Interface {
	private const TABLE_PUBLICATIONS  = 'oj_ai_worksheet_publications';
	private const TABLE_ATTACHMENTS   = 'oj_ai_submission_attachments';
	private const TABLE_REVISIONS     = 'oj_ai_submission_revisions';
	private const TABLE_SUBMISSIONS   = 'oj_ai_submissions';
	private const TABLE_PRIVATE_FILES = 'oj_ai_private_files';

	/**
	 * Initialize WordPress submission evaluation source dependencies and state.
	 *
	 * @param Submission_Print_Source_Query_Port_Interface                                   $submissions Submissions.
	 * @param Canonical_Json                                                                 $canonical Canonical JSON encoder used for stable digests.
	 * @param Submission_Response_Validator                                                  $responses Responses.
	 * @param Assessment_Configuration_Profile                                               $configuration_profile Configuration profile.
	 * @param Private_File_Read_Port_Interface                                               $files Files.
	 * @param object|null                                                                    $database Database.
	 * @param \OJAI\Dashboard\Assessment\Project\Port\Project_Repository_Port_Interface|null $projects Projects.
	 */
	public function __construct(
		private Submission_Print_Source_Query_Port_Interface $submissions,
		private Canonical_Json $canonical,
		private Submission_Response_Validator $responses,
		private Assessment_Configuration_Profile $configuration_profile,
		private Private_File_Read_Port_Interface $files,
		private ?object $database = null,
		private ?\OJAI\Dashboard\Assessment\Project\Port\Project_Repository_Port_Interface $projects = null
	) {
	}

	/**
	 * Find official.
	 *
	 * @param string $submission_id Submission public identifier.
	 * @param string $official_revision_id Official revision id.
	 * @param bool   $manual Use original attachments without AI representations.
	 * @return Submission_Evaluation_Source|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function find_official( string $submission_id, string $official_revision_id, bool $manual = false ): ?Submission_Evaluation_Source {
		new Public_Id( $submission_id );
		new Public_Id( $official_revision_id );
		try {
			$safe = $this->submissions->find_official_revision( $official_revision_id );
		} catch ( Throwable ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORAGE_UNAVAILABLE );
		}
		if ( null === $safe ) {
			return null;
		}
		if ( array() === $safe || ! hash_equals( $submission_id, (string) ( $safe['id'] ?? '' ) ) ) {
			$this->invalid( '$.source' );
		}
		$official = $safe['official_revision'] ?? null;
		// The shared teacher print/detail query projects a revision with an
		// extra created_at_utc field that Submission_Score_Input_Builder's
		// strict exact_keys() check on $.official_revision does not expect
		// (autosave/submit build their own revision projections without it).
		// Drop it here rather than in the shared projection, so the
		// teacher-facing print/detail views that do use it are unaffected.
		if ( is_array( $official ) ) {
			unset( $official['created_at_utc'] );
		}
		$source  = $safe['source'] ?? null;
		$context = $safe['context'] ?? null;
		if (
			! is_array( $official ) || array_is_list( $official )
			|| ! is_array( $source ) || array_is_list( $source )
			|| ! is_array( $context ) || array_is_list( $context )
			|| ! hash_equals( $official_revision_id, (string) ( $safe['official_revision_id'] ?? '' ) )
			|| ! hash_equals( $official_revision_id, (string) ( $official['id'] ?? '' ) )
			|| ! in_array( $safe['state'] ?? null, array( 'submitted', 'auto_submitted', 'proxy_submitted', 'locked' ), true )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::SUBMISSION_NOT_OFFICIAL );
		}

		$publication_id   = $this->public_id( $source['worksheet_publication_id'] ?? null, '$.source.worksheet_publication_id' );
		$publication_hash = $this->hash_value( $source['publication_hash'] ?? null, '$.source.publication_hash' );
		$publication      = $this->publication( $publication_id, $publication_hash );
		$configuration    = $this->configuration( $publication, $publication_id );
		$artifacts        = $this->artifacts( $submission_id, $official_revision_id, $official, $publication, $manual );

		try {
			return new Submission_Evaluation_Source(
				$submission_id,
				$this->non_negative_integer( $safe['lock_version'] ?? null, '$.source.lock_version' ),
				(string) $safe['state'],
				$this->context_type( $context['type'] ?? null, '$.source.context.type' ),
				$this->token( $context['id'] ?? null, 191, '$.source.context.id' ),
				$this->token( $context['revision'] ?? null, 191, '$.source.context.revision' ),
				$official,
				$publication,
				$configuration,
				$artifacts
			);
		} catch ( InvalidArgumentException ) {
			$this->invalid( '$.source.constructor' );
		}
	}

	/**
	 * Publication.
	 *
	 * @param string $publication_id Publication id.
	 * @param string $expected_hash Expected hash.
	 * @return array<string,mixed>
	 */
	private function publication( string $publication_id, string $expected_hash ): array {
		$row = $this->get_row(
			$this->prepare(
				'SELECT public_id, content_hash, state, student_document_schema, student_document_version, student_document_json, student_document_hash, response_schema_schema, response_schema_version, response_schema_json, response_schema_hash, grading_schema_schema, grading_schema_version, grading_schema_json, grading_schema_hash, print_projection_schema, print_projection_version, print_projection_json, print_projection_hash, projection_manifest_version, publication_hash FROM ' . self::TABLE_PUBLICATIONS . ' WHERE public_id = %s LIMIT 1',
				array( $publication_id )
			)
		);
		if ( null === $row || ! hash_equals( $publication_id, $this->public_id( $row['public_id'] ?? null, '$.publication.public_id' ) ) ) {
			$this->invalid( '$.publication.row' );
		}
		if ( ! in_array( $row['state'] ?? null, array( 'active', 'retired' ), true ) ) {
			$this->invalid( '$.publication.state' );
		}
		if ( ! hash_equals( $expected_hash, $this->hash_value( $row['content_hash'] ?? null, '$.publication.content_hash' ) ) ) {
			$this->invalid( '$.publication.content_hash' );
		}
		if ( ! hash_equals( $expected_hash, $this->hash_value( $row['publication_hash'] ?? null, '$.publication.publication_hash' ) ) ) {
			$this->invalid( '$.publication.publication_hash' );
		}

		$profiles          = array();
		$manifest_profiles = array();
		$source_hash       = null;
		foreach ( array( 'grading_schema', 'print_projection', 'response_schema', 'student_document' ) as $name ) {
			$profile             = $this->profile( $row, $name );
			$current_source_hash = $this->hash_value( $profile['source_hash'] ?? null, '$.publication.' . $name . '.source_hash' );
			if ( null !== $source_hash && ! hash_equals( $source_hash, $current_source_hash ) ) {
				$this->invalid( '$.publication.' . $name . '.source_hash_drift' );
			}
			$source_hash                = $current_source_hash;
			$profiles[ $name ]          = $profile;
			$manifest_profiles[ $name ] = array(
				'payload_hash'      => $this->hash_value( $profile['payload_hash'] ?? null, '$.publication.' . $name . '.payload_hash' ),
				'projector_version' => $this->token( $profile['projector_version'] ?? null, 32, '$.publication.' . $name . '.projector_version' ),
				'schema_id'         => $this->token( $profile['schema_id'] ?? null, 100, '$.publication.' . $name . '.schema_id' ),
				'schema_version'    => $this->token( $profile['schema_version'] ?? null, 32, '$.publication.' . $name . '.schema_version' ),
			);
		}
		$manifest_version = $this->token( $row['projection_manifest_version'] ?? null, 32, '$.publication.projection_manifest_version' );
		$manifest         = array(
			'manifest_version'     => $manifest_version,
			'profiles'             => $manifest_profiles,
			'source_revision_hash' => (string) $source_hash,
		);
		if ( ! hash_equals( $expected_hash, hash( 'sha256', $this->canonical->encode( $manifest ) ) ) ) {
			$this->invalid( '$.publication.manifest_hash' );
		}

		return array(
			'grading_schema'   => $profiles['grading_schema'],
			'id'               => $publication_id,
			'manifest'         => $manifest,
			'manifest_version' => $manifest_version,
			'publication_hash' => $expected_hash,
			'response_schema'  => $profiles['response_schema'],
			'student_document' => $profiles['student_document'],
		);
	}

	/**
	 * Profile.
	 *
	 * @param array<string,mixed> $row Row.
	 * @param string              $name Name.
	 * @return array<string,mixed>
	 */
	private function profile( array $row, string $name ): array {
		if ( 1 !== $this->positive_integer( $row[ $name . '_version' ] ?? null, '$.publication.' . $name . '.version' ) ) {
			$this->invalid( '$.publication.' . $name . '.version' );
		}
		$hash    = $this->hash_value( $row[ $name . '_hash' ] ?? null, '$.publication.' . $name . '.hash' );
		$profile = $this->decode_object( (string) ( $row[ $name . '_json' ] ?? '' ), $hash, '$.publication.' . $name . '.json' );
		if ( ! hash_equals( $this->token( $row[ $name . '_schema' ] ?? null, 100, '$.publication.' . $name . '.schema' ), (string) ( $profile['schema_id'] ?? '' ) ) ) {
			$this->invalid( '$.publication.' . $name . '.schema_id' );
		}
		return $profile;
	}

	/**
	 * Configuration.
	 *
	 * @param array<string,mixed> $publication Publication.
	 * @param string              $publication_id Publication id.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function configuration( array $publication, string $publication_id ): array {
		$p03 = $publication['grading_schema']['payload'] ?? null;
		if ( ! is_array( $p03 ) || array_is_list( $p03 ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::GRADING_SCHEMA_INVALID );
		}
		$project = $this->projects?->for_publication( $publication_id, (string) ( $p03['rubric']['revision_id'] ?? '' ) );
		if ( null !== $project ) {
			$review = $this->configuration_record( 'review_rule', (string) ( $p03['review_rule']['revision_id'] ?? '' ) );
			$rubric = \OJAI\Dashboard\Assessment\Project\Domain\Project_Configuration::rubric( $project );
			return array(
				'policy'      => \OJAI\Dashboard\Assessment\Project\Domain\Project_Configuration::policy( $project, $review['revision_id'] ),
				'review_rule' => $review,
				'rubric'      => array(
					'revision_id'  => $rubric['revision_id'],
					'content_hash' => $rubric['content_hash'],
					'state'        => 'active',
					'subject_type' => 'student_submission',
					'value'        => array( 'criteria' => $rubric['criteria'] ),
					'total_points' => $rubric['total_points'],
				),
			);
		}
		$result = array();
		foreach ( array( 'policy', 'review_rule', 'rubric' ) as $resource ) {
			$pin = $p03[ $resource ] ?? null;
			if ( ! is_array( $pin ) || array_is_list( $pin ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::GRADING_SCHEMA_INVALID );
			}
			$result[ $resource ] = $this->configuration_record( $resource, $this->public_id( $pin['revision_id'] ?? null, '$.publication.grading_schema.payload.' . $resource . '.revision_id' ) );
		}
		return $result;
	}

	/**
	 * Configuration record.
	 *
	 * @param string $resource Resource.
	 * @param string $revision_id Revision id.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function configuration_record( string $resource, string $revision_id ): array { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.resourceFound -- Preserve the established named-argument signature; these parameter names are legal in supported PHP versions.
		$table = Assessment_Configuration_Profile::table( $resource );
		$stem  = Assessment_Configuration_Profile::stem( $resource );
		$extra = match ( $resource ) {
			Assessment_Configuration_Profile::RUBRIC => ', total_points',
			Assessment_Configuration_Profile::REVIEW_RULE => ', review_required, explicit_release_required, confidence_threshold',
			default => '',
		};
		$row = $this->get_row(
			$this->prepare(
				'SELECT public_id, lock_version, created_at_utc, updated_at_utc, subject_type, revision_no, state, '
					. $stem . '_schema, ' . $stem . '_version, ' . $stem . '_json, ' . $stem . '_hash, content_hash, '
					. 'activated_at_utc, retired_at_utc' . $extra . ' FROM ' . $table . ' WHERE public_id = %s LIMIT 1',
				array( $revision_id )
			)
		);
		if ( null === $row ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::GRADING_SCHEMA_INVALID );
		}
		try {
			$projected = $this->configuration_profile->project_row( $resource, $row );
		} catch ( InvalidArgumentException ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::GRADING_SCHEMA_INVALID );
		}
		if ( ! hash_equals( $revision_id, (string) $projected['revision_id'] ) || ! in_array( $projected['state'], array( 'active', 'retired' ), true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::GRADING_SCHEMA_INVALID );
		}
		$result = array(
			'content_hash' => $projected['content_hash'],
			'revision_id'  => $projected['revision_id'],
			'state'        => $projected['state'],
			'subject_type' => $projected['subject_type'],
			'value'        => $projected['value'],
		);
		foreach ( array( 'confidence_threshold', 'explicit_release_required', 'review_required', 'total_points' ) as $field ) {
			if ( null !== ( $projected[ $field ] ?? null ) ) {
				$result[ $field ] = $projected[ $field ];
			}
		}
		return $result;
	}

	/**
	 * Artifacts.
	 *
	 * @param string              $submission_id Submission public identifier.
	 * @param string              $revision_id Revision id.
	 * @param array<string,mixed> $official Official.
	 * @param array<string,mixed> $publication Publication.
	 * @param bool                $manual Skip AI extraction for teacher-only scoring.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function artifacts( string $submission_id, string $revision_id, array $official, array $publication, bool $manual = false ): array {
		$response = $official['response'] ?? null;
		// Submission_Response_Validator::profile_items() expects the FULL
		// decoded response-schema profile (schema_id/schema_version/
		// projector_version/source_hash/payload/payload_hash), the same
		// shape response_profile() passes it at autosave/submit time —
		// not just the inner payload.items sub-object.
		$profile = $publication['response_schema'] ?? null;
		if ( ! is_array( $response ) || ! is_array( $profile ) || ! is_array( $response['answers'] ?? null ) ) {
			$this->invalid( '$.artifacts.response' );
		}
		try {
			$expected = $this->responses->validate_complete( $profile, $response['answers'] )['attachments'];
		} catch ( Throwable ) {
			$this->invalid( '$.artifacts.validate_complete' );
		}
		$rows = $this->get_results(
			$this->prepare(
				'SELECT a.public_id, a.response_key, a.state, a.mime_type, a.byte_size, a.sha256, a.ai_representation_schema, a.ai_representation_version, a.ai_representation_json, a.ai_representation_hash FROM ' . self::TABLE_ATTACHMENTS . ' a INNER JOIN ' . self::TABLE_REVISIONS . ' r ON r.id = a.submission_revision_id '
					. 'INNER JOIN ' . self::TABLE_SUBMISSIONS . ' s ON s.id = a.submission_id '
					. 'WHERE s.public_id = %s AND r.public_id = %s ORDER BY a.public_id ASC LIMIT 101',
				array( $submission_id, $revision_id )
			)
		);
		if ( count( $rows ) > Submission_Score_Input_Builder::MAXIMUM_ARTIFACTS ) {
			$this->invalid( '$.artifacts.count' );
		}
		$by_id = array();
		foreach ( $rows as $row ) {
			$id = $this->public_id( $row['public_id'] ?? null, '$.artifacts[].public_id' );
			if ( isset( $by_id[ $id ] ) ) {
				$this->invalid( '$.artifacts.duplicate_id' );
			}
			$by_id[ $id ] = $row;
		}
		$actual_ids   = array_keys( $by_id );
		$expected_ids = array_keys( $expected );
		sort( $actual_ids, SORT_STRING );
		sort( $expected_ids, SORT_STRING );
		if ( $actual_ids !== $expected_ids ) {
			$this->invalid( '$.artifacts.ids_mismatch' );
		}
		ksort( $by_id, SORT_STRING );

		$result = array();
		foreach ( $by_id as $attachment_id => $row ) {
			$definition  = $expected[ $attachment_id ];
			$source_hash = $this->hash_value( $row['sha256'] ?? null, '$.artifacts.' . $attachment_id . '.sha256' );
			if (
				'bound' !== ( $row['state'] ?? null )
				|| $definition['response_id'] !== ( $row['response_key'] ?? null )
				|| ! in_array( $row['mime_type'] ?? null, $definition['mime_types'], true )
				|| $this->non_negative_integer( $row['byte_size'] ?? null, '$.artifacts.' . $attachment_id . '.byte_size' ) > $definition['max_bytes']
			) {
				$this->invalid( '$.artifacts.' . $attachment_id . '.metadata' );
			}
			if ( $manual ) {
				$manifest = array(
					'schema_id'          => Submission_Score_Input_Builder::REPRESENTATION_SCHEMA,
					'schema_version'     => '1.0.0',
					'source_hash'        => $source_hash,
					'state'              => 'unavailable',
					'extractor_contract' => 'teacher-original@1',
					'redaction_version'  => Submission_Score_Input_Builder::REDACTION_VERSION,
					'derived_file_id'    => null,
					'derived_sha256'     => null,
					'derived_byte_size'  => 0,
					'reason_code'        => 'teacher_original_review',
				);
				$result[] = array(
					'anchors'             => array(),
					'attachment_id'       => $attachment_id,
					'kind'                => $definition['kind'],
					'representation'      => $manifest,
					'representation_hash' => hash( 'sha256', $this->canonical->encode( $manifest ) ),
					'response_id'         => $definition['response_id'],
					'role'                => $definition['role'],
					'source_hash'         => $source_hash,
					'text'                => null,
				);
				continue;
			}
			if ( null === ( $row['ai_representation_json'] ?? null ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::ARTIFACT_NOT_READY, array( 'state' => 'missing' ) );
			}
			if (
				Submission_Score_Input_Builder::REPRESENTATION_SCHEMA !== ( $row['ai_representation_schema'] ?? null )
				|| 1 !== $this->positive_integer( $row['ai_representation_version'] ?? null, '$.artifacts.' . $attachment_id . '.representation_version' )
			) {
				$this->invalid( '$.artifacts.' . $attachment_id . '.representation_schema' );
			}
			$representation_hash = $this->hash_value( $row['ai_representation_hash'] ?? null, '$.artifacts.' . $attachment_id . '.representation_hash' );
			$manifest            = $this->decode_object( (string) $row['ai_representation_json'], $representation_hash, '$.artifacts.' . $attachment_id . '.representation_json' );
			if ( ! hash_equals( $source_hash, (string) ( $manifest['source_hash'] ?? '' ) ) ) {
				$this->invalid( '$.artifacts.' . $attachment_id . '.representation_source_hash' );
			}
			$text    = null;
			$anchors = array();
			if ( 'ready' === ( $manifest['state'] ?? null ) ) {
				$text    = $this->representation_text( $attachment_id, $manifest );
				$anchors = $this->anchors( $attachment_id, $text );
			}
			$result[] = array(
				'anchors'             => $anchors,
				'attachment_id'       => $attachment_id,
				'kind'                => $definition['kind'],
				'representation'      => $manifest,
				'representation_hash' => $representation_hash,
				'response_id'         => $definition['response_id'],
				'role'                => $definition['role'],
				'source_hash'         => $source_hash,
				'text'                => $text,
			);
		}
		return $result;
	}

	/**
	 * Representation text.
	 *
	 * @param string              $attachment_id Attachment id.
	 * @param array<string,mixed> $manifest Manifest.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function representation_text( string $attachment_id, array $manifest ): string {
		$derived_id    = $this->public_id( $manifest['derived_file_id'] ?? null, '$.artifacts.' . $attachment_id . '.derived_file_id' );
		$derived_hash  = $this->hash_value( $manifest['derived_sha256'] ?? null, '$.artifacts.' . $attachment_id . '.derived_sha256' );
		$derived_bytes = $this->positive_integer( $manifest['derived_byte_size'] ?? null, '$.artifacts.' . $attachment_id . '.derived_byte_size' );
		if ( $derived_bytes > Submission_Score_Input_Builder::MAXIMUM_ARTIFACT_TEXT ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::INPUT_TOO_LARGE );
		}
		$row = $this->get_row(
			$this->prepare(
				'SELECT public_id, owner_type, owner_public_id, purpose, storage_key, mime_type, byte_size, sha256, state, scan_state, purged_at_utc FROM ' . self::TABLE_PRIVATE_FILES . ' WHERE public_id = %s LIMIT 1',
				array( $derived_id )
			)
		);
		if (
			null === $row
			|| ! hash_equals( $derived_id, $this->public_id( $row['public_id'] ?? null, '$.artifacts.' . $attachment_id . '.derived_file.public_id' ) )
			|| 'submission_attachment' !== ( $row['owner_type'] ?? null )
			|| ! hash_equals( $attachment_id, (string) ( $row['owner_public_id'] ?? '' ) )
			|| 'ai_text_representation' !== ( $row['purpose'] ?? null )
			|| 'text/plain' !== ( $row['mime_type'] ?? null )
			|| 'ready' !== ( $row['state'] ?? null )
			|| 'clean' !== ( $row['scan_state'] ?? null )
			|| null !== ( $row['purged_at_utc'] ?? null )
			|| $derived_bytes !== $this->positive_integer( $row['byte_size'] ?? null, '$.artifacts.' . $attachment_id . '.derived_file.byte_size' )
			|| ! hash_equals( $derived_hash, $this->hash_value( $row['sha256'] ?? null, '$.artifacts.' . $attachment_id . '.derived_file.sha256' ) )
		) {
			$this->invalid( '$.artifacts.' . $attachment_id . '.derived_file' );
		}
		try {
			$path = $this->files->readable_path( $this->storage_key( $row['storage_key'] ?? null, '$.artifacts.' . $attachment_id . '.derived_file.storage_key' ), $derived_hash );
			$text = @file_get_contents( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Native warnings can expose private paths; the failure return is checked and mapped to a stable error. Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
		} catch ( Throwable ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORAGE_UNAVAILABLE );
		}
		if ( ! is_string( $text ) || strlen( $text ) !== $derived_bytes || ! hash_equals( $derived_hash, hash( 'sha256', $text ) ) ) {
			$this->invalid( '$.artifacts.' . $attachment_id . '.derived_file.content' );
		}
		return $text;
	}

	/**
	 * Anchors.
	 *
	 * @param string $attachment_id Attachment id.
	 * @param string $text Text.
	 * @return array<int,array{anchor:string,quote:string}>
	 */
	private function anchors( string $attachment_id, string $text ): array {
		$characters = function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ? null : preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );
		$length     = null === $characters ? mb_strlen( $text, 'UTF-8' ) : ( is_array( $characters ) ? count( $characters ) : 0 );
		if ( $length < 1 ) {
			$this->invalid( '$.artifacts.' . $attachment_id . '.anchors.empty_text' );
		}
		$result = array();
		for ( $offset = 0, $index = 1; $offset < $length; $offset += 800, ++$index ) {
			$quote = null === $characters ? mb_substr( $text, $offset, 800, 'UTF-8' ) : implode( '', array_slice( $characters, $offset, 800 ) );
			if ( '' === trim( $quote ) ) {
				continue;
			}
			$result[] = array(
				'anchor' => 'text:' . $index,
				'quote'  => $quote,
			);
		}
		if ( array() === $result || count( $result ) > 200 ) {
			$this->invalid( '$.artifacts.' . $attachment_id . '.anchors.count' );
		}
		return $result;
	}

	/**
	 * Decode object.
	 *
	 * @param string $json Json.
	 * @param string $hash Hash.
	 * @param string $path Path.
	 * @return array<string,mixed>
	 */
	private function decode_object( string $json, string $hash, string $path ): array {
		if ( ! hash_equals( $hash, hash( 'sha256', $json ) ) ) {
			$this->invalid( $path . '.hash' );
		}
		try {
			$value = json_decode( $json, true, 64, JSON_THROW_ON_ERROR );
		} catch ( JsonException ) {
			$this->invalid( $path . '.decode' );
		}
		if ( ! is_array( $value ) || array_is_list( $value ) || ! hash_equals( $json, $this->canonical->encode( $value ) ) ) {
			$this->invalid( $path . '.canonical' );
		}
		return $value;
	}

	/**
	 * Public id.
	 *
	 * @param mixed  $value Value.
	 * @param string $path Path.
	 * @return string
	 */
	private function public_id( $value, string $path ): string {
		if ( ! is_string( $value ) ) {
			$this->invalid( $path );
		}
		try {
			new Public_Id( $value );
		} catch ( InvalidArgumentException ) {
			$this->invalid( $path );
		}
		return $value;
	}

	/**
	 * Hash value.
	 *
	 * @param mixed  $value Value.
	 * @param string $path Path.
	 * @return string
	 */
	private function hash_value( $value, string $path ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $value ) ) {
			$this->invalid( $path );
		}
		return $value;
	}

	/**
	 * Token.
	 *
	 * @param mixed  $value Value.
	 * @param int    $maximum Maximum.
	 * @param string $path Path.
	 * @return string
	 */
	private function token( $value, int $maximum, string $path ): string {
		if ( ! is_string( $value ) || '' === $value || strlen( $value ) > $maximum || 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@\/-]*$/D', $value ) ) {
			$this->invalid( $path );
		}
		return $value;
	}

	/**
	 * Context type.
	 *
	 * @param mixed  $value Value.
	 * @param string $path Path.
	 * @return string
	 */
	private function context_type( $value, string $path ): string {
		if ( ! is_string( $value ) || ! in_array( $value, array( 'class', 'group' ), true ) ) {
			$this->invalid( $path );
		}
		return $value;
	}

	/**
	 * Positive integer.
	 *
	 * @param mixed  $value Value.
	 * @param string $path Path.
	 * @return int
	 */
	private function positive_integer( $value, string $path ): int {
		if ( is_string( $value ) && 1 === preg_match( '/^[1-9][0-9]*$/D', $value ) ) {
			$value = (int) $value;
		}
		if ( ! is_int( $value ) || $value < 1 ) {
			$this->invalid( $path );
		}
		return $value;
	}

	/**
	 * Non negative integer.
	 *
	 * @param mixed  $value Value.
	 * @param string $path Path.
	 * @return int
	 */
	private function non_negative_integer( $value, string $path ): int {
		if ( is_string( $value ) && 1 === preg_match( '/^(?:0|[1-9][0-9]*)$/D', $value ) ) {
			$value = (int) $value;
		}
		if ( ! is_int( $value ) || $value < 0 ) {
			$this->invalid( $path );
		}
		return $value;
	}

	/**
	 * Storage key.
	 *
	 * @param mixed  $value Value.
	 * @param string $path Path.
	 * @return string
	 */
	private function storage_key( $value, string $path ): string {
		if ( ! is_string( $value ) || strlen( $value ) > 512 || 1 !== preg_match( '/^[a-z0-9][a-z0-9._\/-]*$/D', $value ) || in_array( '..', explode( '/', $value ), true ) ) {
			$this->invalid( $path );
		}
		return $value;
	}

	/**
	 * Invalid.
	 *
	 * @param string $path Path.
	 * @return never
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function invalid( string $path ): never {
		$context = array( 'path' => substr( $path, 0, 128 ) );
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
		throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::SOURCE_INVALID, $context );
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
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORAGE_UNAVAILABLE );
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
		$database = $this->database();
		$this->reset_error( $database );
		$row = $database->get_row( $sql, defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A' );
		$this->assert_read( $database );
		if ( null === $row ) {
			return null;
		}
		if ( ! is_array( $row ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORAGE_UNAVAILABLE );
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
		$database = $this->database();
		$this->reset_error( $database );
		$rows = $database->get_results( $sql, defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A' );
		$this->assert_read( $database );
		if ( ! is_array( $rows ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORAGE_UNAVAILABLE );
		}
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORAGE_UNAVAILABLE );
			}
		}
		return array_values( $rows );
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
	 * Reject invalid read.
	 *
	 * @param object $database Database.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_read( object $database ): void {
		if ( '' !== trim( (string) ( $database->last_error ?? '' ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORAGE_UNAVAILABLE );
		}
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
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORAGE_UNAVAILABLE );
		}
		foreach ( array( 'prepare', 'get_row', 'get_results' ) as $method ) {
			if ( ! method_exists( $database, $method ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::STORAGE_UNAVAILABLE );
			}
		}
		return $database;
	}
}
