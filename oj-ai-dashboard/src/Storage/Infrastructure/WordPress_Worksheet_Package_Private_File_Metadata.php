<?php
/**
 * WordPress worksheet package private file metadata.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Storage\Infrastructure;

use InvalidArgumentException;
use OJAI\Dashboard\Reporting\WorksheetPackage\Application\Worksheet_Package_Failure;
use OJAI\Dashboard\Storage\Port\Generated_Private_File_Metadata_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WordPress worksheet package private file metadata.
 */
final class WordPress_Worksheet_Package_Private_File_Metadata implements Generated_Private_File_Metadata_Port_Interface {
	private const TABLE = 'oj_ai_private_files';
	/**
	 * Initialize WordPress worksheet package private file metadata dependencies and state.
	 *
	 * @param object|null $database Database.
	 */
	public function __construct( private ?object $database = null ) {
	}

	/**
	 * Insert ready.
	 *
	 * @param array $record Record.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function insert_ready( array $record ): void {
		$this->assert_record( $record );
		$db = $this->database();
		$this->reset( $db );
		$written = $db->insert(
			self::TABLE,
			array(
				'public_id'            => $record['file_public_id'],
				'lock_version'         => 0,
				'created_by_user_id'   => $record['actor_user_id'],
				'created_at_utc'       => $record['created_at_utc'],
				'updated_by_user_id'   => $record['actor_user_id'],
				'updated_at_utc'       => $record['created_at_utc'],
				'owner_type'           => 'export_job',
				'owner_public_id'      => $record['job_public_id'],
				'purpose'              => 'worksheet_package',
				'storage_key'          => $record['storage_key'],
				'original_name'        => $record['original_name'],
				'mime_type'            => 'application/vnd.oj.ai-worksheet+zip',
				'byte_size'            => $record['byte_size'],
				'sha256'               => $record['sha256'],
				'state'                => 'ready',
				'scan_state'           => 'clean',
				'retention_class'      => 'generated_export_24h',
				'expires_at_utc'       => $record['expires_at_utc'],
				'legal_hold_until_utc' => null,
				'purged_at_utc'        => null,
			)
		);
		$this->assert_ok( $db );
		if ( 1 !== $written ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Package_Failure::because( Worksheet_Package_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Find ready for owner.
	 *
	 * @param string $file_public_id File public id.
	 * @param string $owner_type Owner type.
	 * @param string $owner_public_id Owner public id.
	 * @return array<string,mixed>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function find_ready_for_owner( string $file_public_id, string $owner_type, string $owner_public_id ): ?array {
		new Public_Id( $file_public_id );
		new Public_Id( $owner_public_id );
		if ( 'export_job' !== $owner_type ) {
			throw new InvalidArgumentException( 'Worksheet package file owner is invalid.' );
		}
		$db = $this->database();
		if ( ! method_exists( $db, 'prepare' ) || ! method_exists( $db, 'get_row' ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Package_Failure::because( Worksheet_Package_Failure::STORAGE_UNAVAILABLE );
		}
		$sql = $db->prepare(
			'SELECT public_id, owner_type, owner_public_id, purpose, storage_key, original_name, mime_type, byte_size, sha256, state, scan_state, expires_at_utc FROM ' . self::TABLE . ' WHERE public_id = %s AND owner_type = %s AND owner_public_id = %s LIMIT 1',
			$file_public_id,
			$owner_type,
			$owner_public_id
		);
		if ( ! is_string( $sql ) || '' === $sql ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Package_Failure::because( Worksheet_Package_Failure::STORAGE_UNAVAILABLE );
		}
		$this->reset( $db );
		$row = $db->get_row( $sql, defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A' );
		$this->assert_ok( $db );
		if ( null === $row ) {
			return null;
		}
		if ( ! is_array( $row ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Package_Failure::because( Worksheet_Package_Failure::STORAGE_UNAVAILABLE );
		}
		return $row;
	}

	/**
	 * Reject invalid record.
	 *
	 * @param array $record Record.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_record( array $record ): void {
		$expected = array( 'actor_user_id', 'byte_size', 'created_at_utc', 'expires_at_utc', 'file_public_id', 'job_public_id', 'original_name', 'sha256', 'storage_key' );
		$actual   = array_keys( $record );
		sort( $actual );
		sort( $expected );
		if ( $actual !== $expected ) {
			throw new InvalidArgumentException( 'Worksheet package private file shape is invalid.' );
		}
		new Public_Id( (string) $record['file_public_id'] );
		new Public_Id( (string) $record['job_public_id'] );
		if ( ! is_int( $record['actor_user_id'] ) || $record['actor_user_id'] < 1 || ! is_int( $record['byte_size'] ) || $record['byte_size'] < 1
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', (string) $record['sha256'] )
			|| 1 !== preg_match( '/^[a-z0-9][a-z0-9._\/-]*$/D', (string) $record['storage_key'] )
			|| in_array( '..', explode( '/', (string) $record['storage_key'] ), true )
			|| '' === (string) $record['original_name'] || strlen( (string) $record['original_name'] ) > 255 ) {
			throw new InvalidArgumentException( 'Worksheet package private file is invalid.' );
		}
		foreach ( array( 'created_at_utc', 'expires_at_utc' ) as $key ) {
			if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', (string) $record[ $key ] ) ) {
				throw new InvalidArgumentException( 'Worksheet package private file UTC is invalid.' );
			}
		}
	}
	/**
	 * Database.
	 *
	 * @return object
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function database(): object {
		$db = $this->database ?? ( $GLOBALS['wpdb'] ?? null );
		if ( ! is_object( $db ) || ! method_exists( $db, 'insert' ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Package_Failure::because( Worksheet_Package_Failure::STORAGE_UNAVAILABLE );
		}
		return $db;
	}
	/**
	 * Reset.
	 *
	 * @param object $db Db.
	 * @return void
	 */
	private function reset( object $db ): void {
		if ( property_exists( $db, 'last_error' ) ) {
			$db->last_error = '';
		}
	}
	/**
	 * Reject invalid ok.
	 *
	 * @param object $db Db.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_ok( object $db ): void {
		if ( '' !== trim( (string) ( $db->last_error ?? '' ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Worksheet_Package_Failure::because( Worksheet_Package_Failure::STORAGE_UNAVAILABLE );
		}
	}
}
