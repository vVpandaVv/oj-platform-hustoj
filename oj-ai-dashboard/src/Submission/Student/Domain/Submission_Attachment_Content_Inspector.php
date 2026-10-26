<?php
/**
 * Deep, bounded validation for staged student attachment bytes.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Student\Domain;

use JsonException;
use OJAI\Dashboard\Storage\Domain\Local_Pdf_Signature;
use RuntimeException;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Performs content checks only after an external malware scanner reports clean.
 */
final class Submission_Attachment_Content_Inspector {
	private const MAX_OFFICE_ENTRIES       = 2000;
	private const MAX_OFFICE_ENTRY_BYTES   = 50 * 1024 * 1024;
	private const MAX_OFFICE_TOTAL_BYTES   = 100 * 1024 * 1024;
	private const MAX_OFFICE_RATIO         = 20;
	private const MAX_DRAWING_ELEMENTS     = 10000;
	private const MAX_DRAWING_NODES        = 100000;
	private const MAX_EMBEDDED_IMAGE_BYTES = 5 * 1024 * 1024;

	/**
	 * Allowed filename extensions and their declared MIME types.
	 *
	 * @var array<string,string>
	 */
	private const TYPES = array(
		'csv'  => 'text/csv',
		'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'jpeg' => 'image/jpeg',
		'jpg'  => 'image/jpeg',
		'json' => 'application/json',
		'pdf'  => 'application/pdf',
		'png'  => 'image/png',
		'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
		'txt'  => 'text/plain',
		'webp' => 'image/webp',
		'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
	);

	/**
	 * Inspect a local file already resolved through private storage.
	 *
	 * @param string                          $path Internal readable path.
	 * @param Submission_Attachment_Scan_Item $item Claimed attachment metadata.
	 * @return Submission_Attachment_Content_Verdict
	 */
	public function inspect( string $path, Submission_Attachment_Scan_Item $item ): Submission_Attachment_Content_Verdict {
		if ( ! is_file( $path ) || ! is_readable( $path ) || is_link( $path ) ) {
			return Submission_Attachment_Content_Verdict::unavailable( 'attachment_inspector_unavailable' );
		}
		$name = $item->original_name();
		if (
			'' === $name
			|| basename( str_replace( '\\', '/', $name ) ) !== $name
			|| 1 === preg_match( '/[\x00-\x1F\x7F]/', $name )
		) {
			return $this->rejected( 'attachment_type_mismatch' );
		}
		$extension = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( ! isset( self::TYPES[ $extension ] ) || ! hash_equals( self::TYPES[ $extension ], $item->mime_type() ) ) {
			return $this->rejected( 'attachment_type_mismatch' );
		}

		return match ( $extension ) {
			'jpg', 'jpeg' => $this->image( $path, IMAGETYPE_JPEG ),
			'png'         => $this->image( $path, IMAGETYPE_PNG ),
			'webp'        => $this->image( $path, defined( 'IMAGETYPE_WEBP' ) ? IMAGETYPE_WEBP : 18 ),
			'pdf'         => $this->pdf( $path ),
			'txt', 'csv'  => $this->text( $path ),
			'json'        => $this->drawing( $path ),
			'docx', 'xlsx', 'pptx' => $this->office( $path, $extension ),
		};
	}

	/**
	 * Validate image type and bounded pixel dimensions.
	 *
	 * @param string $path Internal readable path.
	 * @param int    $expected_type Native image type constant.
	 * @return Submission_Attachment_Content_Verdict
	 */
	private function image( string $path, int $expected_type ): Submission_Attachment_Content_Verdict {
		$size = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Malformed bytes may warn; the false result is rejected without exposing private paths.
		if ( false === $size || $size[2] !== $expected_type ) {
			return $this->rejected( 'attachment_type_mismatch' );
		}
		$width  = $size[0];
		$height = $size[1];
		if ( $width < 1 || $height < 1 || $width > 20000 || $height > 20000 || $width * $height > 100000000 ) {
			return $this->rejected( 'attachment_image_unsafe' );
		}
		return Submission_Attachment_Content_Verdict::clean();
	}

	/**
	 * Check PDF signature and trailer without reading the entire attachment.
	 *
	 * @param string $path Internal readable path.
	 * @return Submission_Attachment_Content_Verdict
	 */
	private function pdf( string $path ): Submission_Attachment_Content_Verdict {
		try {
			if ( ! Local_Pdf_Signature::matches( $path ) ) {
				return $this->rejected( 'attachment_type_mismatch' );
			}
		} catch ( RuntimeException ) {
			return Submission_Attachment_Content_Verdict::unavailable( 'attachment_inspector_unavailable' );
		}
		return Submission_Attachment_Content_Verdict::clean();
	}

	/**
	 * Reject binary or invalid UTF-8 bytes in a text attachment.
	 *
	 * @param string $path Internal readable path, size checked by the scan worker.
	 * @return Submission_Attachment_Content_Verdict
	 */
	private function text( string $path ): Submission_Attachment_Content_Verdict {
		$contents = @file_get_contents( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Private local file, not a URL; read failures return unavailable.
		if ( ! is_string( $contents ) ) {
			return Submission_Attachment_Content_Verdict::unavailable( 'attachment_inspector_unavailable' );
		}
		if ( str_contains( $contents, "\0" ) || 1 !== preg_match( '//u', $contents ) ) {
			return $this->rejected( 'attachment_type_mismatch' );
		}
		return Submission_Attachment_Content_Verdict::clean();
	}

	/**
	 * Validate the frozen drawing response schema and embedded content.
	 *
	 * @param string $path Internal readable path, size checked by the scan worker.
	 * @return Submission_Attachment_Content_Verdict
	 */
	private function drawing( string $path ): Submission_Attachment_Content_Verdict {
		$contents = @file_get_contents( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Private local file, not a URL; failed reads never pass validation.
		if ( ! is_string( $contents ) || '' === trim( $contents ) ) {
			return $this->rejected( 'attachment_drawing_invalid' );
		}
		try {
			$document = json_decode( $contents, false, 128, JSON_THROW_ON_ERROR );
		} catch ( JsonException ) {
			return $this->rejected( 'attachment_drawing_invalid' );
		}
		if ( ! $document instanceof \stdClass ) {
			return $this->rejected( 'attachment_drawing_invalid' );
		}
		$keys = array_keys( get_object_vars( $document ) );
		sort( $keys, SORT_STRING );
		if ( array( 'appState', 'elements', 'files', 'image', 'source', 'type', 'version' ) !== $keys ) {
			return $this->rejected( 'attachment_drawing_invalid' );
		}
		if (
			'oj-ai-dashboard' !== $document->source
			|| 'excalidraw_response' !== $document->type
			|| 1 !== $document->version
			|| ! is_array( $document->elements )
			|| count( $document->elements ) > self::MAX_DRAWING_ELEMENTS
			|| ! $document->appState instanceof \stdClass // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Frozen Excalidraw wire-format field.
			|| ! $document->files instanceof \stdClass
			|| ! $document->image instanceof \stdClass
		) {
			return $this->rejected( 'attachment_drawing_invalid' );
		}
		foreach ( $document->elements as $element ) {
			if ( ! $element instanceof \stdClass || ! is_string( $element->type ?? null ) || 1 !== preg_match( '/^[a-z][a-z0-9_-]{1,63}$/D', $element->type ) ) {
				return $this->rejected( 'attachment_drawing_invalid' );
			}
		}
		$image      = get_object_vars( $document->image );
		$image_keys = array_keys( $image );
		sort( $image_keys, SORT_STRING );
		if (
			array( 'data_url', 'height', 'mime', 'updated_at', 'width' ) !== $image_keys
			|| 'image/png' !== ( $image['mime'] ?? null )
			|| ! is_int( $image['width'] ?? null ) || $image['width'] < 0 || $image['width'] > 10000
			|| ! is_int( $image['height'] ?? null ) || $image['height'] < 0 || $image['height'] > 10000
			|| ! is_string( $image['updated_at'] ?? null ) || strlen( $image['updated_at'] ) > 64
			|| ! is_string( $image['data_url'] ?? null )
		) {
			return $this->rejected( 'attachment_drawing_invalid' );
		}
		$nodes = 0;
		if ( ! $this->drawing_value_is_safe( $document, 0, $nodes ) ) {
			return $this->rejected( 'attachment_drawing_invalid' );
		}
		return Submission_Attachment_Content_Verdict::clean();
	}

	/**
	 * Bound recursive drawing traversal and reject executable URI payloads.
	 *
	 * @param mixed $value Current JSON value.
	 * @param int   $depth Current nesting depth.
	 * @param int   &$nodes Shared visited-node counter.
	 * @return bool
	 */
	private function drawing_value_is_safe( mixed $value, int $depth, int &$nodes ): bool {
		++$nodes;
		if ( $nodes > self::MAX_DRAWING_NODES || $depth > 64 ) {
			return false;
		}
		if ( is_string( $value ) ) {
			if ( str_contains( $value, "\0" ) || 1 !== preg_match( '//u', $value ) ) {
				return false;
			}
			$trimmed = ltrim( $value );
			if ( 1 === preg_match( '/^(?:javascript|vbscript):/i', $trimmed ) ) {
				return false;
			}
			if ( str_starts_with( strtolower( $trimmed ), 'data:' ) ) {
				if ( ! preg_match( '/^data:image\/(?:png|jpeg|webp);base64,([A-Za-z0-9+\/=\r\n]+)$/D', $trimmed, $match ) ) {
					return false;
				}
				$decoded = base64_decode( str_replace( array( "\r", "\n" ), '', $match[1] ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Strictly decode an allowed image data URI to enforce the decoded byte limit; never execute it.
				if ( false === $decoded || strlen( $decoded ) > self::MAX_EMBEDDED_IMAGE_BYTES ) {
					return false;
				}
			}
			return true;
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				if ( ! $this->drawing_value_is_safe( $item, $depth + 1, $nodes ) ) {
					return false;
				}
			}
			return true;
		}
		if ( $value instanceof \stdClass ) {
			foreach ( get_object_vars( $value ) as $item ) {
				if ( ! $this->drawing_value_is_safe( $item, $depth + 1, $nodes ) ) {
					return false;
				}
			}
			return true;
		}
		return null === $value || is_bool( $value ) || is_int( $value ) || is_float( $value );
	}

	/**
	 * Inspect OpenXML archive metadata without extracting untrusted entries.
	 *
	 * @param string $path Internal readable path.
	 * @param string $extension Validated OpenXML extension.
	 * @return Submission_Attachment_Content_Verdict
	 */
	private function office( string $path, string $extension ): Submission_Attachment_Content_Verdict {
		if ( ! class_exists( '\\ZipArchive' ) ) {
			return Submission_Attachment_Content_Verdict::unavailable( 'attachment_inspector_unavailable' );
		}
		$zip    = new \ZipArchive();
		$opened = false;
		try {
			if ( true !== $zip->open( $path ) ) {
				return $this->rejected( 'attachment_container_unsafe' );
			}
			$opened      = true;
			$entry_count = $zip->numFiles; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Native ZipArchive property name.
			if ( $entry_count < 2 || $entry_count > self::MAX_OFFICE_ENTRIES ) {
				return $this->rejected( 'attachment_container_unsafe' );
			}
			$required_root = array(
				'docx' => 'word/',
				'xlsx' => 'xl/',
				'pptx' => 'ppt/',
			)[ $extension ];
			$content_types = false;
			$root_seen     = false;
			$total_size    = 0;
			$names         = array();
			for ( $index = 0; $index < $entry_count; ++$index ) {
				$stat = $zip->statIndex( $index );
				if ( false === $stat ) {
					return $this->rejected( 'attachment_container_unsafe' );
				}
				$name  = str_replace( '\\', '/', $stat['name'] );
				$lower = strtolower( $name );
				if (
					'' === $name || strlen( $name ) > 500 || str_starts_with( $name, '/' )
					|| in_array( '..', explode( '/', $name ), true ) || str_contains( $name, "\0" )
					|| 1 === preg_match( '/[\x00-\x1F\x7F]/', $name )
					|| 1 === preg_match( '/(?:^|\/)[A-Za-z]:/', $name )
					|| isset( $names[ $lower ] )
					|| 1 === preg_match( '/\.(?:exe|dll|com|bat|cmd|ps1|js|vbs|wsf|hta|html?|svg|jar|msi|scr|lnk|chm|iso|img|bin|zip|rar|7z)$/iD', $name )
				) {
					return $this->rejected( 'attachment_container_unsafe' );
				}
				$names[ $lower ] = true;
				$size            = $stat['size'];
				$compressed      = $stat['comp_size'];
				if ( $size < 0 || $compressed < 0 || $size > self::MAX_OFFICE_ENTRY_BYTES ) {
					return $this->rejected( 'attachment_container_unsafe' );
				}
				$total_size += $size;
				if (
					$total_size > self::MAX_OFFICE_TOTAL_BYTES
					|| ( $size > 0 && 0 === $compressed )
					|| ( $compressed > 0 && $size / $compressed > self::MAX_OFFICE_RATIO )
					|| 0 !== $stat['encryption_method']
					|| $this->zip_entry_is_link( $zip, $index )
				) {
					return $this->rejected( 'attachment_container_unsafe' );
				}
				$content_types = $content_types || '[content_types].xml' === $lower;
				$root_seen     = $root_seen || str_starts_with( $lower, $required_root );
			}
			return $content_types && $root_seen
				? Submission_Attachment_Content_Verdict::clean()
				: $this->rejected( 'attachment_container_unsafe' );
		} catch ( Throwable ) {
			return Submission_Attachment_Content_Verdict::unavailable( 'attachment_inspector_unavailable' );
		} finally {
			if ( $opened ) {
				@$zip->close(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort close of an owned read-only archive; no filesystem paths may be emitted.
			}
		}
	}

	/**
	 * Detect symbolic-link entries without extraction.
	 *
	 * @param \ZipArchive $zip Open archive.
	 * @param int         $index Entry index.
	 * @return bool
	 */
	private function zip_entry_is_link( \ZipArchive $zip, int $index ): bool {
		$operating_system = 0;
		$attributes       = 0;
		if ( ! $zip->getExternalAttributesIndex( $index, $operating_system, $attributes ) ) {
			return false;
		}
		return 0120000 === ( ( $attributes >> 16 ) & 0170000 );
	}

	/**
	 * Return a stable rejection reason instead of raw parser errors.
	 *
	 * @param string $reason_code Public-safe rejection code.
	 * @return Submission_Attachment_Content_Verdict
	 */
	private function rejected( string $reason_code ): Submission_Attachment_Content_Verdict {
		return Submission_Attachment_Content_Verdict::rejected( $reason_code );
	}
}
