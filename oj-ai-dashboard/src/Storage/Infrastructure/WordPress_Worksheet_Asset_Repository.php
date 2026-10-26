<?php
/**
 * WordPress worksheet asset metadata repository.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Storage\Infrastructure;

use InvalidArgumentException;
use OJAI\Dashboard\Storage\Application\Worksheet_Asset_Failure;
use OJAI\Dashboard\Storage\Port\Worksheet_Asset_Lifecycle_Port_Interface;
use OJAI\Dashboard\Storage\Port\Worksheet_Asset_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns literal oj_ai_private_files SQL for worksheet body assets.
 */
final class WordPress_Worksheet_Asset_Repository implements Worksheet_Asset_Repository_Port_Interface, Worksheet_Asset_Lifecycle_Port_Interface {
	private const TABLE_PRIVATE_FILES = 'oj_ai_private_files';

	/**
	 * Database.
	 *
	 * @var object|null
	 */
	private ?object $database;

	/**
	 * Initialize WordPress worksheet asset repository dependencies and state.
	 *
	 * @param object|null $database Database.
	 */
	public function __construct( ?object $database = null ) {
		$this->database = $database;
	}

	/**
	 * Find by storage key.
	 *
	 * @param string $storage_key Storage key.
	 * @return array|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function find_by_storage_key( string $storage_key ): ?array {
		if ( '' === $storage_key || strlen( $storage_key ) > 512 ) {
			throw new InvalidArgumentException( 'Worksheet asset storage key is invalid.' );
		}

		$sql      = 'SELECT public_id, lock_version, created_by_user_id, created_at_utc, updated_by_user_id, '
			. 'updated_at_utc, owner_type, owner_public_id, purpose, storage_key, original_name, mime_type, '
			. 'byte_size, sha256, state, scan_state, retention_class, expires_at_utc, legal_hold_until_utc, '
			. 'purged_at_utc FROM ' . self::TABLE_PRIVATE_FILES . ' WHERE storage_key = %s LIMIT 1';
		$database = $this->database();
		$this->reset_error( $database );
		$prepared = $database->prepare( $sql, $storage_key );
		if ( ! is_string( $prepared ) || '' === $prepared ) {
			throw Worksheet_Asset_Failure::storage_unavailable();
		}
		$row = $database->get_row( $prepared, $this->array_output() );
		$this->assert_database_ok( $database );
		if ( null === $row ) {
			return null;
		}
		if ( ! is_array( $row ) ) {
			throw Worksheet_Asset_Failure::storage_unavailable();
		}

		return $row;
	}
	/**
	 * Find by public id.
	 *
	 * @param string $asset_id Asset id.
	 * @return array|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function find_by_public_id( string $asset_id ): ?array {
		new Public_Id( $asset_id );
		$sql      = 'SELECT public_id, lock_version, created_by_user_id, created_at_utc, updated_by_user_id, '
			. 'updated_at_utc, owner_type, owner_public_id, purpose, storage_key, original_name, mime_type, '
			. 'byte_size, sha256, state, scan_state, retention_class, expires_at_utc, legal_hold_until_utc, '
			. 'purged_at_utc FROM ' . self::TABLE_PRIVATE_FILES . ' WHERE public_id = %s LIMIT 1';
		$database = $this->database();
		$this->reset_error( $database );
		$prepared = $database->prepare( $sql, $asset_id );
		if ( ! is_string( $prepared ) || '' === $prepared ) {
			throw Worksheet_Asset_Failure::storage_unavailable();
		}
		$row = $database->get_row( $prepared, $this->array_output() );
		$this->assert_database_ok( $database );
		if ( null === $row ) {
			return null;
		}
		if ( ! is_array( $row ) ) {
			throw Worksheet_Asset_Failure::storage_unavailable();
		}
		return $row;
	}

	/**
	 * Mark quarantined purged.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $worksheet_id Worksheet public identifier.
	 * @param string $asset_id Asset id.
	 * @param string $purged_at_utc Purged at utc.
	 * @return bool
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function mark_quarantined_purged(
		int $actor_user_id,
		string $worksheet_id,
		string $asset_id,
		string $purged_at_utc
	): bool {
		new Public_Id( $worksheet_id );
		new Public_Id( $asset_id );
		if ( $actor_user_id < 1 || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $purged_at_utc ) ) {
			throw new InvalidArgumentException( 'Worksheet asset purge values are invalid.' );
		}
		$database = $this->database();
		if ( ! method_exists( $database, 'query' ) ) {
			throw Worksheet_Asset_Failure::storage_unavailable();
		}
		$sql = 'UPDATE ' . self::TABLE_PRIVATE_FILES
			. ' SET state = %s, lock_version = lock_version + 1, updated_by_user_id = %d, '
			. 'updated_at_utc = %s, expires_at_utc = NULL, purged_at_utc = %s '
			. 'WHERE public_id = %s AND created_by_user_id = %d AND owner_type = %s '
			. 'AND owner_public_id = %s AND purpose = %s AND state = %s AND scan_state = %s '
			. 'AND legal_hold_until_utc IS NULL';
		$this->reset_error( $database );
		$prepared = $database->prepare(
			$sql,
			'purged',
			$actor_user_id,
			$purged_at_utc,
			$purged_at_utc,
			$asset_id,
			$actor_user_id,
			'worksheet',
			$worksheet_id,
			'worksheet_asset',
			'quarantined',
			'clean'
		);
		if ( ! is_string( $prepared ) || '' === $prepared ) {
			throw Worksheet_Asset_Failure::storage_unavailable();
		}
		$changed = $database->query( $prepared );
		$this->assert_database_ok( $database );
		if ( false === $changed ) {
			throw Worksheet_Asset_Failure::storage_unavailable();
		}
		return 1 === $changed;
	}


	/**
	 * Insert quarantined.
	 *
	 * @param array $record Record.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function insert_quarantined( array $record ): void {
		$this->assert_record( $record );
		$database = $this->database();
		$this->reset_error( $database );
		$written = $database->insert(
			self::TABLE_PRIVATE_FILES,
			array(
				'public_id'            => $record['public_id'],
				'lock_version'         => 0,
				'created_by_user_id'   => $record['actor_user_id'],
				'created_at_utc'       => $record['created_at_utc'],
				'updated_by_user_id'   => $record['actor_user_id'],
				'updated_at_utc'       => $record['created_at_utc'],
				'owner_type'           => 'worksheet',
				'owner_public_id'      => $record['worksheet_public_id'],
				'purpose'              => 'worksheet_asset',
				'storage_key'          => $record['storage_key'],
				'original_name'        => $record['original_name'],
				'mime_type'            => $record['mime_type'],
				'byte_size'            => $record['byte_size'],
				'sha256'               => $record['sha256'],
				'state'                => 'quarantined',
				'scan_state'           => 'clean',
				'retention_class'      => 'worksheet_staging',
				'expires_at_utc'       => $record['expires_at_utc'],
				'legal_hold_until_utc' => null,
				'purged_at_utc'        => null,
			),
			array(
				'%s',
				'%d',
				'%d',
				'%s',
				'%d',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%d',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
			)
		);
		$this->assert_database_ok( $database );
		if ( 1 !== $written ) {
			throw Worksheet_Asset_Failure::storage_unavailable();
		}

		$stored = $this->find_by_storage_key( (string) $record['storage_key'] );
		if ( null === $stored || ! hash_equals( (string) $record['public_id'], (string) ( $stored['public_id'] ?? '' ) ) ) {
			throw Worksheet_Asset_Failure::storage_unavailable();
		}
	}

	/**
	 * Reject invalid record.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_record( array $record ): void {
		$expected = array(
			'actor_user_id',
			'byte_size',
			'created_at_utc',
			'expires_at_utc',
			'mime_type',
			'original_name',
			'public_id',
			'sha256',
			'storage_key',
			'worksheet_public_id',
		);
		$keys     = array_keys( $record );
		sort( $keys, SORT_STRING );
		if ( $keys !== $expected ) {
			throw new InvalidArgumentException( 'Worksheet asset record shape is invalid.' );
		}
		new Public_Id( (string) $record['public_id'] );
		new Public_Id( (string) $record['worksheet_public_id'] );
		if (
			! is_int( $record['actor_user_id'] ) || $record['actor_user_id'] < 1
			|| ! is_int( $record['byte_size'] ) || $record['byte_size'] < 1
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', (string) $record['sha256'] )
			|| '' === (string) $record['storage_key'] || strlen( (string) $record['storage_key'] ) > 512
			|| '' === (string) $record['original_name'] || strlen( (string) $record['original_name'] ) > 255
			|| '' === (string) $record['mime_type'] || strlen( (string) $record['mime_type'] ) > 191
		) {
			throw new InvalidArgumentException( 'Worksheet asset record values are invalid.' );
		}
		$this->assert_utc( (string) $record['created_at_utc'] );
		$this->assert_utc( (string) $record['expires_at_utc'] );
	}

	/**
	 * Reject invalid utc.
	 *
	 * @param string $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_utc( string $value ): void {
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value ) ) {
			throw new InvalidArgumentException( 'Worksheet asset UTC value is invalid.' );
		}
	}

	/**
	 * Database.
	 *
	 * @return object
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function database(): object {
		$database = $this->database ?? ( $GLOBALS['wpdb'] ?? null );
		if ( ! is_object( $database ) ) {
			throw Worksheet_Asset_Failure::storage_unavailable();
		}
		foreach ( array( 'prepare', 'get_row', 'insert' ) as $method ) {
			if ( ! method_exists( $database, $method ) ) {
				throw Worksheet_Asset_Failure::storage_unavailable();
			}
		}
		return $database;
	}

	/**
	 * Reset error.
	 *
	 * @param object $database Database.
	 * @return void
	 */
	private function reset_error( object $database ): void {
		if ( property_exists( $database, 'last_error' ) ) {
			$database->last_error = '';
		}
	}

	/**
	 * Reject invalid database ok.
	 *
	 * @param object $database Database.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_database_ok( object $database ): void {
		if ( property_exists( $database, 'last_error' ) && '' !== trim( (string) $database->last_error ) ) {
			throw Worksheet_Asset_Failure::storage_unavailable();
		}
	}

	/**
	 * Array output.
	 *
	 * @return mixed
	 */
	private function array_output() {
		return defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A';
	}
}
