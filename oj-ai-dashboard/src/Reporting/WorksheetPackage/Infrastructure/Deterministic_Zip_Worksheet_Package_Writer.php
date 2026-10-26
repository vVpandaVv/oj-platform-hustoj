<?php
/**
 * Deterministic zip worksheet package writer.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\WorksheetPackage\Infrastructure;

use OJAI\Dashboard\Reporting\Export\Infrastructure\Zip_Entry_Integrity;
use OJAI\Dashboard\Reporting\WorksheetPackage\Application\Worksheet_Package_Failure;
use OJAI\Dashboard\Reporting\WorksheetPackage\Application\Worksheet_Package_Source;
use OJAI\Dashboard\Reporting\WorksheetPackage\Domain\Worksheet_Package_Archive;
use OJAI\Dashboard\Reporting\WorksheetPackage\Domain\Worksheet_Package_Request_Snapshot;
use OJAI\Dashboard\Reporting\WorksheetPackage\Port\Worksheet_Package_Writer_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deterministic zip worksheet package writer.
 */
final class Deterministic_Zip_Worksheet_Package_Writer implements Worksheet_Package_Writer_Port_Interface {
	private const ZIP_EPOCH = 315532800;

	/**
	 * Initialize deterministic zip worksheet package writer dependencies and state.
	 *
	 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
	 */
	public function __construct( private Canonical_Json $canonical ) {
	}

	/**
	 * Write.
	 *
	 * @param Worksheet_Package_Source $source Source.
	 * @return Worksheet_Package_Archive
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function write( Worksheet_Package_Source $source ): Worksheet_Package_Archive {
		if ( ! class_exists( '\\ZipArchive' ) || ! in_array( 'setMtimeName', get_class_methods( '\\ZipArchive' ), true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Package_Failure::because( Worksheet_Package_Failure::ENVIRONMENT_UNSUPPORTED );
		}
		$snapshot        = $source->snapshot();
		$payload         = array(
			'achievement_links' => $snapshot->achievement_links(),
			'schema_id'         => Worksheet_Package_Request_Snapshot::PAYLOAD_SCHEMA,
			'schema_version'    => Worksheet_Package_Request_Snapshot::FORMAT_VERSION,
			'teacher_document'  => $snapshot->teacher_document(),
		);
		$worksheet_json  = $this->canonical->encode( $payload );
		$manifest_assets = array();
		$entries         = array();
		foreach ( $snapshot->assets() as $asset ) {
			$id      = (string) $asset['source_asset_id'];
			$content = $source->assets()[ $id ] ?? null;
			if ( null === $content || ! is_file( $content->path() ) || $content->byte_size() !== filesize( $content->path() )
				|| ! hash_equals( (string) $asset['sha256'], $content->sha256() )
				|| ! hash_equals( (string) $asset['sha256'], (string) hash_file( 'sha256', $content->path() ) )
				|| (int) $asset['byte_size'] !== $content->byte_size()
				|| ! hash_equals( (string) $asset['mime_type'], $content->mime_type() ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Worksheet_Package_Failure::because( Worksheet_Package_Failure::SOURCE_STALE );
			}
			$manifest_assets[]                  = array(
				'byte_size'       => (int) $asset['byte_size'],
				'mime_type'       => (string) $asset['mime_type'],
				'original_name'   => (string) $asset['original_name'],
				'path'            => (string) $asset['path'],
				'sha256'          => (string) $asset['sha256'],
				'source_asset_id' => $id,
			);
			$entries[ (string) $asset['path'] ] = array(
				'kind'      => 'file',
				'value'     => $content->path(),
				'byte_size' => $content->byte_size(),
				'sha256'    => (string) $asset['sha256'],
			);
		}
		$manifest                  = array(
			'assets'               => $manifest_assets,
			'document_schema'      => (string) $snapshot->value()['package']['document_schema'],
			'editor_profile'       => (string) $snapshot->value()['package']['editor_profile'],
			'exporter_version'     => Worksheet_Package_Request_Snapshot::EXPORTER_VERSION,
			'format'               => Worksheet_Package_Request_Snapshot::FORMAT,
			'format_version'       => Worksheet_Package_Request_Snapshot::FORMAT_VERSION,
			'source_revision_hash' => $snapshot->source_hash(),
			'source_revision_id'   => $snapshot->source_id(),
			'worksheet_sha256'     => hash( 'sha256', $worksheet_json ),
		);
		$manifest_json             = $this->canonical->encode( $manifest );
		$entries['manifest.json']  = array(
			'kind'      => 'string',
			'value'     => $manifest_json,
			'byte_size' => strlen( $manifest_json ),
			'sha256'    => hash( 'sha256', $manifest_json ),
		);
		$entries['worksheet.json'] = array(
			'kind'      => 'string',
			'value'     => $worksheet_json,
			'byte_size' => strlen( $worksheet_json ),
			'sha256'    => hash( 'sha256', $worksheet_json ),
		);
		ksort( $entries, SORT_STRING );

		$temporary = tempnam( sys_get_temp_dir(), 'oj-ai-ojws-' );
		if ( false === $temporary ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Package_Failure::because( Worksheet_Package_Failure::STORAGE_UNAVAILABLE );
		}
		$zip    = new \ZipArchive();
		$opened = false;
		try {
			if ( true !== $zip->open( $temporary, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
				throw Worksheet_Package_Failure::because( Worksheet_Package_Failure::ARCHIVE_FAILED );
			}
			$opened = true;
			foreach ( $entries as $name => $entry ) {
				$added = 'file' === $entry['kind'] ? $zip->addFile( (string) $entry['value'], $name ) : $zip->addFromString( $name, (string) $entry['value'] );
				if ( ! $added || ! $zip->setCompressionName( $name, \ZipArchive::CM_STORE ) || ! $zip->setMtimeName( $name, self::ZIP_EPOCH ) ) {
					throw Worksheet_Package_Failure::because( Worksheet_Package_Failure::ARCHIVE_FAILED );
				}
				if ( ! $zip->setExternalAttributesName( $name, \ZipArchive::OPSYS_UNIX, 0100644 << 16 ) ) {
					throw Worksheet_Package_Failure::because( Worksheet_Package_Failure::ARCHIVE_FAILED );
				}
			}
			$zip->setArchiveComment( '' );
			if ( ! $zip->close() ) {
				throw Worksheet_Package_Failure::because( Worksheet_Package_Failure::ARCHIVE_FAILED );
			}
			$opened   = false;
			$expected = array_map(
				static fn ( array $entry ): array => array(
					'byte_size' => $entry['byte_size'],
					'sha256'    => $entry['sha256'],
				),
				$entries
			);
			if ( ! Zip_Entry_Integrity::matches( $temporary, $expected ) ) {
				throw Worksheet_Package_Failure::because( Worksheet_Package_Failure::SOURCE_STALE );
			}
			$bytes  = filesize( $temporary );
			$sha256 = hash_file( 'sha256', $temporary );
			if ( ! is_int( $bytes ) || $bytes < 1 || $bytes > Worksheet_Package_Request_Snapshot::MAX_PACKAGE_BYTES || ! is_string( $sha256 ) ) {
				throw Worksheet_Package_Failure::because( $bytes > Worksheet_Package_Request_Snapshot::MAX_PACKAGE_BYTES ? Worksheet_Package_Failure::TOO_LARGE : Worksheet_Package_Failure::ARCHIVE_FAILED );
			}
			return new Worksheet_Package_Archive( $temporary, $bytes, $sha256, Worksheet_Package_Request_Snapshot::EXPORTER_VERSION );
		} catch ( Worksheet_Package_Failure $failure ) {
			if ( $opened ) {
				$zip->close();
			}
			if ( is_file( $temporary ) ) {
				@unlink( $temporary ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort removal of an owned temporary path must not mask the primary outcome. Private local streams and owned temporary paths require native filesystem operations, not WordPress FTP transport.
			} // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort cleanup of an owned temporary path must not replace the primary outcome with an OS warning. Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
			throw $failure;
		} catch ( Throwable ) {
			if ( $opened ) {
				$zip->close();
			}
			if ( is_file( $temporary ) ) {
				@unlink( $temporary ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort removal of an owned temporary path must not mask the primary outcome. Private local streams and owned temporary paths require native filesystem operations, not WordPress FTP transport.
			} // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort cleanup of an owned temporary path must not replace the primary outcome with an OS warning. Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Package_Failure::because( Worksheet_Package_Failure::ARCHIVE_FAILED );
		}
	}
}
