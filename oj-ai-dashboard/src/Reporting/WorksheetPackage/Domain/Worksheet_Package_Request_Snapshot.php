<?php
/**
 * Worksheet package request snapshot.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\WorksheetPackage\Domain;

use InvalidArgumentException;
use JsonException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Profile;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Worksheet package request snapshot.
 */
final class Worksheet_Package_Request_Snapshot {
	public const SCHEMA             = 'oj_ai.worksheet_package_request';
	public const DATABASE_VERSION   = 1;
	public const VERSION            = 'worksheet_package_request.v1';
	public const FORMAT             = 'oj_ai_worksheet_package';
	public const FORMAT_VERSION     = 1;
	public const PAYLOAD_SCHEMA     = 'oj_ai.worksheet_package_payload';
	public const EXPORTER_VERSION   = 'oj-ai-dashboard-0.1.2';
	public const PROFILE_VERSION    = 'worksheet_package.v1';
	public const MAX_SNAPSHOT_BYTES = 4 * 1024 * 1024;
	public const MAX_PACKAGE_BYTES  = 220 * 1024 * 1024;
	public const MAX_ASSETS         = 100;
	public const MAX_ASSET_BYTES    = 20 * 1024 * 1024;

	/**
	 * Initialize worksheet package request snapshot dependencies and state.
	 *
	 * @param array<string,mixed> $value Value.
	 * @param string              $json Json.
	 * @param string              $hash Hash.
	 */
	private function __construct(
		private array $value,
		private string $json,
		private string $hash
	) {
	}

	/**
	 * Freeze.
	 *
	 * @param array<string,mixed>            $source Source.
	 * @param array<int,array<string,mixed>> $assets Assets.
	 * @param Canonical_Json                 $canonical Canonical JSON encoder used for stable digests.
	 * @return self
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public static function freeze( array $source, array $assets, Canonical_Json $canonical ): self {
		$document = $source['teacher_document'] ?? null;
		$links    = $source['achievement_links'] ?? null;
		if ( ! is_array( $document ) || array_is_list( $document ) || ! is_array( $links ) || ! array_is_list( $links ) ) {
			throw new InvalidArgumentException( 'Worksheet package source is invalid.' );
		}
		$value = array(
			'contract_version' => self::VERSION,
			'package'          => array(
				'achievement_links' => $links,
				'assets'            => $assets,
				'document_schema'   => Worksheet_Document_Profile::SCHEMA_ID,
				'editor_profile'    => Worksheet_Document_Profile::EDITOR_PROFILE,
				'exporter_version'  => self::EXPORTER_VERSION,
				'format'            => self::FORMAT,
				'format_version'    => self::FORMAT_VERSION,
				'teacher_document'  => $document,
			),
			'schema_id'        => self::SCHEMA,
			'schema_version'   => self::DATABASE_VERSION,
			'source'           => array(
				'revision_id'  => (string) ( $source['revision_id'] ?? '' ),
				'source_hash'  => (string) ( $source['source_hash'] ?? '' ),
				'source_state' => (string) ( $source['source_state'] ?? '' ),
			),
		);
		self::assert_value( $value, $canonical );
		$json = $canonical->encode( $value );
		if ( strlen( $json ) > self::MAX_SNAPSHOT_BYTES ) {
			throw new InvalidArgumentException( 'Worksheet package request exceeds the snapshot limit.' );
		}
		return new self( $value, $json, hash( 'sha256', $json ) );
	}

	/**
	 * Restore.
	 *
	 * @param string         $json Json.
	 * @param string         $hash Hash.
	 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
	 * @return self
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public static function restore( string $json, string $hash, Canonical_Json $canonical ): self {
		if ( strlen( $json ) < 2 || strlen( $json ) > self::MAX_SNAPSHOT_BYTES || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $hash )
			|| ! hash_equals( $hash, hash( 'sha256', $json ) ) ) {
			throw new InvalidArgumentException( 'Worksheet package snapshot digest is invalid.' );
		}
		try {
			$value = json_decode( $json, true, 128, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING ); } catch ( JsonException ) {
			throw new InvalidArgumentException( 'Worksheet package snapshot JSON is invalid.' );
			}
			if ( ! is_array( $value ) || array_is_list( $value ) || ! hash_equals( $json, $canonical->encode( $value ) ) ) {
				throw new InvalidArgumentException( 'Worksheet package snapshot is not canonical.' );
			}
			self::assert_value( $value, $canonical );
			return new self( $value, $json, $hash );
	}

	/**
	 * Value.
	 *
	 * @return array<string,mixed>
	 */
	public function value(): array {
		return $this->value;
	}
	/**
	 * Json.
	 *
	 * @return string
	 */
	public function json(): string {
		return $this->json;
	}
	/**
	 * Hash.
	 *
	 * @return string
	 */
	public function hash(): string {
		return $this->hash;
	}
	/**
	 * Source id.
	 *
	 * @return string
	 */
	public function source_id(): string {
		return (string) $this->value['source']['revision_id'];
	}
	/**
	 * Source hash.
	 *
	 * @return string
	 */
	public function source_hash(): string {
		return (string) $this->value['source']['source_hash'];
	}
	/**
	 * Source state.
	 *
	 * @return string
	 */
	public function source_state(): string {
		return (string) $this->value['source']['source_state'];
	}
	/**
	 * Teacher document.
	 *
	 * @return array<string,mixed>
	 */
	public function teacher_document(): array {
		return $this->value['package']['teacher_document'];
	}
	/**
	 * Achievement links.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function achievement_links(): array {
		return $this->value['package']['achievement_links'];
	}
	/**
	 * Assets.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function assets(): array {
		return $this->value['package']['assets'];
	}

	/**
	 * Reject invalid value.
	 *
	 * @param array<string,mixed> $value Value.
	 * @param Canonical_Json      $canonical Canonical JSON encoder used for stable digests.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private static function assert_value( array $value, Canonical_Json $canonical ): void {
		self::exact( $value, array( 'contract_version', 'package', 'schema_id', 'schema_version', 'source' ) );
		if ( self::VERSION !== ( $value['contract_version'] ?? null ) || self::SCHEMA !== ( $value['schema_id'] ?? null )
			|| self::DATABASE_VERSION !== ( $value['schema_version'] ?? null ) ) {
			throw new InvalidArgumentException( 'Worksheet package snapshot version is unsupported.' );
		}
		$source  = $value['source'] ?? null;
		$package = $value['package'] ?? null;
		if ( ! is_array( $source ) || array_is_list( $source ) || ! is_array( $package ) || array_is_list( $package ) ) {
			throw new InvalidArgumentException( 'Worksheet package snapshot sections are invalid.' );
		}
		self::exact( $source, array( 'revision_id', 'source_hash', 'source_state' ) );
		new Public_Id( is_string( $source['revision_id'] ?? null ) ? $source['revision_id'] : '' );
		if ( ! is_string( $source['source_hash'] ?? null ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $source['source_hash'] )
			|| ! in_array( $source['source_state'] ?? null, array( 'draft', 'approved' ), true ) ) {
			throw new InvalidArgumentException( 'Worksheet package source identity is invalid.' );
		}
		self::exact( $package, array( 'achievement_links', 'assets', 'document_schema', 'editor_profile', 'exporter_version', 'format', 'format_version', 'teacher_document' ) );
		if ( self::FORMAT !== ( $package['format'] ?? null ) || self::FORMAT_VERSION !== ( $package['format_version'] ?? null )
			|| self::EXPORTER_VERSION !== ( $package['exporter_version'] ?? null )
			|| Worksheet_Document_Profile::SCHEMA_ID !== ( $package['document_schema'] ?? null )
			|| Worksheet_Document_Profile::EDITOR_PROFILE !== ( $package['editor_profile'] ?? null ) ) {
			throw new InvalidArgumentException( 'Worksheet package format is invalid.' );
		}
		$document = $package['teacher_document'] ?? null;
		$links    = $package['achievement_links'] ?? null;
		$assets   = $package['assets'] ?? null;
		if ( ! is_array( $document ) || array_is_list( $document ) || ! is_array( $links ) || ! array_is_list( $links )
			|| ! is_array( $assets ) || ! array_is_list( $assets ) || count( $assets ) > self::MAX_ASSETS ) {
			throw new InvalidArgumentException( 'Worksheet package content is invalid.' );
		}
		if ( ( $document['schema_id'] ?? null ) !== $package['document_schema'] || ( $document['editor_profile'] ?? null ) !== $package['editor_profile']
			|| ! hash_equals( $source['source_hash'], hash( 'sha256', $canonical->encode( $document ) ) ) ) {
			throw new InvalidArgumentException( 'Worksheet package document hash is invalid.' );
		}
		$last_path = '';
		$ids       = array();
		$paths     = array();
		$total     = 0;
		foreach ( $assets as $asset ) {
			if ( ! is_array( $asset ) || array_is_list( $asset ) ) {
				throw new InvalidArgumentException( 'Worksheet package asset is invalid.' );
			}
			self::exact( $asset, array( 'byte_size', 'extension', 'mime_type', 'original_name', 'path', 'sha256', 'source_asset_id' ) );
			$id = is_string( $asset['source_asset_id'] ?? null ) ? $asset['source_asset_id'] : '';
			new Public_Id( $id );
			$path      = is_string( $asset['path'] ?? null ) ? $asset['path'] : '';
			$hash      = is_string( $asset['sha256'] ?? null ) ? $asset['sha256'] : '';
			$extension = is_string( $asset['extension'] ?? null ) ? $asset['extension'] : '';
			$bytes     = $asset['byte_size'] ?? null;
			if ( isset( $ids[ $id ] ) || isset( $paths[ $path ] ) || ( '' !== $last_path && strcmp( $last_path, $path ) >= 0 )
				|| 1 !== preg_match( '/^[a-z0-9]{2,5}$/D', $extension )
				|| ! hash_equals( 'assets/' . $hash . '.' . $extension, $path )
				|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $hash ) || ! is_int( $bytes ) || $bytes < 1 || $bytes > self::MAX_ASSET_BYTES
				|| ! is_string( $asset['mime_type'] ?? null ) || '' === $asset['mime_type'] || strlen( $asset['mime_type'] ) > 191
				|| ! is_string( $asset['original_name'] ?? null ) || '' === $asset['original_name'] || strlen( $asset['original_name'] ) > 255 ) {
				throw new InvalidArgumentException( 'Worksheet package asset manifest is invalid.' );
			}
			$ids[ $id ]     = true;
			$paths[ $path ] = true;
			$last_path      = $path;
			$total         += $bytes;
		}
		if ( $total > self::MAX_PACKAGE_BYTES ) {
			throw new InvalidArgumentException( 'Worksheet package assets exceed the package limit.' );
		}
	}

	/**
	 * Exact.
	 *
	 * @param array<string,mixed> $value Value.
	 * @param array<int,string>   $expected Expected.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private static function exact( array $value, array $expected ): void {
		$actual = array_keys( $value );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected ) {
			throw new InvalidArgumentException( 'Worksheet package snapshot shape is invalid.' );
		}
	}
}
