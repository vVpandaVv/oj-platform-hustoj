<?php
/**
 * Detail-level, manage_policies-only storage operations read model.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\Diagnostics\Application;

use Closure;
use OJAI\Dashboard\Reporting\Export\Infrastructure\Export_Cleanup_Worker_Module;
use OJAI\Dashboard\Contracts\Storage_Worker_Heartbeat_Port_Interface;
use OJAI\Dashboard\Operations\Diagnostics\Domain\Storage_Diagnostics_Result;
use OJAI\Dashboard\Operations\Diagnostics\Port\Storage_Diagnostics_Port_Interface;
use OJAI\Dashboard\Operations\Diagnostics\Port\Storage_Backlog_Port_Interface;
use OJAI\Dashboard\Submission\Student\Infrastructure\Submission_Attachment_Cleanup_Worker_Module;
use OJAI\Dashboard\Submission\Student\Infrastructure\Submission_Attachment_Scan_Worker_Module;
use OJAI\Dashboard\Submission\ZipImport\Infrastructure\Submission_Zip_Cleanup_Worker_Module;
use OJAI\Dashboard\Submission\ZipImport\Infrastructure\Submission_Zip_Scan_Worker_Module;
use RuntimeException;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Backs the separate "파일 보관 상세" Page linked from the system-status
 * widget's "파일 보관" row. That widget's own renderer is deliberately
 * content-free by an enforced contract; this is a distinct, capability-gated
 * Page, so it is allowed to show the full Q28 metrics plainly.
 *
 * It adds one thing Q28 cannot answer: whether each storage worker's cron
 * hook is actually being invoked, not just scheduled. `wp_next_scheduled()`
 * only reports that WordPress intends to fire a hook -- on a host where
 * pseudo-cron never runs, every hook can show as "scheduled" while nothing
 * ever ticks. The heartbeat read here is the honest substitute: the UTC time
 * each worker's `run()` last actually executed, regardless of whether that
 * tick found work to do.
 *
 * There is deliberately no "currently in progress" flag: all four workers
 * claim and resolve at most one item per `run_once()` call, inside one
 * synchronous request (see Resume_Storage_Jobs and each *_Worker_Module).
 * Nothing is ever observably "mid-flight" between requests under normal
 * operation, so a persistent progress indicator would be fabricated. Heartbeat
 * recency and the same Q28 backlog counts are the closest honest substitutes.
 */
final class Read_Storage_Operations_Detail {
	public const PROFILE = 'oj_ai.storage_operations_detail@1.0.0';

	/** Every worker schedules on a 60-second cron interval (see each Worker_Module::SCHEDULE). */
	private const WORKER_INTERVAL_SECONDS = 60;
	/** A heartbeat is considered fresh within roughly twice its expected interval. */
	private const STALE_AFTER_SECONDS = self::WORKER_INTERVAL_SECONDS * 2;

	public const STATUS_RUNNING = 'running';
	public const STATUS_STALE   = 'stale';
	public const STATUS_NEVER   = 'never';

	/**
	 * Clock.
	 *
	 * @var Closure
	 */
	private Closure $clock;

	/**
	 * Initialize storage operations detail dependencies and state.
	 *
	 * @param Storage_Diagnostics_Port_Interface      $storage Storage.
	 * @param Storage_Worker_Heartbeat_Port_Interface $heartbeat Heartbeat.
	 * @param Closure|null                            $clock Clock.
	 * @param Storage_Backlog_Port_Interface|null     $backlog Backlog.
	 */
	public function __construct(
		private Storage_Diagnostics_Port_Interface $storage,
		private Storage_Worker_Heartbeat_Port_Interface $heartbeat,
		?Closure $clock = null,
		private ?Storage_Backlog_Port_Interface $backlog = null
	) {
		$this->clock = $clock ?? static fn (): string => gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Execute storage operations detail.
	 *
	 * @return array<string,mixed>
	 */
	public function execute(): array {
		$now_utc    = $this->datetime( ( $this->clock )() );
		$result     = $this->storage_result();
		$heartbeats = $this->heartbeats();

		$workers = array();
		foreach ( $this->worker_keys() as $worker_key ) {
			$workers[ $worker_key ] = $this->worker_health( $heartbeats[ $worker_key ] ?? null, $now_utc );
		}

		return array(
			'profile'        => self::PROFILE,
			'checked_at_utc' => $now_utc,
			'status'         => $result->status(),
			'scanner_status' => $result->scanner_status(),
			'metrics'        => Storage_Diagnostics_Result::UNAVAILABLE === $result->status() ? null : $result->metrics(),
			'workers'        => $workers,
			'backlog'        => $this->backlog_counts( $now_utc ),
		);
	}

	/**
	 * Backlog counts.
	 *
	 * @param string $now_utc Now utc.
	 * @return array<string,array{expired:int,held:int,bound:int,eligible?:int,preserved?:int,in_use?:int,blocked?:int}>|null
	 */
	private function backlog_counts( string $now_utc ): ?array {
		try {
			return $this->backlog?->inspect( $now_utc );
		} catch ( Throwable ) {
			return null;
		}
	}

	/**
	 * Storage result.
	 *
	 * @return Storage_Diagnostics_Result
	 */
	private function storage_result(): Storage_Diagnostics_Result {
		try {
			return $this->storage->inspect();
		} catch ( Throwable ) {
			return Storage_Diagnostics_Result::unavailable();
		}
	}

	/**
	 * Heartbeats.
	 *
	 * @return array<string,string|null>
	 */
	private function heartbeats(): array {
		try {
			return $this->heartbeat->last_heartbeats( $this->worker_keys() );
		} catch ( Throwable ) {
			return array();
		}
	}

	/**
	 * Worker keys.
	 *
	 * @return array<int,string>
	 */
	private function worker_keys(): array {
		return array(
			Submission_Attachment_Scan_Worker_Module::HEARTBEAT_KEY,
			Submission_Attachment_Cleanup_Worker_Module::HEARTBEAT_KEY,
			Submission_Zip_Scan_Worker_Module::HEARTBEAT_KEY,
			Submission_Zip_Cleanup_Worker_Module::HEARTBEAT_KEY,
			Export_Cleanup_Worker_Module::HEARTBEAT_KEY,
		);
	}

	/**
	 * Worker health.
	 *
	 * @param mixed  $last_heartbeat_utc Last heartbeat utc.
	 * @param string $now_utc Now utc.
	 * @return array{status:string,last_heartbeat_utc:?string,minutes_since:?int}
	 */
	private function worker_health( mixed $last_heartbeat_utc, string $now_utc ): array {
		if ( ! is_string( $last_heartbeat_utc ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $last_heartbeat_utc ) ) {
			return array(
				'status'             => self::STATUS_NEVER,
				'last_heartbeat_utc' => null,
				'minutes_since'      => null,
			);
		}

		$elapsed_seconds = $this->elapsed_seconds( $last_heartbeat_utc, $now_utc );
		if ( $elapsed_seconds <= self::STALE_AFTER_SECONDS ) {
			return array(
				'status'             => self::STATUS_RUNNING,
				'last_heartbeat_utc' => $last_heartbeat_utc,
				'minutes_since'      => intdiv( max( 0, $elapsed_seconds ), 60 ),
			);
		}

		return array(
			'status'             => self::STATUS_STALE,
			'last_heartbeat_utc' => $last_heartbeat_utc,
			'minutes_since'      => intdiv( $elapsed_seconds, 60 ),
		);
	}

	/**
	 * Elapsed seconds.
	 *
	 * @param string $earlier_utc Earlier utc.
	 * @param string $later_utc Later utc.
	 * @return int
	 */
	private function elapsed_seconds( string $earlier_utc, string $later_utc ): int {
		$earlier = $this->timestamp( $earlier_utc );
		$later   = $this->timestamp( $later_utc );
		return max( 0, $later - $earlier );
	}

	/**
	 * Timestamp.
	 *
	 * @param string $value Value.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function timestamp( string $value ): int {
		$timestamp = strtotime( $value . ' UTC' );
		if ( ! is_int( $timestamp ) ) {
			throw new RuntimeException( 'Storage operations detail clock is invalid.' );
		}
		return $timestamp;
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
			throw new RuntimeException( 'Storage operations detail clock is invalid.' );
		}
		return $value;
	}
}
