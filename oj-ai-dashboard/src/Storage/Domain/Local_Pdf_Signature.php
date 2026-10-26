<?php
/**
 * Bounded PDF signature checks shared by direct and ZIP submission inspection.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Storage\Domain;

use RuntimeException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Inspect local header and trailer bytes without loading a complete PDF.
 */
final class Local_Pdf_Signature {
	/**
	 * Distinguish invalid content from an unavailable private file.
	 *
	 * @param string $path Internal path resolved by private storage.
	 * @return bool
	 * @throws RuntimeException When the local file cannot be inspected.
	 */
	public static function matches( string $path ): bool {
		if ( ! is_file( $path ) || ! is_readable( $path ) || is_link( $path ) ) {
			throw new RuntimeException( 'PDF cannot be read.' );
		}
		$handle = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Private local seekable stream; failures return a path-free error.
		if ( false === $handle ) {
			throw new RuntimeException( 'PDF cannot be read.' );
		}
		try {
			$stat   = fstat( $handle );
			$prefix = fread( $handle, 8 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Read only the private stream's header.
			if ( false === $stat || false === $prefix || 0 !== fseek( $handle, max( 0, $stat['size'] - 1024 ), SEEK_SET ) ) {
				throw new RuntimeException( 'PDF cannot be read.' );
			}
			$tail = stream_get_contents( $handle, 1024 );
			if ( false === $tail ) {
				throw new RuntimeException( 'PDF cannot be read.' );
			}
			return str_starts_with( $prefix, '%PDF-' ) && str_contains( $tail, '%%EOF' );
		} finally {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the owned local stream on every return or failure.
		}
	}
}
