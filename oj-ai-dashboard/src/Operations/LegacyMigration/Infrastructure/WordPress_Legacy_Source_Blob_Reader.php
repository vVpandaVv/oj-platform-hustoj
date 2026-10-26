<?php
/**
 * WordPress legacy source blob reader.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\LegacyMigration\Infrastructure;

use InvalidArgumentException;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Legacy_Target_Transformation_Failure;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Source_Blob;
use OJAI\Dashboard\Operations\LegacyMigration\Port\Legacy_Source_Blob_Reader_Port_Interface;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads only regular files contained by the current WordPress uploads root.
 */
final class WordPress_Legacy_Source_Blob_Reader implements Legacy_Source_Blob_Reader_Port_Interface {
	/**
	 * Read WordPress legacy source blob reader.
	 *
	 * @param int $file_id File id.
	 * @return Legacy_Source_Blob
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function read( int $file_id ): Legacy_Source_Blob {
		if ( $file_id < 1 || ! function_exists( 'get_attached_file' ) || ! function_exists( 'wp_get_upload_dir' ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::SOURCE_INVALID );
		}

		try {
			$source  = get_attached_file( $file_id, true );
			$uploads = wp_get_upload_dir();
			$base    = is_array( $uploads ) ? ( $uploads['basedir'] ?? null ) : null;
			if ( ! is_string( $source ) || '' === $source || ! is_string( $base ) || '' === $base ) {
				throw new InvalidArgumentException( 'Legacy attachment path is unavailable.' );
			}
			$path = realpath( $source );
			$root = realpath( $base );
			if (
				! is_string( $path ) || ! is_string( $root ) || ! $this->within( $path, $root )
				|| ! is_file( $path ) || ! is_readable( $path ) || is_link( $path )
			) {
				throw new InvalidArgumentException( 'Legacy attachment path is unsafe.' );
			}
			$size   = filesize( $path );
			$sha256 = hash_file( 'sha256', $path );
			$name   = basename( str_replace( '\\', '/', $path ) );
			if ( ! is_int( $size ) || $size < 1 || ! is_string( $sha256 ) ) {
				throw new InvalidArgumentException( 'Legacy attachment bytes are unavailable.' );
			}
			return new Legacy_Source_Blob( $file_id, $path, $name, $size, $sha256 );
		} catch ( Legacy_Target_Transformation_Failure $failure ) {
			throw $failure;
		} catch ( Throwable ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::SOURCE_INVALID );
		}
	}

	/**
	 * Within.
	 *
	 * @param string $path Path.
	 * @param string $root Root.
	 * @return bool
	 */
	private function within( string $path, string $root ): bool {
		$path = strtolower( rtrim( str_replace( '\\', '/', $path ), '/' ) );
		$root = strtolower( rtrim( str_replace( '\\', '/', $root ), '/' ) );
		return $path === $root || str_starts_with( $path, $root . '/' );
	}
}
