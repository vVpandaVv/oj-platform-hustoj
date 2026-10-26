<?php
/**
 * Read-only WordPress schema ownership and migration ledger adapter.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Database\Foundation\Infrastructure;

use InvalidArgumentException;
use OJAI\Dashboard\Database\Foundation\Port\Schema_Ledger_Reader_Port_Interface;
use OJAI\Dashboard\Database\Foundation\Schema_Catalog;
use RuntimeException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads the two literal bootstrap tables without exposing mutation methods.
 */
final class WordPress_Schema_Ledger_Reader implements Schema_Ledger_Reader_Port_Interface {
	private const INSTALLATIONS_TABLE = 'oj_ai_installations';
	private const MIGRATIONS_TABLE    = 'oj_ai_schema_migrations';

	/**
	 * Database.
	 *
	 * @var object
	 */
	private object $database;

	/**
	 * Initialize WordPress schema ledger reader dependencies and state.
	 *
	 * @param object $database Database.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct( object $database ) {
		foreach ( array( 'get_row', 'get_results', 'prepare' ) as $method ) {
			if ( ! method_exists( $database, $method ) ) {
				throw new InvalidArgumentException( 'The database adapter is missing required read-only ledger methods.' );
			}
		}
		$this->database = $database;
	}

	/**
	 * Find installation.
	 *
	 * @return array<string,mixed>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function find_installation(): ?array {
		$sql = $this->database->prepare(
			'SELECT * FROM `' . self::INSTALLATIONS_TABLE . '` WHERE addon_id = %s LIMIT 1',
			Schema_Catalog::ADDON_ID
		);
		if ( ! is_string( $sql ) ) {
			throw new RuntimeException( 'schema_ledger_prepare_failed' );
		}
		$mode = defined( 'ARRAY_A' ) ? constant( 'ARRAY_A' ) : 'ARRAY_A';
		$row  = $this->database->get_row( $sql, $mode );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * All migrations.
	 *
	 * @return array<string,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function all_migrations(): array {
		$mode = defined( 'ARRAY_A' ) ? constant( 'ARRAY_A' ) : 'ARRAY_A';
		$rows = $this->database->get_results(
			'SELECT * FROM `' . self::MIGRATIONS_TABLE . '` ORDER BY migration_id ASC',
			$mode
		);
		if ( ! is_array( $rows ) ) {
			throw new RuntimeException( 'schema_ledger_read_failed' );
		}

		$records = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$migration_id = (string) ( $row['migration_id'] ?? '' );
			if ( '' === $migration_id || isset( $records[ $migration_id ] ) ) {
				throw new RuntimeException( 'schema_ledger_invalid' );
			}
			$records[ $migration_id ] = $row;
		}

		return $records;
	}
}
