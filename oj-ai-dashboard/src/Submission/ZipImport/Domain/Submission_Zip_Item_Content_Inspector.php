<?php
/**
 * Deep content inspection for an extracted submission ZIP item.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\ZipImport\Domain;

use OJAI\Dashboard\Storage\Domain\Local_Pdf_Signature;
use OJAI\Dashboard\Submission\ZipImport\Application\Zip_Import_Failure;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detects a canonical MIME from extension plus bounded magic-byte checks.
 */
final class Submission_Zip_Item_Content_Inspector {
	/**
	 * Submission zip item content inspector.
	 *
	 * @var array<string,string>
	 */
	private const TYPES = array(
		'csv'  => 'text/csv',
		'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'jpeg' => 'image/jpeg',
		'jpg'  => 'image/jpeg',
		'pdf'  => 'application/pdf',
		'png'  => 'image/png',
		'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
		'txt'  => 'text/plain',
		'webp' => 'image/webp',
		'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
	);

	/**
	 * Initialize submission zip item content inspector dependencies and state.
	 *
	 * @param Submission_Zip_Entry_Reader $archives Archives.
	 */
	public function __construct( private Submission_Zip_Entry_Reader $archives ) {
	}

	/**
	 * Inspect.
	 *
	 * @param string $path Path.
	 * @param string $relative_path Relative path.
	 * @param int    $byte_size Byte size.
	 * @param string $sha256 Sha256.
	 * @return Submission_Zip_Item_Content_Verdict
	 */
	public function inspect( string $path, string $relative_path, int $byte_size, string $sha256 ): Submission_Zip_Item_Content_Verdict {
		if ( ! is_file( $path ) || ! is_readable( $path ) || is_link( $path ) ) {
			return Submission_Zip_Item_Content_Verdict::unavailable( 'zip_item_inspector_unavailable' );
		}
		$name = basename( $relative_path );
		if (
			$byte_size < 1 || $byte_size > Submission_Zip_Upload_Profile::MAX_ENTRY_BYTES
			|| '' === $name || strlen( $name ) > 255 || 1 === preg_match( '/[\x00-\x1F\x7F]/', $name )
		) {
			return Submission_Zip_Item_Content_Verdict::rejected( 0 === $byte_size ? 'zip_item_empty' : 'zip_item_name_invalid' );
		}
		$extension = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( ! isset( self::TYPES[ $extension ] ) ) {
			return Submission_Zip_Item_Content_Verdict::rejected( 'zip_item_type_not_allowed' );
		}

		try {
			$valid = match ( $extension ) {
				'jpg', 'jpeg' => $this->image( $path, IMAGETYPE_JPEG ),
				'png' => $this->image( $path, IMAGETYPE_PNG ),
				'webp' => $this->image( $path, defined( 'IMAGETYPE_WEBP' ) ? IMAGETYPE_WEBP : 18 ),
				'pdf' => Local_Pdf_Signature::matches( $path ),
				'txt', 'csv' => $this->text( $path ),
				'docx', 'xlsx', 'pptx' => $this->office( $path, $sha256, $extension ),
			};
		} catch ( Zip_Import_Failure ) {
			return Submission_Zip_Item_Content_Verdict::rejected( 'zip_item_type_mismatch' );
		} catch ( Throwable ) {
			return Submission_Zip_Item_Content_Verdict::unavailable( 'zip_item_inspector_unavailable' );
		}
		return $valid
			? Submission_Zip_Item_Content_Verdict::clean( self::TYPES[ $extension ] )
			: Submission_Zip_Item_Content_Verdict::rejected( 'zip_item_type_mismatch' );
	}

	/**
	 * Image.
	 *
	 * @param string $path Path.
	 * @param int    $expected_type Expected type.
	 * @return bool
	 */
	private function image( string $path, int $expected_type ): bool {
		$size = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Native warnings can expose private paths; the failure return is checked and mapped to a stable error.
		if ( false === $size || $size[2] !== $expected_type ) {
			return false;
		}
		$width  = $size[0];
		$height = $size[1];
		return $width > 0 && $height > 0 && $width <= 20000 && $height <= 20000 && $width * $height <= 100000000;
	}

	/**
	 * Text.
	 *
	 * @param string $path Path.
	 * @return bool
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function text( string $path ): bool {
		$contents = @file_get_contents( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Native warnings can expose private paths; the failure return is checked and mapped to a stable error. Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
		if ( ! is_string( $contents ) ) {
			throw new \RuntimeException( 'Text cannot be read.' );
		}
		if ( str_contains( $contents, "\0" ) || 1 !== preg_match( '//u', $contents ) ) {
			return false;
		}
		$without_bom = preg_replace( '/^\xEF\xBB\xBF/u', '', $contents );
		$trimmed     = ltrim( is_string( $without_bom ) ? $without_bom : $contents );
		if (
			str_starts_with( $trimmed, '#!' )
			|| 1 === preg_match( '/<\?(?:php|xml)\b|<!doctype\s+html\b|<(?:html|script|svg)(?:\s|>)/i', $trimmed )
		) {
			return false;
		}
		if ( str_starts_with( $trimmed, '{' ) || str_starts_with( $trimmed, '[' ) ) {
			try {
				json_decode( $trimmed, true, 512, JSON_THROW_ON_ERROR );
				return false;
			} catch ( \JsonException ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- This optional step must not replace the surrounding operation outcome.
				// Brackets used in ordinary prose remain valid text.
			}
		}
		return true;
	}

	/**
	 * Office.
	 *
	 * @param string $path Path.
	 * @param string $sha256 Sha256.
	 * @param string $extension Extension.
	 * @return bool
	 */
	private function office( string $path, string $sha256, string $extension ): bool {
		$required_root = array(
			'docx' => 'word/',
			'xlsx' => 'xl/',
			'pptx' => 'ppt/',
		)[ $extension ];
		$content_types = false;
		$root_seen     = false;
		foreach ( $this->archives->entries( $path, $sha256 ) as $entry ) {
			$name = strtolower( $entry->relative_path() );
			if ( 1 === preg_match( '/\.(?:exe|dll|com|bat|cmd|ps1|js|vbs|wsf|hta|html?|svg|jar|msi|scr|lnk|chm|iso|img|bin)$/iD', $name ) ) {
				return false;
			}
			$content_types = $content_types || '[content_types].xml' === $name;
			$root_seen     = $root_seen || str_starts_with( $name, $required_root );
		}
		return $content_types && $root_seen;
	}
}
