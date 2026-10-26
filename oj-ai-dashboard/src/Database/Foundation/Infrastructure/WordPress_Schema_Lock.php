<?php
/**
 * MySQL named-lock adapter through WordPress.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Database\Foundation\Infrastructure;

use InvalidArgumentException;
use OJAI\Dashboard\Database\Foundation\Port\Schema_Lock_Port_Interface;
use RuntimeException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Uses one database-scoped lock and never WordPress options as a mutex.
 */
final class WordPress_Schema_Lock implements Schema_Lock_Port_Interface {
	/**
	 * Database.
	 *
	 * @var object
	 */
	private object $database;

	/**
	 * Initialize WordPress schema lock dependencies and state.
	 *
	 * @param object $database Database.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct( object $database ) {
		foreach ( array( 'get_var', 'prepare' ) as $method ) {
			if ( ! method_exists( $database, $method ) ) {
				throw new InvalidArgumentException( 'The database adapter is missing required lock methods.' );
			}
		}
		$this->database = $database;
	}

	/**
	 * Acquire.
	 *
	 * @param string $name Name.
	 * @param int    $timeout_seconds Timeout seconds.
	 * @return bool
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function acquire( string $name, int $timeout_seconds ): bool {
		$this->assert_name( $name );
		$timeout_seconds = max( 0, min( 30, $timeout_seconds ) );
		$sql             = $this->database->prepare( 'SELECT GET_LOCK(%s, %d)', $name, $timeout_seconds );
		if ( ! is_string( $sql ) ) {
			throw new RuntimeException( 'schema_lock_prepare_failed' );
		}

		return '1' === (string) $this->database->get_var( $sql );
	}

	/**
	 * Release.
	 *
	 * @param string $name Name.
	 * @return void
	 */
	public function release( string $name ): void {
		$this->assert_name( $name );
		$sql = $this->database->prepare( 'SELECT RELEASE_LOCK(%s)', $name );
		if ( is_string( $sql ) ) {
			$this->database->get_var( $sql );
		}
	}

	/**
	 * Reject invalid name.
	 *
	 * @param string $name Name.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_name( string $name ): void {
		if ( 1 !== preg_match( '/^[a-z][a-z0-9_]{1,63}$/', $name ) ) {
			throw new InvalidArgumentException( 'Invalid schema lock name.' );
		}
	}
}
