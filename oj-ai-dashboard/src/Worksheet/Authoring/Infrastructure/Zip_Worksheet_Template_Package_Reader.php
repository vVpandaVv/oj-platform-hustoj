<?php
/**
 * Safe .ojws package reader.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Authoring\Infrastructure;

use InvalidArgumentException;
use JsonException;
use OJAI\Dashboard\Storage\Domain\Worksheet_Asset_Upload_Profile;
use OJAI\Dashboard\Worksheet\Authoring\Application\Worksheet_Template_Import_Failure;
use OJAI\Dashboard\Worksheet\Authoring\Application\Worksheet_Template_Package;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Document_Profile;
use OJAI\Dashboard\Worksheet\Authoring\Port\Worksheet_Template_Package_Reader_Port_Interface;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads only declared entries and never extracts a caller-controlled path.
 */
final class Zip_Worksheet_Template_Package_Reader implements Worksheet_Template_Package_Reader_Port_Interface {
	public const PACKAGE_MIME           = 'application/vnd.oj.ai-worksheet+zip';
	public const PAYLOAD_SCHEMA         = 'oj_ai.worksheet_package_payload';
	public const FORMAT                 = 'oj_ai_worksheet_package';
	public const FORMAT_VERSION         = 1;
	public const MAX_PACKAGE_BYTES      = 220 * 1024 * 1024;
	public const MAX_JSON_BYTES         = 2 * 1024 * 1024;
	public const MAX_MANIFEST_BYTES     = 256 * 1024;
	public const MAX_ASSETS             = 100;
	public const MAX_ENTRIES            = 102;
	private const MAX_COMPRESSION_RATIO = 100;

	/**
	 * Initialize zip worksheet template package reader dependencies and state.
	 *
	 * @param Canonical_Json                 $canonical Canonical JSON encoder used for stable digests.
	 * @param Worksheet_Asset_Upload_Profile $asset_profile Asset profile.
	 */
	public function __construct(
		private Canonical_Json $canonical,
		private Worksheet_Asset_Upload_Profile $asset_profile
	) {
	}

	/**
	 * Read zip worksheet template package reader.
	 *
	 * @param array $upload Upload.
	 * @return Worksheet_Template_Package
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function read( array $upload ): Worksheet_Template_Package {
		$path = $this->upload_path( $upload );
		if ( ! class_exists( '\\ZipArchive' ) ) {
			throw Worksheet_Template_Import_Failure::environment_unsupported();
		}

		$zip        = new \ZipArchive();
		$opened     = false;
		$temp_dir   = null;
		$temp_files = array();
		try {
			if ( true !== $zip->open( $path, \ZipArchive::CHECKCONS ) ) {
				throw Worksheet_Template_Import_Failure::package_invalid();
			}
			$opened         = true;
			$entries        = $this->scan_entries( $zip );
			$manifest_entry = $this->json_entry( $zip, $entries, 'manifest.json', self::MAX_MANIFEST_BYTES );
			$manifest       = $this->manifest( $manifest_entry['value'] );
			$payload_entry  = $this->json_entry( $zip, $entries, 'worksheet.json', self::MAX_JSON_BYTES );
			if ( ! hash_equals( $manifest['worksheet_sha256'], hash( 'sha256', $payload_entry['raw'] ) ) ) {
				throw Worksheet_Template_Import_Failure::package_invalid();
			}
			$payload = $this->payload( $payload_entry['value'], $manifest );
			$this->assert_exact_entries( $entries, $manifest['assets'] );

			$assets = array();
			if ( array() !== $manifest['assets'] ) {
				$temp_dir = $this->create_temp_directory();
			}
			foreach ( $manifest['assets'] as $position => $asset ) {
				$temp_path = $temp_dir . DIRECTORY_SEPARATOR . 'asset-' . str_pad( (string) $position, 3, '0', STR_PAD_LEFT ) . '.bin';
				$this->copy_entry( $zip, $asset['path'], $temp_path, $asset['byte_size'] );
				$temp_files[]   = $temp_path;
				$inspected      = $this->asset_profile->inspect(
					array(
						'name'     => $asset['original_name'],
						'type'     => $asset['mime_type'],
						'tmp_name' => $temp_path,
						'error'    => UPLOAD_ERR_OK,
						'size'     => $asset['byte_size'],
					)
				);
				$path_extension = strtolower( (string) pathinfo( $asset['path'], PATHINFO_EXTENSION ) );
				if (
					! hash_equals( $asset['sha256'], $inspected['sha256'] )
					|| $asset['byte_size'] !== $inspected['byte_size']
					|| ! hash_equals( $asset['mime_type'], $inspected['mime_type'] )
					|| ! hash_equals( $path_extension, $inspected['extension'] )
				) {
					throw Worksheet_Template_Import_Failure::package_invalid();
				}
				$assets[] = array(
					'source_asset_id' => $asset['source_asset_id'],
					'original_name'   => $inspected['original_name'],
					'mime_type'       => $inspected['mime_type'],
					'byte_size'       => $inspected['byte_size'],
					'sha256'          => $inspected['sha256'],
					'tmp_name'        => $temp_path,
				);
			}

			$zip->close();
			$opened  = false;
			$release = static function () use ( $temp_files, $temp_dir ): void {
				foreach ( $temp_files as $temp_file ) {
					if ( is_file( $temp_file ) ) {
						@unlink( $temp_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort cleanup of an owned temporary path must not replace the primary outcome with an OS warning. Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
					}
				}
				if ( is_string( $temp_dir ) && is_dir( $temp_dir ) ) {
					@rmdir( $temp_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Best-effort cleanup of an owned temporary path must not replace the primary outcome with an OS warning. Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
				}
			};

			$package_hash = hash_file( 'sha256', $path );
			if ( false === $package_hash ) {
				throw Worksheet_Template_Import_Failure::package_invalid();
			}
			return new Worksheet_Template_Package(
				$package_hash,
				$manifest['source_revision_id'],
				$manifest['source_revision_hash'],
				$payload['teacher_document'],
				$payload['achievement_links'],
				$assets,
				$release
			);
		} catch ( Worksheet_Template_Import_Failure $failure ) {
			if ( $opened ) {
				$zip->close();
			}
			$this->cleanup( $temp_files, $temp_dir );
			throw $failure;
		} catch ( Throwable ) {
			if ( $opened ) {
				$zip->close();
			}
			$this->cleanup( $temp_files, $temp_dir );
			throw Worksheet_Template_Import_Failure::package_invalid();
		}
	}

	/**
	 * Upload path.
	 *
	 * @param array<string,mixed> $upload Upload.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function upload_path( array $upload ): string {
		if ( UPLOAD_ERR_OK !== ( $upload['error'] ?? null ) ) {
			throw Worksheet_Template_Import_Failure::invalid_upload();
		}
		$name = str_replace( '\\', '/', trim( (string) ( $upload['name'] ?? '' ) ) );
		$name = basename( $name );
		$path = (string) ( $upload['tmp_name'] ?? '' );
		if (
			'' === $name || ! str_ends_with( strtolower( $name ), '.ojws' )
			|| strlen( $name ) > 255 || 1 === preg_match( '/[\x00-\x1F\x7F]/', $name )
			|| '' === $path || ! is_file( $path ) || ! is_readable( $path ) || is_link( $path )
		) {
			throw Worksheet_Template_Import_Failure::invalid_upload();
		}
		$size = filesize( $path );
		if ( ! is_int( $size ) || $size < 1 || (int) ( $upload['size'] ?? -1 ) !== $size ) {
			throw Worksheet_Template_Import_Failure::invalid_upload();
		}
		if ( $size > self::MAX_PACKAGE_BYTES ) {
			throw Worksheet_Template_Import_Failure::limit_exceeded();
		}
		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
		$prefix = false === $handle ? false : fread( $handle, 4 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
		if ( is_resource( $handle ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
		}
		if ( "PK\x03\x04" !== $prefix ) {
			throw Worksheet_Template_Import_Failure::package_invalid();
		}
		return $path;
	}

	/**
	 * Scan entries.
	 *
	 * @param \ZipArchive $zip Zip.
	 * @return array<string,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function scan_entries( \ZipArchive $zip ): array {
		if ( $zip->numFiles < 2 || $zip->numFiles > self::MAX_ENTRIES ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- This property spelling belongs to an external PHP, WordPress or provider contract.
			throw Worksheet_Template_Import_Failure::limit_exceeded();
		}
		$entries = array();
		$total   = 0;
		for ( $index = 0; $index < $zip->numFiles; ++$index ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- This property spelling belongs to an external PHP, WordPress or provider contract.
			$name = $zip->getNameIndex( $index );
			$stat = $zip->statIndex( $index );
			if ( ! is_string( $name ) || ! is_array( $stat ) || $this->unsafe_entry_name( $name ) || isset( $entries[ $name ] ) ) {
				throw Worksheet_Template_Import_Failure::package_invalid();
			}
			$size       = $stat['size'];
			$compressed = $stat['comp_size'];
			if ( $size < 1 || $compressed < 1 ) {
				throw Worksheet_Template_Import_Failure::package_invalid();
			}
			if ( $size > Worksheet_Asset_Upload_Profile::MAX_FILE_BYTES || $size > $compressed * self::MAX_COMPRESSION_RATIO ) {
				throw Worksheet_Template_Import_Failure::limit_exceeded();
			}
			$total += $size;
			if ( $total > self::MAX_PACKAGE_BYTES ) {
				throw Worksheet_Template_Import_Failure::limit_exceeded();
			}
			if ( 0 !== ( (int) ( $stat['encryption_method'] ) ) || $this->is_symlink( $zip, $index ) ) {
				throw Worksheet_Template_Import_Failure::package_invalid();
			}
			$entries[ $name ] = $stat;
		}
		return $entries;
	}

	/**
	 * Unsafe entry name.
	 *
	 * @param string $name Name.
	 * @return bool
	 */
	private function unsafe_entry_name( string $name ): bool {
		return '' === $name
			|| strlen( $name ) > 512
			|| str_contains( $name, "\0" )
			|| str_contains( $name, '\\' )
			|| str_starts_with( $name, '/' )
			|| str_ends_with( $name, '/' )
			|| 1 === preg_match( '/^[A-Za-z]:\//D', $name )
			|| in_array( '..', explode( '/', $name ), true )
			|| in_array( '.', explode( '/', $name ), true )
			|| in_array( '', explode( '/', $name ), true );
	}

	/**
	 * Is symlink.
	 *
	 * @param \ZipArchive $zip Zip.
	 * @param int         $index Index.
	 * @return bool
	 */
	private function is_symlink( \ZipArchive $zip, int $index ): bool {
		$operations = 0;
		$attributes = 0;
		if ( ! $zip->getExternalAttributesIndex( $index, $operations, $attributes ) ) {
			return false;
		}
		$type = ( $attributes >> 16 ) & 0xF000;
		return 0 !== $type && 0x8000 !== $type;
	}

	/**
	 * Json entry.
	 *
	 * @param \ZipArchive                       $zip Zip.
	 * @param array<string,array<string,mixed>> $entries Entries.
	 * @param string                            $name Name.
	 * @param int                               $maximum Maximum.
	 * @return array{raw:string,value:array<string,mixed>}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function json_entry( \ZipArchive $zip, array $entries, string $name, int $maximum ): array {
		$stat = $entries[ $name ] ?? null;
		if ( ! is_array( $stat ) || (int) $stat['size'] > $maximum ) {
			throw Worksheet_Template_Import_Failure::limit_exceeded();
		}
		$raw = $zip->getFromName( $name, $maximum + 1 );
		if ( ! is_string( $raw ) || strlen( $raw ) !== (int) $stat['size'] || str_starts_with( $raw, "\xEF\xBB\xBF" ) ) {
			throw Worksheet_Template_Import_Failure::package_invalid();
		}
		try {
			$value = json_decode( $raw, true, 128, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING );
		} catch ( JsonException ) {
			throw Worksheet_Template_Import_Failure::package_invalid();
		}
		if ( ! is_array( $value ) || array_is_list( $value ) ) {
			throw Worksheet_Template_Import_Failure::package_invalid();
		}
		try {
			if ( ! hash_equals( $raw, $this->canonical->encode( $value ) ) ) {
				throw Worksheet_Template_Import_Failure::package_invalid();
			}
		} catch ( InvalidArgumentException ) {
			throw Worksheet_Template_Import_Failure::package_invalid();
		}
		return array(
			'raw'   => $raw,
			'value' => $value,
		);
	}

	/**
	 * Manifest.
	 *
	 * @param array<string,mixed> $value Value.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function manifest( array $value ): array {
		$this->assert_exact_keys(
			$value,
			array(
				'assets',
				'document_schema',
				'editor_profile',
				'exporter_version',
				'format',
				'format_version',
				'source_revision_hash',
				'source_revision_id',
				'worksheet_sha256',
			)
		);
		if ( self::FORMAT !== ( $value['format'] ?? null ) ) {
			throw Worksheet_Template_Import_Failure::package_invalid();
		}
		if ( self::FORMAT_VERSION !== ( $value['format_version'] ?? null ) ) {
			throw Worksheet_Template_Import_Failure::version_unsupported();
		}
		if (
			Worksheet_Document_Profile::SCHEMA_ID !== ( $value['document_schema'] ?? null )
			|| Worksheet_Document_Profile::EDITOR_PROFILE !== ( $value['editor_profile'] ?? null )
			|| ! is_string( $value['exporter_version'] ?? null )
			|| 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$/D', $value['exporter_version'] )
		) {
			throw Worksheet_Template_Import_Failure::version_unsupported();
		}
		$source_id = is_string( $value['source_revision_id'] ?? null ) ? $value['source_revision_id'] : '';
		new Public_Id( $source_id );
		foreach ( array( 'source_revision_hash', 'worksheet_sha256' ) as $key ) {
			if ( ! is_string( $value[ $key ] ?? null ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $value[ $key ] ) ) {
				throw Worksheet_Template_Import_Failure::package_invalid();
			}
		}
		$assets = $this->manifest_assets( $value['assets'] ?? null );
		return array_replace(
			$value,
			array(
				'source_revision_id' => $source_id,
				'assets'             => $assets,
			)
		);
	}

	/**
	 * Manifest assets.
	 *
	 * @param mixed $value Value.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function manifest_assets( $value ): array {
		if ( ! is_array( $value ) || ! array_is_list( $value ) || count( $value ) > self::MAX_ASSETS ) {
			throw Worksheet_Template_Import_Failure::limit_exceeded();
		}
		$result     = array();
		$seen_ids   = array();
		$seen_paths = array();
		$last_path  = '';
		foreach ( $value as $asset ) {
			if ( ! is_array( $asset ) || array_is_list( $asset ) ) {
				throw Worksheet_Template_Import_Failure::package_invalid();
			}
			$this->assert_exact_keys( $asset, array( 'byte_size', 'mime_type', 'original_name', 'path', 'sha256', 'source_asset_id' ) );
			$source_id = is_string( $asset['source_asset_id'] ?? null ) ? $asset['source_asset_id'] : '';
			new Public_Id( $source_id );
			$path   = is_string( $asset['path'] ?? null ) ? $asset['path'] : '';
			$sha256 = is_string( $asset['sha256'] ?? null ) ? $asset['sha256'] : '';
			if (
				1 !== preg_match( '/^assets\/([a-f0-9]{64})\.([a-z0-9]{2,5})$/D', $path, $matches )
				|| ! hash_equals( $sha256, $matches[1] )
				|| isset( $seen_ids[ $source_id ] ) || isset( $seen_paths[ $path ] )
				|| ( '' !== $last_path && strcmp( $last_path, $path ) >= 0 )
				|| ! is_string( $asset['original_name'] ?? null ) || '' === $asset['original_name']
				|| ! is_string( $asset['mime_type'] ?? null ) || '' === $asset['mime_type']
				|| ! is_int( $asset['byte_size'] ?? null ) || $asset['byte_size'] < 1
				|| $asset['byte_size'] > Worksheet_Asset_Upload_Profile::MAX_FILE_BYTES
			) {
				throw Worksheet_Template_Import_Failure::package_invalid();
			}
			$seen_ids[ $source_id ] = true;
			$seen_paths[ $path ]    = true;
			$last_path              = $path;
			$result[]               = array_replace(
				$asset,
				array(
					'source_asset_id' => $source_id,
					'path'            => $path,
					'sha256'          => $sha256,
				)
			);
		}
		return $result;
	}

	/**
	 * Payload.
	 *
	 * @param array<string,mixed> $value Value.
	 * @param array<string,mixed> $manifest Manifest.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function payload( array $value, array $manifest ): array {
		$this->assert_exact_keys( $value, array( 'achievement_links', 'schema_id', 'schema_version', 'teacher_document' ) );
		if ( self::PAYLOAD_SCHEMA !== ( $value['schema_id'] ?? null ) || self::FORMAT_VERSION !== ( $value['schema_version'] ?? null ) ) {
			throw Worksheet_Template_Import_Failure::version_unsupported();
		}
		$document = $value['teacher_document'] ?? null;
		$links    = $value['achievement_links'] ?? null;
		if ( ! is_array( $document ) || array_is_list( $document ) || ! is_array( $links ) || ! array_is_list( $links ) ) {
			throw Worksheet_Template_Import_Failure::package_invalid();
		}
		if (
			( $document['schema_id'] ?? null ) !== $manifest['document_schema']
			|| ( $document['editor_profile'] ?? null ) !== $manifest['editor_profile']
			|| ! hash_equals( $manifest['source_revision_hash'], hash( 'sha256', $this->canonical->encode( $document ) ) )
		) {
			throw Worksheet_Template_Import_Failure::package_invalid();
		}
		return array(
			'teacher_document'  => $document,
			'achievement_links' => $links,
		);
	}

	/**
	 * Reject invalid exact entries.
	 *
	 * @param array<string,array<string,mixed>> $entries Entries.
	 * @param array<int,array<string,mixed>>    $assets Assets.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_exact_entries( array $entries, array $assets ): void {
		$expected = array_merge( array( 'manifest.json', 'worksheet.json' ), array_column( $assets, 'path' ) );
		$actual   = array_keys( $entries );
		sort( $expected, SORT_STRING );
		sort( $actual, SORT_STRING );
		if ( $expected !== $actual ) {
			throw Worksheet_Template_Import_Failure::package_invalid();
		}
	}

	/**
	 * Create temp directory.
	 *
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function create_temp_directory(): string {
		try {
			$path = rtrim( sys_get_temp_dir(), '/\\' ) . DIRECTORY_SEPARATOR . 'oj-ai-import-' . bin2hex( random_bytes( 12 ) );
		} catch ( Throwable ) {
			throw Worksheet_Template_Import_Failure::storage_unavailable();
		}
		if ( ! @mkdir( $path, 0700 ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Native warnings can expose private paths; the failure return is checked and mapped to a stable error. Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
			throw Worksheet_Template_Import_Failure::storage_unavailable();
		}
		return $path;
	}

	/**
	 * Copy entry.
	 *
	 * @param \ZipArchive $zip Zip.
	 * @param string      $name Name.
	 * @param string      $destination Destination.
	 * @param int         $expected_size Expected size.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function copy_entry( \ZipArchive $zip, string $name, string $destination, int $expected_size ): void {
		$input  = $zip->getStream( $name );
		$output = fopen( $destination, 'xb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
		if ( false === $input || false === $output ) {
			if ( is_resource( $input ) ) {
				fclose( $input ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
			}
			if ( is_resource( $output ) ) {
				fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
			}
			if ( is_file( $destination ) ) {
				@unlink( $destination ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort cleanup of an owned temporary path must not replace the primary outcome with an OS warning. Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
			}
			throw Worksheet_Template_Import_Failure::storage_unavailable();
		}
		$copied        = stream_copy_to_stream( $input, $output, $expected_size + 1 );
		$input_closed  = fclose( $input ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
		$output_closed = fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
		if ( $copied !== $expected_size || ! $input_closed || ! $output_closed || ! @chmod( $destination, 0600 ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Native warnings can expose private paths; the failure return is checked and mapped to a stable error. Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
			if ( is_file( $destination ) ) {
				@unlink( $destination ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort cleanup of an owned temporary path must not replace the primary outcome with an OS warning. Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
			}
			throw Worksheet_Template_Import_Failure::package_invalid();
		}
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
		$keys = array_keys( $value );
		sort( $keys, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $keys !== $expected ) {
			throw Worksheet_Template_Import_Failure::package_invalid();
		}
	}

	/**
	 * Cleanup.
	 *
	 * @param array<int,string> $files Files.
	 * @param string|null       $directory Directory.
	 * @return void
	 */
	private function cleanup( array $files, ?string $directory ): void {
		foreach ( $files as $file ) {
			if ( is_file( $file ) ) {
				@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort cleanup of an owned temporary path must not replace the primary outcome with an OS warning. Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
			}
		}
		if ( is_string( $directory ) && is_dir( $directory ) ) {
			@rmdir( $directory ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Best-effort cleanup of an owned temporary path must not replace the primary outcome with an OS warning. Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
		}
	}
}
