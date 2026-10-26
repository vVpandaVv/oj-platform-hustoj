<?php
/**
 * Worksheet body asset upload profile.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Storage\Domain;

use OJAI\Dashboard\Storage\Application\Worksheet_Asset_Failure;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detects supported formats from bytes and never trusts the browser MIME value.
 */
final class Worksheet_Asset_Upload_Profile {
	public const MAX_FILE_BYTES = 20 * 1024 * 1024;

	/**
	 * Worksheet asset upload profile.
	 *
	 * @var array<string,string>
	 */
	private const SIMPLE_TYPES = array(
		'jpg'  => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'png'  => 'image/png',
		'webp' => 'image/webp',
		'mp3'  => 'audio/mpeg',
		'ogg'  => 'audio/ogg',
		'wav'  => 'audio/wav',
		'mp4'  => 'video/mp4',
		'webm' => 'video/webm',
		'pdf'  => 'application/pdf',
		'txt'  => 'text/plain',
		'csv'  => 'text/csv',
	);

	/**
	 * Worksheet asset upload profile.
	 *
	 * @var array<string,string>
	 */
	private const OFFICE_TYPES = array(
		'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
	);

	/**
	 * Inspect.
	 *
	 * @param array<string,mixed> $upload Upload.
	 * @return array{original_name:string,extension:string,mime_type:string,byte_size:int,sha256:string,tmp_name:string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function inspect( array $upload ): array {
		if ( UPLOAD_ERR_OK !== ( $upload['error'] ?? null ) ) {
			throw Worksheet_Asset_Failure::invalid_upload();
		}
		$tmp_name = (string) ( $upload['tmp_name'] ?? '' );
		if ( '' === $tmp_name || ! is_file( $tmp_name ) || ! is_readable( $tmp_name ) ) {
			throw Worksheet_Asset_Failure::invalid_upload();
		}
		$actual_size = filesize( $tmp_name );
		if ( ! is_int( $actual_size ) || $actual_size < 1 ) {
			throw Worksheet_Asset_Failure::invalid_upload();
		}
		if ( $actual_size > self::MAX_FILE_BYTES ) {
			throw Worksheet_Asset_Failure::limit_exceeded();
		}
		if ( (int) ( $upload['size'] ?? -1 ) !== $actual_size ) {
			throw Worksheet_Asset_Failure::invalid_upload();
		}

		$original_name = $this->safe_name( (string) ( $upload['name'] ?? '' ) );
		$extension     = strtolower( (string) pathinfo( $original_name, PATHINFO_EXTENSION ) );
		$mime_type     = $this->detect_type( $tmp_name, $extension );
		$sha256        = hash_file( 'sha256', $tmp_name );
		if ( ! is_string( $sha256 ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $sha256 ) ) {
			throw Worksheet_Asset_Failure::invalid_upload();
		}

		return array(
			'original_name' => $original_name,
			'extension'     => $extension,
			'mime_type'     => $mime_type,
			'byte_size'     => $actual_size,
			'sha256'        => $sha256,
			'tmp_name'      => $tmp_name,
		);
	}

	/**
	 * Safe name.
	 *
	 * @param string $name Name.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function safe_name( string $name ): string {
		$name = str_replace( '\\', '/', trim( $name ) );
		$name = basename( $name );
		if (
			'' === $name
			|| '.' === $name
			|| '..' === $name
			|| strlen( $name ) > 255
			|| 1 === preg_match( '/[\x00-\x1F\x7F]/', $name )
		) {
			throw Worksheet_Asset_Failure::invalid_upload();
		}
		return $name;
	}

	/**
	 * Detect type.
	 *
	 * @param string $path Path.
	 * @param string $extension Extension.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function detect_type( string $path, string $extension ): string {
		if ( isset( self::OFFICE_TYPES[ $extension ] ) ) {
			$this->assert_office_container( $path, $extension );
			return self::OFFICE_TYPES[ $extension ];
		}
		if ( ! isset( self::SIMPLE_TYPES[ $extension ] ) ) {
			throw Worksheet_Asset_Failure::unsupported_media();
		}

		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
		if ( false === $handle ) {
			throw Worksheet_Asset_Failure::invalid_upload();
		}
		$prefix = fread( $handle, 8192 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
		if ( ! is_string( $prefix ) || '' === $prefix ) {
			throw Worksheet_Asset_Failure::invalid_upload();
		}

		$valid = match ( $extension ) {
			'jpg', 'jpeg' => str_starts_with( $prefix, "\xFF\xD8\xFF" ),
			'png'         => str_starts_with( $prefix, "\x89PNG\r\n\x1A\n" ),
			'webp'        => strlen( $prefix ) >= 12 && 'RIFF' === substr( $prefix, 0, 4 ) && 'WEBP' === substr( $prefix, 8, 4 ),
			'mp3'         => str_starts_with( $prefix, 'ID3' ) || ( strlen( $prefix ) >= 2 && "\xFF" === $prefix[0] && 0xE0 === ( ord( $prefix[1] ) & 0xE0 ) ),
			'ogg'         => str_starts_with( $prefix, 'OggS' ),
			'wav'         => strlen( $prefix ) >= 12 && 'RIFF' === substr( $prefix, 0, 4 ) && 'WAVE' === substr( $prefix, 8, 4 ),
			'mp4'         => strlen( $prefix ) >= 12 && 'ftyp' === substr( $prefix, 4, 4 ),
			'webm'        => str_starts_with( $prefix, "\x1A\x45\xDF\xA3" ),
			'pdf'         => str_starts_with( $prefix, '%PDF-' ),
			'txt', 'csv'  => ! str_contains( $prefix, "\0" ),
		};
		if ( ! $valid ) {
			throw Worksheet_Asset_Failure::unsupported_media();
		}

		return self::SIMPLE_TYPES[ $extension ];
	}

	/**
	 * Reject invalid office container.
	 *
	 * @param string $path Path.
	 * @param string $extension Extension.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_office_container( string $path, string $extension ): void {
		if ( ! class_exists( '\\ZipArchive' ) ) {
			throw Worksheet_Asset_Failure::unsupported_media();
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $path ) || $zip->numFiles < 2 || $zip->numFiles > 5000 ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- This property spelling belongs to an external PHP, WordPress or provider contract.
			throw Worksheet_Asset_Failure::unsupported_media();
		}
		$required_root     = array(
			'docx' => 'word/',
			'xlsx' => 'xl/',
			'pptx' => 'ppt/',
		)[ $extension ];
		$has_content_types = false;
		$has_required_root = false;
		for ( $index = 0; $index < $zip->numFiles; ++$index ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- This property spelling belongs to an external PHP, WordPress or provider contract.
			$entry = $zip->getNameIndex( $index );
			if ( ! is_string( $entry ) || $this->unsafe_zip_path( $entry ) ) {
				$zip->close();
				throw Worksheet_Asset_Failure::unsupported_media();
			}
			$has_content_types = $has_content_types || '[Content_Types].xml' === $entry;
			$has_required_root = $has_required_root || str_starts_with( $entry, $required_root );
		}
		$zip->close();
		if ( ! $has_content_types || ! $has_required_root ) {
			throw Worksheet_Asset_Failure::unsupported_media();
		}
	}

	/**
	 * Unsafe zip path.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	private function unsafe_zip_path( string $path ): bool {
		$normalized = str_replace( '\\', '/', $path );
		return '' === $normalized
			|| str_starts_with( $normalized, '/' )
			|| 1 === preg_match( '/^[A-Za-z]:\//D', $normalized )
			|| in_array( '..', explode( '/', $normalized ), true );
	}
}
