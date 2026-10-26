<?php
/**
 * Normalized schema metadata contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Database\Foundation;

use InvalidArgumentException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Converts frozen table definitions and information_schema values to one shape.
 */
final class Schema_Metadata_Contract {
	/**
	 * Expected.
	 *
	 * @param Table_Definition $table Table.
	 * @return array{
	 *   engine:string,
	 *   collation:string,
	 *   columns:array<string,array{type:string,nullable:bool,default:?string,auto_increment:bool}>,
	 *   indexes:array<string,array{unique:bool,columns:array<int,string>}>,
	 *   foreign_keys:array<string,array{column:string,table:string,reference:string,on_update:string,on_delete:string}>
	 * }
	 */
	public static function expected( Table_Definition $table ): array {
		$columns = array();
		foreach ( $table->columns() as $name => $definition ) {
			$columns[ $name ] = self::expected_column( $definition );
		}

		$indexes = array(
			'PRIMARY' => array(
				'unique'  => true,
				'columns' => array( 'id' ),
			),
		);
		foreach ( $table->indexes() as $index ) {
			$indexes[ $index['name'] ] = array(
				'unique'  => $index['unique'],
				'columns' => array_values( $index['columns'] ),
			);
		}

		$foreign_keys = array();
		foreach ( $table->foreign_keys() as $foreign_key ) {
			$foreign_keys[ $foreign_key['name'] ] = array(
				'column'    => $foreign_key['column'],
				'table'     => $foreign_key['table'],
				'reference' => $foreign_key['reference'],
				'on_update' => 'RESTRICT',
				'on_delete' => 'RESTRICT',
			);
		}
		ksort( $indexes, SORT_STRING );
		ksort( $foreign_keys, SORT_STRING );

		return array(
			'engine'       => 'InnoDB',
			'collation'    => 'utf8mb4_unicode_ci',
			'columns'      => $columns,
			'indexes'      => $indexes,
			'foreign_keys' => $foreign_keys,
		);
	}

	/**
	 * Normalize type.
	 *
	 * @param string $type Type.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public static function normalize_type( string $type ): string {
		$type = preg_replace( '/\s+/', ' ', trim( $type ) );
		if ( ! is_string( $type ) || '' === $type ) {
			throw new InvalidArgumentException( 'Column type metadata must not be empty.' );
		}
		$type = preg_replace( '/\b(TINYINT|SMALLINT|MEDIUMINT|INT|BIGINT)\([0-9]+\)/i', '$1', $type );
		if ( ! is_string( $type ) ) {
			throw new InvalidArgumentException( 'Unable to normalize column type metadata.' );
		}

		return strtoupper( $type );
	}

	/**
	 * Normalize default.
	 *
	 * @param mixed $value Value.
	 * @return string|null
	 */
	public static function normalize_default( mixed $value ): ?string {
		if ( null === $value ) {
			return null;
		}

		$value = trim( (string) $value );
		if ( 'NULL' === strtoupper( $value ) ) {
			return null;
		}
		if ( strlen( $value ) >= 2 && "'" === $value[0] && "'" === $value[ strlen( $value ) - 1 ] ) {
			$value = substr( $value, 1, -1 );
		}

		return $value;
	}

	/**
	 * Remove only the engine-created indexes required to support uncovered FKs.
	 *
	 * MySQL and MariaDB create a non-unique child index when a foreign-key
	 * column is not already the left-most column of a declared index. That
	 * physical helper is engine-owned and may use an engine-specific name.
	 *
	 * @param Table_Definition                                           $table Table.
	 * @param array<string,array{unique:bool,columns:array<int,string>}> $indexes Observed indexes.
	 * @return array<string,array{unique:bool,columns:array<int,string>}>
	 */
	public static function normalize_observed_indexes( Table_Definition $table, array $indexes ): array {
		$declared = self::expected( $table )['indexes'];
		$covered  = array();
		foreach ( $declared as $index ) {
			$first = $index['columns'][0] ?? null;
			if ( ! is_string( $first ) ) {
				continue;
			}
			$column = preg_replace( '/\([0-9]+\)$/', '', $first );
			if ( is_string( $column ) && '' !== $column ) {
				$covered[ $column ] = true;
			}
		}

		foreach ( $table->foreign_keys() as $foreign_key ) {
			$column = $foreign_key['column'];
			if ( isset( $covered[ $column ] ) ) {
				continue;
			}

			$candidates = array();
			foreach ( $indexes as $name => $index ) {
				if ( isset( $declared[ $name ] )
					|| true === ( $index['unique'] )
					|| array( $column ) !== ( $index['columns'] ) ) {
					continue;
				}
				$candidates[] = $name;
			}

			$preferred = in_array( $foreign_key['name'], $candidates, true )
				? $foreign_key['name']
				: ( in_array( $column, $candidates, true ) ? $column : null );
			if ( null === $preferred && 1 === count( $candidates ) ) {
				$preferred = $candidates[0];
			}
			if ( null !== $preferred ) {
				unset( $indexes[ $preferred ] );
			}
		}

		return $indexes;
	}

	/**
	 * Expected column.
	 *
	 * @param string $definition Definition.
	 * @return array{type:string,nullable:bool,default:?string,auto_increment:bool}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private static function expected_column( string $definition ): array {
		$definition = preg_replace( '/\s+/', ' ', trim( $definition ) );
		if ( ! is_string( $definition ) || '' === $definition ) {
			throw new InvalidArgumentException( 'Column definitions must not be empty.' );
		}

		if ( 1 !== preg_match( '/^(.+?)\s+(NOT NULL|NULL)(?:\s+DEFAULT\s+([^\s]+))?(?:\s+(AUTO_INCREMENT))?$/i', $definition, $matches ) ) {
			throw new InvalidArgumentException( 'Unsupported frozen column definition: ' . $definition ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception details stay in the application failure channel; presentation maps and escapes displayed errors.
		}

		return array(
			'type'           => self::normalize_type( $matches[1] ),
			'nullable'       => 'NULL' === strtoupper( $matches[2] ),
			'default'        => isset( $matches[3] ) && '' !== $matches[3]
				? self::normalize_default( $matches[3] )
				: null,
			'auto_increment' => isset( $matches[4] ),
		);
	}
}
