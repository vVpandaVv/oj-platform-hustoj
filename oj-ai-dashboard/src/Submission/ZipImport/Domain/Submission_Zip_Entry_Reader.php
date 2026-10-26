<?php
/**
 * Bounded reader for a previously staged submission ZIP.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\ZipImport\Domain;

use OJAI\Dashboard\Submission\ZipImport\Application\Zip_Import_Failure;
use RuntimeException;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reuses the C19 profile before locating and streaming any entry bytes.
 */
final class Submission_Zip_Entry_Reader {
	private const EOCD_SIGNATURE    = "PK\x05\x06";
	private const CENTRAL_SIGNATURE = "PK\x01\x02";
	private const LOCAL_SIGNATURE   = "PK\x03\x04";
	private const CHUNK_BYTES       = 1048576;

	/**
	 * Initialize submission zip entry reader dependencies and state.
	 *
	 * @param Submission_Zip_Upload_Profile $profile Profile.
	 */
	public function __construct( private Submission_Zip_Upload_Profile $profile ) {
	}

	/**
	 * Entries.
	 *
	 * @param string $path Path.
	 * @param string $expected_sha256 Expected sha256.
	 * @return array<int,Submission_Zip_Entry>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function entries( string $path, string $expected_sha256 ): array {
		if (
			1 !== preg_match( '/^[a-f0-9]{64}$/D', $expected_sha256 )
			|| ! is_file( $path ) || ! is_readable( $path ) || is_link( $path )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Zip_Import_Failure::because( Zip_Import_Failure::INVALID_UPLOAD );
		}
		$size   = filesize( $path );
		$actual = hash_file( 'sha256', $path );
		if (
			! is_int( $size ) || $size < 22 || $size > Submission_Zip_Upload_Profile::MAX_ARCHIVE_BYTES
			|| ! is_string( $actual ) || ! hash_equals( $expected_sha256, $actual )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Zip_Import_Failure::because( Zip_Import_Failure::UNSAFE_ARCHIVE );
		}
		$this->profile->inspect(
			array(
				'error'    => UPLOAD_ERR_OK,
				'name'     => 'submission.zip',
				'size'     => $size,
				'tmp_name' => $path,
				'type'     => 'application/zip',
			)
		);
		return $this->locate_entries( $path, $size );
	}

	/**
	 * Extracts one validated entry into a mode-0600 temporary file.
	 *
	 * @param string               $archive_path Archive path.
	 * @param Submission_Zip_Entry $entry Entry.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function extract_to_temporary_file( string $archive_path, Submission_Zip_Entry $entry ): string {
		$temporary = tempnam( sys_get_temp_dir(), 'oj-ai-zip-' );
		if ( false === $temporary || is_link( $temporary ) || ! @chmod( $temporary, 0600 ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Native warnings can expose private paths; the failure return is checked and mapped to a stable error. Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
			if ( is_string( $temporary ) && is_file( $temporary ) ) {
				@unlink( $temporary ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort removal of an owned temporary path must not mask the primary outcome. Private local streams and owned temporary paths require native filesystem operations, not WordPress FTP transport.
			} // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort cleanup of an owned temporary path must not replace the primary outcome with an OS warning. Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
			throw new RuntimeException( 'Submission ZIP temporary storage is unavailable.' );
		}

		$source = null;
		$target = null;
		try {
			$source = fopen( $archive_path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
			$target = fopen( $temporary, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
			if ( false === $source || false === $target || 0 !== fseek( $source, $entry->data_offset() ) ) {
				throw Zip_Import_Failure::because( Zip_Import_Failure::UNSAFE_ARCHIVE );
			}
			$sha     = hash_init( 'sha256' );
			$crc     = hash_init( 'crc32b' );
			$written = 0;
			if ( 0 === $entry->method() ) {
				if ( $entry->compressed_bytes() !== $entry->byte_size() ) {
					throw Zip_Import_Failure::because( Zip_Import_Failure::UNSAFE_ARCHIVE );
				}
				$this->copy_stored( $source, $target, $entry, $sha, $crc, $written );
			} else {
				$this->inflate( $source, $target, $entry, $sha, $crc, $written );
			}
			if (
				$written !== $entry->byte_size()
				|| ! hash_equals( sprintf( '%08x', $entry->crc32() ), hash_final( $crc ) )
			) {
				throw Zip_Import_Failure::because( Zip_Import_Failure::UNSAFE_ARCHIVE );
			}
			$actual_sha = hash_final( $sha );
			if ( ! fclose( $target ) || ! fclose( $source ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
				throw new RuntimeException( 'Submission ZIP stream close failed.' );
			}
			$target     = null;
			$source     = null;
			$stored_sha = hash_file( 'sha256', $temporary );
			if ( ! is_string( $stored_sha ) || ! hash_equals( $actual_sha, $stored_sha ) ) {
				throw Zip_Import_Failure::because( Zip_Import_Failure::UNSAFE_ARCHIVE );
			}
			return $temporary;
		} catch ( Throwable $failure ) {
			if ( is_resource( $target ) ) {
				fclose( $target ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Private local streams and owned temporary paths require native filesystem operations, not WordPress FTP transport.
			} // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
			if ( is_resource( $source ) ) {
				fclose( $source ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Private local streams and owned temporary paths require native filesystem operations, not WordPress FTP transport.
			} // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
			@unlink( $temporary ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort cleanup of an owned temporary path must not replace the primary outcome with an OS warning. Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
			throw $failure;
		}
	}

	/**
	 * Locate entries.
	 *
	 * @param string $path Path.
	 * @param int    $file_size File size.
	 * @return array<int,Submission_Zip_Entry>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function locate_entries( string $path, int $file_size ): array {
		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
		if ( false === $handle ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Zip_Import_Failure::because( Zip_Import_Failure::INVALID_UPLOAD );
		}
		try {
			$eocd = $this->eocd( $handle, $file_size );
			if ( 0 !== fseek( $handle, $eocd['central_offset'] ) ) {
				throw Zip_Import_Failure::because( Zip_Import_Failure::UNSAFE_ARCHIVE );
			}
			$metadata = array();
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
					throw Zip_Import_Failure::because( Zip_Import_Failure::UNSAFE_ARCHIVE );
				}
				$name = $this->read_exact( $handle, (int) $header['name_length'] );
				$this->read_exact( $handle, (int) $header['extra_length'] );
				$this->read_exact( $handle, (int) $header['comment_length'] );
				$this->safe_path( $name );
				$metadata[] = array(
					'name'         => $name,
					'flags'        => (int) $header['flags'],
					'method'       => (int) $header['method'],
					'crc'          => (int) $header['crc'],
					'compressed'   => (int) $header['compressed'],
					'uncompressed' => (int) $header['uncompressed'],
					'local_offset' => (int) $header['local_offset'],
					'is_directory' => str_ends_with( $name, '/' ) || 0 !== ( (int) $header['external'] & 0x10 ),
				);
			}
			$result  = array();
			$item_no = 0;
			foreach ( $metadata as $entry ) {
				if ( 0 !== fseek( $handle, $entry['local_offset'] ) ) {
					throw Zip_Import_Failure::because( Zip_Import_Failure::UNSAFE_ARCHIVE );
				}
				$fixed = $this->read_exact( $handle, 30 );
				if ( self::LOCAL_SIGNATURE !== substr( $fixed, 0, 4 ) ) {
					throw Zip_Import_Failure::because( Zip_Import_Failure::UNSAFE_ARCHIVE );
				}
				$local = unpack( 'vneeded/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vuncompressed/vname_length/vextra_length', substr( $fixed, 4 ) );
				if ( ! is_array( $local ) ) {
					throw Zip_Import_Failure::because( Zip_Import_Failure::UNSAFE_ARCHIVE );
				}
				$name = $this->read_exact( $handle, (int) $local['name_length'] );
				$this->read_exact( $handle, (int) $local['extra_length'] );
				if (
					$name !== $entry['name']
					|| (int) $local['flags'] !== $entry['flags']
					|| (int) $local['method'] !== $entry['method']
				) {
					throw Zip_Import_Failure::because( Zip_Import_Failure::UNSAFE_ARCHIVE );
				}
				if ( $entry['is_directory'] ) {
					continue;
				}
				++$item_no;
				$result[] = new Submission_Zip_Entry(
					$item_no,
					$entry['name'],
					$entry['method'],
					$entry['crc'],
					$entry['compressed'],
					$entry['uncompressed'],
					$entry['local_offset'] + 30 + (int) $local['name_length'] + (int) $local['extra_length']
				);
			}
			return $result;
		} finally {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
		}
	}

	/**
	 * Eocd.
	 *
	 * @param mixed $handle Handle.
	 * @param int   $file_size File size.
	 * @return array{entries_total:int,central_offset:int}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function eocd( mixed $handle, int $file_size ): array {
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
			if (
				! is_array( $header )
				|| $position + 22 + (int) $header['comment_length'] !== $tail_size
				|| (int) $header['entries_total'] < 1
				|| (int) $header['entries_total'] > Submission_Zip_Upload_Profile::MAX_CENTRAL_ENTRIES
			) {
				continue;
			}
			return array(
				'entries_total'  => (int) $header['entries_total'],
				'central_offset' => (int) $header['central_offset'],
			);
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
		throw Zip_Import_Failure::because( Zip_Import_Failure::UNSAFE_ARCHIVE );
	}

	/**
	 * Copy stored.
	 *
	 * @param mixed                $source Source.
	 * @param mixed                $target Target.
	 * @param Submission_Zip_Entry $entry Entry.
	 * @param mixed                $sha Sha.
	 * @param mixed                $crc Crc.
	 * @param int                  &$written Written.
	 * @return void
	 */
	private function copy_stored( mixed $source, mixed $target, Submission_Zip_Entry $entry, mixed $sha, mixed $crc, int &$written ): void {
		$remaining = $entry->compressed_bytes();
		while ( $remaining > 0 ) {
			$length = min( self::CHUNK_BYTES, $remaining );
			$this->write_plain( $target, $this->read_exact( $source, $length ), $entry->byte_size(), $sha, $crc, $written );
			$remaining -= $length;
		}
	}

	/**
	 * Inflate.
	 *
	 * @param mixed                $source Source.
	 * @param mixed                $target Target.
	 * @param Submission_Zip_Entry $entry Entry.
	 * @param mixed                $sha Sha.
	 * @param mixed                $crc Crc.
	 * @param int                  &$written Written.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function inflate( mixed $source, mixed $target, Submission_Zip_Entry $entry, mixed $sha, mixed $crc, int &$written ): void {
		if ( ! function_exists( 'inflate_init' ) || ! function_exists( 'inflate_add' ) ) {
			throw new RuntimeException( 'Submission ZIP deflate support is unavailable.' );
		}
		$inflater = inflate_init( ZLIB_ENCODING_RAW );
		if ( false === $inflater ) {
			throw new RuntimeException( 'Submission ZIP inflater is unavailable.' );
		}
		$remaining = $entry->compressed_bytes();
		do {
			$length     = min( self::CHUNK_BYTES, $remaining );
			$chunk      = 0 === $length ? '' : $this->read_exact( $source, $length );
			$remaining -= $length;
			$plain      = inflate_add( $inflater, $chunk, 0 === $remaining ? ZLIB_FINISH : ZLIB_SYNC_FLUSH );
			if ( false === $plain ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Zip_Import_Failure::because( Zip_Import_Failure::UNSAFE_ARCHIVE );
			}
			$this->write_plain( $target, $plain, $entry->byte_size(), $sha, $crc, $written );
		} while ( $remaining > 0 );
	}

	/**
	 * Write plain.
	 *
	 * @param mixed  $target Target.
	 * @param string $plain Plain.
	 * @param int    $maximum Maximum.
	 * @param mixed  $sha Sha.
	 * @param mixed  $crc Crc.
	 * @param int    &$written Written.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function write_plain( mixed $target, string $plain, int $maximum, mixed $sha, mixed $crc, int &$written ): void {
		$length = strlen( $plain );
		if ( $written + $length > $maximum || $written + $length > Submission_Zip_Upload_Profile::MAX_ENTRY_BYTES ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Zip_Import_Failure::because( Zip_Import_Failure::LIMIT_EXCEEDED );
		}
		$offset = 0;
		while ( $offset < $length ) {
			$count = fwrite( $target, substr( $plain, $offset ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
			if ( false === $count || 0 === $count ) {
				throw new RuntimeException( 'Submission ZIP temporary write failed.' );
			}
			$offset += $count;
		}
		hash_update( $sha, $plain );
		hash_update( $crc, $plain );
		$written += $length;
	}

	/**
	 * Safe path.
	 *
	 * @param string $name Name.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function safe_path( string $name ): void {
		if (
			'' === $name || strlen( $name ) > Submission_Zip_Upload_Profile::MAX_PATH_BYTES
			|| 1 !== preg_match( '//u', $name ) || 1 === preg_match( '/[\x00-\x1F\x7F\\\\]/', $name )
			|| str_starts_with( $name, '/' ) || 1 === preg_match( '/^[A-Za-z]:/', $name )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Zip_Import_Failure::because( Zip_Import_Failure::UNSAFE_ARCHIVE );
		}
		foreach ( explode( '/', rtrim( $name, '/' ) ) as $segment ) {
			if ( '' === $segment || '.' === $segment || '..' === $segment ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Zip_Import_Failure::because( Zip_Import_Failure::UNSAFE_ARCHIVE );
			}
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
			throw Zip_Import_Failure::because( Zip_Import_Failure::UNSAFE_ARCHIVE );
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
}
