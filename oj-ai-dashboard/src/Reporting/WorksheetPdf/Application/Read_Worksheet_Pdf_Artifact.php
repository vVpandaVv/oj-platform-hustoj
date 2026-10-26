<?php
/**
 * Read worksheet pdf artifact.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Reporting\WorksheetPdf\Application;

use Closure;
use OJAI\Dashboard\Reporting\WorksheetPdf\Port\Worksheet_Pdf_Query_Port_Interface;
use OJAI\Dashboard\Security\Addon_Capabilities;
use OJAI\Dashboard\Security\Policy\Access_Policy_Request;
use OJAI\Dashboard\Security\Policy\Object_Scope;
use OJAI\Dashboard\Security\Policy\Port\Access_Policy_Port_Interface;
use OJAI\Dashboard\Storage\Port\Private_File_Read_Port_Interface;
use Throwable;
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read worksheet pdf artifact.
 */
final class Read_Worksheet_Pdf_Artifact {
	public const ACTION = 'worksheet.pdf.artifact.download';
	/**
	 * Clock.
	 *
	 * @var Closure
	 */
	private Closure $clock;
	/**
	 * Initialize worksheet pdf artifact dependencies and state.
	 *
	 * @param Worksheet_Pdf_Query_Port_Interface $query Query.
	 * @param Private_File_Read_Port_Interface   $files Files.
	 * @param Access_Policy_Port_Interface       $policy Policy.
	 * @param callable|null                      $clock Clock.
	 */
	public function __construct( private Worksheet_Pdf_Query_Port_Interface $query, private Private_File_Read_Port_Interface $files, private Access_Policy_Port_Interface $policy, ?callable $clock = null ) {
		$this->clock = null === $clock ? static fn(): string=>gmdate( 'Y-m-d H:i:s' ) : Closure::fromCallable( $clock );
	}
	/**
	 * Execute worksheet pdf artifact.
	 *
	 * @param int    $actor Actor.
	 * @param string $artifact_id Artifact id.
	 * @param string $correlation Correlation.
	 * @return Worksheet_Pdf_Download
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function execute( int $actor, string $artifact_id, string $correlation ): Worksheet_Pdf_Download {
		$row = $this->query->find_artifact_for_actor( $actor, $artifact_id );
		if ( null === $row ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Pdf_Failure::because( Worksheet_Pdf_Failure::NOT_FOUND );
		}
		if ( ! $this->authorized( $actor, (string) $row['source_id'], $correlation ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Pdf_Failure::because( Worksheet_Pdf_Failure::ACCESS_DENIED );
		}
		if ( (string) $row['expires_at_utc'] <= (string) ( $this->clock )() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Pdf_Failure::because( Worksheet_Pdf_Failure::EXPIRED );
		}
		if ( 'ready' !== $row['artifact_state'] || 'ready' !== $row['file_state'] || 'clean' !== $row['scan_state'] || 'application/pdf' !== $row['mime_type'] ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Pdf_Failure::because( Worksheet_Pdf_Failure::NOT_READY );
		}
		try {
			$path = $this->files->readable_path( (string) $row['storage_key'], (string) $row['sha256'] );
		} catch ( Throwable ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Pdf_Failure::because( Worksheet_Pdf_Failure::STORAGE_UNAVAILABLE );
		}
		if ( filesize( $path ) !== (int) $row['byte_size'] ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Pdf_Failure::because( Worksheet_Pdf_Failure::STORED_DATA_INVALID );
		}
		return new Worksheet_Pdf_Download( $path, (string) $row['original_name'], (int) $row['byte_size'], (string) $row['sha256'] );
	}
	/**
	 * Authorized.
	 *
	 * @param int    $actor Actor.
	 * @param string $revision Revision.
	 * @param string $correlation Correlation.
	 * @return bool
	 */
	private function authorized( int $actor, string $revision, string $correlation ): bool {
		try {
			$d = $this->policy->authorize( new Access_Policy_Request( $actor, Access_Policy_Request::SHELL_CONSOLE, Access_Policy_Request::SURFACE_ARTIFACT, self::ACTION, array( Addon_Capabilities::EXPORT_REPORTS ), null, new Object_Scope( Object_Scope::TYPE_WORKSHEET_REVISION, $revision, 'view', $revision ), true, $correlation ) );
			return $d->is_allowed() && null !== $d->object() && 'owner' === $d->object()->actor_relation();
		} catch ( Throwable ) {
			return false;
		}
	}
}
