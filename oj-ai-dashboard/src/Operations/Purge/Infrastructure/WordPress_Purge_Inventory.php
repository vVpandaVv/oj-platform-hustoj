<?php
/**
 * WordPress purge inventory.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\Purge\Infrastructure;

use Closure;
use OJAI\Dashboard\Database\Foundation\Schema_Catalog;
use OJAI\Dashboard\Operations\Purge\Application\Purge_Failure;
use OJAI\Dashboard\Operations\Purge\Domain\Purge_Inventory_Snapshot;
use OJAI\Dashboard\Operations\Purge\Port\Purge_Inventory_Port_Interface;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WordPress purge inventory.
 */
final class WordPress_Purge_Inventory implements Purge_Inventory_Port_Interface {
	private const INCOMPLETE_JOB_TERMINAL_STATES = array(
		'evaluation_runs' => array( 'succeeded', 'failed', 'cancelled' ),
		'export_jobs'     => array( 'ready', 'failed', 'cancelled', 'expired' ),
		'zip_uploads'     => array( 'completed', 'canceled', 'rejected' ),
		'import_runs'     => array( 'completed', 'completed_with_errors', 'compensated' ),
		'purge_jobs'      => array( 'completed', 'failed', 'cancelled' ),
	);

	private const JOB_TABLES = array(
		'evaluation_runs' => 'oj_ai_evaluation_runs',
		'export_jobs'     => 'oj_ai_export_jobs',
		'zip_uploads'     => 'oj_ai_zip_uploads',
		'import_runs'     => 'oj_ai_import_runs',
		'purge_jobs'      => 'oj_ai_purge_jobs',
	);

	/**
	 * Database.
	 *
	 * @var Closure
	 */
	private Closure $database;

	/**
	 * Initialize WordPress purge inventory dependencies and state.
	 *
	 * @param Schema_Catalog $catalog Catalog.
	 * @param Closure|null   $database Database.
	 */
	public function __construct( private Schema_Catalog $catalog, ?Closure $database = null ) {
		$this->database = $database ?? static function (): ?object {
			global $wpdb;
			return is_object( $wpdb ) ? $wpdb : null;
		};
	}

	/**
	 * Inspect.
	 *
	 * @return Purge_Inventory_Snapshot
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function inspect(): Purge_Inventory_Snapshot {
		try {
			$database = ( $this->database )();
			if ( ! is_object( $database ) || ! method_exists( $database, 'get_results' ) ) {
				throw Purge_Failure::because( Purge_Failure::INVENTORY_UNAVAILABLE );
			}

			$query  = $this->inventory_query();
			$output = defined( 'ARRAY_A' ) ? constant( 'ARRAY_A' ) : 'ARRAY_A';
			$rows   = $database->get_results( $query, $output );
			if ( ! is_array( $rows ) || ( isset( $database->last_error ) && '' !== (string) $database->last_error ) ) {
				throw Purge_Failure::because( Purge_Failure::INVENTORY_UNAVAILABLE );
			}

			return $this->snapshot( $rows );
		} catch ( Purge_Failure $failure ) {
			throw $failure;
		} catch ( Throwable $error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Purge_Failure::because( Purge_Failure::INVENTORY_UNAVAILABLE, $error );
		}
	}

	/**
	 * Inventory query.
	 *
	 * @return string
	 */
	private function inventory_query(): string {
		$statements = array();
		foreach ( $this->catalog->tables() as $table ) {
			$name         = $this->table_name( $table->name() );
			$owner        = $this->literal( $table->owner() );
			$statements[] = "SELECT 'owner_rows' AS metric, {$owner} AS metric_key, COUNT(*) AS metric_value FROM `{$name}`";
		}

		$private      = $this->table_name( 'oj_ai_private_files' );
		$statements[] = "SELECT 'private_file_count' AS metric, 'private_files' AS metric_key, COUNT(*) AS metric_value FROM `{$private}` WHERE purged_at_utc IS NULL";
		$statements[] = "SELECT 'private_blob_bytes' AS metric, 'private_files' AS metric_key, COALESCE(SUM(byte_size), 0) AS metric_value FROM `{$private}` WHERE purged_at_utc IS NULL";
		$statements[] = "SELECT 'legal_hold_count' AS metric, 'private_files' AS metric_key, COALESCE(SUM(CASE WHEN legal_hold_until_utc IS NOT NULL THEN 1 ELSE 0 END), 0) AS metric_value FROM `{$private}` WHERE purged_at_utc IS NULL";

		foreach ( self::JOB_TABLES as $key => $table ) {
			$table        = $this->table_name( $table );
			$terminal     = array_map( fn ( string $state ): string => $this->literal( $state ), self::INCOMPLETE_JOB_TERMINAL_STATES[ $key ] );
			$key_literal  = $this->literal( $key );
			$statements[] = "SELECT 'incomplete_jobs' AS metric, {$key_literal} AS metric_key, COUNT(*) AS metric_value FROM `{$table}` WHERE state NOT IN (" . implode( ', ', $terminal ) . ')';
		}

		return implode( "\nUNION ALL\n", $statements );
	}

	/**
	 * Snapshot.
	 *
	 * @param array<int,mixed> $rows Rows.
	 * @return Purge_Inventory_Snapshot
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function snapshot( array $rows ): Purge_Inventory_Snapshot {
		$owner_rows          = array_fill_keys( array_keys( $this->catalog->owner_counts() ), 0 );
		$owner_metric_counts = array_fill_keys( array_keys( $owner_rows ), 0 );
		$jobs                = array_fill_keys( array_keys( self::JOB_TABLES ), 0 );
		$seen_jobs           = array_fill_keys( array_keys( self::JOB_TABLES ), false );
		$private_file_count  = null;
		$private_blob_bytes  = null;
		$legal_hold_count    = null;
		$owner_metric_count  = 0;
		$job_metric_count    = 0;

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || array_is_list( $row ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Purge_Failure::because( Purge_Failure::INVENTORY_UNAVAILABLE );
			}
			$metric = $row['metric'] ?? null;
			$key    = $row['metric_key'] ?? null;
			$value  = $this->non_negative_integer( $row['metric_value'] ?? null );
			if ( ! is_string( $metric ) || ! is_string( $key ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Purge_Failure::because( Purge_Failure::INVENTORY_UNAVAILABLE );
			}

			switch ( $metric ) {
				case 'owner_rows':
					if ( ! array_key_exists( $key, $owner_rows ) ) {
						// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
						throw Purge_Failure::because( Purge_Failure::INVENTORY_UNAVAILABLE );
					}
					$owner_rows[ $key ] = $this->checked_add( $owner_rows[ $key ], $value );
					++$owner_metric_counts[ $key ];
					++$owner_metric_count;
					break;
				case 'private_file_count':
					if ( null !== $private_file_count || 'private_files' !== $key ) {
						// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
						throw Purge_Failure::because( Purge_Failure::INVENTORY_UNAVAILABLE );
					}
					$private_file_count = $value;
					break;
				case 'private_blob_bytes':
					if ( null !== $private_blob_bytes || 'private_files' !== $key ) {
						// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
						throw Purge_Failure::because( Purge_Failure::INVENTORY_UNAVAILABLE );
					}
					$private_blob_bytes = $value;
					break;
				case 'legal_hold_count':
					if ( null !== $legal_hold_count || 'private_files' !== $key ) {
						// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
						throw Purge_Failure::because( Purge_Failure::INVENTORY_UNAVAILABLE );
					}
					$legal_hold_count = $value;
					break;
				case 'incomplete_jobs':
					if ( ! array_key_exists( $key, $jobs ) || $seen_jobs[ $key ] ) {
						// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
						throw Purge_Failure::because( Purge_Failure::INVENTORY_UNAVAILABLE );
					}
					$jobs[ $key ]      = $value;
					$seen_jobs[ $key ] = true;
					++$job_metric_count;
					break;
				default:
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
					throw Purge_Failure::because( Purge_Failure::INVENTORY_UNAVAILABLE );
			}
		}

		if (
			Schema_Catalog::TABLE_COUNT !== $owner_metric_count
			|| $this->catalog->owner_counts() !== $owner_metric_counts
			|| count( self::JOB_TABLES ) !== $job_metric_count
			|| in_array( false, $seen_jobs, true )
			|| null === $private_file_count
			|| null === $private_blob_bytes
			|| null === $legal_hold_count
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Purge_Failure::because( Purge_Failure::INVENTORY_UNAVAILABLE );
		}

		return new Purge_Inventory_Snapshot(
			$owner_rows,
			$private_file_count,
			$private_blob_bytes,
			$legal_hold_count,
			$jobs
		);
	}

	/**
	 * Table name.
	 *
	 * @param string $name Name.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function table_name( string $name ): string {
		if ( 1 !== preg_match( '/^oj_ai_[a-z0-9_]+$/D', $name ) || ! in_array( $name, $this->catalog->table_names(), true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Purge_Failure::because( Purge_Failure::INVENTORY_UNAVAILABLE );
		}
		return $name;
	}

	/**
	 * Literal.
	 *
	 * @param string $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function literal( string $value ): string {
		if ( 1 !== preg_match( '/^[A-Za-z][A-Za-z0-9_]{1,63}$/D', $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Purge_Failure::because( Purge_Failure::INVENTORY_UNAVAILABLE );
		}
		return "'{$value}'";
	}

	/**
	 * Non negative integer.
	 *
	 * @param mixed $value Value.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function non_negative_integer( $value ): int {
		if ( is_int( $value ) && $value >= 0 ) {
			return $value;
		}
		if ( ! is_string( $value ) || 1 !== preg_match( '/^(0|[1-9][0-9]*)$/D', $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Purge_Failure::because( Purge_Failure::INVENTORY_UNAVAILABLE );
		}
		$parsed = filter_var( $value, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 0 ) ) );
		if ( false === $parsed ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Purge_Failure::because( Purge_Failure::INVENTORY_UNAVAILABLE );
		}
		return $parsed;
	}

	/**
	 * Checked add.
	 *
	 * @param int $left Left.
	 * @param int $right Right.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function checked_add( int $left, int $right ): int {
		if ( $right > PHP_INT_MAX - $left ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Purge_Failure::because( Purge_Failure::INVENTORY_UNAVAILABLE );
		}
		return $left + $right;
	}
}
