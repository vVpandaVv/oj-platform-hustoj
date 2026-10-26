<?php
/**
 * Read-only diagnostics for the add-on-owned AI evaluation queue.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\Diagnostics\Infrastructure;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use OJAI\Dashboard\Operations\Diagnostics\Domain\Queue_Diagnostics_Result;
use OJAI\Dashboard\Operations\Diagnostics\Port\Queue_Diagnostics_Port_Interface;
use OJAI\Dashboard\Submission\Assessment\Infrastructure\Student_Note_Worker_Module;
use OJAI\Dashboard\Submission\Assessment\Infrastructure\Submission_Evaluation_Worker_Module;
use OJAI\Dashboard\Submission\Assessment\Infrastructure\Submission_Feedback_Worker_Module;
use OJAI\Dashboard\Worksheet\Evaluation\Infrastructure\Worksheet_Evaluation_Worker_Module;
use RuntimeException;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only diagnostics for the add-on-owned AI evaluation queue.
 */
final class WordPress_Evaluation_Queue_Diagnostics implements Queue_Diagnostics_Port_Interface {
	private const TABLE_RUNS        = 'oj_ai_evaluation_runs';
	private const FAILURE_THRESHOLD = 3;
	private const WINDOW_MINUTES    = 15;

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
	 * Clock.
	 *
	 * @var Closure
	 */
	private Closure $clock;

	/**
	 * Initialize WordPress evaluation queue diagnostics dependencies and state.
	 *
	 * @param Closure|null $database_provider Database provider.
	 * @param Closure|null $schedule_provider Schedule provider.
	 * @param Closure|null $clock Clock.
	 */
	public function __construct(
		?Closure $database_provider = null,
		?Closure $schedule_provider = null,
		?Closure $clock = null
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
		$this->clock             = $clock ?? static fn (): string => gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Inspect.
	 *
	 * @return Queue_Diagnostics_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function inspect(): Queue_Diagnostics_Result {
		$scheduled_workers = 0;
		$expected_workers  = count( $this->worker_hooks() );

		try {
			$now_utc    = $this->datetime( ( $this->clock )() );
			$window_utc = $this->window_start( $now_utc );

			foreach ( $this->worker_hooks() as $hook ) {
				$scheduled = ( $this->schedule_provider )( $hook );
				if ( false === $scheduled ) {
					continue;
				}
				if ( ! is_int( $scheduled ) || $scheduled < 0 ) {
					throw new RuntimeException( 'WordPress returned an invalid schedule timestamp.' );
				}
				++$scheduled_workers;
			}

			$row     = $this->queue_row( $now_utc, $window_utc );
			$metrics = array(
				'expected_workers'  => $expected_workers,
				'scheduled_workers' => $scheduled_workers,
				'pending'           => $this->count( $row['pending_count'] ?? null ),
				'active'            => $this->count( $row['active_count'] ?? null ),
				'delayed'           => $this->count( $row['delayed_count'] ?? null ),
				'stale_leases'      => $this->count( $row['stale_lease_count'] ?? null ),
				'recent_failures'   => $this->count( $row['recent_failure_count'] ?? null ),
			);

			$issues = array();
			if ( $scheduled_workers < $expected_workers ) {
				$issues[] = 'queue_schedule_inactive';
			}
			if ( $metrics['stale_leases'] > 0 ) {
				$issues[] = 'queue_stale_lease_detected';
			}
			if ( $metrics['recent_failures'] >= self::FAILURE_THRESHOLD ) {
				$issues[] = 'queue_failure_threshold_reached';
			}
			if ( $metrics['delayed'] > 0 ) {
				$issues[] = 'queue_backlog_delayed';
			}

			if ( $scheduled_workers < $expected_workers ) {
				return new Queue_Diagnostics_Result(
					Queue_Diagnostics_Result::UNAVAILABLE,
					'queue_schedule_inactive',
					$metrics,
					$issues
				);
			}
			if ( array() !== $issues ) {
				return new Queue_Diagnostics_Result(
					Queue_Diagnostics_Result::ATTENTION,
					$issues[0],
					$metrics,
					$issues
				);
			}

			return new Queue_Diagnostics_Result(
				Queue_Diagnostics_Result::READY,
				'queue_runtime_ready',
				$metrics
			);
		} catch ( Throwable ) {
			return new Queue_Diagnostics_Result(
				Queue_Diagnostics_Result::UNAVAILABLE,
				'queue_observation_failed',
				array(
					'expected_workers'  => $expected_workers,
					'scheduled_workers' => $scheduled_workers,
					'pending'           => 0,
					'active'            => 0,
					'delayed'           => 0,
					'stale_leases'      => 0,
					'recent_failures'   => 0,
				),
				array( 'queue_observation_failed' )
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
			Worksheet_Evaluation_Worker_Module::SWEEP_HOOK,
			Submission_Evaluation_Worker_Module::SWEEP_HOOK,
			Submission_Feedback_Worker_Module::SWEEP_HOOK,
			Student_Note_Worker_Module::SWEEP_HOOK,
		);
	}

	/**
	 * Queue row.
	 *
	 * @param string $now_utc Now utc.
	 * @param string $window_utc Window utc.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function queue_row( string $now_utc, string $window_utc ): array {
		$database = ( $this->database_provider )();
		if ( ! is_object( $database ) || ! method_exists( $database, 'prepare' ) || ! method_exists( $database, 'get_row' ) ) {
			throw new RuntimeException( 'WordPress database diagnostics are unavailable.' );
		}

		$sql      = 'SELECT '
			. 'COALESCE(SUM(CASE WHEN state IN (%s,%s) THEN 1 ELSE 0 END),0) AS pending_count, '
			. 'COALESCE(SUM(CASE WHEN state IN (%s,%s) THEN 1 ELSE 0 END),0) AS active_count, '
			. 'COALESCE(SUM(CASE WHEN state IN (%s,%s) AND available_at_utc <= %s THEN 1 ELSE 0 END),0) AS delayed_count, '
			. 'COALESCE(SUM(CASE WHEN state IN (%s,%s) AND lease_expires_at_utc IS NOT NULL AND lease_expires_at_utc <= %s THEN 1 ELSE 0 END),0) AS stale_lease_count, '
			. 'COALESCE(SUM(CASE WHEN state = %s AND finished_at_utc IS NOT NULL AND finished_at_utc >= %s THEN 1 ELSE 0 END),0) AS recent_failure_count '
			. 'FROM ' . self::TABLE_RUNS;
		$prepared = $database->prepare(
			$sql,
			'queued',
			'retry_wait',
			'leased',
			'running',
			'queued',
			'retry_wait',
			$window_utc,
			'leased',
			'running',
			$now_utc,
			'failed',
			$window_utc
		);
		if ( ! is_string( $prepared ) || '' === $prepared ) {
			throw new RuntimeException( 'Queue diagnostic query preparation failed.' );
		}

		$row = $database->get_row( $prepared, defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A' );
		if (
			! is_array( $row )
			|| array_is_list( $row )
			|| '' !== (string) ( $database->last_error ?? '' )
		) {
			throw new RuntimeException( 'Queue diagnostic query failed.' );
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
		throw new RuntimeException( 'Queue diagnostic count is invalid.' );
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
			throw new RuntimeException( 'Queue diagnostic clock is invalid.' );
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
			throw new RuntimeException( 'Queue diagnostic clock cannot be parsed.' );
		}

		return $now->modify( '-' . self::WINDOW_MINUTES . ' minutes' )->format( 'Y-m-d H:i:s' );
	}
}
