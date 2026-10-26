<?php
/**
 * WordPress exact worksheet print-source query.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Authoring\Infrastructure;

use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Application\Worksheet_Failure;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Port\Worksheet_Print_Source_Query_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Port\Worksheet_Publication_Print_Source_Query_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Port\Worksheet_Repository_Port_Interface;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps literal Worksheet/Publication SQL inside the Worksheet owner.
 */
final class WordPress_Worksheet_Print_Source_Query implements Worksheet_Print_Source_Query_Port_Interface, Worksheet_Publication_Print_Source_Query_Port_Interface {
	private const TABLE_WORKSHEETS   = 'oj_ai_worksheets';
	private const TABLE_REVISIONS    = 'oj_ai_worksheet_revisions';
	private const TABLE_PUBLICATIONS = 'oj_ai_worksheet_publications';

	/**
	 * Initialize WordPress worksheet print source query dependencies and state.
	 *
	 * @param Worksheet_Repository_Port_Interface $worksheets Worksheets.
	 * @param Canonical_Json                      $canonical Canonical JSON encoder used for stable digests.
	 * @param object|null                         $database Database.
	 */
	public function __construct(
		private Worksheet_Repository_Port_Interface $worksheets,
		private Canonical_Json $canonical,
		private ?object $database = null
	) {
	}

	/**
	 * Find owned.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $source_type Source type.
	 * @param string $source_public_id Source public id.
	 * @return array|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function find_owned(
		int $actor_user_id,
		string $source_type,
		string $source_public_id
	): ?array {
		if ( $actor_user_id < 1 || ! in_array( $source_type, array( 'revision', 'publication' ), true ) ) {
			throw new InvalidArgumentException( 'Worksheet print source query is invalid.' );
		}
		new Public_Id( $source_public_id );

		if ( 'revision' === $source_type ) {
			return $this->owned_revision( $actor_user_id, $source_public_id );
		}

		return $this->owned_publication( $actor_user_id, $source_public_id );
	}

	/**
	 * Find exact publication.
	 *
	 * @param string $publication_id Publication id.
	 * @param string $expected_publication_hash Expected publication hash.
	 * @return array<string,mixed>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function find_exact_publication( string $publication_id, string $expected_publication_hash ): ?array {
		new Public_Id( $publication_id );
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $expected_publication_hash ) ) {
			throw new InvalidArgumentException( 'Worksheet publication print hash is invalid.' );
		}
		$source = $this->publication( $publication_id, null );
		if ( null === $source ) {
			return null;
		}
		$current_hash = (string) ( $source['source_hash'] ?? '' );
		if ( ! hash_equals( $expected_publication_hash, $current_hash ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Failure::print_source_conflict( $expected_publication_hash, $current_hash );
		}
		return $source;
	}

	/**
	 * Owned revision.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $revision_id Revision id.
	 * @return array<string,mixed>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function owned_revision( int $actor_user_id, string $revision_id ): ?array {
		$source = $this->worksheets->find_owned_revision( $actor_user_id, $revision_id );
		if ( null === $source ) {
			return null;
		}
		$revision = $source['revision'] ?? null;
		$document = $source['teacher_document'] ?? null;
		if (
			! is_array( $revision ) || array_is_list( $revision )
			|| ! is_array( $document ) || array_is_list( $document )
			|| ! is_string( $source['worksheet_id'] ?? null )
		) {
			throw Worksheet_Failure::stored_data_invalid();
		}

		return array(
			'source_type'                 => 'revision',
			'source_public_id'            => $revision_id,
			'source_hash'                 => (string) ( $revision['document_hash'] ?? '' ),
			'worksheet_id'                => (string) $source['worksheet_id'],
			'revision_id'                 => (string) ( $revision['id'] ?? '' ),
			'revision_document_hash'      => (string) ( $revision['document_hash'] ?? '' ),
			'revision_validation_hash'    => (string) ( $revision['validation_hash'] ?? '' ),
			'publication_id'              => null,
			'publication_hash'            => null,
			'publication_state'           => null,
			'projection_manifest_version' => null,
			'teacher_document'            => $document,
			'print_envelope'              => null,
			'print_profile_hash'          => null,
		);
	}

	/**
	 * Owned publication.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $publication_id Publication id.
	 * @return array<string,mixed>|null
	 */
	private function owned_publication( int $actor_user_id, string $publication_id ): ?array {
		return $this->publication( $publication_id, $actor_user_id );
	}

	/**
	 * Publication.
	 *
	 * @param string   $publication_id Publication id.
	 * @param int|null $owner_user_id Owner user id.
	 * @return array<string,mixed>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function publication( string $publication_id, ?int $owner_user_id ): ?array {
		$owner_clause = null === $owner_user_id ? '' : ' AND w.owner_user_id = %d';
		$arguments    = null === $owner_user_id ? array( $publication_id ) : array( $publication_id, $owner_user_id );
		$sql          = 'SELECT p.public_id AS publication_public_id, p.content_hash, p.state AS publication_state, '
			. 'p.projection_manifest_version, p.publication_hash, p.print_projection_schema, '
			. 'p.print_projection_version, p.print_projection_json, p.print_projection_hash, '
			. 'r.public_id AS revision_public_id, r.document_hash AS revision_document_hash, '
			. 'r.validation_hash AS revision_validation_hash, w.public_id AS worksheet_public_id '
			. 'FROM ' . self::TABLE_PUBLICATIONS . ' p '
			. 'INNER JOIN ' . self::TABLE_REVISIONS . ' r ON r.id = p.worksheet_revision_id '
			. 'INNER JOIN ' . self::TABLE_WORKSHEETS . ' w ON w.id = p.worksheet_id '
			. 'WHERE p.public_id = %s' . $owner_clause . ' LIMIT 1';
		$database     = $this->database();
		$this->reset_error( $database );
		$prepared = $database->prepare( $sql, ...$arguments );
		if ( ! is_string( $prepared ) || '' === $prepared ) {
			throw Worksheet_Failure::storage_unavailable();
		}
		$row = $database->get_row( $prepared, defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A' );
		$this->assert_database_ok( $database );
		if ( null === $row ) {
			return null;
		}
		if ( ! is_array( $row ) || array_is_list( $row ) ) {
			throw Worksheet_Failure::storage_unavailable();
		}

		$publication_hash = (string) ( $row['publication_hash'] ?? '' );
		if (
			! hash_equals( $publication_id, (string) ( $row['publication_public_id'] ?? '' ) )
			|| ! $this->is_hash( $publication_hash )
			|| ! hash_equals( $publication_hash, (string) ( $row['content_hash'] ?? '' ) )
			|| 'oj_ai.worksheet_print' !== ( $row['print_projection_schema'] ?? null )
			|| 1 !== $this->positive_integer( $row['print_projection_version'] ?? null )
		) {
			throw Worksheet_Failure::stored_data_invalid();
		}
		$profile_hash = (string) ( $row['print_projection_hash'] ?? '' );
		$envelope     = $this->decode_profile(
			(string) ( $row['print_projection_json'] ?? '' ),
			$profile_hash
		);

		return array(
			'source_type'                 => 'publication',
			'source_public_id'            => $publication_id,
			'source_hash'                 => $publication_hash,
			'worksheet_id'                => (string) ( $row['worksheet_public_id'] ?? '' ),
			'revision_id'                 => (string) ( $row['revision_public_id'] ?? '' ),
			'revision_document_hash'      => (string) ( $row['revision_document_hash'] ?? '' ),
			'revision_validation_hash'    => (string) ( $row['revision_validation_hash'] ?? '' ),
			'publication_id'              => $publication_id,
			'publication_hash'            => $publication_hash,
			'publication_state'           => (string) ( $row['publication_state'] ?? '' ),
			'projection_manifest_version' => (string) ( $row['projection_manifest_version'] ?? '' ),
			'teacher_document'            => null,
			'print_envelope'              => $envelope,
			'print_profile_hash'          => $profile_hash,
		);
	}

	/**
	 * Decode profile.
	 *
	 * @param string $json Json.
	 * @param string $expected_hash Expected hash.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function decode_profile( string $json, string $expected_hash ): array {
		if ( '' === $json || ! $this->is_hash( $expected_hash ) || ! hash_equals( $expected_hash, hash( 'sha256', $json ) ) ) {
			throw Worksheet_Failure::stored_data_invalid();
		}
		try {
			$value = json_decode( $json, true, 128, JSON_THROW_ON_ERROR );
			if ( ! is_array( $value ) || array_is_list( $value ) ) {
				throw Worksheet_Failure::stored_data_invalid();
			}
			$canonical_json = $this->canonical->encode( $value );
		} catch ( Throwable ) {
			throw Worksheet_Failure::stored_data_invalid();
		}
		if ( ! hash_equals( $json, $canonical_json ) ) {
			throw Worksheet_Failure::stored_data_invalid();
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
	private function positive_integer( $value ): int {
		if ( is_int( $value ) && $value > 0 ) {
			return $value;
		}
		if ( is_string( $value ) && 1 === preg_match( '/^[1-9][0-9]*$/D', $value ) ) {
			return (int) $value;
		}
		throw Worksheet_Failure::stored_data_invalid();
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

	/**
	 * Database.
	 *
	 * @return object
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function database(): object {
		$database = $this->database ?? ( $GLOBALS['wpdb'] ?? null );
		if ( ! is_object( $database ) || ! method_exists( $database, 'prepare' ) || ! method_exists( $database, 'get_row' ) ) {
			throw Worksheet_Failure::storage_unavailable();
		}
		return $database;
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
		if ( property_exists( $database, 'last_error' ) && '' !== trim( (string) $database->last_error ) ) {
			throw Worksheet_Failure::storage_unavailable();
		}
	}
}
