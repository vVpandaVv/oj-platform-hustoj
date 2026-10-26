<?php
/**
 * WordPress storage backlog diagnostics.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\Diagnostics\Infrastructure;

use OJAI\Dashboard\Operations\Diagnostics\Port\Storage_Backlog_Port_Interface;
use RuntimeException;
use OJAI\Dashboard\Reporting\Export\Infrastructure\Export_Retention_Query;

/**
 * Reads counts only; expiration is never treated as authorization to delete.
 */
final class WordPress_Storage_Backlog_Diagnostics implements Storage_Backlog_Port_Interface {
	/**
	 * Initialize WordPress storage backlog diagnostics dependencies and state.
	 *
	 * @param object|null $database Database.
	 */
	public function __construct( private ?object $database = null ) {
	}

	/**
	 * Inspect.
	 *
	 * @param string $now_utc Now utc.
	 * @return array
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function inspect( string $now_utc ): array {
		$database = $this->database ?? ( $GLOBALS['wpdb'] ?? null );
		if ( ! is_object( $database ) || ! method_exists( $database, 'prepare' ) || ! method_exists( $database, 'get_results' ) ) {
			throw new RuntimeException( 'Storage backlog observation is unavailable.' );
		}
		$sql      = 'SELECT CASE '
			. 'WHEN purpose = %s AND retention_class = %s THEN %s '
			. 'WHEN purpose IN (%s,%s) AND retention_class = %s THEN %s '
			. 'WHEN retention_class = %s THEN %s '
			. 'WHEN purpose = %s THEN %s ELSE %s END AS category, '
			. 'COUNT(*) AS expired, SUM(CASE WHEN legal_hold_until_utc IS NOT NULL THEN 1 ELSE 0 END) AS held, '
			. 'SUM(CASE WHEN state = %s THEN 1 ELSE 0 END) AS bound '
			. 'FROM oj_ai_private_files WHERE state <> %s AND purged_at_utc IS NULL '
			. 'AND expires_at_utc IS NOT NULL AND expires_at_utc <= %s GROUP BY category';
		$prepared = $database->prepare(
			$sql,
			'submission_attachment',
			'submission_staging',
			'attachment',
			'submission_zip_source',
			'submission_zip_item',
			'submission_zip_staging',
			'zip',
			'generated_export_24h',
			'export',
			'worksheet_asset',
			'worksheet',
			'other',
			'bound',
			'purged',
			$now_utc
		);
		if ( ! is_string( $prepared ) || '' === $prepared ) {
			throw new RuntimeException( 'Storage backlog query preparation failed.' );
		}
		$rows = $database->get_results( $prepared, defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A' );
		$this->assert_database_ok( $database );
		if ( ! is_array( $rows ) ) {
			throw new RuntimeException( 'Storage backlog query failed.' );
		}
		$result = array_fill_keys(
			array( 'attachment', 'zip', 'export', 'worksheet', 'other' ),
			array(
				'expired' => 0,
				'held'    => 0,
				'bound'   => 0,
			)
		);
		foreach ( $rows as $row ) {
			$category = $row['category'] ?? null;
			if ( ! is_string( $category ) || ! isset( $result[ $category ] ) ) {
				throw new RuntimeException( 'Storage backlog category is invalid.' );
			}
			foreach ( array( 'expired', 'held', 'bound' ) as $key ) {
				$value = $row[ $key ] ?? null;
				if ( ! is_int( $value ) && ( ! is_string( $value ) || 1 !== preg_match( '/^(?:0|[1-9][0-9]*)$/D', $value ) ) ) {
					throw new RuntimeException( 'Storage backlog count is invalid.' );
				}
				if ( (int) $value < 0 ) {
					throw new RuntimeException( 'Storage backlog count is negative.' );
				}
				$result[ $category ][ $key ] = (int) $value;
			}
			if ( max( $result[ $category ]['held'], $result[ $category ]['bound'] ) > $result[ $category ]['expired'] ) {
				throw new RuntimeException( 'Storage backlog counts are inconsistent.' );
			}
		}
		$result['export'] += array_fill_keys( array( 'eligible', 'preserved', 'in_use', 'blocked' ), 0 );
		$prepared          = $database->prepare(
			'SELECT ' . Export_Retention_Query::disposition() . ' AS disposition, COUNT(*) AS total '
			. 'FROM oj_ai_private_files f' . Export_Retention_Query::joins()
			. " WHERE f.retention_class = %s AND f.state <> 'purged' AND f.purged_at_utc IS NULL AND f.expires_at_utc <= %s GROUP BY disposition",
			Export_Retention_Query::RETENTION_CLASS,
			$now_utc
		);
		if ( ! is_string( $prepared ) || '' === $prepared ) {
			throw new RuntimeException( 'Export retention query unavailable.' );
		}
		$rows = $database->get_results( $prepared, defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A' );
		$this->assert_database_ok( $database );
		if ( ! is_array( $rows ) ) {
			throw new RuntimeException( 'Export retention observation failed.' );
		}
		foreach ( $rows as $row ) {
			$disposition = $row['disposition'] ?? '';
			if ( ! in_array( $disposition, array( 'eligible', 'preserved', 'in_use', 'blocked' ), true )
				|| 1 !== preg_match( '/^(0|[1-9][0-9]*)$/D', (string) ( $row['total'] ?? '' ) ) ) {
				throw new RuntimeException( 'Export retention counts invalid.' );
			}
			$result['export'][ $disposition ] = (int) $row['total'];
		}
		return $result;
	}

	/**
	 * Reject invalid database ok.
	 *
	 * @param object $database Database.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_database_ok( object $database ): void {
		if ( '' !== trim( (string) ( $database->last_error ?? '' ) ) ) {
			throw new RuntimeException( 'Storage backlog observation failed.' );
		}
	}
}
