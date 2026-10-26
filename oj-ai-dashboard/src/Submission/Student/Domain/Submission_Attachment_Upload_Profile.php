<?php
/**
 * Student submission attachment upload profile.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Student\Domain;

use JsonException;
use OJAI\Dashboard\Submission\Assignment\Application\Assignment_Failure;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detects media from bytes and narrows it to the exact published response.
 */
final class Submission_Attachment_Upload_Profile {
	public const MAX_FILE_BYTES  = 25 * 1024 * 1024;
	public const MAX_TOTAL_FILES = 20;
	public const MAX_TOTAL_BYTES = 100 * 1024 * 1024;

	/**
	 * Submission attachment upload profile.
	 *
	 * @var array<string,string>
	 */
	private const SIMPLE_TYPES = array(
		'jpg'  => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'png'  => 'image/png',
		'webp' => 'image/webp',
		'pdf'  => 'application/pdf',
		'txt'  => 'text/plain',
		'csv'  => 'text/csv',
		'json' => 'application/json',
	);

	/**
	 * Submission attachment upload profile.
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
	 * @param array<string,mixed> $profile Profile.
	 * @param string              $response_id Response id.
	 * @param string              $role Role.
	 * @return array{original_name:string,extension:string,mime_type:string,byte_size:int,sha256:string,tmp_name:string,max_files:int}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function inspect( array $upload, array $profile, string $response_id, string $role ): array {
		$requirement = $this->requirement( $profile, $response_id, $role );
		if ( UPLOAD_ERR_OK !== ( $upload['error'] ?? null ) ) {
			$this->invalid_upload();
		}
		$tmp_name = (string) ( $upload['tmp_name'] ?? '' );
		if ( '' === $tmp_name || ! is_file( $tmp_name ) || ! is_readable( $tmp_name ) ) {
			$this->invalid_upload();
		}
		$size = filesize( $tmp_name );
		if ( ! is_int( $size ) || $size < 1 || (int) ( $upload['size'] ?? -1 ) !== $size ) {
			$this->invalid_upload();
		}
		if ( $size > self::MAX_FILE_BYTES || $size > $requirement['max_bytes'] ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::ATTACHMENT_LIMIT_EXCEEDED );
		}

		$name      = $this->safe_name( (string) ( $upload['name'] ?? '' ) );
		$extension = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );
		$mime      = $this->detect_type( $tmp_name, $extension );
		if ( ! in_array( $mime, $requirement['mime_types'], true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::ATTACHMENT_UNSUPPORTED_MEDIA );
		}
		$sha256 = hash_file( 'sha256', $tmp_name );
		if ( ! is_string( $sha256 ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $sha256 ) ) {
			$this->invalid_upload();
		}

		return array(
			'original_name' => $name,
			'extension'     => $extension,
			'mime_type'     => $mime,
			'byte_size'     => $size,
			'sha256'        => $sha256,
			'tmp_name'      => $tmp_name,
			'max_files'     => $requirement['max_files'],
		);
	}

	/**
	 * Requirement.
	 *
	 * @param array<string,mixed> $profile Profile.
	 * @param string              $response_id Response id.
	 * @param string              $role Role.
	 * @return array{mime_types:array<int,string>,max_bytes:int,max_files:int}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function requirement( array $profile, string $response_id, string $role ): array {
		$payload = $profile['payload'] ?? null;
		$items   = is_array( $payload ) ? ( $payload['items'] ?? null ) : null;
		if ( ! is_array( $items ) || ! array_is_list( $items ) ) {
			$this->stored_invalid();
		}
		$found = null;
		foreach ( $items as $item ) {
			if ( is_array( $item ) && ( $item['response_id'] ?? null ) === $response_id ) {
				if ( null !== $found ) {
					$this->stored_invalid();
				}
				$found = $item;
			}
		}
		if ( ! is_array( $found ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::SUBMISSION_VALIDATION_FAILED );
		}
		$kind        = $found['kind'] ?? null;
		$constraints = $found['constraints'] ?? null;
		if ( ! is_string( $kind ) || ! is_array( $constraints ) || array_is_list( $constraints ) ) {
			$this->stored_invalid();
		}
		if ( in_array( $kind, array( 'image_upload', 'file_upload' ), true ) ) {
			if ( 'file' !== $role ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Assignment_Failure::because( Assignment_Failure::SUBMISSION_VALIDATION_FAILED );
			}
			$mimes     = $constraints['mime_types'] ?? ( 'image_upload' === $kind ? array( 'image/jpeg', 'image/png', 'image/webp' ) : null );
			$max_files = $constraints['max_files'] ?? 5;
			$max_bytes = $constraints['max_bytes'] ?? self::MAX_FILE_BYTES;
		} elseif ( 'drawing' === $kind ) {
			if ( 'document' === $role ) {
				$mimes = array( 'application/json' ); } elseif ( 'preview' === $role && 'image/png' === ( $constraints['preview_mime_type'] ?? null ) ) {
				$mimes = array( 'image/png' );
				} else {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					throw Assignment_Failure::because( Assignment_Failure::SUBMISSION_VALIDATION_FAILED );
				}
				$max_files = 2;
				$max_bytes = self::MAX_FILE_BYTES;
		} else {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::SUBMISSION_VALIDATION_FAILED );
		}
		if ( ! is_array( $mimes ) || ! array_is_list( $mimes ) || array() === $mimes || count( array_unique( $mimes ) ) !== count( $mimes ) ) {
			$this->stored_invalid();
		}
		foreach ( $mimes as $mime ) {
			if ( ! is_string( $mime ) || 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9!#$&^_.+-]*\/[A-Za-z0-9][A-Za-z0-9!#$&^_.+-]*$/D', $mime ) ) {
				$this->stored_invalid();
			}
		}
		if ( ! is_int( $max_files ) || $max_files < 1 || $max_files > self::MAX_TOTAL_FILES || ! is_int( $max_bytes ) || $max_bytes < 1 || $max_bytes > self::MAX_FILE_BYTES ) {
			$this->stored_invalid();
		}
		return array(
			'mime_types' => $mimes,
			'max_bytes'  => $max_bytes,
			'max_files'  => $max_files,
		);
	}

	/**
	 * Safe name.
	 *
	 * @param string $name Name.
	 * @return string
	 */
	private function safe_name( string $name ): string {
		$name = basename( str_replace( '\\', '/', trim( $name ) ) );
		if ( '' === $name || '.' === $name || '..' === $name || strlen( $name ) > 255 || 1 === preg_match( '/[\x00-\x1F\x7F]/', $name ) ) {
			$this->invalid_upload();
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
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::ATTACHMENT_UNSUPPORTED_MEDIA );
		}
		$prefix = file_get_contents( $path, false, null, 0, 8192 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
		if ( ! is_string( $prefix ) || '' === $prefix ) {
			$this->invalid_upload();
		}
		$valid = match ( $extension ) {
			'jpg', 'jpeg' => str_starts_with( $prefix, "\xFF\xD8\xFF" ),
			'png' => str_starts_with( $prefix, "\x89PNG\r\n\x1A\n" ),
			'webp' => strlen( $prefix ) >= 12 && 'RIFF' === substr( $prefix, 0, 4 ) && 'WEBP' === substr( $prefix, 8, 4 ),
			'pdf' => str_starts_with( $prefix, '%PDF-' ),
			'txt', 'csv' => ! str_contains( $prefix, "\0" ),
			'json' => $this->valid_json( $path ),
		};
		if ( ! $valid ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::ATTACHMENT_UNSUPPORTED_MEDIA );
		}
		return self::SIMPLE_TYPES[ $extension ];
	}

	/**
	 * Valid json.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	private function valid_json( string $path ): bool {
		$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
		if ( ! is_string( $contents ) || '' === trim( $contents ) ) {
			return false;
		}
		try {
			return null !== json_decode( $contents, true, 512, JSON_THROW_ON_ERROR ); } catch ( JsonException ) {
			return false;
			}
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
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::ATTACHMENT_UNSUPPORTED_MEDIA );
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $path ) || $zip->numFiles < 2 || $zip->numFiles > 5000 ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- This property spelling belongs to an external PHP, WordPress or provider contract.
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::ATTACHMENT_UNSUPPORTED_MEDIA );
		}
		$root          = array(
			'docx' => 'word/',
			'xlsx' => 'xl/',
			'pptx' => 'ppt/',
		)[ $extension ];
		$content_types = false;
		$required_root = false;
		for ( $index = 0; $index < $zip->numFiles; ++$index ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- This property spelling belongs to an external PHP, WordPress or provider contract.
			$entry      = $zip->getNameIndex( $index );
			$normalized = is_string( $entry ) ? str_replace( '\\', '/', $entry ) : '';
			if ( '' === $normalized || str_starts_with( $normalized, '/' ) || in_array( '..', explode( '/', $normalized ), true ) ) {
				$zip->close();
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Assignment_Failure::because( Assignment_Failure::ATTACHMENT_UNSUPPORTED_MEDIA );
			}
			$content_types = $content_types || '[Content_Types].xml' === $normalized;
			$required_root = $required_root || str_starts_with( $normalized, $root );
		}
		$zip->close();
		if ( ! $content_types || ! $required_root ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::ATTACHMENT_UNSUPPORTED_MEDIA );
		}
	}

	/**
	 * Invalid upload.
	 *
	 * @return never
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function invalid_upload(): never {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
		throw Assignment_Failure::because( Assignment_Failure::ATTACHMENT_INVALID_UPLOAD );
	}
	/**
	 * Stored invalid.
	 *
	 * @return never
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function stored_invalid(): never {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
		throw Assignment_Failure::because( Assignment_Failure::STORED_DATA_INVALID );
	}
}
