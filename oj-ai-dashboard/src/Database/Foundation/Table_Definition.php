<?php
/**
 * Immutable physical table definition.
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
 * Holds one literal add-on table contract and renders deterministic SQL.
 */
final class Table_Definition {
	/**
	 * Name.
	 *
	 * @var string
	 */
	private string $name;
	/**
	 * Owner.
	 *
	 * @var string
	 */
	private string $owner;

	/**
	 * Columns.
	 *
	 * @var array<string,string>
	 */
	private array $columns;

	/**
	 * Indexes.
	 *
	 * @var array<int,array{name:string,columns:array<int,string>,unique:bool}>
	 */
	private array $indexes;

	/**
	 * Foreign keys.
	 *
	 * @var array<int,array{name:string,column:string,table:string,reference:string}>
	 */
	private array $foreign_keys;

	/**
	 * Initialize table definition dependencies and state.
	 *
	 * @param string                                                                    $name Literal physical table name.
	 * @param string                                                                    $owner Single module owner.
	 * @param array<string,string>                                                      $columns Ordered column definitions.
	 * @param array<int,array{name:string,columns:array<int,string>,unique:bool}>       $indexes Index definitions.
	 * @param array<int,array{name:string,column:string,table:string,reference:string}> $foreign_keys Foreign keys.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		string $name,
		string $owner,
		array $columns,
		array $indexes = array(),
		array $foreign_keys = array()
	) {
		if ( 1 !== preg_match( '/^oj_ai_[a-z0-9_]+$/', $name ) ) {
			throw new InvalidArgumentException( 'Table names must use the literal oj_ai_ namespace.' );
		}
		if ( '' === $owner ) {
			throw new InvalidArgumentException( 'Every table requires exactly one owner.' );
		}
		if ( ! isset( $columns['id'] ) ) {
			throw new InvalidArgumentException( 'Every table requires the PK profile.' );
		}

		$this->name         = $name;
		$this->owner        = $owner;
		$this->columns      = $columns;
		$this->indexes      = $indexes;
		$this->foreign_keys = $foreign_keys;
		$this->validate();
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Owner.
	 *
	 * @return string
	 */
	public function owner(): string {
		return $this->owner;
	}

	/**
	 * Columns.
	 *
	 * @return array<string,string>
	 */
	public function columns(): array {
		return $this->columns;
	}

	/**
	 * Indexes.
	 *
	 * @return array<int,array{name:string,columns:array<int,string>,unique:bool}>
	 */
	public function indexes(): array {
		return $this->indexes;
	}

	/**
	 * Foreign keys.
	 *
	 * @return array<int,array{name:string,column:string,table:string,reference:string}>
	 */
	public function foreign_keys(): array {
		return $this->foreign_keys;
	}

	/**
	 * Dependencies.
	 *
	 * @return array<int,string>
	 */
	public function dependencies(): array {
		$dependencies = array();
		foreach ( $this->foreign_keys as $foreign_key ) {
			if ( $foreign_key['table'] !== $this->name ) {
				$dependencies[] = $foreign_key['table'];
			}
		}

		return array_values( array_unique( $dependencies ) );
	}

	/**
	 * Return a stable, serializable contract used for schema fingerprinting.
	 *
	 * @return array<string,mixed>
	 */
	public function normalized_definition(): array {
		return array(
			'name'         => $this->name,
			'owner'        => $this->owner,
			'columns'      => $this->columns,
			'indexes'      => $this->indexes,
			'foreign_keys' => $this->foreign_keys,
			'engine'       => 'InnoDB',
			'charset'      => 'utf8mb4',
			'collation'    => 'utf8mb4_unicode_ci',
		);
	}

	/**
	 * Render one deterministic CREATE TABLE statement.
	 *
	 * @return string
	 */
	public function create_sql(): string {
		$parts = array();
		foreach ( $this->columns as $name => $definition ) {
			$parts[] = '  `' . $name . '` ' . $definition;
		}
		$parts[] = '  PRIMARY KEY (`id`)';

		foreach ( $this->indexes as $index ) {
			$kind    = $index['unique'] ? 'UNIQUE KEY' : 'KEY';
			$columns = array_map( array( self::class, 'render_index_column' ), $index['columns'] );
			$parts[] = '  ' . $kind . ' `' . $index['name'] . '` (' . implode( ', ', $columns ) . ')';
		}

		foreach ( $this->foreign_keys as $foreign_key ) {
			$parts[] = '  CONSTRAINT `' . $foreign_key['name'] . '` FOREIGN KEY (`'
				. $foreign_key['column'] . '`) REFERENCES `' . $foreign_key['table'] . '` (`'
				. $foreign_key['reference'] . '`) ON UPDATE RESTRICT ON DELETE RESTRICT';
		}

		return "CREATE TABLE `{$this->name}` (\n"
			. implode( ",\n", $parts )
			. "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
	}

	/**
	 * Validate columns, indexes, and FK references inside this table contract.
	 *
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function validate(): void {
		$names = array();
		foreach ( $this->indexes as $index ) {
			$this->assert_identifier( $index['name'] );
			if ( isset( $names[ $index['name'] ] ) ) {
				throw new InvalidArgumentException( 'Duplicate index name in ' . $this->name ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception details stay in the application failure channel; presentation maps and escapes displayed errors.
			}
			$names[ $index['name'] ] = true;
			if ( array() === $index['columns'] ) {
				throw new InvalidArgumentException( 'Indexes require at least one column.' );
			}
			foreach ( $index['columns'] as $column ) {
				$base = preg_replace( '/\([0-9]+\)$/', '', $column );
				if ( ! is_string( $base ) || ! isset( $this->columns[ $base ] ) ) {
					throw new InvalidArgumentException( 'Index references an unknown column: ' . $column ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception details stay in the application failure channel; presentation maps and escapes displayed errors.
				}
			}
		}

		foreach ( $this->foreign_keys as $foreign_key ) {
			$this->assert_identifier( $foreign_key['name'] );
			$this->assert_identifier( $foreign_key['column'] );
			$this->assert_identifier( $foreign_key['reference'] );
			if ( ! isset( $this->columns[ $foreign_key['column'] ] ) ) {
				throw new InvalidArgumentException( 'Foreign key references an unknown local column.' );
			}
			if ( 1 !== preg_match( '/^oj_ai_[a-z0-9_]+$/', $foreign_key['table'] ) ) {
				throw new InvalidArgumentException( 'Foreign key target must be a literal add-on table.' );
			}
		}
	}

	/**
	 * Reject invalid identifier.
	 *
	 * @param string $identifier Identifier.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_identifier( string $identifier ): void {
		if ( 1 !== preg_match( '/^[a-z][a-z0-9_]*$/', $identifier ) ) {
			throw new InvalidArgumentException( 'Invalid SQL identifier: ' . $identifier ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception details stay in the application failure channel; presentation maps and escapes displayed errors.
		}
	}

	/**
	 * Render index column.
	 *
	 * @param string $column Column.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private static function render_index_column( string $column ): string {
		if ( 1 !== preg_match( '/^([a-z][a-z0-9_]*)(?:\(([0-9]+)\))?$/', $column, $matches ) ) {
			throw new InvalidArgumentException( 'Invalid index column: ' . $column ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception details stay in the application failure channel; presentation maps and escapes displayed errors.
		}

		return '`' . $matches[1] . '`' . ( isset( $matches[2] ) ? '(' . $matches[2] . ')' : '' );
	}
}
