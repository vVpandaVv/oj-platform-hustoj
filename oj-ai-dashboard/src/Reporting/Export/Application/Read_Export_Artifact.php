<?php
/**
 * Read export artifact.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Reporting\Export\Application;

use Closure;
use OJAI\Dashboard\Reporting\Export\Port\Export_Query_Port_Interface;
use OJAI\Dashboard\Security\Policy\Access_Policy_Request;
use OJAI\Dashboard\Security\Policy\Port\Access_Policy_Port_Interface;
use OJAI\Dashboard\Storage\Port\Private_File_Read_Port_Interface;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Read export artifact.
 */
final class Read_Export_Artifact {
	public const ACTION = 'export.artifact.download';
	/**
	 * Clock.
	 *
	 * @var Closure
	 */
	private Closure $clock;
	/**
	 * Access.
	 *
	 * @var Export_Access_Guard
	 */
	private Export_Access_Guard $access;
	/**
	 * Initialize export artifact dependencies and state.
	 *
	 * @param Export_Query_Port_Interface      $query Query.
	 * @param Private_File_Read_Port_Interface $files Files.
	 * @param Access_Policy_Port_Interface     $policy Policy.
	 * @param callable|null                    $clock Clock.
	 */
	public function __construct( private Export_Query_Port_Interface $query, private Private_File_Read_Port_Interface $files, Access_Policy_Port_Interface $policy, ?callable $clock = null ) {
		$this->access = new Export_Access_Guard( $policy );
		$this->clock  = null === $clock ? static fn(): string=>gmdate( 'Y-m-d H:i:s' ) : Closure::fromCallable( $clock );
	}
	/**
	 * Execute export artifact.
	 *
	 * @param int    $actor Actor.
	 * @param string $artifact_id Artifact id.
	 * @param string $correlation Correlation.
	 * @return Export_Download
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function execute( int $actor, string $artifact_id, string $correlation ): Export_Download {
		$row = $this->query->find_artifact_for_actor( $actor, $artifact_id );
		if ( null === $row ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Export_Failure::because( Export_Failure::NOT_FOUND );
		}
		if ( ! $this->access->allows( $actor, $row, Access_Policy_Request::SURFACE_ARTIFACT, self::ACTION, $correlation ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Export_Failure::because( Export_Failure::ACCESS_DENIED );
		}
		if ( (string) $row['expires_at_utc'] <= (string) ( $this->clock )() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Export_Failure::because( Export_Failure::EXPIRED );
		}
		if ( 'ready' !== $row['artifact_state'] || 'ready' !== $row['file_state'] || 'clean' !== $row['scan_state'] ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Export_Failure::because( Export_Failure::NOT_READY );
		}
		try {
			$path = $this->files->readable_path( (string) $row['storage_key'], (string) $row['sha256'] );
		} catch ( Throwable ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Export_Failure::because( Export_Failure::STORAGE_UNAVAILABLE );
		}
		$size = filesize( $path );
		if ( ! is_int( $size ) || $size !== (int) $row['byte_size'] ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Export_Failure::because( Export_Failure::STORED_DATA_INVALID );
		}
		return new Export_Download( $path, (string) $row['original_name'], (string) $row['mime_type'], (string) $row['artifact_kind'], (int) $row['byte_size'], (string) $row['sha256'] );
	}
}
