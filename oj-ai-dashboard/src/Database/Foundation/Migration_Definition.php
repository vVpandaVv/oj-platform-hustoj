<?php
/**
 * Immutable migration descriptor.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Database\Foundation;

use InvalidArgumentException;
use LogicException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides an append-only migration ID, checksum, SQL, and postcondition.
 */
final class Migration_Definition {
	/**
	 * Id.
	 *
	 * @var string
	 */
	private string $id;
	/**
	 * Target version.
	 *
	 * @var string
	 */
	private string $target_version;
	/**
	 * Schema fingerprint.
	 *
	 * @var string
	 */
	private string $schema_fingerprint;

	/**
	 * Table names.
	 *
	 * @var array<int,string>
	 */
	private array $table_names;

	/**
	 * Statements.
	 *
	 * @var array<int,string>
	 */
	private array $statements;

	/**
	 * Operation kind.
	 *
	 * @var string
	 */
	private string $operation_kind;

	/**
	 * Initialize migration definition dependencies and state.
	 *
	 * @param string            $id Immutable migration ID.
	 * @param string            $target_version Target schema version.
	 * @param string            $schema_fingerprint Frozen catalog fingerprint.
	 * @param array<int,string> $table_names Tables created by this migration.
	 * @param array<int,string> $statements Ordered upward-only SQL.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		string $id,
		string $target_version,
		string $schema_fingerprint,
		array $table_names,
		array $statements
	) {
		if ( 1 !== preg_match( '/^[0-9]{12}_[a-z0-9_]+$/', $id ) ) {
			throw new InvalidArgumentException( 'Migration IDs must be immutable timestamp identifiers.' );
		}
		if ( count( $table_names ) !== count( $statements ) || array() === $table_names ) {
			throw new InvalidArgumentException( 'Each migration table requires one ordered SQL statement.' );
		}
		if ( count( $table_names ) !== count( array_unique( $table_names ) ) ) {
			throw new InvalidArgumentException( 'A migration may not repeat a physical table.' );
		}
		foreach ( $table_names as $table_name ) {
			if ( 1 !== preg_match( '/^oj_ai_[a-z0-9_]+$/', $table_name ) ) {
				throw new InvalidArgumentException( 'Migration tables must use literal physical names.' );
			}
		}

		$this->id                 = $id;
		$this->target_version     = $target_version;
		$this->schema_fingerprint = $schema_fingerprint;
		$this->table_names        = array_values( $table_names );
		$this->statements         = array_map( array( self::class, 'normalize_sql' ), $statements );
		$kinds                    = array();
		foreach ( $this->statements as $position => $statement ) {
			if ( 1 !== preg_match( '/^(CREATE|ALTER) TABLE `(oj_ai_[a-z0-9_]+)`(?:\s|\()/s', $statement, $matches )
				|| $matches[2] !== $this->table_names[ $position ] ) {
				throw new InvalidArgumentException( 'Each migration statement must target its declared literal table.' );
			}
			$kinds[] = strtolower( $matches[1] );
		}
		$kinds = array_values( array_unique( $kinds ) );
		if ( 1 !== count( $kinds ) ) {
			throw new InvalidArgumentException( 'A migration may not mix create and alter operations.' );
		}
		$this->operation_kind = $kinds[0];
	}

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * Target version.
	 *
	 * @return string
	 */
	public function target_version(): string {
		return $this->target_version;
	}

	/**
	 * Schema fingerprint.
	 *
	 * @return string
	 */
	public function schema_fingerprint(): string {
		return $this->schema_fingerprint;
	}

	/**
	 * Table names.
	 *
	 * @return array<int,string>
	 */
	public function table_names(): array {
		return $this->table_names;
	}

	/**
	 * Statements.
	 *
	 * @return array<int,string>
	 */
	public function statements(): array {
		return $this->statements;
	}

	/**
	 * Creates tables.
	 *
	 * @return bool
	 */
	public function creates_tables(): bool {
		return 'create' === $this->operation_kind;
	}

	/**
	 * Alters tables.
	 *
	 * @return bool
	 */
	public function alters_tables(): bool {
		return 'alter' === $this->operation_kind;
	}

	/**
	 * Checksum.
	 *
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function checksum(): string {
		$json = json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Domain JSON uses explicit flags and error handling; fallback re-encoding would change canonical bytes.
			array(
				'id'                 => $this->id,
				'target_version'     => $this->target_version,
				'schema_fingerprint' => $this->schema_fingerprint,
				'table_names'        => $this->table_names,
				'up'                 => $this->statements,
			),
			JSON_UNESCAPED_SLASHES
		);
		if ( false === $json ) {
			throw new LogicException( 'Unable to encode migration checksum input.' );
		}

		return hash( 'sha256', $json );
	}

	/**
	 * Normalize sql.
	 *
	 * @param string $sql Sql.
	 * @return string
	 */
	private static function normalize_sql( string $sql ): string {
		$sql = str_replace( array( "\r\n", "\r" ), "\n", trim( $sql ) );
		$sql = preg_replace( '/[ \t]+$/m', '', $sql );

		return is_string( $sql ) ? $sql : '';
	}
}
