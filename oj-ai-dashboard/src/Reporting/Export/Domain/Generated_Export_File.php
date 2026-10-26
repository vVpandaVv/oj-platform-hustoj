<?php
/**
 * Generated export file.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\Export\Domain;

use InvalidArgumentException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Verified temporary artifact emitted by one format writer.
 */
final class Generated_Export_File {
	/**
	 * Initialize generated export file dependencies and state.
	 *
	 * @param string                 $artifact_kind Artifact kind.
	 * @param string                 $path Path.
	 * @param string                 $original_name Original name.
	 * @param string                 $mime_type Mime type.
	 * @param int                    $byte_size Byte size.
	 * @param string                 $sha256 Sha256.
	 * @param int|null               $page_count Page count.
	 * @param string                 $renderer_version Renderer version.
	 * @param array<array-key,mixed> $details Details.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private string $artifact_kind,
		private string $path,
		private string $original_name,
		private string $mime_type,
		private int $byte_size,
		private string $sha256,
		private ?int $page_count,
		private string $renderer_version,
		private array $details = array()
	) {
		$size = is_file( $path ) ? filesize( $path ) : false;
		$hash = is_file( $path ) ? hash_file( 'sha256', $path ) : false;
		if (
			! in_array( $artifact_kind, array( 'report_csv', 'report_pdf', 'report_json_package', 'submission_bundle' ), true )
			|| ! is_int( $size ) || $size !== $byte_size || $byte_size < 1 || $byte_size > 524_288_000
			|| ! is_string( $hash ) || ! hash_equals( $sha256, $hash ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $sha256 )
			|| '' === $original_name || strlen( $original_name ) > 255 || basename( str_replace( '\\', '/', $original_name ) ) !== $original_name
			|| 1 === preg_match( '/[\x00-\x1F\x7F]/', $original_name ) || '' === $mime_type || strlen( $mime_type ) > 191
			|| ( null !== $page_count && $page_count < 1 ) || 1 !== preg_match( '/^[a-z0-9][a-z0-9._-]{0,31}$/D', $renderer_version )
			|| ( array() !== $details && array_is_list( $details ) )
		) {
			throw new InvalidArgumentException( 'Generated export file is invalid.' );
		}
	}

	/**
	 * Artifact kind.
	 *
	 * @return string
	 */
	public function artifact_kind(): string {
		return $this->artifact_kind;
	}
	/**
	 * Path.
	 *
	 * @return string
	 */
	public function path(): string {
		return $this->path;
	}
	/**
	 * Original name.
	 *
	 * @return string
	 */
	public function original_name(): string {
		return $this->original_name;
	}
	/**
	 * Mime type.
	 *
	 * @return string
	 */
	public function mime_type(): string {
		return $this->mime_type;
	}
	/**
	 * Byte size.
	 *
	 * @return int
	 */
	public function byte_size(): int {
		return $this->byte_size;
	}
	/**
	 * Sha256.
	 *
	 * @return string
	 */
	public function sha256(): string {
		return $this->sha256;
	}
	/**
	 * Page count.
	 *
	 * @return int|null
	 */
	public function page_count(): ?int {
		return $this->page_count;
	}
	/**
	 * Renderer version.
	 *
	 * @return string
	 */
	public function renderer_version(): string {
		return $this->renderer_version;
	}
	/**
	 * Details.
	 *
	 * @return array<string,mixed>
	 */
	public function details(): array {
		return $this->details;
	}
}
