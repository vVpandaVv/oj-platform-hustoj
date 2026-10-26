<?php
/**
 * Worksheet-owned source reference reader for private assets.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Authoring\Infrastructure;

use JsonException;
use OJAI\Dashboard\Storage\Application\Worksheet_Asset_Failure;
use OJAI\Dashboard\Storage\Port\Worksheet_Asset_Source_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Port\Worksheet_Repository_Port_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps Worksheet and Publication SQL out of the Storage owner.
 */
final class WordPress_Worksheet_Asset_Source_Reader implements Worksheet_Asset_Source_Port_Interface {
	private const TABLE_WORKSHEETS   = 'oj_ai_worksheets';
	private const TABLE_PUBLICATIONS = 'oj_ai_worksheet_publications';

	/**
	 * Database.
	 *
	 * @var object|null
	 */
	private ?object $database;

	/**
	 * Initialize WordPress worksheet asset source reader dependencies and state.
	 *
	 * @param Worksheet_Repository_Port_Interface $worksheets Worksheets.
	 * @param object|null                         $database Database.
	 */
	public function __construct(
		private Worksheet_Repository_Port_Interface $worksheets,
		?object $database = null
	) {
		$this->database = $database;
	}

	/**
	 * Owned source references asset.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $source_type Source type.
	 * @param string $source_id Source id.
	 * @param string $asset_id Asset id.
	 * @return bool
	 */
	public function owned_source_references_asset(
		int $actor_user_id,
		string $source_type,
		string $source_id,
		string $asset_id
	): bool {
		if ( $actor_user_id < 1 ) {
			return false;
		}
		new Public_Id( $source_id );
		new Public_Id( $asset_id );

		if ( 'revision' === $source_type ) {
			$revision = $this->worksheets->find_owned_revision( $actor_user_id, $source_id );
			return is_array( $revision )
				&& $this->contains_asset( $revision['teacher_document'] ?? null, $asset_id );
		}
		if ( 'publication' !== $source_type ) {
			return false;
		}

		$row = $this->owned_publication( $actor_user_id, $source_id );
		if ( null === $row || ! in_array( (string) ( $row['state'] ?? '' ), array( 'published', 'retired' ), true ) ) {
			return false;
		}

		foreach ( array( 'student_document', 'print_projection' ) as $projection ) {
			$document = $this->decode_projection( $row, $projection );
			if ( $this->contains_asset( $document, $asset_id ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Owned publication.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $publication_id Publication id.
	 * @return array<string,mixed>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function owned_publication( int $actor_user_id, string $publication_id ): ?array {
		$sql      = 'SELECT p.state, p.student_document_json, p.student_document_hash, '
			. 'p.print_projection_json, p.print_projection_hash FROM ' . self::TABLE_PUBLICATIONS . ' p '
			. 'INNER JOIN ' . self::TABLE_WORKSHEETS . ' w ON w.id = p.worksheet_id '
			. 'WHERE p.public_id = %s AND w.owner_user_id = %d LIMIT 1';
		$database = $this->database();
		$this->reset_error( $database );
		$prepared = $database->prepare( $sql, $publication_id, $actor_user_id );
		if ( ! is_string( $prepared ) || '' === $prepared ) {
			throw Worksheet_Asset_Failure::storage_unavailable();
		}
		$row = $database->get_row( $prepared, $this->array_output() );
		$this->assert_database_ok( $database );
		if ( null === $row ) {
			return null;
		}
		if ( ! is_array( $row ) ) {
			throw Worksheet_Asset_Failure::storage_unavailable();
		}
		return $row;
	}

	/**
	 * Decode projection.
	 *
	 * @param array<string,mixed> $row Row.
	 * @param string              $name Name.
	 * @return mixed
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function decode_projection( array $row, string $name ) {
		$json = (string) ( $row[ $name . '_json' ] ?? '' );
		$hash = (string) ( $row[ $name . '_hash' ] ?? '' );
		if ( '' === $json || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $hash ) || ! hash_equals( $hash, hash( 'sha256', $json ) ) ) {
			throw Worksheet_Asset_Failure::storage_unavailable();
		}
		try {
			return json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
		} catch ( JsonException ) {
			throw Worksheet_Asset_Failure::storage_unavailable();
		}
	}

	/**
	 * Contains asset.
	 *
	 * @param mixed  $value Value.
	 * @param string $asset_id Asset id.
	 * @return bool
	 */
	private function contains_asset( $value, string $asset_id ): bool {
		if ( ! is_array( $value ) ) {
			return false;
		}
		foreach ( $value as $key => $child ) {
			if ( in_array( $key, array( 'asset_id', 'poster_asset_id' ), true ) && $asset_id === $child ) {
				return true;
			}
			if ( is_array( $child ) && $this->contains_asset( $child, $asset_id ) ) {
				return true;
			}
		}
		return false;
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
			throw Worksheet_Asset_Failure::storage_unavailable();
		}
		foreach ( array( 'prepare', 'get_row' ) as $method ) {
			if ( ! method_exists( $database, $method ) ) {
				throw Worksheet_Asset_Failure::storage_unavailable();
			}
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
			throw Worksheet_Asset_Failure::storage_unavailable();
		}
	}

	/**
	 * Array output.
	 *
	 * @return mixed
	 */
	private function array_output() {
		return defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A';
	}
}
