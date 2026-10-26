<?php
/**
 * Worksheet draft save persistence record validator.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Authoring\Infrastructure;

use InvalidArgumentException;
use JsonException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Profile;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Revision_Projector;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Revalidates the application projection before any SQL write begins.
 */
final class Worksheet_Draft_Save_Record_Validator {
	/**
	 * Canonical encoder used to verify stored hashes.
	 *
	 * @var Canonical_Json
	 */
	private Canonical_Json $canonical;
	/**
	 * Allowed source states for this persistence operation.
	 *
	 * @var array<int,string>
	 */
	private array $allowed_expected_states;

	/**
	 * Configures the allowed source states for a persistence transaction.
	 *
	 * @param Canonical_Json    $canonical Canonical encoder.
	 * @param array<int,string> $allowed_expected_states Allowed source states.
	 * @throws InvalidArgumentException When no valid source state is allowed.
	 */
	public function __construct(
		Canonical_Json $canonical,
		array $allowed_expected_states = array( 'draft', 'active' )
	) {
		if ( array() === $allowed_expected_states || array_diff( $allowed_expected_states, array( 'draft', 'active', 'archived' ) ) ) {
			throw new InvalidArgumentException( 'Worksheet persistence expected states are invalid.' );
		}
		$this->canonical               = $canonical;
		$this->allowed_expected_states = array_values( array_unique( $allowed_expected_states ) );
	}

	/**
	 * Validates all persistence fields and their cross-record hashes before writes.
	 *
	 * @param array<string,mixed> $record Unvalidated persistence record.
	 * @throws InvalidArgumentException When the record is incomplete or inconsistent.
	 * @return void
	 */
	public function assert_valid( array $record ): void {
		$this->assert_keys(
			$record,
			array(
				'actor_user_id',
				'command_id',
				'correlation_id',
				'saved_at_utc',
				'idempotency_storage_key',
				'request_digest',
				'worksheet_public_id',
				'expected_state',
				'expected_revision_public_id',
				'revision_public_id',
				'audit_public_id',
				'outbox_public_id',
				'title',
				'locale',
				'document_id',
				'document_json',
				'document_hash',
				'validation_json',
				'validation_hash',
				'content_hash',
				'temporary_id_map',
				'questions',
				'worksheet_achievement_links',
				'question_achievement_links',
				'asset_bindings',
				'staged_asset_receipt_ids',
				'projection_json',
				'projection_hash',
			)
		);
		if ( ! is_int( $record['actor_user_id'] ) || $record['actor_user_id'] < 1 ) {
			throw new InvalidArgumentException( 'SaveWorksheetDraft persistence actor is invalid.' );
		}
		foreach (
			array(
				'command_id',
				'correlation_id',
				'saved_at_utc',
				'idempotency_storage_key',
				'request_digest',
				'worksheet_public_id',
				'expected_state',
				'expected_revision_public_id',
				'revision_public_id',
				'audit_public_id',
				'outbox_public_id',
				'title',
				'locale',
				'document_id',
				'document_json',
				'document_hash',
				'validation_json',
				'validation_hash',
				'content_hash',
				'projection_json',
				'projection_hash',
			) as $key
		) {
			if ( ! is_string( $record[ $key ] ) || '' === $record[ $key ] ) {
				throw new InvalidArgumentException( 'SaveWorksheetDraft persistence record is incomplete.' );
			}
		}
		foreach (
			array( 'worksheet_public_id', 'expected_revision_public_id', 'revision_public_id', 'audit_public_id', 'outbox_public_id', 'document_id' ) as $key
		) {
			new Public_Id( $record[ $key ] );
		}
		foreach (
			array( 'idempotency_storage_key', 'request_digest', 'document_hash', 'validation_hash', 'content_hash', 'projection_hash' ) as $key
		) {
			$this->assert_hash( $record[ $key ] );
		}
		if (
			! in_array( $record['expected_state'], $this->allowed_expected_states, true )
			|| 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $record['saved_at_utc'] )
			|| strlen( $record['document_json'] ) > Worksheet_Document_Profile::MAX_DOCUMENT_BYTES
		) {
			throw new InvalidArgumentException( 'SaveWorksheetDraft persistence precondition is invalid.' );
		}

		$document = $this->decode_json( $record['document_json'], $record['document_hash'] );
		if (
			Worksheet_Document_Profile::SCHEMA_ID !== ( $document['schema_id'] ?? null )
			|| Worksheet_Document_Profile::SCHEMA_VERSION !== ( $document['schema_version'] ?? null )
			|| Worksheet_Document_Profile::EDITOR_PROFILE !== ( $document['editor_profile'] ?? null )
			|| ( $document['document_id'] ?? null ) !== $record['document_id']
			|| ( $document['title'] ?? null ) !== $record['title']
			|| ( $document['locale'] ?? null ) !== $record['locale']
		) {
			throw new InvalidArgumentException( 'SaveWorksheetDraft document does not match its persistence record.' );
		}

		$validation = $this->decode_json( $record['validation_json'], $record['validation_hash'] );
		if (
			Worksheet_Document_Profile::VALIDATION_SCHEMA !== ( $validation['schema_id'] ?? null )
			|| Worksheet_Document_Profile::VALIDATION_VERSION !== ( $validation['schema_version'] ?? null )
			|| 'valid' !== ( $validation['status'] ?? null )
			|| array() !== ( $validation['errors'] ?? null )
		) {
			throw new InvalidArgumentException( 'SaveWorksheetDraft validation receipt is invalid.' );
		}

		if ( ! is_array( $record['staged_asset_receipt_ids'] ) || ! array_is_list( $record['staged_asset_receipt_ids'] ) ) {
			throw new InvalidArgumentException( 'SaveWorksheetDraft staged asset receipts are invalid.' );
		}

		foreach (
			array( 'questions', 'worksheet_achievement_links', 'question_achievement_links', 'asset_bindings' ) as $key
		) {
			if ( ! is_array( $record[ $key ] ) || ! array_is_list( $record[ $key ] ) ) {
				throw new InvalidArgumentException( 'SaveWorksheetDraft projection lists are invalid.' );
			}
		}
		$this->assert_temporary_id_map( $record['temporary_id_map'] );
		$questions = $this->assert_questions( $record['questions'] );
		$this->assert_worksheet_links( $record['worksheet_achievement_links'] );
		$this->assert_question_links( $record['question_achievement_links'], $questions );
		$this->assert_assets( $record['asset_bindings'] );
		$this->assert_staged_receipts( $record['staged_asset_receipt_ids'], $record['asset_bindings'] );
		$this->assert_manifest( $record );

		$expected_content_hash = $record['document_hash'];
		if ( array() !== $record['worksheet_achievement_links'] || array() !== $record['question_achievement_links'] ) {
			$expected_content_hash = hash(
				'sha256',
				$this->canonical->encode(
					array(
						'document_hash'                    => $record['document_hash'],
						'worksheet_achievement_link_hashes' => array_column( $record['worksheet_achievement_links'], 'content_hash' ),
						'question_achievement_link_hashes' => array_column( $record['question_achievement_links'], 'content_hash' ),
					)
				)
			);
		}
		if ( ! hash_equals( $expected_content_hash, $record['content_hash'] ) ) {
			throw new InvalidArgumentException( 'SaveWorksheetDraft aggregate content hash is invalid.' );
		}
	}

	/**
	 * Requires a lowercase SHA-256 digest.
	 *
	 * @param mixed $value Unvalidated digest.
	 * @throws InvalidArgumentException When the digest is malformed.
	 * @return void
	 */
	private function assert_hash( $value ): void {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $value ) ) {
			throw new InvalidArgumentException( 'SaveWorksheetDraft digest is invalid.' );
		}
	}

	/**
	 * Rejects malformed temporary IDs and duplicate stable targets.
	 *
	 * @param mixed $value Temporary ID map.
	 * @throws InvalidArgumentException When the map is malformed or ambiguous.
	 * @return void
	 */
	private function assert_temporary_id_map( $value ): void {
		if ( ! is_array( $value ) || ( array() !== $value && array_is_list( $value ) ) ) {
			throw new InvalidArgumentException( 'SaveWorksheetDraft temporary ID map is invalid.' );
		}
		$stable_ids = array();
		foreach ( $value as $temporary_id => $stable_id ) {
			if (
				! is_string( $temporary_id )
				|| 1 !== preg_match( '/^tmp_[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $temporary_id )
				|| ! is_string( $stable_id )
			) {
				throw new InvalidArgumentException( 'SaveWorksheetDraft temporary ID mapping is invalid.' );
			}
			new Public_Id( $stable_id );
			if ( isset( $stable_ids[ $stable_id ] ) ) {
				throw new InvalidArgumentException( 'SaveWorksheetDraft mapped public IDs must be unique.' );
			}
			$stable_ids[ $stable_id ] = true;
		}
	}

	/**
	 * Checks question identities, private metadata and content hashes.
	 *
	 * @param array<int,mixed> $questions Unvalidated question projections.
	 * @return array<string,string>
	 * @throws InvalidArgumentException When a question projection is inconsistent.
	 */
	private function assert_questions( array $questions ): array {
		$by_public_id  = array();
		$question_keys = array();
		foreach ( $questions as $position => $question ) {
			if ( ! is_array( $question ) || array_is_list( $question ) ) {
				throw new InvalidArgumentException( 'SaveWorksheetDraft question projection is invalid.' );
			}
			$this->assert_keys(
				$question,
				array(
					'public_id',
					'content_hash',
					'question_key',
					'position',
					'response_type',
					'question_schema',
					'question_version',
					'question',
					'question_json',
					'question_hash',
					'private_metadata_schema',
					'private_metadata_version',
					'private_metadata',
					'private_metadata_json',
					'private_metadata_hash',
				)
			);
			$public_id    = is_string( $question['public_id'] ) ? $question['public_id'] : '';
			$question_key = is_string( $question['question_key'] ) ? $question['question_key'] : '';
			new Public_Id( $public_id );
			new Public_Id( $question_key );
			if (
				isset( $by_public_id[ $public_id ] )
				|| isset( $question_keys[ $question_key ] )
				|| $position !== $question['position']
				|| ! in_array( $question['response_type'], Worksheet_Document_Profile::RESPONSE_KINDS, true )
				|| Worksheet_Revision_Projector::QUESTION_SCHEMA !== $question['question_schema']
				|| Worksheet_Revision_Projector::QUESTION_VERSION !== $question['question_version']
			) {
				throw new InvalidArgumentException( 'SaveWorksheetDraft question identity is invalid.' );
			}
			$this->assert_hash( $question['content_hash'] );
			$this->assert_hash( $question['question_hash'] );
			if (
				$this->canonical->encode( $this->decode_json( $question['question_json'], $question['question_hash'] ) )
				!== $this->canonical->encode( $question['question'] )
			) {
				throw new InvalidArgumentException( 'SaveWorksheetDraft question JSON does not match its projection.' );
			}

			$private_hash = null;
			if ( null === $question['private_metadata'] ) {
				if (
					null !== $question['private_metadata_schema']
					|| null !== $question['private_metadata_version']
					|| null !== $question['private_metadata_json']
					|| null !== $question['private_metadata_hash']
				) {
					throw new InvalidArgumentException( 'SaveWorksheetDraft private metadata is inconsistent.' );
				}
			} else {
				if (
					Worksheet_Revision_Projector::PRIVATE_METADATA_SCHEMA !== $question['private_metadata_schema']
					|| Worksheet_Revision_Projector::PRIVATE_METADATA_VERSION !== $question['private_metadata_version']
					|| ! is_string( $question['private_metadata_json'] )
				) {
					throw new InvalidArgumentException( 'SaveWorksheetDraft private metadata profile is invalid.' );
				}
				$this->assert_hash( $question['private_metadata_hash'] );
				if (
					$this->canonical->encode( $this->decode_json( $question['private_metadata_json'], $question['private_metadata_hash'] ) )
					!== $this->canonical->encode( $question['private_metadata'] )
				) {
					throw new InvalidArgumentException( 'SaveWorksheetDraft private metadata JSON does not match.' );
				}
				$private_hash = $question['private_metadata_hash'];
			}

			$expected_hash = hash(
				'sha256',
				$this->canonical->encode(
					array(
						'position'                 => $position,
						'question_schema'          => $question['question_schema'],
						'question_version'         => $question['question_version'],
						'private_metadata_hash'    => $private_hash,
						'private_metadata_schema'  => $question['private_metadata_schema'],
						'private_metadata_version' => $question['private_metadata_version'],
						'question_hash'            => $question['question_hash'],
						'question_key'             => $question_key,
						'response_type'            => $question['response_type'],
					)
				)
			);
			if ( ! hash_equals( $expected_hash, $question['content_hash'] ) ) {
				throw new InvalidArgumentException( 'SaveWorksheetDraft question content hash is invalid.' );
			}
			$by_public_id[ $public_id ]     = $question_key;
			$question_keys[ $question_key ] = true;
		}

		return $by_public_id;
	}

	/**
	 * Checks ordered worksheet-level achievement links.
	 *
	 * @param array<int,mixed> $links Unvalidated worksheet achievement links.
	 * @return void
	 */
	private function assert_worksheet_links( array $links ): void {
		foreach ( $links as $position => $link ) {
			$this->assert_link( $link, $position, 'worksheet', null );
		}
	}

	/**
	 * Checks that every question achievement link targets this revision.
	 *
	 * @param array<int,mixed>     $links Unvalidated question achievement links.
	 * @param array<string,string> $questions Validated question identities.
	 * @throws InvalidArgumentException When a link has an invalid target.
	 * @return void
	 */
	private function assert_question_links( array $links, array $questions ): void {
		$positions = array();
		foreach ( $links as $link ) {
			if ( ! is_array( $link ) || array_is_list( $link ) ) {
				throw new InvalidArgumentException( 'SaveWorksheetDraft question achievement link is invalid.' );
			}
			$question_id  = is_string( $link['question_public_id'] ?? null ) ? $link['question_public_id'] : '';
			$question_key = is_string( $link['question_key'] ?? null ) ? $link['question_key'] : '';
			if ( ! isset( $questions[ $question_id ] ) || $questions[ $question_id ] !== $question_key ) {
				throw new InvalidArgumentException( 'SaveWorksheetDraft question achievement target is invalid.' );
			}
			$position = $positions[ $question_id ] ?? 0;
			$this->assert_link( $link, $position, 'response', $question_key );
			$positions[ $question_id ] = $position + 1;
		}
	}

	/**
	 * Validates an achievement snapshot against its target and content hash.
	 *
	 * @param mixed       $value Unvalidated link projection.
	 * @param int         $position Expected zero-based position within the target.
	 * @param string      $scope Worksheet or response scope.
	 * @param string|null $question_key Expected response identity, if scoped.
	 * @throws InvalidArgumentException When the snapshot is inconsistent.
	 * @return void
	 */
	private function assert_link( $value, int $position, string $scope, ?string $question_key ): void {
		if ( ! is_array( $value ) || array_is_list( $value ) ) {
			throw new InvalidArgumentException( 'SaveWorksheetDraft achievement link projection is invalid.' );
		}
		$keys = array(
			'standard_public_id',
			'standard_source_version',
			'standard_code_snapshot',
			'standard_label_snapshot',
			'alignment_type',
			'position',
			'content_hash',
		);
		if ( null !== $question_key ) {
			$keys[] = 'question_public_id';
			$keys[] = 'question_key';
		}
		$this->assert_keys( $value, $keys );
		if ( ! is_string( $value['standard_public_id'] ) ) {
			throw new InvalidArgumentException( 'SaveWorksheetDraft standard public ID is invalid.' );
		}
		new Public_Id( $value['standard_public_id'] );
		if (
			$position !== $value['position']
			|| ! in_array( $value['alignment_type'], Worksheet_Revision_Projector::ALIGNMENT_TYPES, true )
		) {
			throw new InvalidArgumentException( 'SaveWorksheetDraft achievement link order is invalid.' );
		}
		foreach ( array( 'standard_source_version', 'standard_code_snapshot', 'standard_label_snapshot' ) as $key ) {
			if ( ! is_string( $value[ $key ] ) || '' === $value[ $key ] ) {
				throw new InvalidArgumentException( 'SaveWorksheetDraft achievement snapshot is invalid.' );
			}
		}
		$this->assert_hash( $value['content_hash'] );
		$hash_value = array(
			'scope'                   => $scope,
			'standard_public_id'      => $value['standard_public_id'],
			'standard_source_version' => $value['standard_source_version'],
			'standard_code_snapshot'  => $value['standard_code_snapshot'],
			'standard_label_snapshot' => $value['standard_label_snapshot'],
			'alignment_type'          => $value['alignment_type'],
		);
		if ( null !== $question_key ) {
			$hash_value['question_key'] = $question_key;
		}
		$hash_value['position'] = $position;
		if ( ! hash_equals( hash( 'sha256', $this->canonical->encode( $hash_value ) ), $value['content_hash'] ) ) {
			throw new InvalidArgumentException( 'SaveWorksheetDraft achievement link hash is invalid.' );
		}
	}

	/**
	 * Checks asset identities before they are used as receipt lookup keys.
	 *
	 * @param array<int,mixed> $bindings Unvalidated asset bindings.
	 * @throws InvalidArgumentException When a binding is malformed or inconsistent.
	 * @return void
	 */
	private function assert_assets( array $bindings ): void {
		$seen = array();
		foreach ( $bindings as $position => $binding ) {
			if ( ! is_array( $binding ) || array_is_list( $binding ) ) {
				throw new InvalidArgumentException( 'SaveWorksheetDraft asset binding is invalid.' );
			}
			$this->assert_keys( $binding, array( 'asset_id', 'position', 'references', 'content_hash' ) );
			$asset_id = is_string( $binding['asset_id'] ) ? $binding['asset_id'] : '';
			new Public_Id( $asset_id );
			if (
				isset( $seen[ $asset_id ] )
				|| $position !== $binding['position']
				|| ! is_array( $binding['references'] )
				|| ! array_is_list( $binding['references'] )
				|| array() === $binding['references']
			) {
				throw new InvalidArgumentException( 'SaveWorksheetDraft asset binding identity is invalid.' );
			}
			$this->assert_hash( $binding['content_hash'] );
			$hash_value = $binding;
			unset( $hash_value['content_hash'] );
			if ( ! hash_equals( hash( 'sha256', $this->canonical->encode( $hash_value ) ), $binding['content_hash'] ) ) {
				throw new InvalidArgumentException( 'SaveWorksheetDraft asset binding hash is invalid.' );
			}
			$seen[ $asset_id ] = true;
		}
	}

	/**
	 * Requires each staged receipt to refer to one validated bound asset.
	 *
	 * @param array<int,mixed>               $receipt_ids Unvalidated staged receipt identifiers.
	 * @param array<int,array<string,mixed>> $bindings Previously validated asset bindings.
	 * @throws InvalidArgumentException When a receipt is invalid, duplicated or unbound.
	 * @return void
	 */
	private function assert_staged_receipts( array $receipt_ids, array $bindings ): void {
		$assets = array_fill_keys( array_column( $bindings, 'asset_id' ), true );
		$seen   = array();
		foreach ( $receipt_ids as $receipt_id ) {
			if ( ! is_string( $receipt_id ) ) {
				throw new InvalidArgumentException( 'SaveWorksheetDraft staged asset receipt is invalid.' );
			}
			new Public_Id( $receipt_id );
			if ( isset( $seen[ $receipt_id ] ) || ! isset( $assets[ $receipt_id ] ) ) {
				throw new InvalidArgumentException( 'SaveWorksheetDraft staged asset receipt scope is invalid.' );
			}
			$seen[ $receipt_id ] = true;
		}
	}

	/**
	 * Verifies that the manifest covers exactly the validated projections.
	 *
	 * @param array<string,mixed> $record Persistence record with validated projections.
	 * @throws InvalidArgumentException When the manifest differs from its record.
	 * @return void
	 */
	private function assert_manifest( array $record ): void {
		$manifest        = $this->decode_json( $record['projection_json'], $record['projection_hash'] );
		$reference_count = 0;
		foreach ( $record['asset_bindings'] as $binding ) {
			$reference_count += count( $binding['references'] );
		}
		$expected = array(
			'schema_id'                         => Worksheet_Revision_Projector::PROJECTION_SCHEMA,
			'schema_version'                    => Worksheet_Revision_Projector::PROJECTION_VERSION,
			'source_document_hash'              => $record['document_hash'],
			'question_content_hashes'           => array_column( $record['questions'], 'content_hash' ),
			'worksheet_achievement_link_hashes' => array_column( $record['worksheet_achievement_links'], 'content_hash' ),
			'question_achievement_link_hashes'  => array_column( $record['question_achievement_links'], 'content_hash' ),
			'asset_binding_hashes'              => array_column( $record['asset_bindings'], 'content_hash' ),
			'statistics'                        => array(
				'question_count'                   => count( $record['questions'] ),
				'worksheet_achievement_link_count' => count( $record['worksheet_achievement_links'] ),
				'question_achievement_link_count'  => count( $record['question_achievement_links'] ),
				'asset_binding_count'              => count( $record['asset_bindings'] ),
				'asset_reference_count'            => $reference_count,
			),
		);
		if ( $this->canonical->encode( $manifest ) !== $this->canonical->encode( $expected ) ) {
			throw new InvalidArgumentException( 'SaveWorksheetDraft projection manifest is invalid.' );
		}
	}

	/**
	 * Decodes canonical JSON only after verifying its digest.
	 *
	 * @param string $json Serialized projection.
	 * @param string $expected_hash Expected SHA-256 digest.
	 * @return array<string,mixed>
	 * @throws InvalidArgumentException When the JSON, hash or serialization is invalid.
	 */
	private function decode_json( string $json, string $expected_hash ): array {
		$this->assert_hash( $expected_hash );
		if ( ! hash_equals( $expected_hash, hash( 'sha256', $json ) ) ) {
			throw new InvalidArgumentException( 'SaveWorksheetDraft JSON hash is invalid.' );
		}
		try {
			$value = json_decode( $json, true, 128, JSON_THROW_ON_ERROR );
		} catch ( JsonException ) {
			throw new InvalidArgumentException( 'SaveWorksheetDraft JSON is invalid.' );
		}
		if ( ! is_array( $value ) || ! hash_equals( $json, $this->canonical->encode( $value ) ) ) {
			throw new InvalidArgumentException( 'SaveWorksheetDraft JSON is not canonical.' );
		}

		return $value;
	}

	/**
	 * Rejects missing and unexpected persistence fields.
	 *
	 * @param array<string,mixed> $value Unvalidated record fields.
	 * @param array<int,string>   $expected Exact allowed key set.
	 * @throws InvalidArgumentException When the key set does not match.
	 * @return void
	 */
	private function assert_keys( array $value, array $expected ): void {
		$actual = array_keys( $value );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected ) {
			throw new InvalidArgumentException( 'SaveWorksheetDraft persistence fields are invalid.' );
		}
	}
}
