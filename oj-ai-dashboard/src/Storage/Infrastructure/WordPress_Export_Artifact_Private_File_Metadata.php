<?php
/**
 * WordPress export artifact private file metadata.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Storage\Infrastructure;

use InvalidArgumentException;
use RuntimeException;
use OJAI\Dashboard\Storage\Port\Generated_Private_File_Metadata_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Storage-owned metadata adapter for report and submission bundle artifacts.
 */
final class WordPress_Export_Artifact_Private_File_Metadata implements Generated_Private_File_Metadata_Port_Interface {
	private const TABLE    = 'oj_ai_private_files';
	private const PROFILES = array(
		'report_csv'          => array(
			'owner_type' => 'export_job',
			'purpose'    => 'report_csv',
			'mime_type'  => 'text/csv',
		),
		'report_pdf'          => array(
			'owner_type' => 'pdf_artifact',
			'purpose'    => 'generated_pdf',
			'mime_type'  => 'application/pdf',
		),
		'report_json_package' => array(
			'owner_type' => 'export_job',
			'purpose'    => 'report_json_package',
			'mime_type'  => 'application/zip',
		),
		'submission_bundle'   => array(
			'owner_type' => 'export_job',
			'purpose'    => 'submission_bundle',
			'mime_type'  => 'application/zip',
		),
	);

	/**
	 * Initialize WordPress export artifact private file metadata dependencies and state.
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
		$profile = self::PROFILES[ $record['artifact_kind'] ];
		$owner   = 'pdf_artifact' === $profile['owner_type'] ? $record['artifact_public_id'] : $record['job_public_id'];
		$db      = $this->db();
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
				'owner_type'           => $profile['owner_type'],
				'owner_public_id'      => $owner,
				'purpose'              => $profile['purpose'],
				'storage_key'          => $record['storage_key'],
				'original_name'        => $record['original_name'],
				'mime_type'            => $profile['mime_type'],
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
			throw new RuntimeException( 'Export private file metadata is unavailable.' );
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
		if ( ! in_array( $owner_type, array( 'export_job', 'pdf_artifact' ), true ) ) {
			throw new InvalidArgumentException( 'Export private file owner is invalid.' );
		}
		$db  = $this->db();
		$sql = $db->prepare(
			'SELECT public_id, owner_type, owner_public_id, purpose, storage_key, original_name, mime_type, byte_size, sha256, state, scan_state, expires_at_utc FROM ' . self::TABLE . ' WHERE public_id = %s AND owner_type = %s AND owner_public_id = %s LIMIT 1',
			$file_public_id,
			$owner_type,
			$owner_public_id
		);
		if ( ! is_string( $sql ) || '' === $sql ) {
			throw new RuntimeException( 'Export private file metadata is unavailable.' );
		}
		$this->reset( $db );
		$row = $db->get_row( $sql, defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A' );
		$this->assert_ok( $db );
		if ( null === $row ) {
			return null;
		}
		if ( ! is_array( $row ) ) {
			throw new RuntimeException( 'Export private file metadata is unavailable.' );
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
		$expected = array( 'actor_user_id', 'artifact_kind', 'artifact_public_id', 'byte_size', 'created_at_utc', 'expires_at_utc', 'file_public_id', 'job_public_id', 'mime_type', 'original_name', 'sha256', 'storage_key' );
		$actual   = array_keys( $record );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected || ! isset( self::PROFILES[ $record['artifact_kind'] ] ) ) {
			throw new InvalidArgumentException( 'Export private file record shape is invalid.' );
		}
		new Public_Id( (string) $record['artifact_public_id'] );
		new Public_Id( (string) $record['file_public_id'] );
		new Public_Id( (string) $record['job_public_id'] );
		$profile = self::PROFILES[ $record['artifact_kind'] ];
		if (
			$profile['mime_type'] !== $record['mime_type'] || ! is_int( $record['actor_user_id'] ) || $record['actor_user_id'] < 1
			|| ! is_int( $record['byte_size'] ) || $record['byte_size'] < 1 || $record['byte_size'] > 524_288_000
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', (string) $record['sha256'] )
			|| 1 !== preg_match( '/^[a-z0-9][a-z0-9._\/-]*$/D', (string) $record['storage_key'] )
			|| in_array( '..', explode( '/', (string) $record['storage_key'] ), true )
			|| '' === (string) $record['original_name'] || strlen( (string) $record['original_name'] ) > 255
		) {
			throw new InvalidArgumentException( 'Export private file record is invalid.' );
		}
		foreach ( array( 'created_at_utc', 'expires_at_utc' ) as $key ) {
			if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', (string) $record[ $key ] ) ) {
				throw new InvalidArgumentException( 'Export private file UTC is invalid.' );
			}
		}
	}

	/**
	 * Db.
	 *
	 * @return object
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function db(): object {
		$db = $this->database ?? ( $GLOBALS['wpdb'] ?? null );
		if ( ! is_object( $db ) || ! method_exists( $db, 'insert' ) || ! method_exists( $db, 'prepare' ) || ! method_exists( $db, 'get_row' ) ) {
			throw new RuntimeException( 'Export private file metadata is unavailable.' );
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
			throw new RuntimeException( 'Export private file metadata is unavailable.' );
		}
	}
}
