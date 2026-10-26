<?php
/**
 * Submission ZIP upload safety profile.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\ZipImport\Domain;

use OJAI\Dashboard\Submission\ZipImport\Application\Zip_Import_Failure;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads ZIP metadata without extraction and rejects unsafe archive structure.
 */
final class Submission_Zip_Upload_Profile {
	public const MAX_ARCHIVE_BYTES      = 104857600;
	public const MAX_REGULAR_ENTRIES    = 500;
	public const MAX_CENTRAL_ENTRIES    = 1000;
	public const MAX_ENTRY_BYTES        = 26214400;
	public const MAX_UNCOMPRESSED_BYTES = 524288000;
	public const MAX_COMPRESSION_RATIO  = 20;
	public const MAX_PATH_BYTES         = 500;

	private const EOCD_SIGNATURE    = "PK\x05\x06";
	private const CENTRAL_SIGNATURE = "PK\x01\x02";
	private const LOCAL_SIGNATURE   = "PK\x03\x04";

	/**
	 * Inspect.
	 *
	 * @param array<string,mixed> $upload Upload.
	 * @return array{tmp_name:string,original_name:string,mime_type:string,extension:string,byte_size:int,sha256:string,entry_count:int,compressed_entry_bytes:int,uncompressed_bytes:int}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function inspect( array $upload ): array {
		$this->assert_upload_shape( $upload );
		if ( UPLOAD_ERR_OK !== $upload['error'] ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Zip_Import_Failure::because( Zip_Import_Failure::INVALID_UPLOAD );
		}

		$path = $upload['tmp_name'];
		if ( ! is_file( $path ) || ! is_readable( $path ) || is_link( $path ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Zip_Import_Failure::because( Zip_Import_Failure::INVALID_UPLOAD );
		}
		$size = filesize( $path );
		if ( ! is_int( $size ) || $size < 22 || $size > self::MAX_ARCHIVE_BYTES || $size !== $upload['size'] ) {
			throw Zip_Import_Failure::because(
				is_int( $size ) && $size > self::MAX_ARCHIVE_BYTES
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					? Zip_Import_Failure::LIMIT_EXCEEDED
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					: Zip_Import_Failure::INVALID_UPLOAD
			);
		}

		$name = $this->safe_original_name( $upload['name'] );
		if ( 'zip' !== strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Zip_Import_Failure::because( Zip_Import_Failure::UNSUPPORTED_ARCHIVE );
		}
		$sha256 = hash_file( 'sha256', $path );
		if ( ! is_string( $sha256 ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $sha256 ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Zip_Import_Failure::because( Zip_Import_Failure::INVALID_UPLOAD );
		}

		$archive = $this->inspect_central_directory( $path, $size );
		return array(
			'tmp_name'               => $path,
			'original_name'          => $name,
			'mime_type'              => 'application/zip',
			'extension'              => 'zip',
			'byte_size'              => $size,
			'sha256'                 => $sha256,
			'entry_count'            => $archive['entry_count'],
			'compressed_entry_bytes' => $archive['compressed_entry_bytes'],
			'uncompressed_bytes'     => $archive['uncompressed_bytes'],
		);
	}

	/**
	 * Inspect central directory.
	 *
	 * @param string $path Path.
	 * @param int    $file_size File size.
	 * @return array{entry_count:int,compressed_entry_bytes:int,uncompressed_bytes:int}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function inspect_central_directory( string $path, int $file_size ): array {
		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
		if ( false === $handle ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Zip_Import_Failure::because( Zip_Import_Failure::INVALID_UPLOAD );
		}

		try {
			$eocd = $this->end_of_central_directory( $handle, $file_size );
			if (
				0 !== $eocd['disk_number']
				|| 0 !== $eocd['central_disk']
				|| $eocd['entries_on_disk'] !== $eocd['entries_total']
			) {
				throw Zip_Import_Failure::because( Zip_Import_Failure::UNSAFE_ARCHIVE );
			}
			if (
				0 === $eocd['entries_total']
				|| 0xffff === $eocd['entries_total']
				|| 0xffffffff === $eocd['central_size']
				|| 0xffffffff === $eocd['central_offset']
			) {
				throw Zip_Import_Failure::because( Zip_Import_Failure::UNSUPPORTED_ARCHIVE );
			}
			if ( $eocd['entries_total'] > self::MAX_CENTRAL_ENTRIES ) {
				throw Zip_Import_Failure::because( Zip_Import_Failure::LIMIT_EXCEEDED );
			}
			if ( $eocd['central_offset'] + $eocd['central_size'] !== $eocd['offset'] ) {
				throw Zip_Import_Failure::because( Zip_Import_Failure::UNSAFE_ARCHIVE );
			}
			if ( 0 !== fseek( $handle, $eocd['central_offset'] ) ) {
				throw Zip_Import_Failure::because( Zip_Import_Failure::INVALID_UPLOAD );
			}

			$entries            = array();
			$paths              = array();
			$regular_count      = 0;
			$total_compressed   = 0;
			$total_uncompressed = 0;
			for ( $index = 0; $index < $eocd['entries_total']; ++$index ) {
				$fixed = $this->read_exact( $handle, 46 );
				if ( self::CENTRAL_SIGNATURE !== substr( $fixed, 0, 4 ) ) {
					throw Zip_Import_Failure::because( Zip_Import_Failure::UNSAFE_ARCHIVE );
				}
				$header = unpack(
					'vmade/vneeded/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vuncompressed/vname_length/vextra_length/vcomment_length/vdisk_start/vinternal/Vexternal/Vlocal_offset',
					substr( $fixed, 4 )
				);
				if ( ! is_array( $header ) ) {
					throw Zip_Import_Failure::because( Zip_Import_Failure::INVALID_UPLOAD );
				}
				$name  = $this->read_exact( $handle, (int) $header['name_length'] );
				$extra = $this->read_exact( $handle, (int) $header['extra_length'] );
				$this->read_exact( $handle, (int) $header['comment_length'] );
				$this->assert_supported_extra( $extra );
				$this->assert_common_header( $header );

				$path_key = $this->safe_entry_path( $name );
				if ( isset( $paths[ $path_key ] ) ) {
					throw Zip_Import_Failure::because( Zip_Import_Failure::UNSAFE_ARCHIVE );
				}
				$paths[ $path_key ] = true;
				$is_directory       = $this->is_directory( $name, (int) $header['made'], (int) $header['external'] );
				$this->assert_file_type( (int) $header['made'], (int) $header['external'], $is_directory );
				if ( ! $is_directory ) {
					++$regular_count;
					if ( $regular_count > self::MAX_REGULAR_ENTRIES ) {
						throw Zip_Import_Failure::because( Zip_Import_Failure::LIMIT_EXCEEDED );
					}
					$this->assert_not_nested_archive( $name );
					$compressed   = (int) $header['compressed'];
					$uncompressed = (int) $header['uncompressed'];
					if ( $uncompressed > self::MAX_ENTRY_BYTES ) {
						throw Zip_Import_Failure::because( Zip_Import_Failure::LIMIT_EXCEEDED );
					}
					$this->assert_ratio( $compressed, $uncompressed );
					$total_compressed   += $compressed;
					$total_uncompressed += $uncompressed;
					if ( $total_uncompressed > self::MAX_UNCOMPRESSED_BYTES ) {
						throw Zip_Import_Failure::because( Zip_Import_Failure::LIMIT_EXCEEDED );
					}
				}

				$entries[] = array(
					'name'         => $name,
					'flags'        => (int) $header['flags'],
					'method'       => (int) $header['method'],
					'crc'          => (int) $header['crc'],
					'compressed'   => (int) $header['compressed'],
					'uncompressed' => (int) $header['uncompressed'],
					'local_offset' => (int) $header['local_offset'],
				);
			}
			$position = ftell( $handle );
			if ( ! is_int( $position ) || $position !== $eocd['central_offset'] + $eocd['central_size'] || 0 === $regular_count ) {
				throw Zip_Import_Failure::because( Zip_Import_Failure::UNSAFE_ARCHIVE );
			}
			$this->assert_ratio( $total_compressed, $total_uncompressed );
			$this->assert_local_headers( $handle, $entries, $eocd['central_offset'] );

			return array(
				'entry_count'            => $regular_count,
				'compressed_entry_bytes' => $total_compressed,
				'uncompressed_bytes'     => $total_uncompressed,
			);
		} finally {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
		}
	}

	/**
	 * End of central directory.
	 *
	 * @param mixed $handle Handle.
	 * @param int   $file_size File size.
	 * @return array{offset:int,disk_number:int,central_disk:int,entries_on_disk:int,entries_total:int,central_size:int,central_offset:int}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function end_of_central_directory( mixed $handle, int $file_size ): array {
		$tail_size = min( $file_size, 65557 );
		if ( 0 !== fseek( $handle, $file_size - $tail_size ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Zip_Import_Failure::because( Zip_Import_Failure::INVALID_UPLOAD );
		}
		$tail = $this->read_exact( $handle, $tail_size );
		for ( $position = $tail_size - 22; $position >= 0; --$position ) {
			if ( self::EOCD_SIGNATURE !== substr( $tail, $position, 4 ) ) {
				continue;
			}
			$header = unpack( 'vdisk_number/vcentral_disk/ventries_on_disk/ventries_total/Vcentral_size/Vcentral_offset/vcomment_length', substr( $tail, $position + 4, 18 ) );
			if ( ! is_array( $header ) || $position + 22 + (int) $header['comment_length'] !== $tail_size ) {
				continue;
			}
			return array(
				'offset'          => $file_size - $tail_size + $position,
				'disk_number'     => (int) $header['disk_number'],
				'central_disk'    => (int) $header['central_disk'],
				'entries_on_disk' => (int) $header['entries_on_disk'],
				'entries_total'   => (int) $header['entries_total'],
				'central_size'    => (int) $header['central_size'],
				'central_offset'  => (int) $header['central_offset'],
			);
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
		throw Zip_Import_Failure::because( Zip_Import_Failure::UNSAFE_ARCHIVE );
	}

	/**
	 * Reject invalid common header.
	 *
	 * @param array<string,int> $header Header.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_common_header( array $header ): void {
		$flags = (int) $header['flags'];
		if ( 0 !== ( $flags & 0x0001 ) || 0 !== ( $flags & 0x0040 ) || 0 !== ( $flags & 0x2000 ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Zip_Import_Failure::because( Zip_Import_Failure::UNSAFE_ARCHIVE );
		}
		if ( ! in_array( (int) $header['method'], array( 0, 8 ), true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Zip_Import_Failure::because( Zip_Import_Failure::UNSUPPORTED_ARCHIVE );
		}
		if (
			0 !== (int) ( $header['disk_start'] ?? 0 )
			|| 0xffffffff === (int) $header['compressed']
			|| 0xffffffff === (int) $header['uncompressed']
			|| 0xffffffff === (int) $header['local_offset']
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Zip_Import_Failure::because( Zip_Import_Failure::UNSUPPORTED_ARCHIVE );
		}
	}

	/**
	 * Reject invalid local headers.
	 *
	 * @param mixed                               $handle Handle.
	 * @param array<int,array<string,int|string>> $entries Entries.
	 * @param int                                 $central_offset Central offset.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_local_headers( mixed $handle, array $entries, int $central_offset ): void {
		$ranges = array();
		foreach ( $entries as $entry ) {
			$offset = (int) $entry['local_offset'];
			if ( $offset < 0 || $offset >= $central_offset || 0 !== fseek( $handle, $offset ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Zip_Import_Failure::because( Zip_Import_Failure::UNSAFE_ARCHIVE );
			}
			$fixed = $this->read_exact( $handle, 30 );
			if ( self::LOCAL_SIGNATURE !== substr( $fixed, 0, 4 ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Zip_Import_Failure::because( Zip_Import_Failure::UNSAFE_ARCHIVE );
			}
			$local = unpack( 'vneeded/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vuncompressed/vname_length/vextra_length', substr( $fixed, 4 ) );
			if ( ! is_array( $local ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Zip_Import_Failure::because( Zip_Import_Failure::INVALID_UPLOAD );
			}
			$name  = $this->read_exact( $handle, (int) $local['name_length'] );
			$extra = $this->read_exact( $handle, (int) $local['extra_length'] );
			$this->assert_supported_extra( $extra );
			if (
				$name !== $entry['name']
				|| (int) $local['flags'] !== (int) $entry['flags']
				|| (int) $local['method'] !== (int) $entry['method']
			) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Zip_Import_Failure::because( Zip_Import_Failure::UNSAFE_ARCHIVE );
			}
			$this->assert_common_header(
				array_merge(
					$local,
					array(
						'disk_start'   => 0,
						'local_offset' => $offset,
					)
				)
			);
			if ( 0 === ( (int) $local['flags'] & 0x0008 ) && (
				(int) $local['crc'] !== (int) $entry['crc']
				|| (int) $local['compressed'] !== (int) $entry['compressed']
				|| (int) $local['uncompressed'] !== (int) $entry['uncompressed']
			) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Zip_Import_Failure::because( Zip_Import_Failure::UNSAFE_ARCHIVE );
			}
			$end = $offset + 30 + (int) $local['name_length'] + (int) $local['extra_length'] + (int) $entry['compressed'];
			if ( $end > $central_offset ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Zip_Import_Failure::because( Zip_Import_Failure::UNSAFE_ARCHIVE );
			}
			$ranges[] = array( $offset, $end );
		}
		usort( $ranges, static fn ( array $left, array $right ): int => $left[0] <=> $right[0] );
		if ( 0 !== $ranges[0][0] ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Zip_Import_Failure::because( Zip_Import_Failure::UNSAFE_ARCHIVE );
		}
		$range_count = count( $ranges );
		for ( $index = 1; $index < $range_count; ++$index ) {
			if ( $ranges[ $index ][0] < $ranges[ $index - 1 ][1] ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Zip_Import_Failure::because( Zip_Import_Failure::UNSAFE_ARCHIVE );
			}
		}
	}

	/**
	 * Safe entry path.
	 *
	 * @param string $name Name.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function safe_entry_path( string $name ): string {
		if (
			'' === $name
			|| strlen( $name ) > self::MAX_PATH_BYTES
			|| 1 !== preg_match( '//u', $name )
			|| 1 === preg_match( '/[\x00-\x1F\x7F\\\\]/', $name )
			|| str_starts_with( $name, '/' )
			|| 1 === preg_match( '/^[A-Za-z]:/', $name )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Zip_Import_Failure::because( Zip_Import_Failure::UNSAFE_ARCHIVE );
		}
		$segments = explode( '/', rtrim( $name, '/' ) );
		foreach ( $segments as $segment ) {
			if ( '' === $segment || '.' === $segment || '..' === $segment ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Zip_Import_Failure::because( Zip_Import_Failure::UNSAFE_ARCHIVE );
			}
		}
		return strtolower( rtrim( $name, '/' ) );
	}

	/**
	 * Reject invalid not nested archive.
	 *
	 * @param string $name Name.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_not_nested_archive( string $name ): void {
		$extension = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( in_array( $extension, array( 'zip', 'rar', '7z', 'tar', 'gz', 'tgz', 'bz2', 'tbz', 'xz', 'txz', 'jar', 'war' ), true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Zip_Import_Failure::because( Zip_Import_Failure::UNSAFE_ARCHIVE );
		}
	}

	/**
	 * Reject invalid file type.
	 *
	 * @param int  $made Made.
	 * @param int  $external External.
	 * @param bool $is_directory Is directory.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_file_type( int $made, int $external, bool $is_directory ): void {
		$host = ( $made >> 8 ) & 0xff;
		if ( 3 !== $host ) {
			return;
		}
		$mode = ( $external >> 16 ) & 0xffff;
		$type = $mode & 0170000;
		if ( 0120000 === $type || ( 0 !== $type && ! in_array( $type, array( 0040000, 0100000 ), true ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Zip_Import_Failure::because( Zip_Import_Failure::UNSAFE_ARCHIVE );
		}
		if ( ( $is_directory && 0100000 === $type ) || ( ! $is_directory && 0040000 === $type ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Zip_Import_Failure::because( Zip_Import_Failure::UNSAFE_ARCHIVE );
		}
	}

	/**
	 * Is directory.
	 *
	 * @param string $name Name.
	 * @param int    $made Made.
	 * @param int    $external External.
	 * @return bool
	 */
	private function is_directory( string $name, int $made, int $external ): bool {
		$host = ( $made >> 8 ) & 0xff;
		$mode = ( $external >> 16 ) & 0xffff;
		return str_ends_with( $name, '/' ) || 0 !== ( $external & 0x10 ) || ( 3 === $host && 0040000 === ( $mode & 0170000 ) );
	}

	/**
	 * Reject invalid ratio.
	 *
	 * @param int $compressed Compressed.
	 * @param int $uncompressed Uncompressed.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_ratio( int $compressed, int $uncompressed ): void {
		if ( $compressed < 0 || $uncompressed < 0 || ( $uncompressed > 0 && ( 0 === $compressed || $uncompressed > $compressed * self::MAX_COMPRESSION_RATIO ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Zip_Import_Failure::because( Zip_Import_Failure::LIMIT_EXCEEDED );
		}
	}

	/**
	 * Reject invalid supported extra.
	 *
	 * @param string $extra Extra.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_supported_extra( string $extra ): void {
		$offset = 0;
		$length = strlen( $extra );
		while ( $offset < $length ) {
			if ( $offset + 4 > $length ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Zip_Import_Failure::because( Zip_Import_Failure::UNSAFE_ARCHIVE );
			}
			$header = unpack( 'vtag/vsize', substr( $extra, $offset, 4 ) );
			if ( ! is_array( $header ) || $offset + 4 + (int) $header['size'] > $length ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Zip_Import_Failure::because( Zip_Import_Failure::UNSAFE_ARCHIVE );
			}
			if ( 0x0001 === (int) $header['tag'] ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Zip_Import_Failure::because( Zip_Import_Failure::UNSUPPORTED_ARCHIVE );
			}
			if ( 0x9901 === (int) $header['tag'] ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Zip_Import_Failure::because( Zip_Import_Failure::UNSAFE_ARCHIVE );
			}
			$offset += 4 + (int) $header['size'];
		}
	}

	/**
	 * Read exact.
	 *
	 * @param mixed $handle Handle.
	 * @param int   $length Length.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function read_exact( mixed $handle, int $length ): string {
		if ( $length < 0 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Zip_Import_Failure::because( Zip_Import_Failure::INVALID_UPLOAD );
		}
		if ( 0 === $length ) {
			return '';
		}
		$value = '';
		while ( strlen( $value ) < $length && ! feof( $handle ) ) { // phpcs:ignore Squiz.PHP.DisallowSizeFunctionsInLoops.Found -- Partial stream reads change the accumulated byte count each iteration.
			$chunk = fread( $handle, $length - strlen( $value ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
			if ( false === $chunk ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Zip_Import_Failure::because( Zip_Import_Failure::INVALID_UPLOAD );
			}
			$value .= $chunk;
		}
		if ( strlen( $value ) !== $length ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Zip_Import_Failure::because( Zip_Import_Failure::UNSAFE_ARCHIVE );
		}
		return $value;
	}

	/**
	 * Reject invalid upload shape.
	 *
	 * @param array<string,mixed> $upload Upload.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_upload_shape( array $upload ): void {
		$keys = array_keys( $upload );
		sort( $keys, SORT_STRING );
		if ( $keys !== array( 'error', 'name', 'size', 'tmp_name', 'type' ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Zip_Import_Failure::because( Zip_Import_Failure::INVALID_UPLOAD );
		}
		if (
			! is_string( $upload['name'] ) || '' === $upload['name']
			|| ! is_string( $upload['tmp_name'] ) || '' === $upload['tmp_name']
			|| ! is_string( $upload['type'] )
			|| ! is_int( $upload['error'] )
			|| ! is_int( $upload['size'] )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Zip_Import_Failure::because( Zip_Import_Failure::INVALID_UPLOAD );
		}
	}

	/**
	 * Safe original name.
	 *
	 * @param string $name Name.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function safe_original_name( string $name ): string {
		if (
			$name !== basename( str_replace( '\\', '/', $name ) )
			|| strlen( $name ) > 255
			|| 1 !== preg_match( '//u', $name )
			|| 1 === preg_match( '/[\x00-\x1F\x7F]/', $name )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Zip_Import_Failure::because( Zip_Import_Failure::INVALID_UPLOAD );
		}
		return $name;
	}
}
