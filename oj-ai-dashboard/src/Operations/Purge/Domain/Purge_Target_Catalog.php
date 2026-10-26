<?php
/**
 * Purge target catalog.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\Purge\Domain;

use LogicException;
use OJAI\Dashboard\Database\Foundation\Schema_Catalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Defines the exact C73 data plane while preserving resumable control ledgers.
 */
final class Purge_Target_Catalog {
	public const TARGET_TABLE_COUNT = 43;

	private const PROTECTED_TABLES = array(
		'oj_ai_installations',
		'oj_ai_schema_migrations',
		'oj_ai_audit_events',
		'oj_ai_outbox_events',
		'oj_ai_purge_jobs',
	);

	/**
	 * Initialize purge target catalog dependencies and state.
	 *
	 * @param Schema_Catalog $catalog Catalog.
	 */
	public function __construct( private Schema_Catalog $catalog ) {
	}

	/**
	 * Table names.
	 *
	 * @return array<int,string>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function table_names(): array {
		$tables = array();
		foreach ( array_reverse( $this->catalog->creation_order() ) as $table ) {
			if ( ! in_array( $table->name(), self::PROTECTED_TABLES, true ) ) {
				$tables[] = $table->name();
			}
		}
		if ( self::TARGET_TABLE_COUNT !== count( $tables ) || count( $tables ) !== count( array_unique( $tables ) ) ) {
			throw new LogicException( 'The purge target catalog differs from the frozen 40-table data plane.' );
		}
		return $tables;
	}

	/**
	 * Expected row count.
	 *
	 * @param array<string,mixed> $preview Preview.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function expected_row_count( array $preview ): int {
		$owners = $preview['owner_rows'] ?? null;
		if ( ! is_array( $owners ) || array_is_list( $owners ) ) {
			throw new LogicException( 'The purge preview owner totals are unavailable.' );
		}
		$total = 0;
		foreach ( $this->table_names() as $name ) {
			$owner = $this->catalog->table( $name )->owner();
			$count = $owners[ $owner ] ?? null;
			if ( ! is_int( $count ) || $count < 0 ) {
				throw new LogicException( 'The purge preview owner total is invalid.' );
			}
		}
		foreach ( $owners as $owner => $count ) {
			if ( in_array( $owner, array( Schema_Catalog::OWNER_DATABASE, Schema_Catalog::OWNER_OPERATIONS ), true ) ) {
				continue;
			}
			if ( ! is_int( $count ) || $count < 0 || $count > PHP_INT_MAX - $total ) {
				throw new LogicException( 'The purge target row total is invalid.' );
			}
			$total += $count;
		}
		return $total;
	}

	/**
	 * Protected tables.
	 *
	 * @return array<int,string>
	 */
	public static function protected_tables(): array {
		return self::PROTECTED_TABLES;
	}
}
