<?php
/**
 * Verifies the bytes actually stored in an export archive.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\Export\Infrastructure;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rejects changed, omitted, duplicated, or unexpected archive entries.
 */
final class Zip_Entry_Integrity {
	/**
	 * Compare a completed ZIP against the captured source identities.
	 *
	 * @param string                                           $path Completed private archive path.
	 * @param array<string,array{byte_size:int,sha256:string}> $expected Expected entry identities.
	 * @return bool Whether every stored entry matches its expected bytes.
	 */
	public static function matches( string $path, array $expected ): bool {
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $path, \ZipArchive::CHECKCONS ) ) {
			return false;
		}
		try {
			if ( count( $expected ) !== $zip->numFiles ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- This property spelling belongs to an external PHP, WordPress or provider contract.
				return false;
			}
			$seen = array();
			for ( $index = 0; $index < $zip->numFiles; ++$index ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- This property spelling belongs to an external PHP, WordPress or provider contract.
				$entry = $zip->statIndex( $index );
				if ( false === $entry || ! isset( $expected[ $entry['name'] ] ) || isset( $seen[ $entry['name'] ] ) ) {
					return false;
				}
				$name     = $entry['name'];
				$identity = $expected[ $name ];
				if ( $entry['size'] !== $identity['byte_size'] ) {
					return false;
				}
				$stream = $zip->getStream( $name );
				if ( false === $stream ) {
					return false;
				}
				try {
					$hash = hash_init( 'sha256' );
					// Read one extra byte to reject data exceeding the captured size.
					$read = hash_update_stream( $hash, $stream, $identity['byte_size'] + 1 );
					if ( $read !== $identity['byte_size'] || ! hash_equals( $identity['sha256'], hash_final( $hash ) ) ) {
						return false;
					}
				} finally {
					fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
				}
				$seen[ $name ] = true;
			}
			return true;
		} finally {
			$zip->close();
		}
	}
}
