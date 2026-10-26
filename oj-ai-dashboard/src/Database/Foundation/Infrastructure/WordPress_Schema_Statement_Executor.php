<?php
/**
 * Forward-only WordPress schema statement executor.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Database\Foundation\Infrastructure;

use InvalidArgumentException;
use OJAI\Dashboard\Database\Foundation\Port\Schema_Statement_Executor_Port_Interface;
use RuntimeException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Executes bounded CREATE/ALTER statements for literal add-on tables.
 */
final class WordPress_Schema_Statement_Executor implements Schema_Statement_Executor_Port_Interface {
	/**
	 * Database.
	 *
	 * @var object
	 */
	private object $database;

	/**
	 * Initialize WordPress schema statement executor dependencies and state.
	 *
	 * @param object $database Database.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct( object $database ) {
		if ( ! method_exists( $database, 'query' ) ) {
			throw new InvalidArgumentException( 'The database adapter is missing the query method.' );
		}
		$this->database = $database;
	}

	/**
	 * Execute WordPress schema statement executor.
	 *
	 * @param string $statement Statement.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function execute( string $statement ): void {
		$statement = trim( $statement );
		if ( str_contains( $statement, ';' ) ) {
			throw new InvalidArgumentException( 'Schema migrations must contain one statement at a time.' );
		}
		if ( 1 !== preg_match( '/^(?:CREATE TABLE|ALTER TABLE) `(?<table>oj_ai_[a-z0-9_]+)`(?:\s|\()/s', $statement ) ) {
			throw new InvalidArgumentException( 'Schema statements must target one literal add-on table.' );
		}
		if ( 1 === preg_match( '/\b(?:DROP|RENAME|TRUNCATE)\b/i', $statement ) ) {
			throw new InvalidArgumentException( 'Destructive schema statements are not supported.' );
		}

		if ( false === $this->database->query( $statement ) ) {
			throw new RuntimeException( 'schema_statement_failed' );
		}
	}
}
