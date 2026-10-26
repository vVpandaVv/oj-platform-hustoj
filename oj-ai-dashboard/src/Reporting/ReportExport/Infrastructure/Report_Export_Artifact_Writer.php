<?php
/**
 * Report export artifact writer.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\ReportExport\Infrastructure;

use OJAI\Dashboard\Reporting\Export\Domain\Generated_Export_File;
use OJAI\Dashboard\Reporting\Export\Infrastructure\Zip_Entry_Integrity;
use OJAI\Dashboard\Reporting\ReportExport\Application\Report_Export_Failure;
use OJAI\Dashboard\Reporting\ReportExport\Domain\Report_Export_Request_Snapshot;
use OJAI\Dashboard\Reporting\ReportExport\Domain\Report_Print_Html_Serializer;
use OJAI\Dashboard\Reporting\ReportExport\Port\Report_Export_Writer_Port_Interface;
use OJAI\Dashboard\Reporting\WorksheetPdf\Port\Html_To_Pdf_Renderer_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Emits bounded CSV, report PDF, or deterministic JSON package bytes.
 */
final class Report_Export_Artifact_Writer implements Report_Export_Writer_Port_Interface {
	private const ZIP_EPOCH   = 315532800;
	private const CSV_COLUMNS = array(
		'worksheet_id',
		'worksheet_title',
		'worksheet_state',
		'subject_label',
		'publication_id',
		'publication_number',
		'publication_state',
		'published_at_utc',
		'rubric_revision_id',
		'rubric_total_points',
		'rubric_criterion_count',
		'assignment_count',
		'target_count',
		'submission_rate',
		'assessment_rate',
		'release_rate',
		'average_score',
		'review_required_count',
		'updated_at_utc',
	);

	/**
	 * Initialize report export artifact writer dependencies and state.
	 *
	 * @param Canonical_Json                      $canonical Canonical JSON encoder used for stable digests.
	 * @param Html_To_Pdf_Renderer_Port_Interface $pdf Pdf.
	 * @param Report_Print_Html_Serializer        $html Html.
	 */
	public function __construct(
		private Canonical_Json $canonical,
		private Html_To_Pdf_Renderer_Port_Interface $pdf,
		private Report_Print_Html_Serializer $html
	) {
	}

	/**
	 * Write.
	 *
	 * @param Report_Export_Request_Snapshot $snapshot Snapshot.
	 * @param string                         $source_manifest_json Source manifest json.
	 * @return Generated_Export_File
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function write( Report_Export_Request_Snapshot $snapshot, string $source_manifest_json ): Generated_Export_File {
		return match ( $snapshot->artifact_kind() ) {
			'report_csv' => $this->csv( $snapshot ),
			'report_pdf' => $this->pdf( $snapshot ),
			'report_json_package' => $this->json_package( $snapshot, $source_manifest_json ),
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			default => throw Report_Export_Failure::because( Report_Export_Failure::PROFILE_UNSUPPORTED ),
		};
	}

	/**
	 * Csv.
	 *
	 * @param Report_Export_Request_Snapshot $snapshot Snapshot.
	 * @return Generated_Export_File
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function csv( Report_Export_Request_Snapshot $snapshot ): Generated_Export_File {
		$path   = $this->temporary( 'oj-ai-report-csv-' );
		$handle = fopen( $path, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
		if ( false === $handle ) {
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort cleanup of an owned temporary path must not replace the primary outcome with an OS warning. Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Report_Export_Failure::because( Report_Export_Failure::STORAGE_UNAVAILABLE );
		}
		$neutralized = 0;
		try {
			if ( 3 !== fwrite( $handle, "\xEF\xBB\xBF" ) || false === fputcsv( $handle, self::CSV_COLUMNS, ',', '"', '', "\r\n" ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
				throw Report_Export_Failure::because( Report_Export_Failure::STORAGE_UNAVAILABLE );
			}
			foreach ( $snapshot->projection()['items'] as $item ) {
				$row = $this->csv_row( $item );
				foreach ( $row as &$cell ) {
					$cell = $this->neutralize( $cell, $neutralized );
				} unset( $cell );
				if ( false === fputcsv( $handle, $row, ',', '"', '', "\r\n" ) ) {
					throw Report_Export_Failure::because( Report_Export_Failure::STORAGE_UNAVAILABLE );
				}
			}
			if ( ! fclose( $handle ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
				$handle = null;
				throw Report_Export_Failure::because( Report_Export_Failure::STORAGE_UNAVAILABLE );
			}
			$handle = null;
			return $this->generated(
				$snapshot,
				$path,
				'csv',
				'text/csv',
				null,
				'oj-ai-report-csv-1.0.0',
				array(
					'column_count'           => count( self::CSV_COLUMNS ),
					'neutralized_cell_count' => $neutralized,
					'row_count'              => $snapshot->row_count(),
				),
				Report_Export_Request_Snapshot::MAXIMUM_BYTES
			);
		} catch ( Throwable $error ) {
			if ( is_resource( $handle ) ) {
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Private local streams and owned temporary paths require native filesystem operations, not WordPress FTP transport.
			} // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
			if ( is_file( $path ) ) {
				@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort removal of an owned temporary path must not mask the primary outcome. Private local streams and owned temporary paths require native filesystem operations, not WordPress FTP transport.
			} // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort cleanup of an owned temporary path must not replace the primary outcome with an OS warning. Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
			if ( $error instanceof Report_Export_Failure ) {
				throw $error;
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Report_Export_Failure::because( Report_Export_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Pdf.
	 *
	 * @param Report_Export_Request_Snapshot $snapshot Snapshot.
	 * @return Generated_Export_File
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function pdf( Report_Export_Request_Snapshot $snapshot ): Generated_Export_File {
		$path = $this->temporary( 'oj-ai-report-pdf-' );
		try {
			$result = $this->pdf->render( $this->html->serialize( $snapshot ) );
			if ( $result->page_count() > 500 || strlen( $result->bytes() ) > 104_857_600 ) {
				throw Report_Export_Failure::because( Report_Export_Failure::TOO_LARGE );
			}
			if ( strlen( $result->bytes() ) !== file_put_contents( $path, $result->bytes(), LOCK_EX ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
				throw Report_Export_Failure::because( Report_Export_Failure::STORAGE_UNAVAILABLE );
			}
			return $this->generated(
				$snapshot,
				$path,
				'pdf',
				'application/pdf',
				$result->page_count(),
				$result->renderer_version(),
				array(
					'page_count' => $result->page_count(),
					'row_count'  => $snapshot->row_count(),
				),
				104_857_600
			);
		} catch ( Throwable $error ) {
			if ( is_file( $path ) ) {
				@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort removal of an owned temporary path must not mask the primary outcome. Private local streams and owned temporary paths require native filesystem operations, not WordPress FTP transport.
			} // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort cleanup of an owned temporary path must not replace the primary outcome with an OS warning. Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
			if ( $error instanceof Report_Export_Failure ) {
				throw $error;
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Report_Export_Failure::because( Report_Export_Failure::RENDER_FAILED );
		}
	}

	/**
	 * Json package.
	 *
	 * @param Report_Export_Request_Snapshot $snapshot Snapshot.
	 * @param string                         $source_manifest_json Source manifest json.
	 * @return Generated_Export_File
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function json_package( Report_Export_Request_Snapshot $snapshot, string $source_manifest_json ): Generated_Export_File {
		if ( ! hash_equals( $snapshot->source_manifest_hash(), hash( 'sha256', $source_manifest_json ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Report_Export_Failure::because( Report_Export_Failure::SOURCE_STALE );
		}
		if ( ! class_exists( '\\ZipArchive' ) || ! in_array( 'setMtimeName', get_class_methods( '\\ZipArchive' ), true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Report_Export_Failure::because( Report_Export_Failure::ZIP_UNAVAILABLE );
		}
		$path      = $this->temporary( 'oj-ai-report-json-' );
		$value     = $snapshot->value();
		$manifest  = $this->canonical->encode(
			array(
				'schema_id'            => 'oj_ai.report_json_package',
				'schema_version'       => '1.0.0',
				'snapshot'             => array(
					'id'    => $snapshot->source_id(),
					'hash'  => $snapshot->source_hash(),
					'state' => $snapshot->source_state(),
				),
				'filter_hash'          => $snapshot->filter_hash(),
				'source_manifest_hash' => $snapshot->source_manifest_hash(),
				'projection_hash'      => $snapshot->projection_hash(),
				'locale'               => $snapshot->locale(),
				'timezone'             => $snapshot->timezone(),
			)
		);
		$report    = $this->canonical->encode(
			array(
				'schema_id'        => 'oj_ai.report_export',
				'schema_version'   => '1.0.0',
				'snapshot_id'      => $snapshot->source_id(),
				'generated_at_utc' => $value['source']['generated_at_utc'],
				'filter'           => $value['export']['filter'],
				'projection'       => $snapshot->projection(),
			)
		);
		$checksums = hash( 'sha256', $manifest ) . "  manifest.json\n" . hash( 'sha256', $report ) . "  report.json\n";
		$entries   = array(
			'checksums.sha256' => $checksums,
			'manifest.json'    => $manifest,
			'report.json'      => $report,
		);
		$zip       = new \ZipArchive();
		$opened    = false;
		try {
			if ( true !== $zip->open( $path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
				throw Report_Export_Failure::because( Report_Export_Failure::ARCHIVE_FAILED );
			}
			$opened = true;
			foreach ( $entries as $name => $bytes ) {
				if ( ! $zip->addFromString( $name, $bytes ) || ! $zip->setCompressionName( $name, \ZipArchive::CM_STORE ) || ! $zip->setMtimeName( $name, self::ZIP_EPOCH ) ) {
					throw Report_Export_Failure::because( Report_Export_Failure::ARCHIVE_FAILED );
				}
				if ( ! $zip->setExternalAttributesName( $name, \ZipArchive::OPSYS_UNIX, 0100644 << 16 ) ) {
					throw Report_Export_Failure::because( Report_Export_Failure::ARCHIVE_FAILED );
				}
			}
			$zip->setArchiveComment( '' );
			if ( ! $zip->close() ) {
				throw Report_Export_Failure::because( Report_Export_Failure::ARCHIVE_FAILED );
			}
			$opened   = false;
			$expected = array_map(
				static fn ( string $bytes ): array => array(
					'byte_size' => strlen( $bytes ),
					'sha256'    => hash( 'sha256', $bytes ),
				),
				$entries
			);
			if ( ! Zip_Entry_Integrity::matches( $path, $expected ) ) {
				throw Report_Export_Failure::because( Report_Export_Failure::ARCHIVE_FAILED );
			}
			return $this->generated(
				$snapshot,
				$path,
				'zip',
				'application/zip',
				null,
				'oj-ai-report-json-1.0.0',
				array(
					'entry_count'              => 3,
					'row_count'                => $snapshot->row_count(),
					'source_manifest_verified' => true,
				),
				Report_Export_Request_Snapshot::MAXIMUM_BYTES
			);
		} catch ( Throwable $error ) {
			if ( $opened ) {
				$zip->close();
			}
			if ( is_file( $path ) ) {
				@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort removal of an owned temporary path must not mask the primary outcome. Private local streams and owned temporary paths require native filesystem operations, not WordPress FTP transport.
			} // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort cleanup of an owned temporary path must not replace the primary outcome with an OS warning. Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
			if ( $error instanceof Report_Export_Failure ) {
				throw $error;
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Report_Export_Failure::because( Report_Export_Failure::ARCHIVE_FAILED );
		}
	}

	/**
	 * Csv row.
	 *
	 * @param array<string,mixed> $item Item.
	 * @return array<int,string>
	 */
	private function csv_row( array $item ): array {
		$worksheet   = $this->map( $item['worksheet'] ?? null );
		$publication = $this->map( $item['publication'] ?? null );
		$rubric      = $this->map( $item['rubric'] ?? null );
		$progress    = $this->map( $item['progress'] ?? null );
		return array_map(
			static fn ( mixed $value ): string => null === $value ? '' : (string) $value,
			array(
				$worksheet['id'] ?? null,
				$worksheet['title'] ?? null,
				$worksheet['state'] ?? null,
				$worksheet['subject_label'] ?? null,
				$publication['id'] ?? null,
				$publication['number'] ?? null,
				$publication['state'] ?? null,
				$publication['published_at_utc'] ?? null,
				$rubric['revision_id'] ?? null,
				$rubric['total_points'] ?? null,
				$rubric['criterion_count'] ?? null,
				$progress['assignment_count'] ?? null,
				$progress['target_count'] ?? null,
				$this->metric_value( $progress['submission_rate'] ?? null ),
				$this->metric_value( $progress['assessment_rate'] ?? null ),
				$this->metric_value( $progress['release_rate'] ?? null ),
				$this->metric_value( $progress['average_score'] ?? null ),
				$progress['review_required_count'] ?? null,
				$item['updated_at_utc'] ?? null,
			)
		);
	}

	/**
	 * Metric value.
	 *
	 * @param mixed $value Value.
	 * @return string|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function metric_value( mixed $value ): ?string {
		$metric = $this->map( $value );
		if ( ! array_key_exists( 'value', $metric ) || ( null !== $metric['value'] && ! is_string( $metric['value'] ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Report_Export_Failure::because( Report_Export_Failure::SOURCE_INVALID );
		}
		return $metric['value'];
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
	 * Map report export artifact writer.
	 *
	 * @param mixed $value Value.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function map( mixed $value ): array {
		if ( ! is_array( $value ) || array_is_list( $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Report_Export_Failure::because( Report_Export_Failure::SOURCE_INVALID );
		} return $value;
	}
	/**
	 * Temporary.
	 *
	 * @param string $prefix Prefix.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function temporary( string $prefix ): string {
		$path = tempnam( sys_get_temp_dir(), $prefix );
		if ( false === $path ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Report_Export_Failure::because( Report_Export_Failure::STORAGE_UNAVAILABLE );
		} return $path;
	}

	/**
	 * Generated.
	 *
	 * @param Report_Export_Request_Snapshot $snapshot Snapshot.
	 * @param string                         $path Path.
	 * @param string                         $suffix Suffix.
	 * @param string                         $mime Mime.
	 * @param int|null                       $pages Pages.
	 * @param string                         $renderer Renderer.
	 * @param array<string,mixed>            $details Details.
	 * @param int                            $maximum Maximum.
	 * @return Generated_Export_File
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function generated( Report_Export_Request_Snapshot $snapshot, string $path, string $suffix, string $mime, ?int $pages, string $renderer, array $details, int $maximum ): Generated_Export_File {
		$bytes  = filesize( $path );
		$sha256 = hash_file( 'sha256', $path );
		if ( ! is_int( $bytes ) || $bytes < 1 || $bytes > $maximum || ! is_string( $sha256 ) ) {
			if ( is_file( $path ) ) {
				@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort removal of an owned temporary path must not mask the primary outcome. Private local streams and owned temporary paths require native filesystem operations, not WordPress FTP transport.
			} // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort cleanup of an owned temporary path must not replace the primary outcome with an OS warning. Owned local/private storage requires native streams and filesystem semantics, not WordPress FTP transport.
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Report_Export_Failure::because( is_int( $bytes ) && $bytes > $maximum ? Report_Export_Failure::TOO_LARGE : Report_Export_Failure::STORAGE_UNAVAILABLE );
		}
		return new Generated_Export_File( $snapshot->artifact_kind(), $path, 'report-' . strtolower( $snapshot->source_id() ) . '.' . $suffix, $mime, $bytes, $sha256, $pages, $renderer, $details );
	}
}
