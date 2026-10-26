<?php
/**
 * WordPress database schema inspector.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Database\Foundation\Infrastructure;

use InvalidArgumentException;
use OJAI\Dashboard\Database\Foundation\Port\Schema_Inspector_Port_Interface;
use OJAI\Dashboard\Database\Foundation\Schema_Metadata_Contract;
use RuntimeException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads information_schema for exact literal oj_ai_* tables only.
 */
final class WordPress_Schema_Inspector implements Schema_Inspector_Port_Interface {
	/**
	 * Database.
	 *
	 * @var object
	 */
	private object $database;

	/**
	 * Initialize WordPress schema inspector dependencies and state.
	 *
	 * @param object $database Database.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct( object $database ) {
		foreach ( array( 'get_col', 'get_results', 'prepare' ) as $method ) {
			if ( ! method_exists( $database, $method ) ) {
				throw new InvalidArgumentException( 'The database adapter is missing required metadata methods.' );
			}
		}
		$this->database = $database;
	}

	/**
	 * Literal table names.
	 *
	 * @return array<int,string>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function literal_table_names(): array {
		$sql  = 'SELECT TABLE_NAME FROM information_schema.TABLES'
			. " WHERE TABLE_SCHEMA = DATABASE() AND LEFT(TABLE_NAME, 6) = 'oj_ai_'"
			. ' ORDER BY TABLE_NAME ASC';
		$rows = $this->database->get_col( $sql );
		if ( ! is_array( $rows ) ) {
			throw new RuntimeException( 'schema_table_inventory_failed' );
		}

		$names = array();
		foreach ( $rows as $row ) {
			$name = (string) $row;
			if ( 1 === preg_match( '/^oj_ai_[a-z0-9_]+$/', $name ) ) {
				$names[ $name ] = true;
			}
		}
		$names = array_keys( $names );
		sort( $names, SORT_STRING );

		return $names;
	}

	/**
	 * Metadata.
	 *
	 * @param array<int,string> $table_names Exact literal names.
	 * @return array<string,array<string,mixed>>
	 */
	public function metadata( array $table_names ): array {
		$table_names = $this->validate_names( $table_names );
		if ( array() === $table_names ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $table_names ), '%s' ) );
		$options      = $this->rows(
			$this->prepare(
				'SELECT TABLE_NAME AS table_name, ENGINE AS engine, TABLE_COLLATION AS table_collation'
				. ' FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'
				. ' AND TABLE_NAME IN (' . $placeholders . ') ORDER BY TABLE_NAME ASC',
				$table_names
			)
		);
		$columns      = $this->rows(
			$this->prepare(
				'SELECT TABLE_NAME AS table_name, COLUMN_NAME AS column_name, COLUMN_TYPE AS column_type,'
				. ' IS_NULLABLE AS is_nullable, COLUMN_DEFAULT AS column_default, EXTRA AS extra'
				. ' FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()'
				. ' AND TABLE_NAME IN (' . $placeholders . ') ORDER BY TABLE_NAME ASC, ORDINAL_POSITION ASC',
				$table_names
			)
		);
		$indexes      = $this->rows(
			$this->prepare(
				'SELECT TABLE_NAME AS table_name, INDEX_NAME AS index_name, NON_UNIQUE AS non_unique,'
				. ' COLUMN_NAME AS column_name, SUB_PART AS sub_part'
				. ' FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()'
				. ' AND TABLE_NAME IN (' . $placeholders . ')'
				. ' ORDER BY TABLE_NAME ASC, INDEX_NAME ASC, SEQ_IN_INDEX ASC',
				$table_names
			)
		);
		$foreign_keys = $this->rows(
			$this->prepare(
				'SELECT k.TABLE_NAME AS table_name, k.CONSTRAINT_NAME AS constraint_name,'
				. ' k.COLUMN_NAME AS column_name, k.REFERENCED_TABLE_NAME AS referenced_table_name,'
				. ' k.REFERENCED_COLUMN_NAME AS referenced_column_name, r.UPDATE_RULE AS update_rule,'
				. ' r.DELETE_RULE AS delete_rule FROM information_schema.KEY_COLUMN_USAGE k'
				. ' INNER JOIN information_schema.REFERENTIAL_CONSTRAINTS r'
				. ' ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME'
				. ' AND r.TABLE_NAME = k.TABLE_NAME WHERE k.TABLE_SCHEMA = DATABASE()'
				. ' AND k.REFERENCED_TABLE_NAME IS NOT NULL AND k.TABLE_NAME IN (' . $placeholders . ')'
				. ' ORDER BY k.TABLE_NAME ASC, k.CONSTRAINT_NAME ASC, k.ORDINAL_POSITION ASC',
				$table_names
			)
		);

		$metadata = array();
		foreach ( $options as $row ) {
			$table_name = (string) ( $row['table_name'] ?? '' );
			if ( ! in_array( $table_name, $table_names, true ) ) {
				continue;
			}
			$metadata[ $table_name ] = array(
				'engine'       => (string) ( $row['engine'] ?? '' ),
				'collation'    => (string) ( $row['table_collation'] ?? '' ),
				'columns'      => array(),
				'indexes'      => array(),
				'foreign_keys' => array(),
			);
		}

		foreach ( $columns as $row ) {
			$table_name  = (string) ( $row['table_name'] ?? '' );
			$column_name = (string) ( $row['column_name'] ?? '' );
			if ( ! isset( $metadata[ $table_name ] ) || '' === $column_name ) {
				continue;
			}
			$extra = strtolower( (string) ( $row['extra'] ?? '' ) );
			$metadata[ $table_name ]['columns'][ $column_name ] = array(
				'type'           => Schema_Metadata_Contract::normalize_type( (string) ( $row['column_type'] ?? '' ) ),
				'nullable'       => 'YES' === strtoupper( (string) ( $row['is_nullable'] ?? '' ) ),
				'default'        => Schema_Metadata_Contract::normalize_default( $row['column_default'] ?? null ),
				'auto_increment' => str_contains( $extra, 'auto_increment' ),
			);
		}

		foreach ( $indexes as $row ) {
			$table_name = (string) ( $row['table_name'] ?? '' );
			$index_name = (string) ( $row['index_name'] ?? '' );
			$column     = (string) ( $row['column_name'] ?? '' );
			if ( ! isset( $metadata[ $table_name ] ) || '' === $index_name || '' === $column ) {
				continue;
			}
			if ( ! isset( $metadata[ $table_name ]['indexes'][ $index_name ] ) ) {
				$metadata[ $table_name ]['indexes'][ $index_name ] = array(
					'unique'  => 0 === (int) ( $row['non_unique'] ?? 1 ),
					'columns' => array(),
				);
			}
			$sub_part = isset( $row['sub_part'] ) ? (int) $row['sub_part'] : 0;
			$metadata[ $table_name ]['indexes'][ $index_name ]['columns'][] = $column
				. ( $sub_part > 0 ? '(' . $sub_part . ')' : '' );
		}

		foreach ( $foreign_keys as $row ) {
			$table_name = (string) ( $row['table_name'] ?? '' );
			$name       = (string) ( $row['constraint_name'] ?? '' );
			if ( ! isset( $metadata[ $table_name ] ) || '' === $name ) {
				continue;
			}
			$metadata[ $table_name ]['foreign_keys'][ $name ] = array(
				'column'    => (string) ( $row['column_name'] ?? '' ),
				'table'     => (string) ( $row['referenced_table_name'] ?? '' ),
				'reference' => (string) ( $row['referenced_column_name'] ?? '' ),
				'on_update' => strtoupper( (string) ( $row['update_rule'] ?? '' ) ),
				'on_delete' => strtoupper( (string) ( $row['delete_rule'] ?? '' ) ),
			);
		}
		foreach ( $metadata as &$table_metadata ) {
			ksort( $table_metadata['indexes'], SORT_STRING );
			ksort( $table_metadata['foreign_keys'], SORT_STRING );
		}
		unset( $table_metadata );

		return $metadata;
	}

	/**
	 * Validate names.
	 *
	 * @param array<array-key,mixed> $names Observed names to validate.
	 * @return array<int,string>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function validate_names( array $names ): array {
		$validated = array();
		foreach ( $names as $name ) {
			if ( ! is_string( $name ) || 1 !== preg_match( '/^oj_ai_[a-z0-9_]+$/', $name ) ) {
				throw new InvalidArgumentException( 'Metadata inspection requires exact literal table names.' );
			}
			$validated[ $name ] = true;
		}

		return array_keys( $validated );
	}

	/**
	 * Prepare.
	 *
	 * @param string            $sql Sql.
	 * @param array<int,string> $values Values.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function prepare( string $sql, array $values ): string {
		$prepared = $this->database->prepare( $sql, $values );
		if ( ! is_string( $prepared ) || '' === $prepared ) {
			throw new RuntimeException( 'schema_metadata_prepare_failed' );
		}

		return $prepared;
	}

	/**
	 * Rows.
	 *
	 * @param string $sql Sql.
	 * @return array<int,array<string,mixed>>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function rows( string $sql ): array {
		$mode = defined( 'ARRAY_A' ) ? constant( 'ARRAY_A' ) : 'ARRAY_A';
		$rows = $this->database->get_results( $sql, $mode );
		if ( ! is_array( $rows ) ) {
			throw new RuntimeException( 'schema_metadata_query_failed' );
		}

		return array_values( array_filter( $rows, 'is_array' ) );
	}
}
