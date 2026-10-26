<?php
/**
 * Build an exact, student-safe worksheet print projection.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Authoring\Application;

use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Worksheet_Document;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Canonicalizer;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Profile;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Validation_Failure;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Preview_Projection;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Preview_Projector;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Projection_Safety_Inspector;
use OJAI\Dashboard\Worksheet\Authoring\Port\Worksheet_Print_Source_Query_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Port\Worksheet_Publication_Print_Source_Query_Port_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves revision or publication provenance before an asynchronous PDF job.
 */
final class Build_Worksheet_Print_Projection {
	public const ARTIFACT_PROFILE_VERSION     = 'worksheet_print.v1';
	public const PUBLICATION_MANIFEST_VERSION = '1.0.0';

	/**
	 * Initialize worksheet print projection dependencies and state.
	 *
	 * @param Worksheet_Print_Source_Query_Port_Interface $sources Sources.
	 * @param Worksheet_Document_Canonicalizer            $documents Documents.
	 * @param Worksheet_Preview_Projector                 $previews Previews.
	 * @param Worksheet_Projection_Safety_Inspector       $safety Safety.
	 * @param Canonical_Json                              $canonical Canonical JSON encoder used for stable digests.
	 */
	public function __construct(
		private Worksheet_Print_Source_Query_Port_Interface $sources,
		private Worksheet_Document_Canonicalizer $documents,
		private Worksheet_Preview_Projector $previews,
		private Worksheet_Projection_Safety_Inspector $safety,
		private Canonical_Json $canonical
	) {
	}

	/**
	 * Build.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $source_type Source type.
	 * @param string $source_public_id Source public id.
	 * @param string $expected_source_hash Expected source hash.
	 * @param string $profile Profile.
	 * @return array{
	 *   source:array{type:string,id:string,hash:string},
	 *   worksheet_id:string,
	 *   revision:array{id:string,document_hash:string,validation_hash:string},
	 *   publication:?array{id:string,hash:string,state:string,manifest_version:string},
	 *   print:array{
	 *     artifact_profile_version:string,
	 *     schema_id:string,
	 *     schema_version:string,
	 *     projector_version:string,
	 *     profile:string,
	 *     page_profile:string,
	 *     locale:string,
	 *     profile_hash:string,
	 *     projection_hash:string,
	 *     projection:array<string,mixed>
	 *   }
	 * }
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function build(
		int $actor_user_id,
		string $source_type,
		string $source_public_id,
		string $expected_source_hash,
		string $profile
	): array {
		$this->assert_request( $actor_user_id, $source_type, $source_public_id, $expected_source_hash, $profile );
		$source = $this->sources->find_owned( $actor_user_id, $source_type, $source_public_id );
		if ( null === $source ) {
			throw Worksheet_Failure::not_found();
		}
		$this->assert_source_identity( $source, $source_type, $source_public_id );
		$current_source_hash = (string) $source['source_hash'];
		if ( ! hash_equals( $expected_source_hash, $current_source_hash ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Failure::print_source_conflict( $expected_source_hash, $current_source_hash );
		}
		return $this->projection_result( $source, $source_type, $source_public_id, $current_source_hash, $profile );
	}

	/**
	 * Reads an immutable publication for a Context-authorized caller without
	 * inventing worksheet ownership.
	 *
	 * @param string $publication_id Publication id.
	 * @param string $expected_publication_hash Expected publication hash.
	 * @param string $profile Profile.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function build_exact_publication(
		string $publication_id,
		string $expected_publication_hash,
		string $profile = 'student_blank'
	): array {
		new Public_Id( $publication_id );
		if (
			! $this->sources instanceof Worksheet_Publication_Print_Source_Query_Port_Interface
			|| ! $this->is_hash( $expected_publication_hash )
			|| 'student_blank' !== $profile
		) {
			throw new InvalidArgumentException( 'Exact worksheet publication print request is invalid.' );
		}
		$source = $this->sources->find_exact_publication( $publication_id, $expected_publication_hash );
		if ( null === $source ) {
			throw Worksheet_Failure::not_found();
		}
		return $this->projection_result( $source, 'publication', $publication_id, $expected_publication_hash, $profile );
	}

	/**
	 * Projection result.
	 *
	 * @param array<string,mixed> $source Source.
	 * @param string              $source_type Source type.
	 * @param string              $source_public_id Source public id.
	 * @param string              $current_source_hash Current source hash.
	 * @param string              $profile Profile.
	 * @return array<string,mixed>
	 */
	private function projection_result( array $source, string $source_type, string $source_public_id, string $current_source_hash, string $profile ): array {
		$this->assert_source_identity( $source, $source_type, $source_public_id );
		$print      = 'revision' === $source_type
			? $this->from_revision( $source, $profile )
			: $this->from_publication( $source, $profile );
		$projection = $print->projection();
		$envelope   = $projection['print_projection'];
		$payload    = $envelope['payload'];

		return array(
			'source'       => array(
				'type' => $source_type,
				'id'   => $source_public_id,
				'hash' => $current_source_hash,
			),
			'worksheet_id' => (string) $source['worksheet_id'],
			'revision'     => array(
				'id'              => (string) $source['revision_id'],
				'document_hash'   => (string) $source['revision_document_hash'],
				'validation_hash' => (string) $source['revision_validation_hash'],
			),
			'publication'  => 'publication' === $source_type
				? array(
					'id'               => (string) $source['publication_id'],
					'hash'             => (string) $source['publication_hash'],
					'state'            => (string) $source['publication_state'],
					'manifest_version' => (string) $source['projection_manifest_version'],
				)
				: null,
			'print'        => array(
				'artifact_profile_version' => self::ARTIFACT_PROFILE_VERSION,
				'schema_id'                => (string) $envelope['schema_id'],
				'schema_version'           => (string) $envelope['schema_version'],
				'projector_version'        => (string) $envelope['projector_version'],
				'profile'                  => $print->profile(),
				'page_profile'             => (string) $payload['layout']['print_token'],
				'locale'                   => (string) $payload['document']['locale'],
				'profile_hash'             => $this->canonical_hash( $envelope ),
				'projection_hash'          => $print->projection_hash(),
				'projection'               => $projection,
			),
		);
	}

	/**
	 * From revision.
	 *
	 * @param array<string,mixed> $source Source.
	 * @param string              $profile Profile.
	 * @return Worksheet_Preview_Projection
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function from_revision( array $source, string $profile ): Worksheet_Preview_Projection {
		if (
			null !== $source['publication_id']
			|| null !== $source['print_envelope']
			|| ! is_array( $source['teacher_document'] )
			|| array_is_list( $source['teacher_document'] )
		) {
			throw Worksheet_Failure::stored_data_invalid();
		}
		$document = $this->canonical_document( $source['teacher_document'] );
		if (
			! hash_equals( (string) $source['revision_document_hash'], $document->document_hash() )
			|| ! hash_equals( (string) $source['revision_validation_hash'], $document->validation_hash() )
		) {
			throw Worksheet_Failure::stored_data_invalid();
		}

		try {
			$student    = $this->previews->student( $document );
			$print      = $this->previews->print( $document, $profile );
			$inspection = $this->safety->inspect( $document, $student, $print );
		} catch ( InvalidArgumentException ) {
			throw Worksheet_Failure::stored_data_invalid();
		}
		if ( true !== ( $inspection['ready'] ) || array() !== ( $inspection['blockers'] ) ) {
			throw Worksheet_Failure::stored_data_invalid();
		}
		return $print;
	}

	/**
	 * From publication.
	 *
	 * @param array<string,mixed> $source Source.
	 * @param string              $profile Profile.
	 * @return Worksheet_Preview_Projection
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function from_publication( array $source, string $profile ): Worksheet_Preview_Projection {
		if ( 'student_blank' !== $profile ) {
			throw new InvalidArgumentException( 'Immutable publication print profile is student_blank.' );
		}
		$envelope = $source['print_envelope'];
		if (
			! is_array( $envelope ) || array_is_list( $envelope )
			|| null !== $source['teacher_document']
			|| ! in_array( $source['publication_state'], array( 'active', 'retired' ), true )
			|| self::PUBLICATION_MANIFEST_VERSION !== $source['projection_manifest_version']
			|| ! hash_equals( (string) $source['source_hash'], (string) $source['publication_hash'] )
			|| ! hash_equals(
				(string) $source['print_profile_hash'],
				$this->canonical_hash( $envelope )
			)
		) {
			throw Worksheet_Failure::stored_data_invalid();
		}

		$this->assert_exact_keys(
			$envelope,
			array( 'schema_id', 'schema_version', 'projector_version', 'source_hash', 'payload_hash', 'payload' )
		);
		$payload = $envelope['payload'] ?? null;
		if (
			Worksheet_Preview_Projector::PRINT_SCHEMA !== ( $envelope['schema_id'] ?? null )
			|| Worksheet_Preview_Projector::PRINT_VERSION !== ( $envelope['schema_version'] ?? null )
			|| Worksheet_Preview_Projector::PROJECTOR_VERSION !== ( $envelope['projector_version'] ?? null )
			|| ! hash_equals( (string) $source['revision_document_hash'], (string) ( $envelope['source_hash'] ?? '' ) )
			|| ! is_array( $payload ) || array_is_list( $payload )
			|| ! $this->is_hash( (string) ( $envelope['payload_hash'] ?? '' ) )
			|| ! hash_equals(
				(string) $envelope['payload_hash'],
				$this->canonical_hash( $payload )
			)
		) {
			throw Worksheet_Failure::stored_data_invalid();
		}

		$public_document = $payload['document'] ?? null;
		if ( ! is_array( $public_document ) || array_is_list( $public_document ) ) {
			throw Worksheet_Failure::stored_data_invalid();
		}
		$this->assert_exact_keys( $payload, array( 'profile', 'layout', 'document', 'response_schema' ) );
		$this->assert_exact_keys(
			$public_document,
			array( 'document_id', 'editor_profile', 'locale', 'title', 'navigation', 'nodes' )
		);
		if ( 'student_blank' !== $payload['profile'] || 'document' !== $public_document['navigation'] ) {
			throw Worksheet_Failure::stored_data_invalid();
		}

		$reconstructed = array(
			'schema_id'      => Worksheet_Document_Profile::SCHEMA_ID,
			'schema_version' => Worksheet_Document_Profile::SCHEMA_VERSION,
			'editor_profile' => $public_document['editor_profile'],
			'document_id'    => $public_document['document_id'],
			'locale'         => $public_document['locale'],
			'title'          => $public_document['title'],
			'nodes'          => $public_document['nodes'],
			'settings'       => array(
				'student_navigation' => 'document',
				'print_profile'      => 'worksheet_a4@1',
			),
		);
		$document      = $this->canonical_document( $reconstructed );

		try {
			$regenerated_student = $this->previews->student( $document );
			$regenerated_print   = $this->previews->print( $document, 'student_blank' );
			$inspection          = $this->safety->inspect( $document, $regenerated_student, $regenerated_print );
			$regenerated_payload = $regenerated_print->projection()['print_projection']['payload'] ?? null;
		} catch ( InvalidArgumentException ) {
			throw Worksheet_Failure::stored_data_invalid();
		}
		if (
			true !== ( $inspection['ready'] )
			|| array() !== ( $inspection['blockers'] )
			|| ! hash_equals( $this->canonical_bytes( $payload ), $this->canonical_bytes( $regenerated_payload ) )
		) {
			throw Worksheet_Failure::stored_data_invalid();
		}

		$projection = array(
			'kind'             => 'print',
			'profile'          => 'student_blank',
			'print_projection' => $envelope,
		);
		return new Worksheet_Preview_Projection(
			'print',
			'student_blank',
			$projection,
			$this->canonical_hash( $projection )
		);
	}

	/**
	 * Canonical bytes.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function canonical_bytes( $value ): string {
		try {
			return $this->canonical->encode( $value );
		} catch ( InvalidArgumentException ) {
			throw Worksheet_Failure::stored_data_invalid();
		}
	}

	/**
	 * Canonical hash.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function canonical_hash( $value ): string {
		return hash( 'sha256', $this->canonical_bytes( $value ) );
	}

	/**
	 * Canonical document.
	 *
	 * @param array<string,mixed> $document Document.
	 * @return Canonical_Worksheet_Document
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function canonical_document( array $document ): Canonical_Worksheet_Document {
		try {
			$result = $this->documents->canonicalize( $document );
		} catch ( Worksheet_Document_Validation_Failure | InvalidArgumentException ) {
			throw Worksheet_Failure::stored_data_invalid();
		}
		if ( array() !== $result->temporary_id_map() ) {
			throw Worksheet_Failure::stored_data_invalid();
		}
		return $result;
	}

	/**
	 * Reject invalid source identity.
	 *
	 * @param array<string,mixed> $source Source.
	 * @param string              $source_type Source type.
	 * @param string              $source_public_id Source public id.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_source_identity( array $source, string $source_type, string $source_public_id ): void {
		$expected = array(
			'source_type',
			'source_public_id',
			'source_hash',
			'worksheet_id',
			'revision_id',
			'revision_document_hash',
			'revision_validation_hash',
			'publication_id',
			'publication_hash',
			'publication_state',
			'projection_manifest_version',
			'teacher_document',
			'print_envelope',
			'print_profile_hash',
		);
		$this->assert_exact_keys( $source, $expected );
		foreach ( array( 'worksheet_id', 'revision_id' ) as $key ) {
			if ( ! is_string( $source[ $key ] ) ) {
				throw Worksheet_Failure::stored_data_invalid();
			}
			try {
				new Public_Id( $source[ $key ] );
			} catch ( InvalidArgumentException ) {
				throw Worksheet_Failure::stored_data_invalid();
			}
		}
		if (
			$source_type !== $source['source_type']
			|| ! hash_equals( $source_public_id, (string) $source['source_public_id'] )
			|| ! $this->is_hash( (string) $source['source_hash'] )
			|| ! $this->is_hash( (string) $source['revision_document_hash'] )
			|| ! $this->is_hash( (string) $source['revision_validation_hash'] )
		) {
			throw Worksheet_Failure::stored_data_invalid();
		}
	}

	/**
	 * Reject invalid request.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $source_type Source type.
	 * @param string $source_public_id Source public id.
	 * @param string $expected_source_hash Expected source hash.
	 * @param string $profile Profile.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_request(
		int $actor_user_id,
		string $source_type,
		string $source_public_id,
		string $expected_source_hash,
		string $profile
	): void {
		if (
			$actor_user_id < 1
			|| ! in_array( $source_type, array( 'revision', 'publication' ), true )
			|| ! $this->is_hash( $expected_source_hash )
			|| ! in_array( $profile, Worksheet_Preview_Projector::PRINT_PROFILES, true )
		) {
			throw new InvalidArgumentException( 'Worksheet print projection request is invalid.' );
		}
		new Public_Id( $source_public_id );
	}

	/**
	 * Reject invalid exact keys.
	 *
	 * @param array<string,mixed> $value Value.
	 * @param array<int,string>   $expected Expected.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_exact_keys( array $value, array $expected ): void {
		$actual = array_keys( $value );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected ) {
			throw Worksheet_Failure::stored_data_invalid();
		}
	}

	/**
	 * Is hash.
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	private function is_hash( string $value ): bool {
		return 1 === preg_match( '/^[a-f0-9]{64}$/D', $value );
	}
}
