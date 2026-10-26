<?php
/**
 * Deterministic submission bundle writer.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\SubmissionBundle\Infrastructure;

use OJAI\Dashboard\Reporting\Export\Domain\Generated_Export_File;
use OJAI\Dashboard\Reporting\Export\Infrastructure\Zip_Entry_Integrity;
use OJAI\Dashboard\Reporting\SubmissionBundle\Application\Submission_Bundle_Failure;
use OJAI\Dashboard\Reporting\SubmissionBundle\Domain\Submission_Bundle_Request_Snapshot;
use OJAI\Dashboard\Reporting\SubmissionBundle\Port\Submission_Bundle_Attachment_Read_Port_Interface;
use OJAI\Dashboard\Reporting\SubmissionBundle\Port\Submission_Bundle_Writer_Port_Interface;
use OJAI\Dashboard\Reporting\SubmissionPdf\Application\Build_Submission_Print_Projection;
use OJAI\Dashboard\Reporting\SubmissionPdf\Domain\Submission_Print_Html_Serializer;
use OJAI\Dashboard\Reporting\WorksheetPdf\Port\Html_To_Pdf_Renderer_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds deterministic teacher-only submission PDFs and allowed originals into one ZIP.
 */
final class Deterministic_Submission_Bundle_Writer implements Submission_Bundle_Writer_Port_Interface {
	private const ZIP_EPOCH        = 315532800;
	private const MANIFEST_COLUMNS = array(
		'target_id',
		'display_name',
		'submission_id',
		'submission_state',
		'submitted_at_utc',
		'official_revision_id',
		'official_revision_hash',
		'pdf_path',
		'attachment_count',
		'attachment_paths',
	);

	/**
	 * Initialize deterministic submission bundle writer dependencies and state.
	 *
	 * @param Build_Submission_Print_Projection                $projections Projections.
	 * @param Submission_Print_Html_Serializer                 $html Html.
	 * @param Html_To_Pdf_Renderer_Port_Interface              $pdf Pdf.
	 * @param Submission_Bundle_Attachment_Read_Port_Interface $attachments Attachments.
	 * @param Canonical_Json                                   $canonical Canonical JSON encoder used for stable digests.
	 */
	public function __construct(
		private Build_Submission_Print_Projection $projections,
		private Submission_Print_Html_Serializer $html,
		private Html_To_Pdf_Renderer_Port_Interface $pdf,
		private Submission_Bundle_Attachment_Read_Port_Interface $attachments,
		private Canonical_Json $canonical
	) {
	}

	/**
	 * Write.
	 *
	 * @param Submission_Bundle_Request_Snapshot $snapshot Snapshot.
	 * @return Generated_Export_File
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function write( Submission_Bundle_Request_Snapshot $snapshot ): Generated_Export_File {
		if ( ! class_exists( '\\ZipArchive' ) || ! in_array( 'setMtimeName', get_class_methods( '\\ZipArchive' ), true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Bundle_Failure::because( Submission_Bundle_Failure::ZIP_UNAVAILABLE );
		}
		$manifest    = $snapshot->manifest();
		$assignment  = $this->map( $manifest['assignment'] ?? null );
		$submissions = $manifest['submissions'] ?? null;
		if ( ! is_array( $submissions ) || ! array_is_list( $submissions ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Bundle_Failure::because( Submission_Bundle_Failure::STORED_DATA_INVALID );
		}
		$entries       = array();
		$manifest_rows = array();
		$neutralized   = 0;
		$page_count    = 0;
		$pdf_renderer  = null;
		try {
			foreach ( $submissions as $item ) {
				$item             = $this->map( $item );
				$target           = $this->map( $item['target'] ?? null );
				$submission       = $this->map( $item['submission'] ?? null );
				$revision         = $this->map( $item['official_revision'] ?? null );
				$item_attachments = $item['attachments'] ?? null;
				if ( ! is_array( $item_attachments ) || ! array_is_list( $item_attachments ) ) {
					throw Submission_Bundle_Failure::because( Submission_Bundle_Failure::STORED_DATA_INVALID );
				}
				$projection = $this->projections->build(
					(string) $revision['id'],
					(string) $revision['hash'],
					(string) $assignment['publication_hash'],
					(string) $revision['attachment_set_hash']
				);
				$this->assert_projection( $snapshot, $assignment, $item, $projection );
				$rendered = $this->pdf->render( $this->html->serialize( $projection['print']['projection'], (string) $revision['id'] ) );
				if ( $rendered->page_count() > 500 || strlen( $rendered->bytes() ) > 104_857_600 ) {
					throw Submission_Bundle_Failure::because( Submission_Bundle_Failure::TOO_LARGE );
				}
				$page_count    += $rendered->page_count();
				$pdf_renderer ??= $rendered->renderer_version();
				if ( ! hash_equals( $pdf_renderer, $rendered->renderer_version() ) ) {
					throw Submission_Bundle_Failure::because( Submission_Bundle_Failure::RENDER_FAILED );
				}
				$pdf_path = (string) ( $item['pdf_path'] ?? '' );
				$this->archive_path( $pdf_path );
				$entries[ $pdf_path ] = array(
					'kind'      => 'string',
					'value'     => $rendered->bytes(),
					'sha256'    => hash( 'sha256', $rendered->bytes() ),
					'byte_size' => strlen( $rendered->bytes() ),
				);

				$attachment_paths = array();
				foreach ( $item_attachments as $attachment ) {
					$attachment   = $this->map( $attachment );
					$archive_path = (string) ( $attachment['archive_path'] ?? '' );
					$this->archive_path( $archive_path );
					$path   = $this->attachments->readable_path( (string) $submission['id'], $attachment );
					$bytes  = filesize( $path );
					$sha256 = hash_file( 'sha256', $path );
					if ( ! is_int( $bytes ) || $bytes !== (int) $attachment['byte_size'] || ! is_string( $sha256 ) || ! hash_equals( (string) $attachment['sha256'], $sha256 ) ) {
						throw Submission_Bundle_Failure::because( Submission_Bundle_Failure::SOURCE_STALE );
					}
					$entries[ $archive_path ] = array(
						'kind'      => 'file',
						'value'     => $path,
						'sha256'    => $sha256,
						'byte_size' => $bytes,
					);
					$attachment_paths[]       = $archive_path;
				}
				$row             = array(
					$target['id'] ?? '',
					$target['display_name'] ?? '',
					$submission['id'] ?? '',
					$submission['state'] ?? '',
					$submission['submitted_at_utc'] ?? '',
					$revision['id'] ?? '',
					$revision['hash'] ?? '',
					$pdf_path,
					count( $item_attachments ),
					implode( ';', $attachment_paths ),
				);
				$manifest_rows[] = array_map(
					function ( mixed $value ) use ( &$neutralized ): string {
						return $this->neutralize( (string) $value, $neutralized );
					},
					$row
				);
			}

			$manifest_csv            = $this->manifest_csv( $manifest_rows );
			$entries['manifest.csv'] = array(
				'kind'      => 'string',
				'value'     => $manifest_csv,
				'sha256'    => hash( 'sha256', $manifest_csv ),
				'byte_size' => strlen( $manifest_csv ),
			);
			ksort( $entries, SORT_STRING );
			$checksums = '';
			foreach ( $entries as $name => $entry ) {
				$checksums .= $entry['sha256'] . '  ' . $name . "\n";
			}
			$entries['checksums.sha256'] = array(
				'kind'      => 'string',
				'value'     => $checksums,
				'sha256'    => hash( 'sha256', $checksums ),
				'byte_size' => strlen( $checksums ),
			);
			ksort( $entries, SORT_STRING );
			if ( count( $entries ) !== $snapshot->entry_count() || count( $entries ) > Submission_Bundle_Request_Snapshot::MAXIMUM_ARCHIVE_FILES ) {
				throw Submission_Bundle_Failure::because( Submission_Bundle_Failure::TOO_LARGE );
			}
			return $this->zip( $snapshot, $entries, $neutralized, $page_count, $pdf_renderer ?? 'dompdf-unknown' );
		} catch ( Submission_Bundle_Failure $failure ) {
			throw $failure;
		} catch ( Throwable ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Bundle_Failure::because( Submission_Bundle_Failure::RENDER_FAILED );
		}
	}

	/**
	 * Reject invalid projection.
	 *
	 * @param Submission_Bundle_Request_Snapshot $snapshot Snapshot.
	 * @param array<string,mixed>                $assignment Assignment.
	 * @param array<string,mixed>                $item Item.
	 * @param array<string,mixed>                $projection Projection.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_projection( Submission_Bundle_Request_Snapshot $snapshot, array $assignment, array $item, array $projection ): void {
		$source              = $this->map( $projection['source'] ?? null );
		$context             = $this->map( $projection['context'] ?? null );
		$submission          = $this->map( $projection['submission'] ?? null );
		$publication         = $this->map( $projection['publication'] ?? null );
		$expected_submission = $this->map( $item['submission'] ?? null );
		$revision            = $this->map( $item['official_revision'] ?? null );
		if (
			! hash_equals( (string) $revision['id'], (string) ( $source['id'] ?? '' ) ) || ! hash_equals( (string) $revision['hash'], (string) ( $source['hash'] ?? '' ) )
			|| ! hash_equals( (string) $expected_submission['id'], (string) ( $submission['id'] ?? '' ) ) || $expected_submission['state'] !== ( $submission['state'] ?? null )
			|| ! hash_equals( (string) $assignment['publication_id'], (string) ( $publication['id'] ?? '' ) )
			|| ! hash_equals( (string) $assignment['publication_hash'], (string) ( $publication['hash'] ?? '' ) )
			|| ! hash_equals( $this->canonical->encode( $snapshot->context() ), $this->canonical->encode( $context ) )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Bundle_Failure::because( Submission_Bundle_Failure::SOURCE_STALE );
		}
	}

	/**
	 * Manifest csv.
	 *
	 * @param array<int,array<int,string>> $rows Rows.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function manifest_csv( array $rows ): string {
		$handle = fopen( 'php://temp', 'w+b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
		if ( false === $handle ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Bundle_Failure::because( Submission_Bundle_Failure::STORAGE_UNAVAILABLE );
		}
		try {
			if ( 3 !== fwrite( $handle, "\xEF\xBB\xBF" ) || false === fputcsv( $handle, self::MANIFEST_COLUMNS, ',', '"', '', "\r\n" ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
				throw Submission_Bundle_Failure::because( Submission_Bundle_Failure::STORAGE_UNAVAILABLE );
			}
			foreach ( $rows as $row ) {
				if ( false === fputcsv( $handle, $row, ',', '"', '', "\r\n" ) ) {
					throw Submission_Bundle_Failure::because( Submission_Bundle_Failure::STORAGE_UNAVAILABLE );
				}
			}
			if ( 0 !== fseek( $handle, 0 ) ) {
				throw Submission_Bundle_Failure::because( Submission_Bundle_Failure::STORAGE_UNAVAILABLE );
			}
			$bytes = stream_get_contents( $handle );
			if ( ! is_string( $bytes ) || '' === $bytes ) {
				throw Submission_Bundle_Failure::because( Submission_Bundle_Failure::STORAGE_UNAVAILABLE );
			}
			return $bytes;
		} finally {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Private local streams and owned temporary paths require native filesystem operations, not WordPress FTP transport.
		} // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
	}

	/**
	 * Zip.
	 *
	 * @param Submission_Bundle_Request_Snapshot                                        $snapshot Snapshot.
	 * @param array<string,array{kind:string,value:string,sha256:string,byte_size:int}> $entries Entries.
	 * @param int                                                                       $neutralized Neutralized.
	 * @param int                                                                       $pages Pages.
	 * @param string                                                                    $pdf_renderer Pdf renderer.
	 * @return Generated_Export_File
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function zip( Submission_Bundle_Request_Snapshot $snapshot, array $entries, int $neutralized, int $pages, string $pdf_renderer ): Generated_Export_File {
		$path = tempnam( sys_get_temp_dir(), 'oj-ai-submissions-' );
		if ( false === $path ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Bundle_Failure::because( Submission_Bundle_Failure::STORAGE_UNAVAILABLE );
		}
		$zip    = new \ZipArchive();
		$opened = false;
		try {
			if ( true !== $zip->open( $path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
				throw Submission_Bundle_Failure::because( Submission_Bundle_Failure::ARCHIVE_FAILED );
			}
			$opened = true;
			foreach ( $entries as $name => $entry ) {
				$added = 'file' === $entry['kind'] ? $zip->addFile( $entry['value'], $name ) : $zip->addFromString( $name, $entry['value'] );
				if ( ! $added || ! $zip->setCompressionName( $name, \ZipArchive::CM_STORE ) || ! $zip->setMtimeName( $name, self::ZIP_EPOCH ) ) {
					throw Submission_Bundle_Failure::because( Submission_Bundle_Failure::ARCHIVE_FAILED );
				}
				if ( ! $zip->setExternalAttributesName( $name, \ZipArchive::OPSYS_UNIX, 0100644 << 16 ) ) {
					throw Submission_Bundle_Failure::because( Submission_Bundle_Failure::ARCHIVE_FAILED );
				}
			}
			$zip->setArchiveComment( '' );
			if ( ! $zip->close() ) {
				throw Submission_Bundle_Failure::because( Submission_Bundle_Failure::ARCHIVE_FAILED );
			}
			$opened   = false;
			$expected = array_map(
				static fn ( array $entry ): array => array(
					'byte_size' => $entry['byte_size'],
					'sha256'    => $entry['sha256'],
				),
				$entries
			);
			if ( ! Zip_Entry_Integrity::matches( $path, $expected ) ) {
				throw Submission_Bundle_Failure::because( Submission_Bundle_Failure::SOURCE_STALE );
			}
			$bytes  = filesize( $path );
			$sha256 = hash_file( 'sha256', $path );
			if ( ! is_int( $bytes ) || $bytes < 1 || $bytes > Submission_Bundle_Request_Snapshot::MAXIMUM_ARCHIVE_BYTES || ! is_string( $sha256 ) ) {
				throw Submission_Bundle_Failure::because( is_int( $bytes ) && $bytes > Submission_Bundle_Request_Snapshot::MAXIMUM_ARCHIVE_BYTES ? Submission_Bundle_Failure::TOO_LARGE : Submission_Bundle_Failure::ARCHIVE_FAILED );
			}
			return new Generated_Export_File(
				Submission_Bundle_Request_Snapshot::ARTIFACT_KIND,
				$path,
				'submissions-' . strtolower( $snapshot->source_id() ) . '.zip',
				'application/zip',
				$bytes,
				$sha256,
				null,
				'oj-ai-submission-bundle-1.0.0',
				array(
					'entry_count'            => count( $entries ),
					'neutralized_cell_count' => $neutralized,
					'pdf_page_count'         => $pages,
					'pdf_renderer_version'   => $pdf_renderer,
					'submission_count'       => $snapshot->submission_count(),
				)
			);
		} catch ( Throwable $error ) {
			if ( $opened ) {
				$zip->close();
			}
			if ( is_file( $path ) ) {
				@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort removal of an owned temporary path must not mask the primary outcome. Private local streams and owned temporary paths require native filesystem operations, not WordPress FTP transport.
			} // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort cleanup of an owned temporary path must not replace the primary outcome with an OS warning. Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
			if ( $error instanceof Submission_Bundle_Failure ) {
				throw $error;
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Bundle_Failure::because( Submission_Bundle_Failure::ARCHIVE_FAILED );
		}
	}

	/**
	 * Archive path.
	 *
	 * @param string $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function archive_path( string $value ): void {
		if ( '' === $value || strlen( $value ) > 512 || str_starts_with( $value, '/' ) || str_contains( $value, '\\' ) || in_array( '..', explode( '/', $value ), true ) || 1 !== preg_match( '/^[a-z0-9][a-z0-9._\/-]*$/D', $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Bundle_Failure::because( Submission_Bundle_Failure::SOURCE_INVALID );
		}
	}
	/**
	 * Neutralize.
	 *
	 * @param string $value Value.
	 * @param int    &$count Count.
	 * @return string
	 */
	private function neutralize( string $value, int &$count ): string {
		// Include LF/NUL and full-width formula prefixes used by locale-aware spreadsheets.
		if ( 1 === preg_match( '/^(?:[=+\-@\t\r\n\x00]|\xEF\xBC[\x8B\x8D\x9D\xA0])/', $value ) ) {
			++$count;
			return "'" . $value;
		}
		return $value;
	}
	/**
	 * Map deterministic submission bundle writer.
	 *
	 * @param mixed $value Value.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function map( mixed $value ): array {
		if ( ! is_array( $value ) || array_is_list( $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Submission_Bundle_Failure::because( Submission_Bundle_Failure::STORED_DATA_INVALID );
		} return $value;
	}
}
