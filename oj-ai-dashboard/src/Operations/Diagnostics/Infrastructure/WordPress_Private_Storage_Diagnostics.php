<?php
/**
 * Read-only diagnostics for add-on private file storage.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\Diagnostics\Infrastructure;

use Closure;
use OJAI\Dashboard\Reporting\Export\Infrastructure\Export_Retention_Query;
use OJAI\Dashboard\Reporting\Export\Infrastructure\Export_Cleanup_Worker_Module;
use DateTimeImmutable;
use DateTimeZone;
use OJAI\Dashboard\Operations\Diagnostics\Domain\Storage_Diagnostics_Result;
use OJAI\Dashboard\Operations\Diagnostics\Domain\Storage_Path_Diagnostics;
use OJAI\Dashboard\Operations\Diagnostics\Port\Storage_Diagnostics_Port_Interface;
use OJAI\Dashboard\Submission\Student\Infrastructure\Submission_Attachment_Cleanup_Worker_Module;
use OJAI\Dashboard\Submission\Student\Infrastructure\Submission_Attachment_Scan_Worker_Module;
use OJAI\Dashboard\Submission\Student\Infrastructure\Clamav_Instream_Submission_Attachment_Scanner;
use OJAI\Dashboard\Submission\ZipImport\Infrastructure\Submission_Zip_Cleanup_Worker_Module;
use OJAI\Dashboard\Submission\ZipImport\Infrastructure\Submission_Zip_Scan_Worker_Module;
use RuntimeException;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only diagnostics for add-on private file storage.
 */
final class WordPress_Private_Storage_Diagnostics implements Storage_Diagnostics_Port_Interface {
	private const TABLE_PRIVATE_FILES = 'oj_ai_private_files';
	private const WINDOW_MINUTES      = 15;

	/**
	 * Database provider.
	 *
	 * @var Closure
	 */
	private Closure $database_provider;
	/**
	 * Schedule provider.
	 *
	 * @var Closure
	 */
	private Closure $schedule_provider;
	/**
	 * Path provider.
	 *
	 * @var Closure
	 */
	private Closure $path_provider;
	/**
	 * Clock.
	 *
	 * @var Closure
	 */
	private Closure $clock;
	/**
	 * Scanner probe.
	 *
	 * @var Closure
	 */
	private Closure $scanner_probe;

	/**
	 * Initialize WordPress private storage diagnostics dependencies and state.
	 *
	 * @param Closure|null $database_provider Database provider.
	 * @param Closure|null $schedule_provider Schedule provider.
	 * @param Closure|null $path_provider Path provider.
	 * @param Closure|null $clock Clock.
	 * @param Closure|null $scanner_probe Scanner probe.
	 */
	public function __construct(
		?Closure $database_provider = null,
		?Closure $schedule_provider = null,
		?Closure $path_provider = null,
		?Closure $clock = null,
		?Closure $scanner_probe = null
	) {
		$this->database_provider = $database_provider ?? static function () {
			return $GLOBALS['wpdb'] ?? null;
		};
		$this->schedule_provider = $schedule_provider ?? static function ( string $hook ) {
			if ( ! function_exists( 'wp_next_scheduled' ) ) {
				throw new RuntimeException( 'WordPress scheduling is unavailable.' );
			}
			return wp_next_scheduled( $hook );
		};
		$this->path_provider     = $path_provider ?? static fn (): Storage_Path_Diagnostics => ( new Local_Private_Storage_Path_Diagnostics() )->inspect();
		$this->clock             = $clock ?? static fn (): string => gmdate( 'Y-m-d H:i:s' );
		$this->scanner_probe     = $scanner_probe ?? static fn (): bool => ( new Clamav_Instream_Submission_Attachment_Scanner( null, 2 ) )->is_available();
	}

	/**
	 * Inspect.
	 *
	 * @return Storage_Diagnostics_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function inspect(): Storage_Diagnostics_Result {
		$path              = null;
		$scheduled_workers = 0;
		$expected_workers  = count( $this->worker_hooks() );

		try {
			$path = ( $this->path_provider )();
			if ( ! $path instanceof Storage_Path_Diagnostics ) {
				throw new RuntimeException( 'Private storage path diagnostics are invalid.' );
			}
			$now_utc    = $this->datetime( ( $this->clock )() );
			$window_utc = $this->window_start( $now_utc );

			foreach ( $this->worker_hooks() as $hook ) {
				$scheduled = ( $this->schedule_provider )( $hook );
				if ( false === $scheduled ) {
					continue;
				}
				if ( ! is_int( $scheduled ) || $scheduled < 0 ) {
					throw new RuntimeException( 'WordPress returned an invalid storage schedule timestamp.' );
				}
				++$scheduled_workers;
			}

			$row     = $this->storage_row( $now_utc, $window_utc );
			$metrics = array(
				'expected_workers'  => $expected_workers,
				'scheduled_workers' => $scheduled_workers,
				'pending_scans'     => $this->count( $row['pending_scan_count'] ?? null ),
				'delayed_scans'     => $this->count( $row['delayed_scan_count'] ?? null ),
				'cleanup_due'       => $this->count( $row['cleanup_due_count'] ?? null ),
				'delayed_cleanup'   => $this->count( $row['delayed_cleanup_count'] ?? null ),
				'metadata_issues'   => $this->count( $row['metadata_issue_count'] ?? null ),
			);

			$issues = array();
			if ( Storage_Path_Diagnostics::READY !== $path->status() ) {
				$issues[] = $path->code();
			}
			if ( $scheduled_workers < $expected_workers ) {
				$issues[] = 'storage_schedule_inactive';
			}
			try {
				$scanner_ready = true === ( $this->scanner_probe )();
			} catch ( Throwable ) {
				$scanner_ready = false;
			}
			if ( ! $scanner_ready ) {
				$issues[] = 'storage_scanner_unavailable';
			}
			if ( $metrics['metadata_issues'] > 0 ) {
				$issues[] = 'storage_metadata_inconsistent';
			}
			if ( $metrics['delayed_scans'] > 0 ) {
				$issues[] = 'storage_scan_delayed';
			}
			if ( $metrics['delayed_cleanup'] > 0 ) {
				$issues[] = 'storage_cleanup_delayed';
			}
			$scanner_status = $scanner_ready ? 'responding' : 'unavailable';

			if ( Storage_Path_Diagnostics::READY !== $path->status() || $scheduled_workers < $expected_workers ) {
				return new Storage_Diagnostics_Result( Storage_Diagnostics_Result::UNAVAILABLE, $issues[0], $path, $metrics, $issues, $scanner_status );
			}
			if ( array() !== $issues ) {
				return new Storage_Diagnostics_Result( Storage_Diagnostics_Result::ATTENTION, $issues[0], $path, $metrics, $issues, $scanner_status );
			}

			return new Storage_Diagnostics_Result( Storage_Diagnostics_Result::READY, 'storage_runtime_ready', $path, $metrics, array(), $scanner_status );
		} catch ( Throwable ) {
			return Storage_Diagnostics_Result::unavailable(
				'storage_observation_failed',
				$path instanceof Storage_Path_Diagnostics ? $path : null,
				$expected_workers,
				$scheduled_workers
			);
		}
	}

	/**
	 * Worker hooks.
	 *
	 * @return array<int,string>
	 */
	private function worker_hooks(): array {
		return array(
			Submission_Attachment_Scan_Worker_Module::SWEEP_HOOK,
			Submission_Attachment_Cleanup_Worker_Module::SWEEP_HOOK,
			Submission_Zip_Scan_Worker_Module::SWEEP_HOOK,
			Submission_Zip_Cleanup_Worker_Module::SWEEP_HOOK,
			Export_Cleanup_Worker_Module::SWEEP_HOOK,
		);
	}

	/**
	 * Storage row.
	 *
	 * @param string $now_utc Now utc.
	 * @param string $window_utc Window utc.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function storage_row( string $now_utc, string $window_utc ): array {
		$database = ( $this->database_provider )();
		if ( ! is_object( $database ) || ! method_exists( $database, 'prepare' ) || ! method_exists( $database, 'get_row' ) ) {
			throw new RuntimeException( 'WordPress private storage diagnostics are unavailable.' );
		}

		$cleanup  = "f.legal_hold_until_utc IS NULL AND f.purged_at_utc IS NULL AND f.state NOT IN ('purged','bound')"
			. " AND (f.retention_class <> 'generated_export_24h' OR (" . Export_Retention_Query::eligible() . '))';
		$sql      = 'SELECT '
			. 'COALESCE(SUM(CASE WHEN f.state = %s AND f.scan_state = %s THEN 1 ELSE 0 END),0) AS pending_scan_count, '
			. 'COALESCE(SUM(CASE WHEN f.state = %s AND f.scan_state = %s AND f.created_at_utc <= %s THEN 1 ELSE 0 END),0) AS delayed_scan_count, '
			. 'COALESCE(SUM(CASE WHEN f.expires_at_utc <= %s AND ' . $cleanup . ' THEN 1 ELSE 0 END),0) AS cleanup_due_count, '
			. 'COALESCE(SUM(CASE WHEN f.expires_at_utc <= %s AND ' . $cleanup . ' THEN 1 ELSE 0 END),0) AS delayed_cleanup_count, '
			. 'COALESCE(SUM(CASE WHEN f.state NOT IN (%s,%s,%s,%s,%s,%s) OR f.scan_state NOT IN (%s,%s,%s) '
			. 'OR (f.state IN (%s,%s) AND f.scan_state <> %s) OR (f.state = %s AND f.purged_at_utc IS NULL) '
			. 'OR (f.state <> %s AND f.purged_at_utc IS NOT NULL) '
			. "OR (f.retention_class = 'generated_export_24h' AND f.state <> 'purged' AND f.expires_at_utc <= %s AND ("
			. Export_Retention_Query::disposition() . ") = 'blocked') THEN 1 ELSE 0 END),0) AS metadata_issue_count "
			. 'FROM ' . self::TABLE_PRIVATE_FILES . ' f' . Export_Retention_Query::joins();
		$prepared = $database->prepare(
			$sql,
			'quarantined',
			'pending',
			'quarantined',
			'pending',
			$window_utc,
			$now_utc,
			$window_utc,
			'quarantined',
			'ready',
			'rejected',
			'bound',
			'expired',
			'purged',
			'pending',
			'clean',
			'rejected',
			'ready',
			'bound',
			'clean',
			'purged',
			'purged',
			$now_utc
		);
		if ( ! is_string( $prepared ) || '' === $prepared ) {
			throw new RuntimeException( 'Private storage diagnostic query preparation failed.' );
		}

		$row = $database->get_row( $prepared, defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A' );
		if ( ! is_array( $row ) || array_is_list( $row ) || '' !== trim( (string) ( $database->last_error ?? '' ) ) ) {
			throw new RuntimeException( 'Private storage diagnostic query failed.' );
		}
		return $row;
	}

	/**
	 * Count.
	 *
	 * @param mixed $value Value.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function count( $value ): int {
		if ( is_int( $value ) && $value >= 0 ) {
			return $value;
		}
		if ( is_string( $value ) && 1 === preg_match( '/^(?:0|[1-9][0-9]*)$/D', $value ) ) {
			return (int) $value;
		}
		throw new RuntimeException( 'Private storage diagnostic count is invalid.' );
	}

	/**
	 * Datetime.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function datetime( $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value ) ) {
			throw new RuntimeException( 'Private storage diagnostic clock is invalid.' );
		}
		return $value;
	}

	/**
	 * Window start.
	 *
	 * @param string $now_utc Now utc.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function window_start( string $now_utc ): string {
		$now = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $now_utc, new DateTimeZone( 'UTC' ) );
		if ( false === $now || $now->format( 'Y-m-d H:i:s' ) !== $now_utc ) {
			throw new RuntimeException( 'Private storage diagnostic clock cannot be parsed.' );
		}
		return $now->modify( '-' . self::WINDOW_MINUTES . ' minutes' )->format( 'Y-m-d H:i:s' );
	}
}
