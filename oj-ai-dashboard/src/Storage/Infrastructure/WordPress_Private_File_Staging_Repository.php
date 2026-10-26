<?php
/**
 * WordPress private file staging metadata repository.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Storage\Infrastructure;

use InvalidArgumentException;
use OJAI\Dashboard\Storage\Port\Private_File_Staging_Port_Interface;
use OJAI\Dashboard\Submission\Assignment\Application\Assignment_Failure;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns literal oj_ai_private_files writes for add-on staging workflows.
 */
final class WordPress_Private_File_Staging_Repository implements Private_File_Staging_Port_Interface {
	private const TABLE_PRIVATE_FILES = 'oj_ai_private_files';

	/**
	 * Initialize WordPress private file staging repository dependencies and state.
	 *
	 * @param object|null $database Database.
	 */
	public function __construct( private ?object $database = null ) {
	}

	/**
	 * Find by storage key.
	 *
	 * @param string $storage_key Storage key.
	 * @return array|null
	 */
	public function find_by_storage_key( string $storage_key ): ?array {
		$this->assert_storage_key( $storage_key );
		$sql = 'SELECT public_id, lock_version, created_by_user_id, created_at_utc, updated_by_user_id, '
			. 'updated_at_utc, owner_type, owner_public_id, purpose, storage_key, original_name, mime_type, '
			. 'byte_size, sha256, state, scan_state, retention_class, expires_at_utc, legal_hold_until_utc, '
			. 'purged_at_utc FROM ' . self::TABLE_PRIVATE_FILES . ' WHERE storage_key = %s LIMIT 1';
		return $this->get_row( $this->prepare( $sql, array( $storage_key ) ) );
	}

	/**
	 * Insert quarantined.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function insert_quarantined( array $record ): void {
		$this->assert_record( $record );
		$db = $this->database();
		$this->reset_error( $db );
		$written = $db->insert(
			self::TABLE_PRIVATE_FILES,
			array(
				'public_id'            => $record['public_id'],
				'lock_version'         => 0,
				'created_by_user_id'   => $record['actor_user_id'],
				'created_at_utc'       => $record['created_at_utc'],
				'updated_by_user_id'   => $record['actor_user_id'],
				'updated_at_utc'       => $record['created_at_utc'],
				'owner_type'           => $record['owner_type'],
				'owner_public_id'      => $record['owner_public_id'],
				'purpose'              => $record['purpose'],
				'storage_key'          => $record['storage_key'],
				'original_name'        => $record['original_name'],
				'mime_type'            => $record['mime_type'],
				'byte_size'            => $record['byte_size'],
				'sha256'               => $record['sha256'],
				'state'                => 'quarantined',
				'scan_state'           => $record['scan_state'],
				'retention_class'      => $record['retention_class'],
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
		$this->assert_database_ok( $db );
		if ( 1 !== $written ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORAGE_UNAVAILABLE );
		}

		$stored = $this->find_by_storage_key( (string) $record['storage_key'] );
		if ( null === $stored || ! hash_equals( (string) $record['public_id'], (string) ( $stored['public_id'] ?? '' ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORAGE_UNAVAILABLE );
		}
	}

	/**
	 * Mark purged.
	 *
	 * @param string $file_id File id.
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $purged_at_utc Purged at utc.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function mark_purged( string $file_id, int $actor_user_id, string $purged_at_utc ): void {
		new Public_Id( $file_id );
		if ( $actor_user_id < 1 ) {
			throw new InvalidArgumentException( 'Private file purge actor is invalid.' );
		}
		$this->assert_utc( $purged_at_utc );
		$db = $this->database();
		$this->reset_error( $db );
		$changed = $db->update(
			self::TABLE_PRIVATE_FILES,
			array(
				'state'              => 'purged',
				'lock_version'       => 1,
				'updated_by_user_id' => $actor_user_id,
				'updated_at_utc'     => $purged_at_utc,
				'expires_at_utc'     => null,
				'purged_at_utc'      => $purged_at_utc,
			),
			array(
				'public_id'            => $file_id,
				'created_by_user_id'   => $actor_user_id,
				'state'                => 'quarantined',
				'lock_version'         => 0,
				'legal_hold_until_utc' => null,
			)
		);
		$this->assert_database_ok( $db );
		if ( false === $changed ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORAGE_UNAVAILABLE );
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
			'owner_public_id',
			'owner_type',
			'public_id',
			'purpose',
			'retention_class',
			'scan_state',
			'sha256',
			'storage_key',
		);
		$actual   = array_keys( $record );
		sort( $actual, SORT_STRING );
		if ( $actual !== $expected ) {
			throw new InvalidArgumentException( 'Private file staging record shape is invalid.' );
		}
		new Public_Id( (string) $record['public_id'] );
		new Public_Id( (string) $record['owner_public_id'] );
		if (
			! is_int( $record['actor_user_id'] ) || $record['actor_user_id'] < 1
			|| ! is_int( $record['byte_size'] ) || $record['byte_size'] < 1
			|| ! in_array( $record['scan_state'], array( 'pending', 'clean' ), true )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', (string) $record['sha256'] )
			|| '' === (string) $record['original_name'] || strlen( (string) $record['original_name'] ) > 255
			|| '' === (string) $record['mime_type'] || strlen( (string) $record['mime_type'] ) > 191
		) {
			throw new InvalidArgumentException( 'Private file staging record values are invalid.' );
		}
		foreach ( array(
			'owner_type'      => 64,
			'purpose'         => 64,
			'retention_class' => 64,
		) as $field => $maximum ) {
			$this->assert_token( (string) $record[ $field ], $maximum );
		}
		$this->assert_storage_key( (string) $record['storage_key'] );
		$this->assert_utc( (string) $record['created_at_utc'] );
		$this->assert_utc( (string) $record['expires_at_utc'] );
	}

	/**
	 * Reject invalid storage key.
	 *
	 * @param string $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_storage_key( string $value ): void {
		if ( strlen( $value ) > 512 || 1 !== preg_match( '/^[a-z0-9][a-z0-9._\/-]*$/D', $value ) || in_array( '..', explode( '/', $value ), true ) ) {
			throw new InvalidArgumentException( 'Private file storage key is invalid.' );
		}
	}

	/**
	 * Reject invalid token.
	 *
	 * @param string $value Value.
	 * @param int    $maximum Maximum.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_token( string $value, int $maximum ): void {
		if ( '' === $value || strlen( $value ) > $maximum || 1 !== preg_match( '/^[a-z][a-z0-9_.-]*$/D', $value ) ) {
			throw new InvalidArgumentException( 'Private file token is invalid.' );
		}
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
			throw new InvalidArgumentException( 'Private file UTC value is invalid.' );
		}
	}

	/**
	 * Prepare.
	 *
	 * @param string           $sql Sql.
	 * @param array<int,mixed> $values Values.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function prepare( string $sql, array $values ): string {
		$prepared = $this->database()->prepare( $sql, ...$values );
		if ( ! is_string( $prepared ) || '' === $prepared ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORAGE_UNAVAILABLE );
		}
		return $prepared;
	}

	/**
	 * Get row.
	 *
	 * @param string $sql Sql.
	 * @return array<string,mixed>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function get_row( string $sql ): ?array {
		$db = $this->database();
		$this->reset_error( $db );
		$row = $db->get_row( $sql, defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A' );
		$this->assert_database_ok( $db );
		if ( null === $row ) {
			return null;
		}
		if ( ! is_array( $row ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORAGE_UNAVAILABLE );
		}
		return $row;
	}

	/**
	 * Database.
	 *
	 * @return object
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function database(): object {
		$db = $this->database ?? ( $GLOBALS['wpdb'] ?? null );
		if ( ! is_object( $db ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORAGE_UNAVAILABLE );
		}
		foreach ( array( 'prepare', 'get_row', 'insert', 'update' ) as $method ) {
			if ( ! method_exists( $db, $method ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Assignment_Failure::because( Assignment_Failure::STORAGE_UNAVAILABLE );
			}
		}
		return $db;
	}

	/**
	 * Reset error.
	 *
	 * @param object $db Db.
	 * @return void
	 */
	private function reset_error( object $db ): void {
		if ( property_exists( $db, 'last_error' ) ) {
			$db->last_error = '';
		}
	}

	/**
	 * Reject invalid database ok.
	 *
	 * @param object $db Db.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_database_ok( object $db ): void {
		if ( '' !== trim( (string) ( $db->last_error ?? '' ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::STORAGE_UNAVAILABLE );
		}
	}
}
