<?php
/**
 * Local private binary storage.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Storage\Infrastructure;

use OJAI\Dashboard\Storage\Application\Worksheet_Asset_Failure;
use OJAI\Dashboard\Storage\Port\Private_File_Read_Port_Interface;
use OJAI\Dashboard\Storage\Port\Private_File_Store_Port_Interface;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores private files outside the WordPress public root without public URLs.
 */
final class Local_Private_File_Store implements Private_File_Store_Port_Interface, Private_File_Read_Port_Interface {
	/**
	 * Root.
	 *
	 * @var string
	 */
	private string $root;

	/**
	 * Initialize local private file store dependencies and state.
	 *
	 * @param string|null $root Root.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct( ?string $root = null ) {
		try {
			$this->root = Local_Private_Storage_Root::resolve( $root );
		} catch ( Throwable ) {
			throw Worksheet_Asset_Failure::storage_unavailable();
		}
	}

	/**
	 * Put quarantined.
	 *
	 * @param string $source_path Source path.
	 * @param string $storage_key Storage key.
	 * @param string $sha256 Sha256.
	 * @return bool
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function put_quarantined( string $source_path, string $storage_key, string $sha256 ): bool {
		$this->assert_hash( $sha256 );
		if ( ! is_file( $source_path ) || ! is_readable( $source_path ) || is_link( $source_path ) ) {
			throw Worksheet_Asset_Failure::invalid_upload();
		}

		$destination = $this->path( $storage_key );
		$this->ensure_directory( dirname( $destination ) );
		if ( is_file( $destination ) ) {
			$this->assert_file_hash( $destination, $sha256 );
			return false;
		}

		try {
			$temporary = $destination . '.part-' . bin2hex( random_bytes( 8 ) );
		} catch ( Throwable ) {
			throw Worksheet_Asset_Failure::storage_unavailable();
		}

		$input  = fopen( $source_path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
		$output = fopen( $temporary, 'xb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
		if ( false === $input || false === $output ) {
			if ( is_resource( $input ) ) {
				fclose( $input ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
			}
			if ( is_resource( $output ) ) {
				fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
			}
			$this->remove_partial( $temporary );
			throw Worksheet_Asset_Failure::storage_unavailable();
		}

		$copied        = stream_copy_to_stream( $input, $output );
		$input_closed  = fclose( $input ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
		$output_closed = fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
		if ( false === $copied || ! $input_closed || ! $output_closed || ! @chmod( $temporary, 0600 ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Native warnings can expose private paths; the failure return is checked and mapped to a stable error. Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
			$this->remove_partial( $temporary );
			throw Worksheet_Asset_Failure::storage_unavailable();
		}

		try {
			$this->assert_file_hash( $temporary, $sha256 );
		} catch ( Worksheet_Asset_Failure $failure ) {
			$this->remove_partial( $temporary );
			throw $failure;
		}

		if ( @rename( $temporary, $destination ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.rename_rename -- Native warnings can expose private paths; the failure return is checked and mapped to a stable error. Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
			return true;
		}
		if ( is_file( $destination ) ) {
			$this->remove_partial( $temporary );
			$this->assert_file_hash( $destination, $sha256 );
			return false;
		}

		$this->remove_partial( $temporary );
		throw Worksheet_Asset_Failure::storage_unavailable();
	}

	/**
	 * Delete local private file store.
	 *
	 * @param string $storage_key Storage key.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function delete( string $storage_key ): void {
		$path = $this->path( $storage_key );
		if ( is_link( $path ) || ( file_exists( $path ) && ! is_file( $path ) ) ) {
			throw Worksheet_Asset_Failure::storage_unavailable();
		}
		if ( is_file( $path ) && ! @unlink( $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort cleanup of an owned temporary path must not replace the primary outcome with an OS warning. Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
			throw Worksheet_Asset_Failure::storage_unavailable();
		}
	}
	/**
	 * Readable path.
	 *
	 * @param string $storage_key Storage key.
	 * @param string $sha256 Sha256.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function readable_path( string $storage_key, string $sha256 ): string {
		$this->assert_hash( $sha256 );
		$path = $this->path( $storage_key );
		if ( ! is_file( $path ) || ! is_readable( $path ) || is_link( $path ) ) {
			throw Worksheet_Asset_Failure::storage_unavailable();
		}
		$root = realpath( $this->root );
		$real = realpath( $path );
		if ( false === $root || false === $real || ! $this->contains( $root, $real ) ) {
			throw Worksheet_Asset_Failure::storage_unavailable();
		}
		$this->assert_file_hash( $real, $sha256 );
		return $real;
	}


	/**
	 * Path.
	 *
	 * @param string $storage_key Storage key.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function path( string $storage_key ): string {
		try {
			Local_Private_Storage_Root::assert_private( $this->root );
		} catch ( Throwable ) {
			throw Worksheet_Asset_Failure::storage_unavailable();
		}
		if (
			strlen( $storage_key ) > 512
			|| 1 !== preg_match( '/^[a-z0-9][a-z0-9._\/-]*$/D', $storage_key )
			|| in_array( '..', explode( '/', $storage_key ), true )
		) {
			throw Worksheet_Asset_Failure::storage_unavailable();
		}

		$path = $this->root;
		foreach ( explode( '/', $storage_key ) as $part ) {
			$path .= DIRECTORY_SEPARATOR . $part;
			if ( is_link( $path ) ) {
				throw Worksheet_Asset_Failure::storage_unavailable();
			}
		}
		return $path;
	}

	/**
	 * Ensure directory.
	 *
	 * @param string $directory Directory.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function ensure_directory( string $directory ): void {
		if ( ! is_dir( $directory ) && ! @mkdir( $directory, 0700, true ) && ! is_dir( $directory ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Native warnings can expose private paths; the failure return is checked and mapped to a stable error. Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
			throw Worksheet_Asset_Failure::storage_unavailable();
		}
		$root = realpath( $this->root );
		$real = realpath( $directory );
		if ( false === $root || false === $real || ! $this->contains( $root, $real ) ) {
			throw Worksheet_Asset_Failure::storage_unavailable();
		}
	}

	/**
	 * Contains.
	 *
	 * @param string $parent Parent.
	 * @param string $child Child.
	 * @return bool
	 */
	private function contains( string $parent, string $child ): bool { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.parentFound -- Preserve the established named-argument signature; these parameter names are legal in supported PHP versions.
		$parent = strtolower( rtrim( str_replace( '\\', '/', $parent ), '/' ) ) . '/';
		$child  = strtolower( rtrim( str_replace( '\\', '/', $child ), '/' ) ) . '/';
		return str_starts_with( $child, $parent );
	}

	/**
	 * Reject invalid hash.
	 *
	 * @param string $sha256 Sha256.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_hash( string $sha256 ): void {
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $sha256 ) ) {
			throw Worksheet_Asset_Failure::storage_unavailable();
		}
	}

	/**
	 * Reject invalid file hash.
	 *
	 * @param string $path Path.
	 * @param string $sha256 Sha256.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_file_hash( string $path, string $sha256 ): void {
		$actual = hash_file( 'sha256', $path );
		if ( ! is_string( $actual ) || ! hash_equals( $sha256, $actual ) ) {
			throw Worksheet_Asset_Failure::storage_unavailable();
		}
	}

	/**
	 * Remove partial.
	 *
	 * @param string $path Path.
	 * @return void
	 */
	private function remove_partial( string $path ): void {
		if ( is_file( $path ) ) {
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort cleanup of an owned temporary path must not replace the primary outcome with an OS warning. Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
		}
	}
}
