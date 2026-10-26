<?php
/**
 * Student file content.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Submission\Student\Application;

use RuntimeException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Holds only verified bytes. A path replacement cannot affect a pending response.
 */
final class Student_File_Content {
	/**
	 * Snapshot.
	 *
	 * @var resource|null
	 */
	private $snapshot = null;

	/**
	 * Initialize student file content dependencies and state.
	 *
	 * @param string $path Path.
	 * @param array  $metadata Metadata.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct( string $path, private array $metadata ) {
		$source = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native warnings can expose private paths; the failure return is checked and mapped to a stable error. Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
		// Bounded by the upload profile; never spill student bytes into a public temp directory.
		$this->snapshot = fopen( 'php://memory', 'w+b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
		if ( false === $source || false === $this->snapshot ) {
			if ( is_resource( $source ) ) {
				fclose( $source ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Private local streams and owned temporary paths require native filesystem operations, not WordPress FTP transport.
			} // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
			$this->close();
			throw new RuntimeException( 'File snapshot unavailable.' );
		}
		try {
			$hash = hash_init( 'sha256' );
			$size = 0;
			while ( ! feof( $source ) ) {
				$chunk = fread( $source, 65536 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
				if ( false === $chunk || ( '' === $chunk && ! feof( $source ) ) ) {
					throw new RuntimeException( 'File read failed.' );
				}
				$size += strlen( $chunk );
				if ( $size > $metadata['byte_size'] || fwrite( $this->snapshot, $chunk ) !== strlen( $chunk ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
					throw new RuntimeException( 'File size mismatch.' );
				}
				hash_update( $hash, $chunk );
			}
			if ( $size !== $metadata['byte_size'] || ! hash_equals( $metadata['sha256'], hash_final( $hash ) ) ) {
				throw new RuntimeException( 'File integrity mismatch.' );
			}
			rewind( $this->snapshot );
		} catch ( \Throwable $failure ) {
			$this->close();
			throw $failure;
		} finally {
			fclose( $source ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Private local streams and owned temporary paths require native filesystem operations, not WordPress FTP transport.
		} // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
	}

	/**
	 * Matches.
	 *
	 * @param array<string,mixed> $metadata Metadata.
	 * @return bool
	 */
	public function matches( array $metadata ): bool {
		return $this->metadata === $metadata;
	}
	/**
	 * Headers.
	 *
	 * @return array
	 */
	public function headers(): array {
		return array(
			'Content-Type'                 => 'application/octet-stream',
			'X-OJ-AI-File-Type'            => 1 === preg_match( '/\A[a-z0-9][a-z0-9.+-]*\/[a-z0-9][a-z0-9.+-]*\z/D', $this->metadata['mime_type'] ) ? $this->metadata['mime_type'] : 'application/octet-stream',
			'Content-Length'               => (string) $this->metadata['byte_size'],
			'Content-Disposition'          => 'attachment; filename="download"; filename*=UTF-8\'\'' . rawurlencode( $this->metadata['original_name'] ),
			'Cache-Control'                => 'private, no-store, max-age=0, must-revalidate',
			'Pragma'                       => 'no-cache',
			'Vary'                         => 'Cookie, X-WP-Nonce',
			'X-Content-Type-Options'       => 'nosniff',
			'Content-Security-Policy'      => "sandbox; default-src 'none'; frame-ancestors 'none'",
			'Cross-Origin-Resource-Policy' => 'same-origin',
		);
	}
	/**
	 * Emit.
	 *
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function emit(): void {
		if ( ! is_resource( $this->snapshot ) ) {
			throw new RuntimeException( 'File response already consumed.' );
		}
		try {
			while ( ! feof( $this->snapshot ) ) {
				$chunk = fread( $this->snapshot, 65536 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
				if ( false === $chunk ) {
					throw new RuntimeException( 'File response unavailable.' );
				}
				// Already hash-verified binary bytes, not HTML.
				echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		} finally {
			$this->close();
		}
	}
	/**
	 * Close.
	 *
	 * @return void
	 */
	public function close(): void {
		if ( is_resource( $this->snapshot ) ) {
			fclose( $this->snapshot ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
		} $this->snapshot = null;
	}
	/**
	 * Release resources owned by this instance.
	 */
	public function __destruct() {
		$this->close();
	}
}
